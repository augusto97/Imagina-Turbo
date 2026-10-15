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

### Fase 5 — Carga condicional de plugins ✅ (ver "Estado de la fase 5" al final; WPML, Freemius real y el verificador externo quedan para las pruebas reales)
- `PluginFilter` con todas las protecciones (lecciones de Perfmatters MU).
- Experimentos con el verificador, promoción y vigilancia automática de reglas.
- Integración con Imagina Search.
- Resolver la decisión abierta sobre retirar o mantener el filtro de lectura.
- **Aceptación:** criterios del módulo 4; prueba de estrés con Freemius, WPML y un plugin que hace flush de rewrite rules.

### Fase 6 — Navegación instantánea y RUM ✅ (ver "Estado de la fase 6" al final; prerender, métricas de campo y mejora de LCP quedan para sitios reales)
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

## Estado de la fase 5

Completada el 2026-10-13 (versión 0.7.0). Probada en OpenLiteSpeed + LSCache + WooCommerce 11.2.1 con Imagina Search 6.0.2 (código del repositorio) y plugins de prueba que reproducen las trampas conocidas.

| Criterio | Resultado |
|---|---|
| Reducción de memoria y tiempo | `get_refreshed_fragments`: −41 % de memoria (15,0 → 8,9 MB), 108 → 84 ms. Imagina Search: −28 % (23,7 → 17,0 MB), 182 → 151 ms, quitando los 7 plugins ajenos de un sitio ligero |
| Cero desactivaciones permanentes (Freemius) | Un plugin que guarda `active_plugins` en `init` y `shutdown`: 31 requests filtrados sin perder ninguno. Contraprueba: sin la protección, un solo request los desactivaba |
| `rewrite_rules` intactas tras un flush | Flush forzado en `wp_loaded` durante un request filtrado: ruta del plugin oculto intacta. Contraprueba: sin la protección, se perdía |
| Las diferencias se detectan | Quitar el plugin que agrega un fragmento al carrito: el experimento falla en el primer par con la diferencia |
| Vigilancia | Un fatal en un request filtrado desactiva la regla en el siguiente request de admin, con evento |
| Experimentos | 20 pares iguales promueven; revisión diaria con 5 pares; el límite de búsquedas de Imagina Search no interfiere |

Decisión resuelta: los filtros de lectura y de escritura se mantienen todo el request (ver docs/02, módulo 4).

Calidad: 213 pruebas unitarias, PHPStan nivel 8 y PHPCS sin errores, E2E del admin con la pantalla Carga condicional (revisión de una regla activa, móvil sin desborde), benchmark de la etapa temprana 0,096 ms.

Pendiente para las pruebas reales:

- WPML y un plugin con Freemius real (prueba de estrés del documento).
- Sitios con Elementor, SEO y constructores, para medir el −40 % en condiciones reales.
- El verificador externo con Playwright (páginas completas). Mientras tanto, los experimentos son loopback y comparan respuestas, no efectos fuera de ellas.

## Estado de la fase 6

Completada el 2026-10-13 (versión 0.8.0). Probada en OpenLiteSpeed + LSCache + WooCommerce 11.2.1 (WordPress 7.1, API de Speculation Rules del core) con Chromium (`tests/e2e/nav-e2e.js`).

| Criterio | Resultado |
|---|---|
| > 30 % de navegaciones especuladas en Chrome móvil | Medible: el RUM distingue prerender (`activationStart`) y prefetch (`deliveryType`). En Chromium, hover + clic llegó como `navigational-prefetch`, y también la página predicha. **El porcentaje real sale de visitantes reales** |
| Cero prefetch a URLs de acción | El core excluye toda URL con query; se agregan carrito, checkout, cuenta, login y las rutas sin caché. El respaldo excluye query, extensiones, nofollow, descargas y esas rutas |
| El servidor no recibe más requests sin caché | Solo se especula lo cacheable. El predictivo exige contenido publicado y pasa las mismas exclusiones |
| bfcache | Atrás y adelante restaurados del bfcache y contados por el RUM; causas (`notRestoredReasons`) y páginas propias con no-store en la pantalla |
| Mejora de LCP p75 | La imagen LCP recibe `fetchpriority="high"`, sin lazy load, y precarga móvil con la URL medida (comprobado en el HTML). **La mejora se mide con RUM antes/después en un sitio con tráfico** |
| RUM sin datos personales | Solo contadores; visitantes distintos con un mapa de 64 bits de hash de IP con sal diaria. Endpoint local en 15 ms sin cargar plugins; otro origen → 403, basura → 400 |
| Scripts < 3 KB | `itb-rum.min.js` 2,0 KB, `itb-prefetch.min.js` 1,4 KB |

