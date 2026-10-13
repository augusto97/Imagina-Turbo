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

### Implementación (fase 3)

Verificado en el código de WooCommerce 11.2.1 y de los temas Astra, Kadence, Blocksy, Storefront, OceanWP, GeneratePress y Hello Elementor (wordpress.org, 2026-10-13).

- **Reemplazo de `wc-cart-fragments`**: se desregistra y se registra `itb-cart.js` con el mismo handle. WooCommerce solo lo encola con el widget del carrito, pero los temas lo encolan por su cuenta (Storefront, Kadence, Blocksy, OceanWP). Ningún tema lo declara como dependencia de sus scripts. El pedido a `wc-ajax=get_refreshed_fragments` desaparece de todas las páginas.
- **`itb-cart.js` (3,0 KB minificado)**:
  - Sin la cookie `woocommerce_items_in_cart` no hace ninguna petición.
  - Con carrito, pinta lo guardado en `localStorage` y pide `GET /wc/store/v1/cart`, que no necesita nonce.
  - Muestra el **subtotal de artículos** (`total_items` más `total_items_tax` si la tienda muestra precios con impuestos), que es lo que enseñan los encabezados de los temas, no el total con envío.
  - En el contador actualiza el texto y los atributos `data-cart-total` (Astra con iconos), `data-count` (Blocksy) y `data-counter` (Elementor). Si el contador lleva texto («3 items» en Storefront), usa las formas singular y plural traducidas del tema.
  - Se actualiza con `added_to_cart`, `removed_from_cart`, `wc_cart_emptied`, `updated_cart_totals` (jQuery), `wc-blocks_added_to_cart` y `wc-blocks_removed_from_cart`, al volver a la pestaña (como mucho cada 10 s) y al volver del bfcache.
  - En prerender no pide nada hasta `prerenderingchange`.
- **Nonce de la Store API**: WooCommerce Blocks guarda el nonce en `localStorage` (`storeApiNonce`) y se queda con el más reciente por marca de tiempo. La respuesta del carrito trae `Nonce` y `Nonce-Timestamp`: el script los deja ahí (y llama a `wp.apiFetch.setNonce` si existe). El nonce viejo de una página cacheada deja de romper el carrito y el checkout de bloques.
- **Desplegable del mini-carrito**: el HTML de la lista de productos es del tema y no se puede reconstruir sin él. Cuando alguien con carrito pasa el cursor, enfoca o toca el contenedor del mini-carrito (selector por tema), se piden una vez los fragments de WooCommerce (`get_refreshed_fragments`) y se reemplazan. Así se respeta el HTML exacto del tema, solo bajo demanda.
- **Selectores por tema**: verificados en el código para Astra, Kadence (su `.header-cart-total` es la CANTIDAD; no muestra precio en el botón), Blocksy, Storefront y OceanWP (que renombra `.woocommerce-Price-amount` en su estilo normal). Flatsome, Woodmart y el widget de Elementor Pro son de pago: sus selectores están marcados como **no verificados** hasta probarlos en un sitio real. Cada sitio puede añadir los suyos en la pantalla WooCommerce.
- **Cookies de carrito** — la regla es asimétrica:
  - Edge: con el módulo activo, la regla de Cloudflare deja de excluirlas, así que quien tiene carrito recibe páginas cacheadas.
  - Origen: la respuesta generada para alguien con carrito siempre sale con `no-store` y el plugin de caché recibe `no-cache`. Su HTML puede llevar sus productos en el mini-carrito (Kadence lo imprime en el servidor).
  - Comprobado en el sitio de pruebas: LSCache, con sus valores por defecto, guardaba esas páginas como públicas y se las servía a visitantes anónimos.
- **Productos vistos**: con el widget activo, `wc_track_product_view` envía `Set-Cookie` en cada producto y la página deja de ser cacheable. Se quita esa acción y un script en línea, solo en esas páginas, escribe la cookie `woocommerce_recently_viewed` en el navegador con la misma lógica (el último al final, máximo 15). El widget sigue funcionando y la página ya no envía cookies.
- **Geolocalización con caché**: WooCommerce redirige con 307 desde `template_redirect` (prioridad 10) para añadir `?v=`. Cloudflare incluye el query en la llave por defecto y la reescritura de campañas no toca `v`. En modo cabeceras, el coordinador pone un `no-store` provisional al empezar `template_redirect`: cualquier redirección o salida temprana queda fuera del edge, y el emisor lo reemplaza si la página es cacheable.
- **Store API y `wc-ajax` nunca se cachean en el origen**: LSCache, con sus valores por defecto, cachea la REST, y en el sitio de pruebas servía a cualquier visitante el carrito de otra persona (con su dirección). Es una protección de privacidad que corre siempre, incluso con el kill switch: solo emite `litespeed_control_set_nocache` y `DONOTCACHEPAGE`. Al actualizar, se descartan las respuestas REST que LSCache tenía guardadas (tag `REST`).

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

