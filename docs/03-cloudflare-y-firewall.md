# 03 — Cloudflare y firewall

> **Importante para la implementación:** todos los endpoints, fases, campos y límites de este documento deben verificarse contra la documentación vigente de Cloudflare (developers.cloudflare.com) antes de codificarlos. Los límites por plan cambian: guardarlos en una tabla actualizable desde el hub, nunca fijos en el código.

## 1. Conexión y token

- El usuario crea un **API Token** (no la Global API Key) limitado a la zona, con permisos:
  - Zone → Zone: Read
  - Zone → Zone Settings: Edit
  - Zone → Cache Rules: Edit (o el permiso de Rulesets equivalente)
  - Zone → Zone WAF: Edit (reglas personalizadas y rate limiting)
  - Zone → Cache Purge: Purge
  - Zone → Analytics: Read
  - Zone → Config Rules / Transform Rules: Edit (solo si se usan; ver 4.3)
- El admin muestra un enlace a la plantilla de creación de token con instrucciones paso a paso.
- `TokenVault`: cifrar con `sodium_crypto_secretbox` usando una clave derivada de `AUTH_KEY` y `SECURE_AUTH_SALT`. Guardar en una opción autoload = no. Mostrar solo los últimos 4 caracteres. Nunca incluir en exportaciones.
- Verificación: `GET /user/tokens/verify`, luego `GET /zones?name={dominio}` para obtener el `zone_id` y `GET /zones/{zone_id}` para el plan.
- No aplicar nada en dominios de staging (lista `core.staging_domains` de la matriz) ni si el host del sitio no coincide con la zona.

## 2. Cliente de API (`Client`)

- Base: `https://api.cloudflare.com/client/v4`. Usar `wp_remote_request` con timeout de 15 s.
- Reintentos con backoff exponencial ante 429 y 5xx (máximo 3). Respetar el límite global de la API (del orden de 1.200 peticiones cada 5 minutos por usuario; verificar).
- Toda respuesta se valida (`success`, `errors[]`) y se registra en el `EventLog` (sin el token).

## 3. Reglas propias: cómo gestionarlas sin pisar las del cliente

- Las reglas viven en **rulesets por fase** (Rulesets API):
  - `http_request_cache_settings` → reglas de caché
  - `http_request_firewall_custom` → reglas WAF personalizadas
  - `http_ratelimit` → rate limiting
  - `http_config_settings` → reglas de configuración (opcional)
  - `http_request_transform` → reescritura de URL (opcional, ver 4.3)
- Leer el ruleset de entrada: `GET /zones/{zone_id}/rulesets/phases/{phase}/entrypoint`.
  - Si no existe (404), crearlo con `PUT` sobre la misma ruta **solo con nuestras reglas**.
  - Si existe, **nunca** hacer `PUT` del ruleset completo (reemplaza todas las reglas). Usar los endpoints por regla:
    - Crear: `POST /zones/{zone_id}/rulesets/{ruleset_id}/rules`
    - Modificar: `PATCH /zones/{zone_id}/rulesets/{ruleset_id}/rules/{rule_id}`
    - Eliminar: `DELETE /zones/{zone_id}/rulesets/{ruleset_id}/rules/{rule_id}`
- Identificar las reglas propias con el campo `ref` (estable, por ejemplo `itb-cache-html`) y además con la descripción prefijada `[imagina-turbo] ...`.
- El orden importa: en caché, las reglas posteriores sobrescriben ajustes de las anteriores; en WAF, la primera acción terminal gana. Usar la posición (`position.before` / `position.after`) al crear.
- **Detección de deriva**: comparar el hash de cada regla propia con el último aplicado. Si alguien la modificó a mano, marcarla como "modificada externamente" y no sobrescribirla sin confirmación.

## 4. Plantillas de caché

### 4.1 `itb-cache-html` — hacer elegible el HTML anónimo

- **Acción:** `set_cache_settings`.
- **Parámetros:** `cache: true`; `edge_ttl: { mode: "respect_origin" }`; `browser_ttl: { mode: "respect_origin" }`; `serve_stale: { disable_stale_while_updating: false }`; `respect_strong_etags: true`.
- La duración real la decide el origen con `Cloudflare-CDN-Cache-Control` (ver módulo `CacheCoordinator`). Si el origen manda `private`/`no-store` o `Set-Cookie`, no se cachea. Así WordPress, que sabe qué es dinámico, manda; y la regla del edge solo habilita.
- **Expresión** (se genera con las rutas reales del sitio; ejemplo):

