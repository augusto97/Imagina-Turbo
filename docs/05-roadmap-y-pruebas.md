# 05 — Roadmap y pruebas

## Principio

Se construye de lo más seguro y medible a lo más invasivo. La carga condicional de plugins es la función con más ganancia, pero también la más riesgosa: llega en la fase 5, cuando ya existen el perfilador (para saber qué filtrar) y el verificador (para probarlo sin romper sitios).

Antes de la fase 1, en paralelo: medir 3 sitios representativos (un WooCommerce, un corporativo con Elementor, un blog) con LSCache bien configurado y reglas de Cloudflare a mano. Esa es la línea base contra la que se compara cada fase.

## Fases

### Dependencias externas

- **Hub**: servicio aparte, sin especificación de servidor todavía (solo el protocolo en `docs/04`). La fase 1 envía telemetría; hasta que el hub exista, el cliente se construye contra un servidor simulado y la telemetría queda desactivada. Ninguna fase del plugin se bloquea por el hub (regla 7).
- **Verificador**: servicio aparte (Node + Playwright). Se necesita desde la fase 3 (WooCart) y es obligatorio en la fase 5. Debe especificarse y construirse en paralelo a las fases 1–2. Mientras no exista, la verificación es manual y queda registrada como tal.

### Fase 0 — Fundaciones ✅ (completada, ver "Estado de la fase 0" al final)
- Bootstrap, Composer, contenedor, `ModuleInterface`, registro de módulos.
- MU loader (instalación/actualización/desinstalación automáticas y seguras).
- `ConfigCompiler` + `CompiledConfig` con escritura atómica.
- `RequestClassifier` con pruebas para todos los tipos.
- Kill switch, cookie de bypass, modo seguro por fatales.
- `Detector` y `OverlapGuard`.
- Carga y validación de la matriz de compatibilidad (`Compat\Matrix`, `Resolver`).
- Esqueleto del admin (React + Vite) con Panel, Registro y Avanzado.
- Licencia vía Imagina Updater.
- **Aceptación:** loader < 0,3 ms y < 150 KB medidos con el benchmark; activar/desactivar el plugin no deja residuos; un fatal simulado 3 veces activa el modo seguro (fatal originado en el propio plugin, ya que en la fase 0 aún no se filtran plugins); la matriz compila solo las reglas de los plugins activos; el loader huérfano (plugin borrado por FTP) no produce errores.

### Fase 1 — Perfilador y diagnóstico (solo lectura) ✅ (completada, ver "Estado de la fase 1" al final)
- Módulo `Profiler`, `Server-Timing`, muestras y rollups.
- Pantalla Diagnóstico.
- Auditoría de `Set-Cookie` y de bfcache (lado servidor).
- Hub: registro y telemetría básica.
- **Aceptación:** ranking de plugins coherente con Query Monitor (±15 %); overhead dentro del presupuesto; telemetría llega al hub y el sitio no se afecta si el hub está caído.

### Fase 2 — Caché y Cloudflare
- `CacheCoordinator` completo (cabeceras, tags, mapa de purga, cola, adaptadores LSCache/WP Rocket/Null, recalentado).
- Módulo Cloudflare: token, inspector, plantillas de caché, ajustes de zona, purga, diff, aplicar, snapshots, revertir, deriva.
- Pantallas Caché y Cloudflare.
- Refresco de nonces para formularios en páginas cacheadas (adelantado desde la fase 3). Sin él, edge TTL máximo de 10 h.
- Verificar si LSCache y WP Rocket conservan las cabeceras propias en sus respuestas cacheadas (ver `docs/02`, módulo 2) y elegir el plan B si no.
- **Aceptación:** en el sitio WooCommerce de prueba, > 85 % de aciertos del edge en tráfico anónimo; edición de producto reflejada en < 60 s; revertir un snapshot deja la zona exactamente como estaba (comparación JSON).

### Fase 3 — WooCommerce
- `WooCart` (Store API, fragments fuera, cookies neutralizadas, geolocalización).
- **Aceptación:** criterios del módulo 3 en `docs/02-modulos.md`, probados en Astra, Flatsome y Woodmart (o los temas reales de los clientes).

### Fase 4 — Escudo, ajustes y firewall
- `Shield`, `Tweaks` (reemplazo de Perfmatters), firewall edge (plantillas, modo desafío → bloqueo), rate limiting, verificación de origen cerrado, `HtaccessWriter` para Apache.
- Migración desde Perfmatters (detección, mapeo de sus ajustes equivalentes, aviso).
- **Aceptación:** requests de basura (404 de assets, XML-RPC, escáneres) no cargan plugins ni tema; ningún webhook de pasarela de pago bloqueado en pruebas; el firewall en desafío no afecta navegación ni checkout de usuarios reales.

