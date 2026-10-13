# 02 — Módulos

Formato de cada módulo: **riesgo**, **por defecto**, **qué hace**, **cómo implementarlo**, **criterios de aceptación**. Los hooks marcados con *(verificar)* deben confirmarse contra la documentación vigente antes de usarse.

---

## 1. Perfilador (`Profiler`)

- **Riesgo:** seguro. **Por defecto:** activo (muestreo 1 %).
- **Qué hace:** mide cuánto cuesta cada plugin y cada fase del request, y lo muestra en el admin y en `Server-Timing`.
- **Cómo:**
  - En el loader, guardar `hrtime(true)` y `memory_get_usage()` iniciales.
  - Engancharse a la acción `plugin_loaded` (se dispara una vez por plugin, desde WP 5.1) para registrar tiempo y memoria incremental de cada plugin.
  - Marcas en `muplugins_loaded`, `plugins_loaded`, `after_setup_theme`, `init`, `wp_loaded`, `template_redirect`, `shutdown`.
  - Consultas: `$wpdb->num_queries` (nunca activar `SAVEQUERIES` en producción).
  - Memoria pico: `memory_get_peak_usage()`.
  - `Server-Timing` solo para admins con la cookie de diagnóstico (`itb_diag`, firmada como la de bypass pero con clave derivada distinta, HttpOnly, hasta 8 h). *(Se descartó `?itb_debug=1` con nonce: un parámetro en la URL rompe la caché de página y el nonce caduca.)* La cabecera solo puede llevar lo ocurrido hasta `send_headers`; el desglose completo queda en la pantalla Diagnóstico. Descripciones en ASCII (las cabeceras son Latin-1).
  - Muestras al 1 %: se guardan en `route_samples` (tipo de request, plantilla, ms, MB, consultas, top 15 componentes por costo, fases, cookies enviadas y `Cache-Control`) y un job horario las agrega en `metrics_hourly`. Las muestras de la cookie de diagnóstico no entran en los informes ni en los agregados (las de un admin sesgan).
  - **Muestreo intensivo:** desde el admin, todos los requests durante 15 minutos (sitios con poco tráfico o antes de comparar). Se apaga solo.
- **Qué es "costo por plugin" (implementado en la fase 1):**
  - **Carga:** tiempo y memoria entre eventos `plugin_loaded` / `mu_plugin_loaded`.
  - **Hooks clave:** tiempo *exclusivo* (sin contar envoltorios anidados) de los callbacks de cada plugin en `plugins_loaded`, `after_setup_theme`, `init`, `wp_loaded`, `rest_api_init`, `admin_init`, `wp`, `template_redirect`, `wp_enqueue_scripts`, `wp_head`, `wp_footer` y, según el request, `wp_ajax_{acción}` / `wc_ajax_{acción}`. Los callbacks se envuelven solo mientras corre su hook (mismas claves en `WP_Hook::callbacks`, así que `remove_action` y `has_action` siguen funcionando) y se restauran al terminar.
  - **Render de bloques dinámicos:** el `render_callback` de los bloques de plugins y temas se envuelve en `register_block_type_args` (los de `block.json` se atribuyen por su `render.php`). En una tienda con bloques es una parte grande del costo de WooCommerce y no pasa por ningún hook.
  - **Núcleo:** sus callbacks no se envuelven (el ranking es por plugin y duplicaban el costo de medir). Las funciones y clases internas de PHP cuentan como núcleo.
  - **Atribución:** por el archivo donde está definida la función o la clase. El resultado se guarda en `profiler-symbols.php` (OPcache), atado al hash de la config y con caducidad de 24 h: sin esa caché, resolver ~400 callbacks repartidos en ~170 archivos era la mayor parte del costo de una muestra.
  - **Fuera del alcance:** callbacks de buffers de salida (corren después de las funciones de `shutdown`, igual que para Query Monitor), filtros de contenido de alta frecuencia (`the_content`, `render_block`) y trabajo que un plugin dispara en hooks no listados. Por eso la cifra es un mínimo; el A/B de la fase 1 mostró que cubre ~90 % del costo real de WooCommerce.
