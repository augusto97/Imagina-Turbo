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

### Fase 2 — Caché y Cloudflare (2a ✅ y 2b ✅ contra un simulador de la API; la prueba con una zona real queda para el final, ver "Estado de la fase 2b")
- `CacheCoordinator` completo (cabeceras, tags, mapa de purga, cola, adaptadores LSCache/WP Rocket/Null, recalentado).
- Módulo Cloudflare: token, inspector, plantillas de caché, ajustes de zona, purga, diff, aplicar, snapshots, revertir, deriva.
- Pantallas Caché y Cloudflare.
- Refresco de nonces para formularios en páginas cacheadas (adelantado desde la fase 3). Sin él, edge TTL máximo de 10 h.
- Verificar si LSCache y WP Rocket conservan las cabeceras propias en sus respuestas cacheadas (ver `docs/02`, módulo 2) y elegir el plan B si no.
- **Aceptación:** en el sitio WooCommerce de prueba, > 85 % de aciertos del edge en tráfico anónimo; edición de producto reflejada en < 60 s; revertir un snapshot deja la zona exactamente como estaba (comparación JSON).

### Fase 3 — WooCommerce ✅ (ver "Estado de la fase 3" al final; `cf-cache-status: HIT` y los temas de pago quedan para las pruebas reales)
- `WooCart` (Store API, fragments fuera, cookies neutralizadas, geolocalización).
- **Aceptación:** criterios del módulo 3 en `docs/02-modulos.md`, probados en Astra, Flatsome y Woodmart (o los temas reales de los clientes).

### Fase 4 — Escudo, ajustes y firewall ✅ (ver "Estado de la fase 4" al final; eventos reales, `url_decode` y la autoprueba en Apache quedan para las pruebas reales)
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

## Estado de la fase 2a

Fase 2 dividida en dos entregas: **2a** (coordinador de caché y refresco de nonces, sin Cloudflare) y **2b** (módulo Cloudflare, con una zona de pruebas real). 2a completada el 2026-10-13 (versión 0.3.0, esquema de base de datos v3). Probada en **OpenLiteSpeed 1.9.3 + LiteSpeed Cache 7.9.1** con WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.3 (OPcache) y MariaDB 10.11, y con **WP Rocket 3.23** (código fuente, sin licencia) para el adaptador.

| Criterio | Resultado |
|---|---|
| LSCache reproduce nuestras cabeceras en los aciertos | Sí: `Cloudflare-CDN-Cache-Control` y `Cache-Tag` en el fallo y en el acierto (`x-litespeed-cache: hit`) |
| Lo no cacheable no se guarda en el edge | Carrito, checkout, cuenta, login, 404, búsqueda, logueados y respuestas con `Set-Cookie` (también uno añadido durante el render) salen con `no-store` para el edge |
| Purga por tag propio en LSCache | Purgar `p-2` deja esa página en `miss`; las demás siguen en `hit` |
| Edición de producto reflejada en < 60 s | Cambio de stock por HTTP: producto, categoría, tienda y portada purgados en el origen al instante; tras el vaciado de la cola y el recalentado, todas otra vez en `hit` a los 47 s |
| Purga diferida de LSCache en cron | Validada: el worker hace un request a `admin-ajax.php` y la purga pendiente llega al servidor antes de recalentar |
| Nonces en páginas cacheadas | Endpoint: firma válida → nonces; firma alterada, acción no firmada o fecha cambiada → 400; no lo cachea LSCache. En Chromium con el reloj adelantado 11 h: una sola petición, valores reemplazados en campos, atributos, enlaces y objetos globales (también anidados) |
| WP Rocket | `rocket_clean_files` una vez por request con las URLs correctas; `rocket_clean_domain` se replica como "todo"; en OpenLiteSpeed/nginx el HTML no se deja en el edge (`edge_html = false`) |
| Costo propio en el frontend | 0,075 ms y 27 KB al registrar el módulo en una página (presupuesto: 0,5 ms y 200 KB) |

Hallazgos corregidos durante la validación:

- LSCache, con sus valores por defecto (caché privada y caché de REST activas), guardaba en caché privada los GET de la API del admin: el panel mostraba un estado viejo (por ejemplo, el kill switch no aparecía activo). Ahora todas las rutas propias envían `litespeed_control_set_nocache`, y al actualizar se descarta la caché privada vieja.
- Con la cookie de diagnóstico, LSCache guardaba la respuesta con `Server-Timing` y la repetía aun después de retirar la cookie. Esas respuestas ya no se guardan.
- LSCache cacheaba el endpoint público de nonces (una semana): ahora recibe `no-cache`.
- LSCache reconstruye su cabecera de purga al final del request con todo lo acumulado: sin filtrar, nuestras propias purgas volvían como "purgar todo". Lo ya purgado en el request no se repite.
- Se recalentaba la URL de `product_type` (taxonomía no pública): solo se usan taxonomías visibles.
- `rocket_clean_files` se llamaba tres veces por request con las mismas URLs.
- Un `Set-Cookie` añadido durante el render no cambiaba la cabecera del edge.

Decisiones que cambian los documentos (anotadas en `docs/02` y `docs/03`): lo no cacheable se marca con `Cloudflare-CDN-Cache-Control: no-store` (el `Cache-Control` del sitio y el bfcache no cambian); la plantilla del HTML nunca usa `override_origin` (Cloudflare quitaría el `Set-Cookie` y guardaría la respuesta); la purga del edge es por tag solo si la caché de origen reproduce `Cache-Tag`; WP Rocket se purga por archivos y dominio porque `rocket_clean_post` solo existe con licencia.

Calidad: 171 pruebas unitarias, PHPStan nivel 8 sin errores, PHPCS sin errores, TypeScript estricto, E2E del admin en Chromium con la pantalla Caché (activar con confirmación, cambiar de modo con confirmación, purgas, probar URLs, escritorio y móvil sin desbordes).

Pendiente para 2b: el módulo Cloudflare (token, plantillas, purga en el edge, snapshots), y con él el criterio de > 85 % de aciertos del edge. WP Rocket en Apache con licencia real queda sin probar en vivo (el bloque de `.htaccess` se genera y se prueba en unitarias).

## Estado de la fase 2b

Completada el 2026-10-13 (versión 0.4.0, esquema de base de datos v4). **Por decisión del usuario, todas las fases se construyen primero y las pruebas con APIs reales (Cloudflare, hub, Imagina Search) se hacen al final.** Hasta entonces, la 2b está validada contra `tests/e2e/mock-cloudflare.php`, un simulador que reproduce la forma documentada de la API (verificada contra la documentación oficial y el esquema OpenAPI de Cloudflare del 2026-10-13: ver `docs/03`). El sitio de pruebas sigue en OpenLiteSpeed + LSCache.

| Criterio | Resultado (simulador) |
|---|---|
| Token cifrado, nunca expuesto | En la base solo hay el cifrado y los últimos 4 caracteres. Si cambian las claves de `wp-config.php`, el token queda "reconectar" (prueba unitaria) |
| Token rechazado / válido | Un token falso da un mensaje claro y no se guarda nada. Con un token válido se encuentran la zona y el plan, y se sondean los permisos de lectura |
| Diff y aplicar | Plan con crear, modificar, eliminar, sin cambios, deriva y bloqueado. Por defecto solo vienen marcados los cambios seguros. Las reglas propias quedan antes de la del cliente, que sigue mandando |
| Validación previa | Cada operación pasa antes por `?dry_run=true`. Con la fase llena (10 reglas en Free) la creación queda bloqueada antes de llamar |
| Fallo a mitad | Con un error persistente en el cuarto cambio, se restaura el snapshot y la zona queda idéntica a la previa (comparación JSON) |
| Revertir un snapshot deja la zona exactamente como estaba | Sí, en comparación JSON sin contar los `id` que asigna Cloudflare. Restaurar un snapshot más nuevo recrea las reglas en su posición |
| Deriva | Una regla cambiada "a mano" en el simulador aparece como "Modificada a mano" y no se marca para aplicar. La tarea horaria la registra una vez |
| Purga en el edge | Por tags desde la cola del coordinador. La sexta purga del minuto en Free recibe el 429 (código 1134): lo pendiente vuelve a la cola y se reintenta a los 60 s que pidió la API |
| Analíticas | 24 h por estado de caché y rutas que más llegan al origen, renovadas cada hora |
| Admin | E2E en escritorio y móvil, sin errores ni desbordes: conectar (token falso y válido), aplicar con confirmación, restaurar con confirmación |