### Fase 5 — Carga condicional de plugins
- `PluginFilter` con todas las protecciones (lecciones de Perfmatters MU).
- Experimentos con el verificador, promoción y vigilancia automática de reglas.
- Integración con Imagina Search.
- Resolver la decisión abierta sobre retirar o mantener el filtro de lectura.
- **Aceptación:** criterios del módulo 4; prueba de estrés con Freemius, WPML y un plugin que hace flush de rewrite rules.

### Fase 6 — Navegación instantánea y RUM
- `Rum`, `Speculation` (core + predictivo + fallback), bfcache (RUM), View Transitions, `FrontendHints` (LCP, fachadas).
- **Aceptación:** criterios de los módulos 5, 9 y 11.

### Fase 7 — Assets y base de datos
- `AssetManager` con sugerencias y verificación.
- `Database` con auditoría, acciones y respaldos.
- Alimentar exclusiones de delay JS de WP Rocket/LSCache con la matriz.

### Fase 8 — Flota
- Plantillas de configuración estándar desde el hub, detección de desviaciones, aplicación por lotes de reglas de Cloudflare a varias zonas.

## Estrategia de pruebas

### Unitarias (PHPUnit + Brain Monkey)
- `RequestClassifier` (tabla de casos con `$_SERVER` simulado).
- `ConfigCompiler` (salida determinista, escritura atómica).
- Constructores de expresiones de Cloudflare (snapshots de texto esperado, longitud máxima).
- `PlanDiff` (crear/modificar/eliminar/sin cambios, deriva).
- `TagBuilder`, `PurgeMap`.
- Protecciones de `PluginFilter` (escritura de `active_plugins`, `rewrite_rules`, coincidencia por carpeta).

### Integración (wp-env o contenedor con WordPress + WooCommerce)
- Activación/desactivación limpias.
- Flujo de purga real contra LSCache/WP Rocket instalados.
- Cliente de Cloudflare contra un servidor simulado (respuestas grabadas) y, aparte, contra una zona de pruebas real con token de pruebas.

### Rendimiento (`tests/Performance`)
- Benchmark del loader: 10.000 iteraciones del camino del frontend con config compilada; falla si supera el presupuesto.
- Comparación de memoria por tipo de request con y sin reglas de carga condicional.

### Verificador (servicio aparte, Node + Playwright, en el VPS de herramientas)
- Recibe: URL del sitio, lista de páginas clave (home, categoría, producto, carrito, checkout sin pagar, login, un formulario), y opcionalmente una cookie firmada de experimento.
- Comprueba: código de estado, errores de consola, errores PHP reportados por el sitio, diferencia visual con capturas de referencia (umbral configurable), flujo de agregar al carrito con un producto de prueba, presencia y envío de un formulario de prueba, cabeceras de caché esperadas (`cf-cache-status`).
- Devuelve pasa/falla con evidencia. El plugin revierte automáticamente lo probado si falla.
- Autenticación con firma HMAC; nunca guarda credenciales del sitio.

### Herramientas

| Comando | Qué hace |
|---|---|
| `composer test` | Pruebas unitarias (PHPUnit + Brain Monkey) |
| `composer analyse` | PHPStan nivel 8 con stubs de WordPress |
| `composer lint` | PHPCS con WordPress Coding Standards (nombres camelCase y PSR-4 permitidos) |
| `composer bench` | Benchmark de la etapa temprana; falla si supera 0,3 ms o 150 KB |
| `cd admin && npm run build` | Typecheck y build del admin |
| `bin/build.sh` | ZIP de distribución (dependencias sin dev, `classmap-authoritative`, admin compilado) |
| `tests/e2e/` | Procedimiento y prueba E2E en un WordPress real |

### Métricas de éxito del proyecto
- TTFB p75 de páginas anónimas < 100 ms en Colombia/México (desde el edge).
- LCP p75 móvil < 2,0 s en las plantillas principales.
- Requests que llegan a PHP: −70 % frente a la línea base.
- Memoria media por request en `wc-ajax`/REST: −40 %.
- Cero incidentes de funcionalidad rota sin reversión automática.

## Estado de la fase 0

Completada el 2026-10-13. Criterios de aceptación:

| Criterio | Resultado |
|---|---|
| Loader < 0,3 ms y < 150 KB | 0,04 ms y 17 KB con OPcache; 0,04 ms y 137 KB sin OPcache (`composer bench`) |
| Activar/desactivar sin residuos | Verificado en WordPress real: al desactivar no quedan loader, config ni directorio; al desinstalar no quedan tablas, opciones, capacidad ni tareas |
| 3 fatales activan el modo seguro | Verificado en pruebas aisladas y en WordPress real; los fatales de otros plugins no cuentan |
| La matriz compila solo las reglas de plugins activos | Verificado (pruebas y config real: solo WooCommerce y WPS Hide Login) |
| Loader huérfano sin errores | Verificado (plugin borrado por FTP: sitio y admin responden 200) |