- **Aceptación:** overhead < 0,2 ms sin muestreo y < 1 ms en request muestreado; ranking de plugins por costo visible por tipo de request; datos consistentes con Query Monitor en un sitio de prueba (±15 %). Resultados en `docs/05`, "Estado de la fase 1".

---

## 2. Coordinador de caché (`CacheCoordinator`)

- **Riesgo:** moderado. **Por defecto:** inactivo (regla 3: solo lo seguro viene activo). Al conectar Cloudflare, el asistente propone activarlo; primero en modo "solo sincronizar purgas" y, tras verificación, con emisión de cabeceras para el edge.
- **Requisito previo (fase 2):** hasta que exista el refresco de nonces (`itb-nonce.js`), el edge TTL máximo es 10 h, porque los nonces de WordPress viven 12–24 h y los formularios/AJAX con nonce fallarían en páginas cacheadas más tiempo. Por eso el refresco de nonces se adelanta a la fase 2. *(Hecho en la fase 2a: sin refresco, el TTL se recorta a 10 h automáticamente.)*
- **Cabeceras en caché de origen** *(verificado en la fase 2a)*:
  - **LSCache en OpenLiteSpeed:** guarda y reproduce las cabeceras propias en los aciertos (probado en OLS 1.9.3 + LSCache 7.9.1). Se usan `Cloudflare-CDN-Cache-Control` y `Cache-Tag` tal cual.
  - **WP Rocket:** en Apache sirve el archivo de caché con `mod_rewrite` y PHP no corre; la única vía es su filtro `rocket_htaccess_files_match`, que añade un `Header set Cloudflare-CDN-Cache-Control` fijo a sus archivos `index*.html(_gzip)`. No puede llevar `Cache-Tag` (es por página). En nginx o vía `advanced-cache.php` no hay forma de añadir cabeceras: el HTML **no** se deja en el edge (la config compila `edge_html = false`).
  - **Plan B descartado:** `override_origin` en la regla del edge. Cloudflare, con TTL forzado, **quita el `Set-Cookie` y guarda la respuesta**, lo que viola la regla 9 (nunca cachear respuestas con `Set-Cookie`). La plantilla del HTML siempre respeta el origen.
  - Consecuencia: la purga en el edge es **por tag solo si el adaptador reproduce las cabeceras en los aciertos** (LSCache); con WP Rocket en Apache, por URL.
- **Qué hace:** decide qué es cacheable, etiqueta las páginas, emite cabeceras para el edge y sincroniza purgas entre el plugin de caché y Cloudflare.
- **Cabeceras para páginas cacheables** (anónimo, GET, sin `Set-Cookie`, no excluido):
  - `Cloudflare-CDN-Cache-Control: max-age=<edge_ttl>, stale-while-revalidate=<swr>, stale-if-error=<sie>` — controla solo el edge de Cloudflare, sin interferir con el `Cache-Control` que manda WordPress o LSCache al navegador. *(verificar soporte y precedencia en docs de Cloudflare)*
  - `Cache-Tag: <prefijo>-all,<prefijo>-p-123,<prefijo>-t-45,<prefijo>-home` — Cloudflare la elimina antes de enviarla al visitante. *(verificar disponibilidad de purga por tag en el plan)*
  - Valores por defecto: edge TTL 1 día (blogs/corporativos 7 días), SWR 1 día, SIE 3 días.