Hallazgos corregidos durante la validación:

- Un patrón de exclusión con comodín inicial producía un prefijo vacío en el respaldo, y eso habría excluido todos los enlaces en Safari y Firefox.
- El mismo destino desde móvil y escritorio pisaba el mapa de visitantes. Ahora se combina con `BIT_OR` en SQL.
- La tienda de WooCommerce es el archivo del tipo `product`, no una página: ahora cuenta como contenido publicado.

Calidad: 223 pruebas unitarias, PHPStan nivel 8 y PHPCS sin errores, E2E del admin con la pantalla Navegación (confirmación del prerender, móvil sin desborde), benchmark de la etapa temprana 0,05 ms.

Pendiente para las pruebas reales:

- Prerender en un navegador real (Chromium headless no lo dispara).
- Porcentaje de navegaciones especuladas y mejora de LCP con visitantes reales.
- Beacons al hub real.


## Estado de la fase 7

Completada el 2026-10-13 (versión 0.9.0). Probada en OpenLiteSpeed + LSCache 7.9.1 (con caché de usuarios con sesión) + WooCommerce 11.2.1 + Contact Form 7 6.2.2.

| Criterio | Resultado |
|---|---|
| `AssetManager` con sugerencias y verificación | Inventario por loopback firmado; sugerencias de formularios, sliders y WooCommerce según el uso en el contenido, Elementor y widgets. Cada regla nace en prueba, se ve con la previsualización solo del admin y se activa con confirmación (queda quién y cuándo). Con CF7: portada en caché sin sus assets, contacto con su formulario |
| Sin tocar el HTML | Solo `wp_dequeue_*` en hooks de WordPress; una dependencia que otro asset necesita sigue cargándose |
| `Database` con auditoría, acciones y respaldos | Auditoría siempre disponible; cuatro acciones con respaldo por lote y evento; restaurar devolvió el estado exacto en las cuatro |
| Exclusiones de delay JS desde la matriz | `rocket_delay_js_exclusions` (126 patrones en el sitio) y `litespeed_optm_js_defer_exc` (107 literales), verificados en el código de WP Rocket 3.23.5.1 y LSCache 7.9.1 |

Hallazgos corregidos durante la validación:

- Con "Cache Logged-in Users" de LSCache, el admin veía su copia en caché de antes de la previsualización: ahora activar o quitar la previsualización purga su caché privada.
- La atribución de pedidos de WooCommerce aparecía como sugerencia "solo en la tienda", pero registra la página de entrada del visitante: queda fuera de las sugerencias.
- `wp_user_roles` se atribuía a un plugin desconocido; ahora es núcleo.

Calidad: 233 pruebas unitarias, PHPStan nivel 8 y PHPCS sin errores, E2E del admin con las pantallas Assets y Base de datos (móvil sin desborde), benchmark de la etapa temprana 0,06 ms.

Pendiente para las pruebas reales:

- El verificador externo con Playwright para comprobar cada regla de assets en páginas completas (hoy la verificación es la previsualización del admin).
- Sitios con Elementor y formularios en plantillas del tema, para afinar la detección de uso.
- Bases grandes (millones de filas en `wp_postmeta` y Action Scheduler) para medir las tandas.

## Estado de la fase 8

Completada el 2026-10-13 (versión 0.10.0), del lado del plugin. El hub es un servicio aparte: se probó con el hub simulado (`tests/e2e/mock-hub.php`), que implementa la plantilla firmada y el envío por lotes, y con el simulador de Cloudflare.