### Implementación (fase 5)

- **Decisión del punto 6 (resuelta con pruebas):** los filtros de lectura **y** de escritura se mantienen todo el request. Con la lectura filtrada, `is_plugin_active()` coincide con lo cargado (el addon de prueba ve el plugin oculto como inactivo). Con la protección de escritura todo el request, también se cubren las escrituras tardías: el simulador de Freemius vuelve a guardar la lista en `init` y en `shutdown`. Contraprueba en el sitio: sin la protección, **un solo request** desactivó para siempre los dos plugins ocultos; con ella, 31 requests seguidos no perdieron ninguno y la lista quedó ordenada.
- **`rewrite_rules`**: contraprueba igual. Un plugin que regenera las reglas en `wp_loaded` durante un request filtrado borró la ruta del plugin oculto sin la protección y la dejó intacta con ella.
- **Exclusiones además de las del documento**:
  - **Store API**: su namespace incluye el checkout de bloques y la regla es por namespace.
  - **`wp/v2`, la raíz de la REST y la API propia (`imagina-turbo/v1`)**.
  - **Requests con sesión iniciada**: el editor y el admin usan REST y admin-ajax.
  - **Solo GET por defecto en la REST**: en un POST, los plugins quitados no ejecutarían sus efectos (analítica de servidor, sincronización, CRM) y el experimento no los ve. wc-ajax y admin-ajax aplican a todos los métodos, porque casi siempre llegan por POST; la pantalla lo advierte.
- **Config compilada**: `filter` (clave → `{p: plugins, w: todos los métodos}`) solo con reglas activas y plugins que siguen activos. `experiments` (id → clave y plugins) con las reglas en prueba y las activas, para poder revisarlas.
- **Loader**: un `isset` en el camino normal. Si hay regla o cookie de experimento, carga `EarlyFilter` (y `ExperimentProbe`). Benchmark de la etapa temprana: 0,096 ms.
- **Experimentos sin el verificador externo**: el sitio se llama a sí mismo (loopback, anónimo, sin seguir redirecciones, con un parámetro único contra la caché). Una cookie firmada (`itb_exp`, HMAC con clave derivada de `AUTH_KEY` + `AUTH_SALT`, 10 minutos como máximo) decide en el loader si ese request va sin filtrar o filtrado.
  - Cada request de experimento escribe tiempo, memoria pico, avisos y fatal en `wp-content/imagina-turbo/experiments/{id}.log`. Es una escritura acotada: solo en requests firmados por el propio sitio.
  - **Calibración**: dos llamadas sin filtrar eligen la comparación exacta (con nonces, hashes y fechas neutralizados) o la estructural (claves y tipos del JSON, etiquetas del HTML).
  - **Diferencia**: estado, tipo de contenido, redirección, nombres de cookies, cuerpo, un fatal o más avisos de PHP que sin filtrar.
  - **Promoción**: 20 pares iguales seguidos. Los experimentos no cuentan para el modo seguro: sus fallos quedan en la evidencia.
  - El verificador externo con Playwright (páginas completas) sigue pendiente.
- **Vigilancia**: un fatal en un request filtrado queda en `filter-faults.log` (lo escribe el loader en shutdown). La regla se desactiva en el siguiente request de admin o en la tarea horaria, con evento de error. Probado con un addon que llama a una función del plugin oculto. Cada regla activa se revisa una vez al día con 5 pares.
- **La compilación nunca corre en un request con plugins ocultos**: vería una lista de activos recortada.
- **Candidatos**: los endpoints filtrables con muestras del perfilador (48 h) y los plugins con al menos 0,5 ms de costo medio allí, sin los protegidos (el propio plugin; WooCommerce en wc-ajax y `wc/*`; Imagina Search y WooCommerce en `wss/v1`).
- **Medido en el sitio de pruebas** (OpenLiteSpeed, WooCommerce):
  - `get_refreshed_fragments` sin dos plugins de prueba (uno de ellos reserva 6 MB): 15,0 → 8,9 MB (−41 %), 108 → 84 ms.
  - Imagina Search (`wss/v1`, búsqueda y populares) sin los 7 plugins ajenos: 23,7 → 17,0 MB (−28 %), 182 → 151 ms.
  - El −40 % depende de lo que pesen los plugins del sitio: aquí son ligeros. Con Elementor, un SEO o un constructor, la diferencia crece.