- **Páginas no cacheables:** asegurar `Cache-Control: private, no-store` (carrito, checkout, cuenta, logueados, respuestas con `Set-Cookie`, previews, búsqueda si Imagina Search la marca dinámica).
- **Auditoría de `Set-Cookie`:** en páginas que deberían ser cacheables, registrar qué código envía cookies (por ejemplo, `woocommerce_recently_viewed` de `wc_track_product_view`). Mostrar en el admin y ofrecer neutralizarlo (ver WooCart).
- **Tags (`TagBuilder`):** `all`, `home`, `p-{id}` (post/página/producto), `t-{term_id}`, `a-{author}`, `pt-{post_type}` (archivos), `shop`, `feed`, `search`. Prefijo corto por sitio (de la config) para zonas compartidas.
- **Mapa de purga (`PurgeMap`):**

| Evento | Qué se purga |
|---|---|
| Guardar/publicar/despublicar post | `p-{id}`, sus términos, `pt-{tipo}`, `home`, `feed` |
| Producto: stock, precio, venta programada | `p-{id}`, padre si es variación, términos, `shop` |
| `woocommerce_save_product_variation` | producto padre |
| Comentario aprobado | `p-{id}` |
| Editar término | `t-{id}` |
| Menús, widgets, Customizer, cambio de tema, activar/desactivar plugin | `all` |
| Elementor: `elementor/core/files/clear_cache`, `update_option__elementor_global_css` | `all` |
| Cambios en páginas de Woo (IDs de carrito/checkout/cuenta) | `all` + recompilar config |

- **Cola (`PurgeQueue`):** Action Scheduler, ventana de agrupación de 30 s, deduplicar, lotes según el límite de la API. Purga por tag si el plan lo permite; si no, por URL (resolver URLs de cada tag).
- **Orden de purga:** primero la caché de origen (LSCache/WP Rocket), después Cloudflare, después el recalentado. Al revés, el edge vuelve a llenarse con la copia vieja del origen.
- **Sin bucles:** las purgas que nosotros enviamos al plugin de caché se marcan (bandera en memoria durante el request) para que el escucha de purgas del adaptador no las vuelva a encolar hacia Cloudflare.
- **Adaptadores:**
  - `LiteSpeedAdapter`: enviar tags propios a LSCache y purgar con sus acciones públicas (`litespeed_tag_add`, `litespeed_purge`, `litespeed_purge_url`, `litespeed_purge_all`; verificado en 7.9.1). Escuchar sus purgas con el filtro `litespeed_purge_tags` (tags con el id de blog delante; `*` = todo) y traducirlas a tags propios para replicarlas en Cloudflare.
  - `WpRocketAdapter`: purgar con `rocket_clean_files` y `rocket_clean_domain`; escuchar `after_rocket_clean_post`, `after_rocket_clean_files`, `after_rocket_clean_home` y `rocket_after_clean_domain` (verificado en el código de la 3.23). Alimentar `rocket_delay_js_exclusions` con la matriz.
  - `NullAdapter`: solo edge.
- **Recalentado (`Warmer`):** fuentes en orden: URLs más visitadas (RUM/muestras), sitemap del plugin SEO (ver matriz), páginas purgadas. Concurrencia 1–2, pausa si `sys_getloadavg()[0]` > núcleos × 0,7, user agent `ImaginaTurbo-Warmer/1.0`, respetar exclusiones de precarga de la matriz y trampas de crawler.
- **Aceptación:** después de editar un producto, su URL, su categoría y la tienda se sirven actualizadas en < 60 s y vuelven a estar en caché del edge tras el recalentado; ninguna respuesta con `Set-Cookie` lleva cabeceras de caché del edge.

### Implementación (fase 2a)