| Criterio | Resultado |
|---|---|
| Plantillas de configuración estándar desde el hub | Plantilla firmada en los dos sentidos; lista blanca de ajustes; nada invasivo; lo ignorado se informa. Una firma inválida conserva la anterior |
| Detección de desviaciones | Ajustes, módulos, ajustes rápidos y Cloudflare (falta, distinta, cambiada a mano). En la pantalla Flota y en la telemetría |
| Aplicación por lotes de reglas de Cloudflare a varias zonas | El hub llama al endpoint firmado de cada sitio; cada uno aplica en su zona con su token, snapshot y evento. Probado: estado (dry run), rechazo sin autorización (403), aplicación de 4 ajustes y 4 cambios de Cloudflare, versión desfasada (409), repetición del mismo nonce (401) y una regla cambiada a mano en el panel que se deja sin pisar |
| Reversible | Snapshot de ajustes por aplicación (revertir probado) y snapshot de la zona en Cloudflare |

Hallazgos corregidos durante la validación:

- Con el módulo Cloudflare apagado y el token conectado, la plantilla habría tocado la zona: ahora Cloudflare solo se comprueba y aplica con el módulo activo (o si la misma plantilla lo enciende).
- El sitio y el hub se llaman mutuamente durante un lote (el sitio descarga la plantilla mientras el hub espera): el hub simulado necesita `PHP_CLI_SERVER_WORKERS`.

Calidad: 238 pruebas unitarias, PHPStan nivel 8 y PHPCS sin errores, E2E del admin con la pantalla Flota (móvil sin desborde).

Pendiente para las pruebas reales:

- El hub real (gestión de plantillas, grupos de sitios, orquestación de lotes con pausa ante errores).
- Lotes contra zonas reales de Cloudflare con tokens de prueba.

## Versión 0.10.1 — antes de las pruebas en producción

Revisión de la regla 4 (todo cambio es reversible) antes de instalar en un sitio real:

- **Cloudflare**: botón "Quitar todo lo de Imagina Turbo" (reglas propias fuera, ajustes devueltos a su valor original, con snapshot) y aviso en la pantalla de plugins y al desconectar el token si quedan cosas en la zona. Probado con el simulador: aplicar 7 reglas y 5 ajustes con una regla del cliente en la misma fase → quitar todo (la del cliente intacta, ajustes como al principio) → deshacer con el snapshot → quitar todo otra vez. El E2E compara la zona final con la inicial.
- **`.htaccess`**: el bloque del firewall se quita al desactivar el plugin (en OpenLiteSpeed hace falta reiniciar para aplicarlo).
- **Recalentado**: se pausa un minuto si una página tarda más de 5 s. En el servidor de pruebas (10 procesos PHP) hubo una saturación mientras recalentaba tras purgar todo; la carga de CPU no la detectaba.

Lo que sigue sin ser reversible está en la pantalla correspondiente: purgas de caché (se rehace sola), "Limitar revisiones" (WordPress borra las sobrantes al guardar, sin respaldo) y respaldos de base de datos de más de 30 días.

## Versión 0.10.2

Detección de Cloudflare Access (Zero Trust) en las peticiones del sitio a sí mismo: el error dice que Access bloqueó la ruta y cómo permitirla. Probado simulando Access delante de `/contacto/` y `admin-ajax.php`: el inventario de assets, la inspección de caché y un experimento de carga condicional devolvieron el mensaje; la portada siguió funcionando.

## Versión 0.10.3 — primera instalación fuera del entorno de pruebas

La primera instalación en un WordPress ajeno (TasteWP) sacó a la luz:

- **Pantalla Caché en blanco en un sitio nuevo**: sin purgas previas, el estado llegaba como `[]` (arreglo PHP vacío) y en JavaScript `[].at` es una función, que se tomaba por la fecha. Corregido en la API (objeto) y en `formatDate` (solo texto o número). El sitio de pruebas siempre había tenido purgas, por eso no se vio.
- **Un error en una pantalla dejaba todo el admin en blanco**: ahora cada pantalla tiene una barrera de errores que muestra el mensaje y deja el resto del plugin funcionando.
- **El ZIP llevaba el repositorio `.git`, las pruebas y la documentación de Action Scheduler** (5,8 MB, superaba el límite de subida de 2 MB de muchos hostings, y un `.git` dentro de un plugin hace saltar los escáneres de seguridad). Ahora pesa 1 MB y el empaquetado falla si queda un `.git`.
- **Nueva prueba `tests/e2e/screens-e2e.js`**: abre cada pantalla en una carga nueva y falla ante errores de JavaScript, 5xx o pantallas vacías. Se corre en el sitio de pruebas y en un WordPress limpio (sin plugin de caché ni WooCommerce).
- **TasteWP suspendió el sitio** (por su escáner, sin motivo concreto). No se reprodujo la pantalla en blanco al activar: en un WordPress limpio, con y sin permisos de escritura en `wp-content`, el plugin se activa y el Panel explica lo que falta. Los sandboxes con escáneres agresivos no sirven para probar plugins que escriben su loader en `mu-plugins` y su configuración compilada.

## Versión 0.10.4 — lo aprendido en el primer sitio de producción

Revisión de un WooCommerce real (WoodMart + Elementor, OpenLiteSpeed con LiteSpeed Cache, ServerAvatar):

- **Scripts que no se difieren y sus dependencias.** Con "Load JS Deferred" de LSCache, la página de producto daba `Cannot read properties of undefined (reading 'use')`. Las exclusiones de la matriz (heredadas de WP Rocket, donde el delay mueve todo junto) dejaban `api-fetch`, `i18n` y `hooks` sin diferir, mientras `url`, `private-apis` y `wp-polyfill`, de los que dependen, sí se diferían: el excluido corría antes que sus dependencias. Ahora, al imprimir cada script (`script_loader_tag`), las dependencias de todo script excluido (por su src o por su código en línea, con las exclusiones de Turbo y las escritas a mano en LSCache) se marcan con `data-no-defer` (LSCache, verificado en `src/optimize.cls.php` 7.9.1) y `nowprocket` (WP Rocket). Reproducido en la instancia de pruebas: sin el arreglo, los mismos dos errores; con él, ninguno y `api-fetch` funcional.
- **Alertas nuevas en el Panel** (`Core\EnvironmentChecks`, solo admin):
  - OPcache lleno o con pocos aciertos. En el sitio, 128 MB llenos y 44 % de aciertos: WordPress tardaba 1,5 s en arrancar; con 512 MB a nivel de servidor (en ServerAvatar la opción de la aplicación no aplica a OPcache), 0,35 s y un tercio de la memoria.
  - EWWW Image Optimizer genera WebP sin método de entrega (10 MB de PNG por página; con la reescritura por JS, 1,4–2,5 MB) o los entrega por `.htaccess` detrás de Cloudflare.
  - LiteSpeed Cache guarda la página de acceso: la copia no envía `wordpress_test_cookie` y el primer intento de acceso falla con "las cookies están bloqueadas".

## Versión 0.10.5

- **Auditoría de cacheabilidad sin falsas alarmas.** Si una página envía cookies a un visitante anónimo, se pide otra vez: cuando la segunda llega sin cookies (la sirvió la caché de página, que guarda la copia sin `Set-Cookie`), la cookie solo la recibe quien regenera la página y queda como nota ("Cacheable"), no como problema. Una página marcada `no-cache` a propósito por el plugin de caché (resultados de búsqueda, formularios) también es una nota. Cookies conocidas nuevas: `_fbp`/`_fbc` (Meta), `se-recently-viewed` (Searchanise), WoodMart, YITH Request a Quote, sourcebuster, PixelYourSite, Complianz, CookieYes. En el sitio real eran los 6 avisos de la auditoría y ninguno impedía la caché.
- **Gestor de assets**: una regla "nunca aquí" sin lugares no hacía nada y ahora se rechaza (igual que "solo aquí" sin lugares). Las sugerencias de WooCommerce reconocen los widgets de productos de Elementor y WoodMart: antes, una portada de WoodMart con carruseles de productos parecía no usar la tienda y se sugería quitar `wc-add-to-cart` de ella.
- Reglas aplicadas en el sitio real con previsualización, prueba funcional (carrito, cotización de YITH con variación, formulario de Contact Form 7, checkout) y verificación como visitante por el `id` de cada script (LiteSpeed renombra las URLs al minificar): YITH Request a Quote y jQuery UI solo en productos y en la lista de cotización, Contact Form 7 solo en su página, fuentes Roboto de Elementor fuera (no se usaban). Unas 30 peticiones menos por página.