- **Detección de diferencias probada**: quitar el plugin que agrega un fragmento al mini-carrito hizo fallar el experimento en el primer par, con la diferencia exacta en la evidencia.
- **Pendiente para pruebas reales**: WPML (no hay licencia en el entorno de pruebas) y plugins con Freemius real.

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

### Implementación (fase 6)

API del core verificada en WordPress 7.1 (`wp-includes/speculative-loading.php`):

- Con sesión iniciada o sin enlaces permanentes, la configuración es `null` (desactivado) y se respeta.
- Con enlaces permanentes, el core ya excluye toda URL con query: `add-to-cart`, `_wpnonce`, `action` y logout quedan fuera sin hacer nada. También excluye wp-admin, `wp-*.php`, uploads, plugins y temas.
- Una regla de lista admite `immediate`; una de documento no.

Lo que hace el módulo:

- **Configuración**: `wp_speculation_rules_configuration` devuelve el modo y la anticipación elegidos. Por defecto, prefetch moderado. El prerender pide confirmación (428).
- **Exclusiones**: `wp_speculation_rules_href_exclude_paths` agrega carrito, checkout, cuenta, login y las rutas que nunca se cachean, sacadas de la config compilada.
- **Prefetch predictivo**: `wp_load_speculation_rules` agrega una regla de lista `itb-predicted` (prefetch, `immediate`) con las páginas siguientes de la plantilla actual, sin la página actual.
- **WordPress < 6.8**: la misma regla, impresa en `wp_footer`.
- **Respaldo** (`itb-prefetch.js`, 1,4 KB): solo se descarga si el navegador no entiende Speculation Rules. Precarga al tocar, al pulsar o tras 80 ms de hover, y los enlaces predichos cuando entran en pantalla. Respeta Save-Data y 2G. Las exclusiones con comodín inicial se omiten: un prefijo vacío excluiría todo.
- **View Transitions**: `<style>@view-transition{navigation:auto}</style>` en `wp_head`. Apagado por defecto porque cambia cómo se ve la navegación.
- **bfcache**: la pantalla muestra las causas de exclusión (RUM, `notRestoredReasons`) y las páginas propias que salen con `no-store` según las muestras del perfilador.
- **Medido en Chromium** (sitio de pruebas):
  - Con prefetch moderado, al pasar el cursor y pulsar, la página llegó como `navigational-prefetch`.
  - Atrás y adelante volvieron del bfcache.
  - La regla predicha (portada → `/tienda/`) también sirvió la navegación desde el prefetch.
  - **El prerender no se pudo comprobar**: Chromium headless no lo dispara. Queda para un navegador real.

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

### Implementación (fase 4)

- **`EarlyGuard`** corre en el loader MU, sin autoloader ni base de datos. Solo se carga para requests candidatos (asset inexistente, XML-RPC, `?author=` o REST con `/users` o `rest_route`): en una página normal cuesta unas comparaciones. Responde con la cabecera `X-ITB-Shield: <motivo>` y termina el request.
- **Credenciales HTTP**: el `.htaccess` de WordPress copia la cabecera `Authorization` a `HTTP_AUTHORIZATION` aunque venga vacía. Solo cuenta si tiene valor; si no, el bloqueo de `/wp/v2/users` nunca se aplicaría (pasó en OpenLiteSpeed).
- **XML-RPC y usuarios**: la config compilada los apaga si un plugin activo los requiere según la matriz (`requires.xmlrpc`, `requires.rest_users`). Comprobado con Jetpack: con él activo, XML-RPC responde 200; sin él, 403.
- **Heartbeat**: se quita del frontend solo para visitantes sin sesión (con sesión, un LMS o un chat puede usarlo, y quitar el script quita también los que dependen de él). En el admin, `heartbeat_settings` fija el intervalo: 60 s, 15 s en el editor.
- **Filtros de Woo**: con más de N parámetros de filtro, `X-Robots-Tag: noindex, nofollow` en `send_headers`.
- **Cron**: la pantalla muestra si `DISABLE_WP_CRON` está activo y los comandos listos para copiar (WP-CLI y curl). No se edita `wp-config.php`.
- **Medido en el sitio de pruebas** (OpenLiteSpeed, contenedor lento): un asset inexistente pasa de 250 ms a 10 ms. Esos 10 ms son el arranque del core de WordPress hasta los MU plugins (medido con un MU plugin que corre antes que el loader); la guardia añade < 0,3 ms. En un servidor normal ese arranque son unos pocos ms. Bajar de ahí exige cortar antes de PHP (reglas del servidor), que es lo que hace la capa del edge.