- **Dos modos.** `sync` (al activar el módulo): purgas sincronizadas con el plugin de caché, tags propios en LSCache, recalentado y refresco de nonces; ninguna cabecera para el edge. `headers`: además emite `Cloudflare-CDN-Cache-Control` y `Cache-Tag`. Pasar a `headers` exige confirmación explícita (REST responde 428 sin ella) y queda en el registro.
- **Lo no cacheable sale con `Cloudflare-CDN-Cache-Control: no-store`**, no con `Cache-Control: private, no-store`. Esa cabecera solo la lee el edge y Cloudflare la quita antes de responder: el `Cache-Control` del sitio no cambia y el navegador conserva el bfcache. Carrito, checkout, cuenta y login siempre llevan `no-store` para el edge (defensa adicional a la regla de Cloudflare).
- **Qué decide la cacheabilidad** (`Cacheability`): tipo (página, feed, sitemap), GET/HEAD, anónimo, sin cookies de bypass, carrito o variación, sin vista previa, búsqueda, contraseña ni `DONOTCACHEPAGE`, fuera de las rutas de bypass de las integraciones de la matriz (corregido en la fase 2b), sin `Set-Cookie` ni `Cache-Control` privado en las cabeceras ya enviadas, estado 200. Un 404 llevaría 10 min como máximo, pero WordPress ya lo envía con `nocache_headers()`, así que en la práctica no se guarda. Se decide en `template_redirect` y se revisa en `shutdown`: si durante el render algo definió `DONOTCACHEPAGE` o añadió un `Set-Cookie` o un `Cache-Control` privado, y las cabeceras aún no salieron (LSCache retiene la salida en su buffer), la cabecera pasa a `no-store` y el plugin de caché recibe `no-cache`. Si ya salieron, Cloudflare tampoco guarda una respuesta con `Set-Cookie`, porque la regla respeta al origen.
- **Purga en dos tiempos** (`Purger`): el origen se purga en el mismo request; el edge y el recalentado se encolan en `itb_purge_queue` y los vacía `PurgeWorker` a los 30 s (Action Scheduler, WP-Cron si falta). Dentro de un request no se repite lo ya purgado, y el aviso de vuelta del plugin de caché no se vuelve a encolar.
- **LSCache difiere la purga en cron y CLI** (la guarda en `litespeed.purge.queue`) y la envía en el siguiente request PHP. El worker hace un GET a `admin-ajax.php` antes de recalentar para que la purga llegue al servidor.
- **WP Rocket** solo carga `rocket_clean_post` con licencia válida; por eso se purga con `rocket_clean_files` (URLs) y `rocket_clean_domain`.
- **Variaciones de WooCommerce** purgan el producto padre; el cambio de stock y de precio llegan por los hooks de WooCommerce (no pasan por `save_post`). Solo se purgan términos de taxonomías públicas (`is_taxonomy_viewable`: nunca `product_type` ni `product_visibility`).
- **Refresco de nonces** (`NonceRefresh` + `itb-nonce.js`, 1,5 KB): en páginas cacheables se recogen las acciones de los nonces creados (filtro `nonce_life`, que recibe la acción desde WP 6.1) y se imprime en el pie una lista firmada (HMAC con `wp_salt('nonce')`, máximo 20 acciones). Si la página tiene más de 10 h, el script pide `GET /nonce` y reemplaza los valores viejos en atributos, objetos globales y `wp.apiFetch`. El endpoint solo genera nonces para la lista firmada, tiene límite de tasa, no se cachea (incluido LSCache) e inicia la sesión de WooCommerce para acciones `woocommerce*`.
- **Recalentado** (`Warmer`): las URLs purgadas, o el sitemap (matriz o `/wp-sitemap.xml`, hasta 500 URLs) tras purgar todo. Lotes de 10, una URL a la vez, pausa si la carga supera núcleos × 0,7, exclusiones de la matriz y de rutas privadas.
- **Las respuestas con `Server-Timing` (cookie de diagnóstico) y las de la API del admin no se guardan en la caché de página.** LSCache, con sus valores por defecto, guarda en caché privada los GET de la REST API de usuarios logueados e ignora `Cache-Control`: se le indica con `litespeed_control_set_nocache`.
- **Costo propio en el frontend:** 0,075 ms y 27 KB al registrar el módulo en una página (medido en el sitio de pruebas con OPcache).

---

## 3. WooCommerce: carrito y sesión (`WooCart`)

