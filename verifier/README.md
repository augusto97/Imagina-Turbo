# Verificador de Imagina Turbo

Servicio aparte (Node 22 + Playwright + Lighthouse) para un VPS de herramientas. Abre las páginas clave de cada sitio como un visitante en el móvil y avisa cuando algo se rompe o se pone lento. Así cada cambio de rendimiento (retrasar JS, diferir elementos, quitar plugins o assets) se puede comprobar en páginas completas, no solo en el HTML.

## Qué comprueba

En cada página (Chromium móvil, sin sesión, español de Colombia):

- **Estado HTTP**: falla con 400 o más, salvo una página marcada con `"expect": 404`.
- **Errores de JavaScript** sin capturar: fallan. Los errores de consola quedan como aviso.
- **Peticiones del propio sitio que fallan**: JS, CSS, fuentes, AJAX o cualquier 5xx fallan. Un 404 de imagen, o un fallo de un tercero, queda como aviso.
- **Errores de PHP en el HTML**: «Fatal error», «Warning», «There has been a critical error»…
- **Interacción**: mueve el ratón, desplaza y pulsa Tab para que carguen los scripts retrasados (LSCache, WP Rocket, Imagina Turbo). Después baja hasta el final para despertar lo diferido y vuelve a mirar los errores.
- **LCP, CLS y TTFB** medidos en el navegador.
- **Cambio visual** de la primera pantalla frente a una captura de referencia (la primera corrida la crea; el admin puede renovarla). Un cambio de más del 15 % queda como aviso: en una tienda el contenido cambia a diario.
- **Añadir al carrito** (`"flow": "add_to_cart"`): elige la primera opción de cada atributo, pulsa el botón y lo comprueba con la Store API. Después quita lo añadido. No crea pedidos.
- **Cabeceras de caché**: `cf-cache-status`, `x-litespeed-cache`, `cache-control`, `age`…
- **Lighthouse** (opcional, y siempre en la nocturna): rendimiento en móvil con la limitación simulada por defecto. Da la puntuación, las métricas y las cinco oportunidades con más ahorro.

**No envía formularios**: en producción mandaría correos reales.

## Instalación (Hetzner u otro VPS con Docker)

Requisitos: 2 GB de RAM (Chromium + Lighthouse), Docker con Compose.

```bash
git clone https://github.com/augusto97/imagina-turbo.git
cd imagina-turbo/verifier
mkdir -p data && sudo chown 1000:1000 data      # el contenedor corre como el usuario node (1000)
cp sites.example.json data/sites.json            # se rellena en el paso siguiente
docker compose up -d --build
curl http://127.0.0.1:8787/v1/health
```

El puerto solo escucha en `127.0.0.1`. Delante va TLS, por ejemplo con Caddy:

```
verificador.tudominio.com {
	reverse_proxy 127.0.0.1:8787
}
```

O con un túnel de Cloudflare (`cloudflared tunnel`) hacia `http://127.0.0.1:8787`, sin abrir puertos.

## Conectar un sitio

1. En el WordPress: **Imagina Turbo → Avanzado → Verificador externo**. Escribe la URL del verificador y un id del sitio, y guarda.
2. El plugin genera la clave y muestra **una sola vez** el bloque para `sites.json`, con la URL del sitio y sus páginas clave: portada, entrada, página, producto con el flujo del carrito, tienda, categoría, carrito, pago, cuenta y una página inexistente.
3. Pégalo dentro de `"sites"` en `data/sites.json` y recarga sin reiniciar: `docker compose kill -s HUP verifier`.
4. En el plugin: **Probar conexión** y **Ejecutar batería**.

La clave se guarda cifrada en el sitio (sodium, como el token de Cloudflare). En `sites.json` está en claro: protege el servidor.

## Corrida nocturna

Cada día a `NIGHTLY_HOUR` (hora local de `TZ`, por defecto las 3:00 de Bogotá) corre la batería y Lighthouse en los sitios con `"nightly": true`. Avisa si una página falla o si su puntuación baja 10 puntos o más frente a la nocturna anterior. El plugin lee el resultado una vez al día y lo deja en su registro.

Capturas: se guardan 14 días. Corridas: 60 días.

## API

Todas las rutas, salvo `/v1/health`, van firmadas con la misma cadena canónica que el hub:

- Cabeceras `X-ITB-Site`, `X-ITB-Timestamp`, `X-ITB-Nonce` (32 hex) y `X-ITB-Signature`.
- La firma es HMAC-SHA256 de `MÉTODO\nRUTA?QUERY\nTIMESTAMP\nNONCE\nsha256_hex(cuerpo)` con la clave del sitio.
- Ventana de 5 minutos. Cada nonce se acepta una sola vez.

| Método | Ruta | Uso |
|---|---|---|
| GET | `/v1/health` | Estado, versión y cola |
| POST | `/v1/runs` | `{pages?, lighthouse?, visual?, threshold?, cookie?}` → `{id, status}`. Sin `pages`, usa las de `sites.json`. Solo URLs del mismo host que el sitio. `cookie` solo admite cookies `itb_*` (experimentos firmados por el plugin), nunca una sesión |
| GET | `/v1/runs?limit=10` | Últimas corridas (resumen) |
| GET | `/v1/runs/{id}` | Una corrida con todos los resultados |
| POST | `/v1/baseline` | `{run_id}`: las capturas de esa corrida pasan a ser la referencia visual |

Una sola corrida a la vez: con más de 20 en cola responde 429.

## Desarrollo

```bash
npm install
npm test                                          # firma, páginas permitidas, nocturna, diferencias visuales, Lighthouse
VERIFIER_DATA=./data NIGHTLY_HOUR= npm start
```

## CSS crítico: por qué no está aquí

Generar CSS crítico es trabajo del plugin de caché, y Imagina Turbo no lo hace (CLAUDE.md): en OpenLiteSpeed lo genera LiteSpeed Cache con QUIC.cloud y en Plesk lo hace WP Rocket. Un segundo generador competiría con el suyo y tendría que reescribir el HTML. El verificador sí sirve para comprobar que el CSS crítico del plugin de caché no rompe la primera pantalla (cambio visual, CLS).