## Versión 0.10.6

- **La pantalla Base de datos no cargaba en el sitio real**: el servidor respondía 403 a cualquier URL con `/database/` (reglas tipo 7G/8G de firewalls de servidor y de plugins), antes de llegar a WordPress. Las rutas pasan a `/db/status`, `/db/audit`, `/db/actions/{acción}`, `/db/restore/{lote}` y `/db/settings`. Se probaron las 73 rutas de la API contra ese firewall: era la única afectada.

## Versión 0.10.7

- **"Delayed" de LiteSpeed sin romper WooCommerce.** LSCache no retrasa ninguna etiqueta que contenga "defer" (`_js_defer` en `src/optimize.cls.php` 7.9.1). Los scripts que WordPress imprime con `defer` nativo (estrategia de WP 6.3+, como `add-to-cart-variation` de WooCommerce) corrían al cargar la página, con sus dependencias sin `defer` (`wp-util`, `underscore`) retrasadas hasta la interacción: "wp.template is not a function" al elegir una variación en el sitio real. Con el modo Delayed activo, esos scripts cuentan como excluidos y sus dependencias se marcan para no retrasarse. Reproducido en la instancia de pruebas con un producto variable: sin el arreglo, "wp is not defined"; con él, sin errores.
- **Los scripts propios no se difieren ni se retrasan** (`/imagina-turbo/assets/js/`): con Delayed, el contador del carrito se quedaba en 0 hasta que el visitante movía el ratón.
- **Carga condicional en el sitio real**: `admin-ajax: woodmart_load_html_dropdowns` (el menú desplegable de WoodMart, una vez por visitante nuevo) sin 27 plugins ajenos: 632 → 278 ms y 63 → 49 MB, 20 pares idénticos. El primer intento falló porque Meta for WooCommerce pone `_fbp` en esa respuesta: se dejó cargado.

## Versión 0.10.8

Segunda prueba de "Delayed" en el sitio real (con la 0.10.7), revertida al encontrar dos fallos:

- **Los datos de los scripts marcados se retrasaban.** `wp_localize_script` imprime `var itbCart = {...}` (id `{handle}-js-extra`) fuera de `script_loader_tag`: el script del carrito corría sin su configuración y el contador quedaba en 0. Ahora `wp_inline_script_attributes` marca también los bloques en línea (`-js-extra`, `-js-before`, `-js-after`, `-js-translations`) de los scripts que no se retrasan. Verificado en la instancia de pruebas: `itbCart` y `wc_add_to_cart_variation_params` existen sin interacción.
- **LSCache no repite el primer clic.** Carga el JS retrasado con la primera interacción (`assets/js/js_delay.js`), pero el clic que la dispara se pierde: "Añadir al presupuesto" (`href="#"`) no hacía nada si era lo primero que tocaba el visitante. Con Delayed activo, un script en línea (menos de 1 KB, no se retrasa) guarda ese primer clic en un control que depende de JS (botón, campo, enlace sin destino) y lo repite al recibir `DOMContentLiteSpeedLoaded`. Los enlaces normales navegan como siempre. Probado en la instancia de pruebas con un botón cuyo comportamiento agrega un script retrasado: sin la repetición, el clic se perdía en escritorio y en móvil; con ella, llega una vez.
- La comprobación de LSCache se hace al imprimir, no al registrar: LSCache carga después de Imagina Turbo.

## Versión 0.10.9