```
(http.request.method in {"GET" "HEAD"})
and not starts_with(http.request.uri.path, "/wp-admin")
and not http.request.uri.path in {"/wp-login.php" "/xmlrpc.php" "/wp-cron.php"}
and not starts_with(http.request.uri.path, "/wp-json")
and not http.request.uri.query contains "wc-ajax"
and not http.request.uri.query contains "add-to-cart"
and not http.request.uri.query contains "rest_route"
and not http.request.uri.query contains "preview"
and not http.request.uri.query contains "s="
and not http.cookie contains "wordpress_logged_in_"
and not http.cookie contains "wp-postpass_"
and not http.cookie contains "comment_author_"
and not starts_with(http.request.uri.path, "/carrito")
and not starts_with(http.request.uri.path, "/finalizar-compra")
and not starts_with(http.request.uri.path, "/mi-cuenta")
```

- Si el módulo `WooCart` está **inactivo**, agregar `and not http.cookie contains "woocommerce_items_in_cart"`.
- Agregar las cookies de variación/bypass de la matriz que apliquen al sitio (moneda, idioma, consentimiento). En el edge, una cookie de variación se trata como **bypass** salvo en planes que permitan cookies en la llave de caché.
- Excluir `s=` solo si Imagina Search no marca sus resultados como cacheables.
- Controlar la longitud de la expresión: hay un límite por plan. Si se excede, agrupar rutas con `starts_with` o mover exclusiones al origen (que manda `no-store`).

### 4.2 `itb-cache-static` — assets

- Expresión: extensión en `{"css" "js" "jpg" "jpeg" "png" "gif" "webp" "avif" "svg" "woff" "woff2" "ttf" "ico" "mp4" "webm" "pdf"}` (usar `http.request.uri.path.extension`; verificar disponibilidad del campo, alternativa `ends_with`).
- `edge_ttl: override_origin 30 días`, `browser_ttl: respect_origin`.
- Purga por URL al reemplazar un adjunto (`wp_update_attachment_metadata`, plugins de optimización de imágenes).

### 4.3 Parámetros de campañas (llave de caché)

Objetivo: que `?fbclid=...` o `?utm_source=...` no generen copias nuevas. Estrategia según plan:

1. **Llave de caché personalizada** en `itb-cache-html` con `cache_key.custom_key.query_string.exclude.list` = `core.ignore_query_params`, si el plan lo permite.
2. Si no: **regla de transformación de URL** que reescriba el query eliminando esos parámetros (función de eliminación de argumentos del lenguaje de reglas, si está disponible en el plan). El navegador conserva la URL original, así que la analítica en JavaScript sigue leyendo los parámetros.
3. Si ninguna está disponible: no hacer nada en el edge y documentarlo en el panel (el origen sí los ignora en LSCache/WP Rocket).

### 4.4 Ajustes de zona (`ZoneSettings`)

| Ajuste | Acción |
|---|---|
| HTTP/3 | Activar |
| Early Hints | Activar (aprovecha los `Link: preload` del origen) |
| Smart Tiered Cache | Activar si el plan lo permite |
| Rocket Loader | Desactivar (choca con el delay JS del plugin de caché) |
| Browser Cache TTL | "Respetar cabeceras existentes" |
| SSL/TLS | **Solo lectura.** Alertar si está en Flexible; recomendar Full (strict) |
| Always Use HTTPS, certificados, DNS | **Solo lectura** |
| Bot Fight Mode | **No activar automáticamente**: en el plan Free no se puede excluir por ruta y bloquea webhooks de pasarelas de pago. Mostrar como recomendación con advertencia |
| Bloqueo de crawlers de IA | Decisión del cliente; mostrar la opción gestionada de Cloudflare |

Endpoint general: `GET/PATCH /zones/{zone_id}/settings/{setting}` (verificar nombres de cada ajuste; Tiered Cache tiene su propio endpoint).

## 5. Purga

- `POST /zones/{zone_id}/purge_cache` con uno de: `{"tags": [...]}`, `{"files": [...]}`, `{"prefixes": [...]}`, `{"hosts": [...]}`, `{"purge_everything": true}`.
- Preferir tags. Verificar en la zona si la purga por tag está disponible (en 2025 Cloudflare la extendió a todos los planes; confirmar). Si falla con error de permisos/plan, pasar a purga por URL resolviendo las URLs de cada tag.
- Lotes según el límite de elementos por llamada (verificar; usar 30 si no se puede confirmar). Límite de llamadas por minuto: respetar con la cola.
- `purge_everything` solo con confirmación del usuario o en eventos de "todo" (cambio de tema, Elementor CSS global) **y** seguido de recalentado.
- Sincronizar siempre con el adaptador del plugin de caché (purgar ambos niveles).