- **Riesgo:** moderado. **Por defecto:** inactivo; se activa tras verificación.
- **Qué hace:** que todo el HTML de catálogo sea cacheable aunque el visitante tenga carrito.
- **Cómo:**
  - Desencolar `wc-cart-fragments` en todo el sitio.
  - `itb-cart.js`: lee `GET /wp-json/wc/store/v1/cart`, guarda conteo y total en `localStorage`, actualiza los elementos del mini-carrito (selectores configurables con valores por defecto para los temas comunes: Astra, Flatsome, Woodmart, Kadence, Blocksy, Hello+Elementor). Se actualiza en los eventos `added_to_cart`, `removed_from_cart` (jQuery) y `wc-blocks_added_to_cart`, y al volver a la pestaña (`visibilitychange`).
  - Si el carrito está vacío (no existe `woocommerce_items_in_cart`), no hacer la petición: mostrar 0 directamente.
  - **Prerender**: si `document.prerendering` es verdadero, no llamar a la Store API hasta el evento `prerenderingchange`. Si no, cada página pre-renderizada genera un request sin caché al origen aunque nadie la vea.
  - Alcance: el script actualiza conteo y total. Los mini-carritos que muestran la lista de productos (desplegable) necesitan un renderizador por tema; los no soportados abren el carrito en lugar de mostrar el desplegable.
  - Neutralizar cookies en páginas cacheables: reemplazar `wc_track_product_view` por una versión en `localStorage` (opcional, configurable) para el widget de productos vistos.
  - Geolocalización con caché (`?v=hash`): si `woocommerce_default_customer_address` = `geolocation_ajax`, incluir `v` en la llave de caché.
  - Nunca cachear: carrito, checkout (con cualquier query string), cuenta (con cualquier query string), `?add-to-cart=`, `wc-ajax`, Store API.
- **Aceptación:** con un producto en el carrito, la home, categorías y productos devuelven `cf-cache-status: HIT`; el contador del carrito es correcto en < 300 ms tras cargar; agregar/quitar productos actualiza el contador sin recargar; el checkout funciona con las pasarelas instaladas.

---

## 4. Carga condicional de plugins (`PluginFilter`)

- **Riesgo:** invasivo. **Por defecto:** inactivo. Se activa por regla, solo después de verificación.
- **Qué hace:** quita del `active_plugins` los plugins que no se necesitan para un tipo de request o endpoint concreto.
- **Reglas de implementación (lecciones de Perfmatters MU — obligatorias):**
  1. Filtro `option_active_plugins` (y `site_option_active_sitewide_plugins` en multisite) con prioridad 1, registrado desde el loader.
  2. No filtrar si `wp_is_mobile` aún no existe (otro MU-plugin leyendo la opción antes de `vars.php`).
  3. **Proteger escrituras**: filtro `pre_update_option_active_plugins` con prioridad `PHP_INT_MAX` que reinserta los plugins ocultos en este request. Sin esto, un plugin que lee y guarda la lista (Freemius lo hace) los desactiva para siempre.
  4. **Proteger `rewrite_rules`**: filtro `pre_update_option_rewrite_rules` que devuelve el valor anterior si hay plugins ocultos. Si `rewrite_rules` está vacía, no filtrar nada en ese request.
  5. Comparar por carpeta exacta (`woocommerce/`), nunca por prefijo (`woocommerce-memberships/` es otro plugin). Plugins de un solo archivo se comparan por nombre de archivo.
  6. El filtro de `rewrite_rules` **se mantiene todo el request**, porque los flush ocurren en `wp_loaded` o después. **Decisión abierta (resolver con pruebas en la fase 5):** si el filtro de `option_active_plugins` y el de protección de escritura se retiran en `plugins_loaded` o se mantienen todo el request. Mantenerlo hace que `is_plugin_active()` sea coherente con lo cargado (algunos addons muestran avisos de "plugin requerido"); retirarlo evita que código posterior vea una lista distinta a la guardada. Perfmatters mantiene la lectura filtrada y retira solo la protección de escritura tras cargar los plugins.
  7. Nunca filtrar: admin, login, cron, CLI, checkout, cuenta, ni requests con `itb_bypass` válido.
  8. Decidir solo con la config compilada (tipo de request + endpoint/ruta). Sin consultas, sin `url_to_postid`.