Tercera prueba de "Delayed" en el sitio real (con la 0.10.8), revertida: el contador del carrito seguía en 0. Las etiquetas estaban bien marcadas (`itbCart` y el script del carrito sin retrasar), pero LSCache **minifica** también los scripts que no retrasa, y en modo Delayed su filtro `litespeed_optm_cssjs` cambia `DOMContentLoaded` por `DOMContentLiteSpeedLoaded` en todo el JS minificado (`src/optimize.cls.php`, 7.9.1). El script del carrito, cargado al leer la página, esperaba un evento que solo llega tras la primera interacción.

- Con Delayed activo, los scripts marcados (y sus bloques en línea) llevan además `data-no-optimize="1"`: LSCache los deja intactos (ni minifica, ni combina, ni retrasa). En los demás modos se siguen minificando.
- `itb-cart.js` ya no contiene el nombre literal del evento, por si otro optimizador lo reescribe.
- La barra lateral del admin muestra la versión instalada en lugar de un rótulo de desarrollo ("Fase 8 · Flota…").

## Versión 0.11.0

Lo que faltaba de la lista de mejoras, medido en la tienda real (WooCommerce + WoodMart + Elementor, LiteSpeed Cache, EWWW, Cloudflare).

- **"Delayed" de LiteSpeed, descartado en este sitio.** Con la 0.10.9 el contador del carrito ya funcionaba, pero tras la primera interacción LiteSpeed carga 56 scripts uno detrás de otro (7 s sin respuesta) y los plugins que llegan tarde (YITH) no ven lo que el visitante ya hizo: el botón de presupuesto quedaba desactivado aunque la variación estaba elegida. Sin Delayed funciona en escritorio y móvil.
- **Imagen principal sin esperar a JavaScript.** La imagen LCP de producto y de categoría salía con `src="data:..."` (lazy load de EWWW) y, aunque no fuera perezosa, la "reescritura de WebP por JS" de EWWW también la cambiaba por un marcador; EWWW además ignora `fetchpriority="high"` si la imagen tiene `srcset`. LCP en móvil con 4G lento: 8,7 s en producto, 11 s en categoría. Ahora la imagen principal (la del RUM; sin datos, la de la ficha de producto y la primera de los dos primeros productos de un listado) lleva `data-skip-lazy` / `data-no-lazy`, queda fuera de la reescritura por JS y apunta a la copia `.webp` que ya existe. No vale "la primera imagen": el megamenú de WoodMart imprime ~25 imágenes de producto antes del contenido.
- **Dos lazy load a la vez.** EWWW no difería las tres primeras imágenes (logo, cabecera) y LiteSpeed las difería igual: el logo era el LCP de una categoría. Con solo el de EWWW, 4,7 → 3,2 s. Nueva revisión del entorno `double_lazy`.
- **Peticiones de los visitantes después de cargar la página** (Diagnóstico): AJAX, REST y Store API de visitantes sin sesión, con frecuencia, CPU por día y proporción por página vista (RUM). Columna `anon` en las muestras (esquema 9).
- **Renderizado diferido** (`content-visibility`, moderado, por reglas): en una categoría con 56 productos, el re-maquetado tras una interacción bajó de 267 a 213 ms y el TBT ~90 ms (móvil, CPU 4x). Sugerencias por tema.
- **Base de datos:** retención de Action Scheduler (filtro `action_scheduler_retention_period`), dueño de cada tabla grande (48 MB de tablas de Wordfence y Simple History desinstalados en el sitio real; solo se informa) y plugins incompatibles con HPOS.
- **Speed Brain de Cloudflare.** Con Speed Brain activo y el HTML fuera de la caché del edge, Cloudflare responde 503 (`cf-speculation-refused: prefetch refused: not eligible`) a toda petición con `Sec-Purpose: prefetch`, también a las de Imagina Turbo: 0 de 45 navegaciones del RUM salieron precargadas. El prerender sí pasa. Se detecta con una petición a la propia portada y se avisa con las salidas (desactivar Speed Brain, caché de HTML en el edge o prerender). Además el escudo rechaza con 503 las precargas de URL con efectos (agregar al carrito, cerrar sesión, nonces, carrito, pago, cuenta), vengan de donde vengan.
- Probado sin cambios visibles en el sitio: "Optimized Markup" de Elementor quitaba 0–57 elementos de ~4.000 y movía 92 px una categoría; se dejó apagado.