---

## 7. Ajustes rápidos (`Tweaks`) — reemplazo de Perfmatters

- **Riesgo:** seguro, salvo los marcados. **Por defecto:** los seguros activos.
- Lista: desactivar emojis; desactivar embeds (oEmbed discovery y `wp-embed`); quitar RSD, wlwmanifest, shortlink, generator, enlaces REST en `<head>`; dashicons solo para logueados; desactivar self-pingbacks; limitar revisiones (configurable); intervalo de autosave; quitar `wc-password-strength-meter` fuera de cuenta/checkout; desactivar el Marketing Hub, el panel de análisis y los avisos de WooCommerce Admin si no se usan (moderado); desactivar feeds RSS (opcional); desactivar comentarios globalmente (opcional); quitar jQuery Migrate (**invasivo**, solo con verificación); desactivar Google Fonts de temas conocidos (moderado).
- **Aceptación:** cada ajuste tiene su propio interruptor, descripción en español y nivel de riesgo visible.

### Implementación (fase 4)

Hooks verificados en WordPress 7.1 y WooCommerce 11.2.1:

- **Emojis**: `print_emoji_detection_script` (`wp_head` y `admin_print_scripts`), `wp_enqueue_emoji_styles`, `print_emoji_styles`, `wp_staticize_emoji` (feeds y correo) y el filtro `emoji_svg_url`.
- **Limpieza del `<head>`**: `rsd_link`, `wp_generator`, `wp_shortlink_wp_head` y `wp_shortlink_header`. `wlwmanifest_link` ya no está enganchado en el core.
- **Enlaces REST**: `rest_output_link_wp_head` y `rest_output_link_header`.
- **Embeds**: `wp_oembed_add_discovery_links` (prioridad por defecto), `wp_oembed_add_host_js` y el script `wp-embed`.
- **Autoguardado**: `AUTOSAVE_INTERVAL` se define después de `plugins_loaded`, así que la constante se define antes que el core.
- **WooCommerce Admin**: el filtro `woocommerce_admin_features` (quita `marketing`, `analytics`, `remote-inbox-notifications`). `woocommerce_admin_disabled_features` no existe.
- **Medidor de contraseña**: WooCommerce ya lo limita a carrito, checkout y cuenta. El ajuste lo quita en cualquier otra página donde lo encole un plugin.
- **Perfmatters encendido**: el módulo se apaga entero por solapamiento. La migración lee `perfmatters_options` (claves verificadas en 2.5.0 y 2.6.6: las casillas se guardan como `'1'`), propone los equivalentes y lista lo que no tiene, con dónde vive (WooCart, plugin de caché, fases 6 y 7).
- Los ajustes no seguros exigen confirmación en la REST (428) y en la pantalla.

---

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

### Implementación (fase 6)

- **Origen de los datos**: la imagen LCP por plantilla la decide la tarea diaria del RUM (`RumInsights`) con los datos móviles de 14 días. Hacen falta 50 muestras, unos 20 visitantes distintos (estimados con el mapa de bits de IP-hash) y que la misma imagen sea el LCP en al menos el 60 % de las visitas de esa plantilla.
- **Tipos de imagen LCP**:
  - Una imagen fija (la portada, por ejemplo): un adjunto de la biblioteca, resuelto con `attachment_url_to_postid` sobre la ruta sin sufijo de tamaño.
  - **`featured`**: la imagen destacada de cada página (fichas de producto, entradas). El RUM compara el nombre de archivo del LCP con el de la imagen destacada que la página le pasa en su configuración. Así una plantilla con imágenes distintas en cada página también se aprovecha.
- **En la página**:
  - `fetchpriority="high"` y `loading="eager"` en esa imagen (`wp_get_attachment_image_attributes` y `wp_content_img_tag`).
  - Con una imagen fija, además `<link rel="preload" as="image" media="(max-width: 767px)">` con la URL que midió el RUM. Solo en móvil: en escritorio el navegador elegiría otro tamaño y la precarga se desperdiciaría.
- **Fachadas** (`embed_oembed_html`): YouTube con miniatura de `i.ytimg.com` y Vimeo sin miniatura (no hay forma oficial de obtenerla sin pedirla a su API). El iframe se crea al tocar, con `autoplay=1`. No se tocan los iframes con `enablejsapi`. El CSS y el JS (unos cientos de bytes en línea) solo se imprimen en páginas con alguna fachada. Probado en el sitio: sin iframes al cargar y el reproductor aparece al tocar.
- **Preconnect**: no implementado. Las imágenes LCP que se aceptan son del mismo origen, y para terceros haría falta medir sus recursos en el RUM. Queda para cuando haya datos reales.

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