Calidad: 117 pruebas unitarias, PHPStan nivel 8 sin errores, PHPCS sin errores, TypeScript estricto, E2E del admin en Chromium.

Pendiente fuera del alcance de la fase 0: el hub (fase 1) y el verificador (servicios aparte), y las pruebas en OpenLiteSpeed/LSPHP reales (la instancia de prueba usó el servidor integrado de PHP).

## Estado de la fase 1

Completada el 2026-10-13 (versión 0.2.0, esquema de base de datos v2). Probada en WordPress 7.1.3 + WooCommerce 11.2.1 + LiteSpeed Cache 7.9.1 + WPS Hide Login, PHP 8.3 con OPcache, MariaDB 10.11.

| Criterio | Resultado |
|---|---|
| Overhead < 0,2 ms sin muestreo | Sin diferencia medible en el benchmark (+0,00–0,01 ms; el loader completo sigue en 0,04 ms y 18 KB) |
| Overhead < 1 ms en request muestreado | Costo propio medido en el sitio real: mediana ~0,5 ms en REST/AJAX y ~0,6–0,9 ms en páginas de WooCommerce (incluye envolver ~180 bloques). Benchmark sintético: 0,27 ms. A/B de tiempo de respuesta muestreado vs no muestreado: diferencia dentro del ruido (< 0,5–1 ms) |
| Totales coherentes con Query Monitor | Tiempo hasta `shutdown` en el mismo request: 180,53 ms (perfilador) vs 180,54 ms (Query Monitor 4.0.7). Memoria y consultas del perfilador son mayores porque se toman al final de `shutdown`, después de que Query Monitor genera su propia salida |
| Ranking de plugins ±15 % | Query Monitor no desglosa tiempo por plugin, así que se validó con A/B (desactivar el plugin y medir): WooCommerce en `/sample-page/` 63,7 ms atribuidos vs 72 ms de A/B (medianas, −11,5 %) y 70,1 vs 80 ms (medias, −12,4 %). LiteSpeed Cache: 3 ms atribuidos, A/B indistinguible del ruido (±2 ms). El orden coincide |
| Telemetría llega al hub; el sitio no se afecta si cae | Hub simulado (`tests/e2e/mock-hub.php`): registro, lote firmado y comprimido, verificación de firma y nonce. Con el hub respondiendo 503 o con el puerto cerrado: error controlado, reintento a 1 h que la tarea horaria respeta, sitio y admin responden 200 en todo momento; al volver el hub, el lote pendiente llega |

Hallazgos corregidos durante la validación:

- Sin atribuir el render de bloques dinámicos, el perfilador solo veía ~56 % del costo real de WooCommerce. Ahora se miden los `render_callback` de plugins (incluidos los de `block.json`).
- El costo propio rondaba 1–1,4 ms: casi todo era resolver a qué plugin pertenece cada callback (~420 callbacks en ~170 archivos). Se dejó de envolver el núcleo y se añadió una caché persistente de atribución (`profiler-symbols.php`, OPcache). Una alternativa probada (listar las funciones del núcleo al arrancar) costaba 0,35–0,8 ms por sí sola y se descartó.
- La telemetría podía marcar como enviada una hora aún no agregada: ahora el envío ejecuta antes el agregado y no pasa de la última hora agregada.
- Los eventos del lote podían repetirse en el siguiente (faltaba el límite superior de la ventana).
- `Server-Timing` con tildes se corrompía (las cabeceras son Latin-1): ahora solo ASCII.
- `Set-Cookie: =valor` se registraba como cookie `valor`.
- El orden de las fases dependía de qué tipos de request se mezclaban; ahora sigue el orden real del request.

Decisiones que cambian los documentos de arquitectura (anotadas en `docs/01` y `docs/02`): en modo seguro el perfilador no corre; no se usa `fastcgi_finish_request()`; `Server-Timing` solo con la cookie de diagnóstico (sin `?itb_debug=1`).

Calidad: 142 pruebas unitarias, PHPStan nivel 8 sin errores, PHPCS sin errores, TypeScript estricto, E2E del admin en Chromium (escritorio y móvil sin desbordes).

Pendiente fuera del alcance: el hub real (servicio aparte; el cliente está probado contra el simulado), causas de bfcache del lado del navegador (RUM, fase 6) y las pruebas en OpenLiteSpeed/LSPHP reales.