## 6. Firewall por capas

### Capa 1 — Edge (Cloudflare WAF), la principal

Reglas personalizadas en `http_request_firewall_custom`. En el plan Free hay pocas reglas y **sin regex** (usar `contains`, `starts_with`, `ends_with`, `in`, `wildcard` si está disponible, `lower()`). El orden es:

1. **`itb-fw-skip-webhooks`** — acción `skip` (resto de reglas propias) para webhooks y APIs legítimas: `/wc-api/`, `?wc-api=`, rutas REST de pasarelas de pago presentes en la matriz, IPs/ASN de servicios que el usuario agregue. Va primero para que nada las bloquee ni las desafíe.
2. **`itb-fw-exploits`** (inspirada en 8G) — acción `block`:

```
(http.request.uri.query contains "../")
or (lower(http.request.uri.query) contains "base64_")
or (lower(http.request.uri.query) contains "union select")
or (lower(http.request.uri.query) contains "<script")
or (lower(http.request.uri.query) contains "eval(")
or (http.request.uri.query contains "GLOBALS=")
or (http.request.uri.query contains "_REQUEST=")
or (http.request.uri.query contains "/etc/passwd")
or (http.request.uri.path contains "/.git")
or (http.request.uri.path contains "/.env")
or (ends_with(http.request.uri.path, ".sql"))
or (ends_with(http.request.uri.path, ".bak"))
or (http.request.uri.path contains "/vendor/phpunit")
or (http.request.uri.path contains "phpinfo")
or (http.request.method in {"TRACE" "TRACK" "DEBUG"})
```

3. **`itb-fw-wp-surface`** — acción `block`: `xmlrpc.php` (salvo que la matriz indique un plugin que lo necesita, por ejemplo Jetpack), enumeración (`path eq "/"` y query con `author=`), cualquier `.php` bajo `/wp-content/uploads/`, `readme.html`, `license.txt`, `wp-config` en cualquier forma.
4. **`itb-fw-bad-bots`** — acción `block` para escáneres conocidos (sqlmap, nikto, nmap, masscan, zgrab, wpscan, etc.); lista "suave" opcional con `managed_challenge` (python-requests, Go-http-client, curl) desactivada por defecto porque algunas integraciones legítimas la usan.
5. **Reservada** para el cliente o para la regla de emergencia del optimizador.

Notas:
- **No usar la presencia de una cookie como excepción de seguridad** (es falsificable). Solo para reducir falsos positivos en reglas de baja severidad.
- **Modo de prueba**: la acción `log` en reglas personalizadas es exclusiva de planes Enterprise. En Free/Pro/Business, las reglas nuevas entran con `managed_challenge` durante 7 días (un humano pasa, un bot no) y luego pasan a `block`. Las rutas de API/webhooks ya quedaron excluidas por la regla 1.
- Antes de reutilizar patrones del 8G, revisar su licencia y dar crédito a su autor (Jeff Starr, Perishable Press) en el código y el README.

### Rate limiting (`http_ratelimit`)

En Free hay una sola regla, con características y periodos limitados (típicamente por IP, ventanas cortas y condiciones restringidas a la ruta; verificar). Plantilla por defecto:

- Rutas: `/wp-login.php` y `/wp-json/wc/store/v1/checkout` (protege contra fuerza bruta y contra **card testing** en WooCommerce).
- Umbral inicial: 5 peticiones / 10 s por IP → bloqueo por el tiempo que permita el plan.
- En planes superiores agregar reglas para búsquedas (`?s=`), combinaciones de filtros y `?add-to-cart=`.

### Capa 2 — Cerrar el origen

- Si el VPS acepta tráfico directo, todo lo anterior se puede saltar.
- `OriginLockCheck`:
  - En el sitio: muestrear requests que llegan **sin** `CF-Connecting-IP`/`CF-Ray` (excluyendo localhost, cron, el warmer y WP-CLI). Si hay tráfico externo directo, alertar.
  - Desde el verificador: pedir la IP del servidor directamente con la cabecera `Host` del sitio; si responde con el sitio, alertar.
- Guía en el admin: permitir 80/443 solo desde los rangos de IP de Cloudflare (lista oficial publicada por Cloudflare) en el firewall del VPS (ServerAvatar/UFW/Plesk), o usar Authenticated Origin Pulls si el servidor lo soporta.
- Verificar que OpenLiteSpeed use la IP real (`CF-Connecting-IP`) solo desde IPs de Cloudflare de confianza, para que CrowdSec y los logs vean al visitante real.