### Implementación (fase 6)

- **Carga del script**: un script en línea en cada página del frontend sin sesión guarda la plantilla actual en `sessionStorage` (para medir de qué plantilla viene cada visita) y decide la muestra (5 %). Solo la muestra descarga `itb-rum.js` (2,0 KB). En un prerender espera a `prerenderingchange`.
- **Qué mide**:
  - LCP (y la ruta de su imagen si es del mismo sitio), INP aproximado (Event Timing, umbral de 40 ms), CLS (ventanas de sesión) y TTFB.
  - El tipo de navegación: normal, recarga, atrás sin bfcache, restaurada del bfcache, prerender (`activationStart`) o prefetch (`deliveryType`).
  - `notRestoredReasons` y la transición plantilla anterior → ruta actual.
  - El beacon se envía con `sendBeacon` al ocultarse la página. Una vuelta desde el bfcache envía uno mínimo en `pageshow`.
- **Endpoint local**: `POST /?itb-rum=1`, atendido en el loader MU antes de cargar plugins (como la guardia del escudo). Responde en unos 15 ms en el sitio de pruebas. Valida el origen, limita el tamaño a 2 KB y, si hay APCu, limita a 30 beacons por minuto e IP. Hace dos `INSERT … ON DUPLICATE KEY UPDATE`. Se eligió el loader en vez de `SHORTINIT` porque no exige adivinar la ruta de `wp-load.php` desde un archivo propio.
- **Almacenamiento**: solo contadores.
  - `itb_rum_hist`: histogramas por hora, plantilla, dispositivo y métrica. El p75 sale de los intervalos: 25 ms para LCP y TTFB, 10 ms para INP, 0,01 para CLS.
  - `itb_rum_events`: por día, imagen LCP, página siguiente y causas de bfcache, con un mapa de 64 bits (`ip_bits`): un bit por hash de IP con sal diaria. Con conteo lineal estima visitantes distintos. **La IP no se guarda.**
  - Retención: 30 días.
- **Con hub**: si el sitio está registrado, los beacons van a `{hub}/v1/rum?site={id}` (el hub simulado lo acepta).
- **Probado en Chromium** con la muestra al 100 %: beacons de navegación normal, prefetch, recarga y bfcache, transición portada → tienda, LCP con su imagen, CLS y TTFB.

---

## 12. Integración con Imagina Search (`Integrations/ImaginaSearch`)

- Detectar el plugin. Registrar su namespace REST como tipo de request propio para la carga condicional (cargar solo Imagina Search + WooCommerce si consulta precios/stock).
- Excluir sus URLs de resultados del prerender y del edge si son dinámicas.
- Mostrar en el perfilador el costo de cada búsqueda.

### Implementación (fase 5)

Verificado en `augusto97/imagina-search` (Woo Smart Search 6.0.2):

- Es el plugin `woo-smart-search/woo-smart-search.php`; se reconoce por el archivo aunque cambie la carpeta.
- Namespace REST `wss/v1`. En modo WooCommerce usa `wc_get_product()`, así que WooCommerce queda protegido en su regla.
- Aparece como candidato aunque no haya muestras, con todos los plugins ajenos propuestos y como pruebas una búsqueda real (primera palabra del primer producto) y `/popular`.
- Su límite de 30 búsquedas por minuto e IP (`wss_rate_limit`) se levanta solo en los requests de experimento.
- La página de resultados (`results_page_id`) imprime el término y pide los resultados desde el navegador: el HTML es cacheable por URL. Su ruta va a la config (`imagina_search.results_path`). Como siempre lleva `?q=`, el core ya la excluye de la especulación (con enlaces permanentes excluye toda URL con query).
- El costo de cada búsqueda ya sale en el perfilador, con la ruta `wss/v1`.

---

## 13. Hub (`Hub`) y compatibilidad remota (`Compat\RemoteUpdater`)

- El plugin se identifica con la licencia de Imagina Updater + una clave por sitio (HMAC).
- Envía telemetría agregada cada hora por Action Scheduler (lotes, gzip). Si el hub no responde, reintenta con backoff y descarta después de 24 h.
- Descarga la matriz de compatibilidad firmada (ed25519, `sodium_crypto_sign_verify_detached`, clave pública incluida en el plugin). Si la firma falla, conserva la versión anterior.
- Recibe plantillas de flota (configuración estándar) y reporta desviaciones.
- Detalle del protocolo en `docs/04-admin-y-api.md`.