## Versión 0.12.0

Lo que quedaba del informe de investigación (lo que hacen WP Rocket, FlyingPress, LiteSpeed, Object Cache Pro, Kinsta y WooCommerce), todo por hooks y sin costo en las páginas en caché.

- **Precarga y Early Hints de la imagen principal.** En la ficha de producto (tamaño `woocommerce_gallery_image_size`) y en los listados (el primer producto de la consulta principal, tamaño `single_product_archive_thumbnail_size`): `<link rel="preload" imagesrcset imagesizes fetchpriority="high">` en el `<head>` y la misma precarga como cabecera `Link`, que Cloudflare guarda y envía como 103 Early Hints. Mismo tamaño que pide WooCommerce, así que el navegador elige el mismo archivo: verificado en móvil y escritorio, una sola descarga. La cabecera no admite comillas ni saltos de línea.
- **Ajustes de la plataforma** (Diagnóstico): Elementor (íconos SVG, caché de elementos, CSS externo, Font Awesome 4, `font-display`, fondos diferidos, Google Fonts locales, markup optimizado), WooCommerce (caché de pedidos HPOS, tabla de búsqueda de atributos y su regeneración), caché de objetos persistente (aviso si PHP tiene Redis y WordPress no lo usa) e índices (Index WP MySQL For Speed con más de 100.000 filas en postmeta). Opciones verificadas en el código de Elementor 4.3.5 y WooCommerce 11.2. Solo recomienda.
- **Trabajadores PHP ocupados** (Diagnóstico): media y hora pico de procesos PHP trabajando a la vez (ley de Little sobre el muestreo aleatorio), qué rutas ocupan el tiempo y cuántas pasan de 5 s. Con LiteSpeed se compara con `LSAPI_CHILDREN`.
- No se construye: Zaraz (requiere el token de Cloudflare y verificar su API) ni caché por tipo de dispositivo en el edge (módulo Cloudflare, con token).

## Versión 0.13.0

- **Caché por tipo de dispositivo en Cloudflare.** Si el plugin de caché guarda una copia para móviles (LiteSpeed Cache «Caché para móviles», `litespeed.conf.cache-mobile`; WP Rocket `cache_mobile` + `do_caching_mobile_files`), la regla de caché del HTML agrega `cache_key.cache_by_device_type` (todos los planes; Cloudflare envía `CF-Device-Type` al origen). Sin esto, el primer visitante decidía qué versión recibían todos. Verificado en la documentación de Cloudflare (repositorio `cloudflare-docs`, `cache/how-to/cache-rules/settings.mdx`).
- **Speed Brain** entra en los ajustes de zona que gestiona el módulo Cloudflare (`PATCH /zones/{id}/settings/speed_brain`, `value` on/off, verificado en `speed/optimization/content/speed-brain.mdx`): recomendado apagado mientras el HTML no esté en la caché del edge, encendido cuando sí.
- **WoodMart** en Ajustes de la plataforma: Google Fonts locales, `font-display`, precarga de la fuente de íconos, doble lazy load con EWWW y doble precarga de la imagen LCP. Claves de `xts-woodmart-options` leídas de la pantalla de ajustes de WoodMart 8.5.4 en un sitio real.
- **Consultas de la base de datos** en el request de diagnóstico de un administrador: la etapa temprana activa `SAVEQUERIES` solo con la cookie de diagnóstico válida y el filtro `log_query_custom_data` anota qué plugin o tema lanzó cada consulta. Diagnóstico muestra el total, las lentas (≥ 50 ms), las repetidas (el mismo patrón 3 o más veces: suele ser un bucle) y el tiempo por componente. Los valores literales se reemplazan por `?`: no se guarda ningún dato. En la instancia de pruebas detectó 33 lecturas de opciones una por una.
- No se construye la migración automática a Zaraz: mover Google Analytics y el píxel de Meta a Cloudflare exige configurar cada herramienta, el consentimiento y la atribución en Zaraz y quitar los scripts del sitio a mano; hacerlo por fe rompería la medición. Queda como recomendación cuando haya token.