Hallazgos corregidos durante la validación:

- **Fase 2a:** las rutas de bypass de la matriz (páginas dinámicas de integraciones, como EDD o `/wc-api/v*`) llegaban a la config compilada, pero el emisor de cabeceras no las consultaba. Ahora esas páginas salen con `no-store` para el edge. Las rutas del núcleo (admin, login, REST…) ya las cubren los tipos de request.
- La API del admin no actualizaba el esquema de la base: si tras una actualización la primera visita era a la REST, el snapshot fallaba. Al fallar, no se aplicaba nada (lo correcto); ahora las rutas del admin aseguran el esquema.
- Cada lectura de una fase sin ruleset (404 esperado) quedaba en el registro como error.
- Con muchísimas cookies de bypass, la expresión de la regla del HTML no cabía en 4.096 caracteres. Recortar cookies sería inseguro: la regla queda bloqueada con el motivo.

Calidad: 184 pruebas unitarias, PHPStan nivel 8 y PHPCS sin errores, TypeScript estricto, E2E del admin con la pantalla Cloudflare.

**Pendiente para la prueba con una zona real** (al final del proyecto):

- Conectar un token real y aplicar las plantillas.
- Confirmar con `dry_run` los puntos que la documentación no deja claros: el campo `http.request.uri.args.names` en la reescritura de parámetros, el filtro `clientRequestHTTPHost` en GraphQL y el código de error de un token de cuenta en `/user/tokens/verify`.
- Medir el criterio de más del 85 % de aciertos del edge en tráfico anónimo.

## Estado de la fase 3

Completada el 2026-10-13 (versión 0.5.0). Probada en OpenLiteSpeed + LSCache 7.9.1 + WooCommerce 11.2.1 con los temas **Storefront** y **Astra** (`tests/e2e/woo-e2e.js`, Chromium, visitante anónimo, checkout de bloques con pago contra entrega).

| Criterio | Resultado |
|---|---|
| Contador correcto en < 300 ms tras cargar | 55–143 ms desde `DOMContentLoaded`, con una sola petición a la Store API |
| Sin carrito: ninguna petición | 0 peticiones a la Store API y 0 a fragments |
| Agregar y quitar sin recargar | Agregar desde la tienda (AJAX) y quitar desde el desplegable actualizan contador y total |
| Sin `get_refreshed_fragments` al navegar | 0. Solo una petición al pasar el cursor por el mini-carrito, para el desplegable con los productos |
| El checkout funciona | Pedido completado con el checkout de bloques |
| Páginas con carrito cacheables en el edge | El origen marca `no-store` en la respuesta generada para quien tiene carrito y cacheable en la anónima. Con WooCart, la regla de Cloudflare deja de excluir las cookies de carrito. **`cf-cache-status: HIT` con carrito queda pendiente de la zona real** |
| Productos vistos | La página de producto ya no envía `Set-Cookie`; el navegador escribe `woocommerce_recently_viewed` |

Hallazgos corregidos durante la validación (los tres son de privacidad):

- **LSCache cacheaba la Store API**: con sus valores por defecto, `GET /wc/store/v1/cart` quedaba guardado y se servía a cualquier visitante con el carrito (y la dirección) de otra persona. Ahora Store API y `wc-ajax` nunca se guardan en la caché de página. Corre siempre, incluso con el kill switch, y al actualizar se descartan las respuestas REST guardadas.
- **LSCache guardaba como pública la página de quien tiene carrito**: con temas que imprimen el mini-carrito en el servidor, otros visitantes verían sus productos. Ahora esa respuesta no se guarda en ningún nivel; leer de la caché sigue permitido.
- **Redirecciones antes del emisor**: una salida temprana en `template_redirect` (geolocalización de WooCommerce, `redirect_canonical`) quedaba sin cabecera del edge. Ahora lleva un `no-store` provisional.