- **Dónde está la ganancia:** `WcAjax` (excepto `update_order_review` y `checkout`, que por defecto no se filtran), `AdminAjax` por `action`, `Rest` por namespace (incluido el de Imagina Search), `StaticMiss`, y `FrontendHtml` por plantilla/ruta solo en fases posteriores.
- **Cómo se generan reglas (nunca a ciegas):**
  1. **Candidatos**: plugins con costo alto según el perfilador + categorías conocidas en la matriz (page builders, SEO, formularios, sliders, popups, analítica) que no deberían intervenir en ese endpoint.
  2. **Experimento**: el verificador (ver docs/05) llama al endpoint con y sin el plugin (cookie de experimento firmada que activa la regla solo para ese request) y compara código de estado, estructura del JSON/HTML (hash normalizado), cabeceras relevantes y errores PHP registrados.
  3. **Promoción**: si 20 llamadas seguidas coinciden, la regla pasa a activa. Queda registrada con su evidencia.
  4. **Vigilancia**: si después aparecen fatales o diferencias, la regla se desactiva sola.
- **Aceptación:** reducción medible de memoria y tiempo en los endpoints filtrados (objetivo: −40 % de memoria en `wc-ajax` y REST de Imagina Search); cero desactivaciones permanentes de plugins en pruebas de estrés con Freemius instalado; `rewrite_rules` intactas tras flush forzado durante requests filtrados.

---

## 5. Navegación instantánea (`Speculation`)

- **Riesgo:** seguro (prefetch) / moderado (prerender). **Por defecto:** prefetch `moderate` activo; prerender `conservative` **inactivo** hasta verificarlo en el sitio (regla 3). El prerender ejecuta JavaScript (analítica, píxeles, carrito) de páginas que quizá nadie vea.
- **Cómo:**
  - Usar la API del core (WP 6.8+): filtros `wp_speculation_rules_configuration` (modo y eagerness), `wp_speculation_rules_href_exclude_paths` (exclusiones) y la acción `wp_load_speculation_rules` para agregar reglas propias *(verificar firmas)*. En WP < 6.8, imprimir el `<script type="speculationrules">` en `wp_footer`.
  - Exclusiones siempre: carrito, checkout, cuenta, logout, `wp-admin`, `wp-login`, `*add-to-cart=*`, `*remove_item=*`, `*_wpnonce=*`, `*action=*`, enlaces con `rel="nofollow"`, descargas y extensiones de archivo.
  - Solo especular URLs que el coordinador marca como cacheables.
  - **Prefetch predictivo**: el RUM registra transiciones página → siguiente (muestreadas). Un job diario calcula las 2–4 siguientes más probables por plantilla y las agrega como regla `immediate` de prefetch. Como los beacons no se autentican, solo entran URLs del mismo origen que existan como contenido publicado y pasen las exclusiones de arriba.
  - Respetar `Save-Data` y conexiones lentas (el script de fallback lo evalúa en el cliente).
  - `itb-prefetch-fallback.js` para Safari/Firefox: prefetch en `touchstart`/`mousedown` y de las URLs predichas, con `IntersectionObserver`.
- **bfcache:** auditar en el admin las causas de exclusión (RUM con `notRestoredReasons` en Chrome) y las cabeceras `no-store` en páginas cacheables. Corregir lo propio; reportar lo de terceros.
- **View Transitions:** opción para agregar `@view-transition { navigation: auto; }` en un `<style>` pequeño.
- **Aceptación:** en Chrome móvil, > 30 % de navegaciones internas con `activationStart > 0` o servidas desde prefetch; cero prefetch a URLs de acción; el servidor no recibe más requests sin caché que antes (medido con las muestras).