### Capa 3 — Servidor

- **CrowdSec** (ya instalado): reputación de IPs y bloqueo por comportamiento. El plugin no lo duplica; solo verifica que reciba la IP real.
- **`HtaccessWriter`** (sitios Apache/Plesk): subconjunto de reglas 8G entre marcadores `# BEGIN Imagina Turbo Firewall` / `# END Imagina Turbo Firewall`. Antes de escribir: respaldo. Después: pedir la home, una página de producto y `/wp-json/`; si alguna no responde 200, restaurar el respaldo automáticamente.
- **OpenLiteSpeed**: solo interpreta parte del `.htaccess` y puede requerir recarga del servidor. Ofrecer las reglas como opcionales y avisar que dependen de la configuración del servidor. La protección principal en OLS es Cloudflare + CrowdSec.

### Capa 4 — Guardia PHP mínima

Ver módulo `Shield` (bloqueos baratos antes de cargar plugins). Sin WAF completo en PHP.

## 7. Analíticas y ciclo de optimización (`Analytics`, `Optimizer`)

Fuentes de datos:
- **GraphQL de Cloudflare** (`POST /graphql`): conjuntos de datos de requests HTTP agregados (por estado de caché y ruta) y eventos de firewall. La disponibilidad, el detalle y la retención dependen del plan (en Free son limitados): detectar qué hay disponible y degradar con elegancia.
- **Muestras del origen**: todo request que llega a PHP es un miss o un bypass del edge. El perfilador registra ruta, tipo, cookies presentes (solo nombres), parámetros y si la respuesta fue cacheable. Esta fuente funciona en cualquier plan.
- **RUM**: experiencia real de los visitantes.

Optimizaciones automáticas (cada una con nivel de riesgo y registro):

| Detección | Acción | Riesgo |
|---|---|---|
| Parámetro de query frecuente que no cambia la respuesta (mismo hash normalizado) | Proponer agregarlo a parámetros ignorados; auto-aplicar si coincide con patrones de campaña (`*clid`, `utm_*`, `mc_*`, `hsa_*`) | Seguro |
| URL muy visitada que siempre llega al origen | Diagnóstico del motivo (cookie, `Set-Cookie`, cabecera, regla) y recomendación | Seguro |
| Regla WAF en `managed_challenge` 7 días sin afectar tráfico legítimo (sin desafíos resueltos por humanos, si el dato está disponible) | Pasar a `block` | Moderado |
| Umbral de rate limit | Calcular el p99 de peticiones por IP en esa ruta en tráfico normal × 3 | Moderado |
| Carga del servidor > núcleos × 1,5 durante 2 min **y** misses > 5 × línea base | Modo emergencia: desafío en búsquedas/filtros, rate limit más estricto; revertir solo a los 30 min; notificar | Invasivo (opcional) |

## 8. Ciclo de aplicación (UI y backend)

1. **Detectar**: `ZoneInspector` lee plan, rulesets, ajustes y SSL; `Detector` aporta el contexto del sitio.
2. **Proponer**: `PlanDiff` genera la lista de cambios (crear/modificar/eliminar/sin cambios) con el JSON exacto que se enviará.
3. **Aplicar**: `Snapshot` guarda el estado previo (reglas propias + ajustes tocados) en `itb_cf_snapshots`; luego se aplican los cambios. Seguros: automáticos. Sensibles: requieren aprobación o entran en modo prueba.
4. **Vigilar**: jobs de Action Scheduler (cada hora: analíticas y muestras; diario: optimizador).
5. **Ajustar**: el optimizador propone o aplica según el riesgo.
6. **Revertir**: restaurar un snapshot afecta solo las reglas propias (`ref` con prefijo `itb-`) y los ajustes registrados.

## 9. Límites por plan (orientativos — verificar y mantener en el hub)

| | Free | Pro | Business |
|---|---|---|---|
| Reglas de caché | ~10 | ~25 | ~50 |
| Reglas WAF personalizadas | ~5 | ~20 | ~100 |
| Reglas de rate limiting | 1 | 2 | 5 |
| Regex en expresiones | No | No | Sí |
| Acción `log` en WAF | No | No | No (solo Enterprise) |

El plugin adapta las plantillas: en Free combina condiciones en menos reglas; en planes superiores separa reglas para tener mejor visibilidad.