Calidad: 190 pruebas unitarias, PHPStan nivel 8 y PHPCS sin errores, E2E del admin con la pantalla WooCommerce.

Pendiente para las pruebas reales:

- `cf-cache-status: HIT` con un producto en el carrito.
- Flatsome, Woodmart y el widget de carrito de Elementor Pro (selectores marcados como no verificados).
- Pasarelas reales de LATAM.

## Estado de la fase 4

Completada el 2026-10-13 (versión 0.6.0). Probada en OpenLiteSpeed + LSCache + WooCommerce 11.2.1 (tema Astra) y con el simulador de Cloudflare (`tests/e2e/mock-cloudflare.php`, que ahora devuelve eventos del firewall y valida los paréntesis fuera de las cadenas).

| Criterio | Resultado |
|---|---|
| La basura no carga plugins ni tema | Asset inexistente: 404 en 10 ms (antes 250 ms), con `X-ITB-Shield: static_miss` y `Cache-Control: public, max-age=600`. `?author=1` 404, XML-RPC 403, `/wp-json/wp/v2/users` y `?rest_route=/wp/v2/users` 401. Con sesión, `/wp/v2/users` sigue en 200 |
| 404 de imagen en < 5 ms de PHP | La guardia añade < 0,3 ms. Los 10 ms medidos son el arranque del core hasta los MU plugins en este contenedor (medido con un MU plugin previo al loader) |
| XML-RPC bloqueado no afecta a Jetpack | Con Jetpack activo, la config deja XML-RPC abierto (200); al desactivarlo vuelve a 403 |
| Ningún webhook bloqueado | Las reglas del edge excluyen `/wc-api/`, `?wc-api=` y las rutas de la matriz; las del `.htaccess`, `/wc-api/` y `?wc-api=` (comprobado con `../` en el query) |
| El desafío no afecta a la navegación | Las reglas entran en `managed_challenge` y pasan a `block` a los 7 días (plan probado adelantando la fecha). **El efecto sobre visitantes reales queda para la zona real** |
| Firewall en la zona | Crear, pasar a bloqueo y eliminar al apagar el módulo, con snapshot. Rate limit en Free: solo ruta, 10 s |
| `.htaccess` | Bloque al principio, autoprueba (portada, producto, REST), quitar deja el archivo idéntico. En OLS bloquea `.env`, `readme.html`, PHP en uploads, `../`, `<script>` y TRACE tras reiniciar el servidor |
| Ajustes rápidos | Seguros activos por defecto; el HTML sale sin emojis, RSD, generator, shortlink ni enlaces REST. Moderados e invasivos piden confirmación (428). Feeds → 301, jQuery Migrate fuera, oEmbed fuera, Heartbeat 60 s / 15 s en el editor |
| Migración de Perfmatters | Equivalencias y no equivalencias mostradas; importación con confirmación y registrada |

Hallazgo corregido: OpenLiteSpeed (con el `.htaccess` de WordPress) define `HTTP_AUTHORIZATION` vacío en todos los requests y el bloqueo de usuarios no se aplicaba.

Calidad: 203 pruebas unitarias, PHPStan nivel 8 y PHPCS sin errores, E2E del admin con las pantallas Ajustes rápidos y Firewall (escritorio y móvil), benchmark de la etapa temprana 0,08 ms.

Pendiente para las pruebas reales:

- Eventos reales del firewall (`firewallEventsAdaptive` en Free) y que lleven la descripción de la regla.
- `url_decode()` con `%2e%2e` y `+` en el query, y `dry_run` en las fases WAF y rate limit.
- Autoprueba del `.htaccess` en Apache (Plesk).
- Origen cerrado con tráfico directo real.