---

## 6. Escudo de servidor (`Shield`)

- **Riesgo:** seguro, salvo los bloqueos marcados como moderados. **Por defecto:** activos los seguros (`StaticMiss`, `?author=N`). Los moderados (XML-RPC y `/wp-json/wp/v2/users`) se ofrecen activados solo si ningún plugin activo los requiere según la matriz (`requires.xmlrpc`, `requires.rest_users`); si alguno los requiere, quedan desactivados y se explica por qué.
- **Guardia PHP en el loader (antes de cargar plugins):**
  - `XmlRpc` → 403 (o permitir si la matriz lo requiere: Jetpack, app móvil de WordPress, etc.).
  - `?author=N` en anónimo → 404 (solo si no hay cookie `wordpress_logged_in_*`; la validación real de la sesión no existe aún en el loader, así que un logueado conserva el comportamiento del core).
  - `StaticMiss` → 404 con cuerpo vacío y `Cache-Control: public, max-age=600` (10 min: si el archivo aparece después, p. ej. tras regenerar CSS, el 404 no queda cacheado mucho tiempo). Excepciones: `/favicon.ico` y `static_miss_passthrough` de la matriz.
  - `/wp-json/wp/v2/users` en anónimo → 401 (configurable).
- **Cron:** detectar si `DISABLE_WP_CRON` está activo. Si no, mostrar guía para cron del sistema (comando listo para copiar, incluido ServerAvatar/Plesk). No editar `wp-config.php` automáticamente.
- **Heartbeat:** desactivar en el frontend, 60 s en el admin, 15 s en el editor (configurable).
- **Combinaciones de filtros de Woo:** detectar URLs con más de N parámetros `filter_*`/`query_type_*`/`min_price`/`max_price`; agregar `noindex, nofollow` vía cabecera `X-Robots-Tag` y alimentar la regla de rate limit de Cloudflare.
- **Aceptación:** un 404 de imagen se resuelve en < 5 ms de PHP sin cargar plugins ni tema; XML-RPC bloqueado no afecta a Jetpack cuando está instalado.

---

## 7. Ajustes rápidos (`Tweaks`) — reemplazo de Perfmatters

- **Riesgo:** seguro, salvo los marcados. **Por defecto:** los seguros activos.
- Lista: desactivar emojis; desactivar embeds (oEmbed discovery y `wp-embed`); quitar RSD, wlwmanifest, shortlink, generator, enlaces REST en `<head>`; dashicons solo para logueados; desactivar self-pingbacks; limitar revisiones (configurable); intervalo de autosave; quitar `wc-password-strength-meter` fuera de cuenta/checkout; desactivar el Marketing Hub, el panel de análisis y los avisos de WooCommerce Admin si no se usan (moderado); desactivar feeds RSS (opcional); desactivar comentarios globalmente (opcional); quitar jQuery Migrate (**invasivo**, solo con verificación); desactivar Google Fonts de temas conocidos (moderado).
- **Aceptación:** cada ajuste tiene su propio interruptor, descripción en español y nivel de riesgo visible.

---

## 8. Gestor de assets por ruta (`AssetManager`)

- **Riesgo:** moderado. **Por defecto:** inactivo.
- **Qué hace:** desencola CSS/JS que una ruta no usa (por handle), en `wp_enqueue_scripts` con prioridad 9999 y en `wp_print_styles`/`wp_print_scripts`. Sin tocar el HTML.
- **Reglas:** por tipo de contenido, plantilla, ID, o "todo excepto". Sugerencias automáticas: assets de formularios fuera de páginas con formulario (CF7, WPForms, Fluent Forms, Gravity Forms detectando shortcode/bloque), assets de Woo fuera de páginas de Woo (moderado, con verificación), assets de sliders fuera de páginas con slider.
- **Aceptación:** UI tipo "script manager" en el admin con previsualización de la ruta; cada regla verificable con el verificador.

---

## 9. Pistas de frontend (`FrontendHints`)

- **Riesgo:** seguro. **Por defecto:** activo cuando hay datos de RUM.
- **Datos no confiables:** los beacons de RUM no llevan autenticación, así que cualquiera puede enviar datos falsos. Solo se aceptan como LCP URLs del mismo origen que correspondan a un adjunto de la biblioteca de medios (resuelto en el job diario, nunca en el frontend), y solo se usan valores que se repiten en ≥ 50 muestras de ≥ 20 IP-hash distintas en el hub.
- **LCP:** el RUM reporta el elemento LCP por plantilla y dispositivo (URL de imagen y selector). Con ≥ 50 muestras: agregar `fetchpriority="high"` y quitar `loading="lazy"` a esa imagen vía `wp_get_attachment_image_attributes` y `wp_content_img_tag`; `<link rel="preload" as="image">` con `imagesrcset` en `wp_head`.
- **Fachadas:** YouTube y Vimeo con `embed_oembed_html` (miniatura + carga al tocar).
- **Preconnect** a los orígenes de terceros detectados como críticos.
- Delay de JS de terceros y fuentes: **delegar** al plugin de caché, alimentando sus exclusiones con la matriz.
- **Aceptación:** mejora de LCP p75 en móvil en las plantillas afectadas, medida por RUM antes/después.

---

## 10. Base de datos (`Database`)

- **Riesgo:** moderado. **Por defecto:** solo auditoría; acciones manuales o programadas por el usuario.
- **Auditoría:** tamaño del autoload y top 20 opciones; opciones de plugins inactivos; transients expirados; Action Scheduler (completadas/fallidas > 30 días); revisiones; postmeta huérfano; estado de HPOS; índices sugeridos para `wp_postmeta` (solo recomendación).
- **Acciones:** limpiar transients expirados, purgar Action Scheduler antiguo, limitar revisiones, cambiar `autoload` a `no` en opciones de plugins inactivos (con confirmación y respaldo de la fila). Nunca borrar tablas automáticamente.
- **Aceptación:** cada acción crea un respaldo restaurable y un evento en el registro.

---

## 11. RUM (`Rum`)

- **Riesgo:** seguro. **Por defecto:** activo al 5 %.
- `itb-rum.js` (< 3 KB): LCP (y su elemento), INP aproximado, CLS, TTFB, `activationStart` (prerender), `navigation.type` (`back_forward` = bfcache), `notRestoredReasons` cuando exista, tipo de dispositivo y transición a la siguiente página.
- Envío con `navigator.sendBeacon` en `pagehide`, **directo al hub** (no al servidor del cliente). Sin datos personales; IP no se guarda.
- Alternativa sin hub: endpoint local mínimo con `SHORTINIT` que solo agrega contadores.

---

## 12. Integración con Imagina Search (`Integrations/ImaginaSearch`)

- Detectar el plugin. Registrar su namespace REST como tipo de request propio para la carga condicional (cargar solo Imagina Search + WooCommerce si consulta precios/stock).
- Excluir sus URLs de resultados del prerender y del edge si son dinámicas.
- Mostrar en el perfilador el costo de cada búsqueda.

---

## 13. Hub (`Hub`) y compatibilidad remota (`Compat\RemoteUpdater`)

- El plugin se identifica con la licencia de Imagina Updater + una clave por sitio (HMAC).
- Envía telemetría agregada cada hora por Action Scheduler (lotes, gzip). Si el hub no responde, reintenta con backoff y descarta después de 24 h.
- Descarga la matriz de compatibilidad firmada (ed25519, `sodium_crypto_sign_verify_detached`, clave pública incluida en el plugin). Si la firma falla, conserva la versión anterior.
- Recibe plantillas de flota (configuración estándar) y reporta desviaciones.
- Detalle del protocolo en `docs/04-admin-y-api.md`.
