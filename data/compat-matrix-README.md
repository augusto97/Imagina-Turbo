# Matriz de compatibilidad v1 — plugin de rendimiento

Generada el 2026-10-12 a partir de WP Rocket 3.23.5.1 y Perfmatters 2.6.9 (ambos GPLv2+). Los datos se reestructuraron a un esquema propio; no se copió código.

## Contenido de `compat-matrix-v1.json`

| Sección | Qué contiene |
|---|---|
| `core.bypass_cookies` / `bypass_paths` | Lo que nunca se cachea en ningún sitio (logueados, contraseña de post, comentaristas, admin, login, feeds, embeds, REST). |
| `core.ignore_query_params` | 94 parámetros de campañas (utm, fbclid, gclid, gbraid, wbraid, srsltid, hsa_*…) que se quitan de la llave de caché. Se limpiaron errores del origen (`"and hsa_ver"`, `"plus hsa_la"`, duplicados). |
| `core.preload_exclude_patterns` | Enlaces que el crawler nunca debe seguir (mailto, tel, whatsapp, javascript:, jet-popup…). |
| `core.staging_domains` | 61 dominios de staging donde no se aplican reglas de Cloudflare ni purgas. |
| `integrations[]` | 411 entradas (294 plugins, 117 temas). Cada una: `detect`, y según aplique `cache`, `purge`, `preload`, `delay_js`, `filters`, `notes`, `sources`. |
| `third_party_scripts_never_delay` | Analytics, redes de anuncios, pasarelas de pago (Stripe) y otros servicios que se rompen si se retrasan. |
| `delay_js_global_exclusions` | Exclusiones globales de delay/defer (inline y externas). |
| `overlapping_plugins` | Plugins con funciones que se pisan con las nuestras, agrupados por módulo. |

### Tipos de reglas de caché por integración

- `bypass_paths` / `bypass_paths_dynamic`: no cachear esas rutas. Las dinámicas (IDs de páginas de Woo, slug de login oculto, etc.) se resuelven al compilar la configuración, nunca en cada request.
- `vary_cookies`: la caché se separa por el valor de la cookie (moneda, país, consentimiento).
- `require_cookie_before_cache`: no servir caché hasta que la cookie exista (p. ej. moneda por geolocalización).
- `cart_cookies` (Woo): solo actúan como bypass si el módulo de carrito por Store API está desactivado.
- `cache_key_query_params_conditional`: parámetros que sí deben formar parte de la llave en ciertas configuraciones (`v` de geolocalización de Woo, `lang` de WPML).

### Qué es dato fuerte y qué hay que validar

- **Delay JS (`delay_js`, scripts de terceros):** es la parte más sólida; son listas mantenidas en producción por ambos plugins.
- **Cookies, rutas y purga (19 integraciones con reglas de caché):** extraídas a mano de las integraciones de WP Rocket. Revisarlas contra la versión actual de cada plugin antes de marcarlas como verificadas.
- **`overlapping_plugins.cache_or_full_optimization`:** lista curada, no viene de los ZIP.
- Las listas de hostings administrados (Kinsta, WP Engine, etc.) se dejaron fuera porque no aplican a VPS propios.

## Lecciones de diseño (no son datos, van al CLAUDE.md)

1. **Desactivar plugins por ruta tiene trampas reales.** Perfmatters MU las documenta en su código:
   - Si otro código lee `active_plugins` mientras está filtrado y lo vuelve a guardar (Freemius lo hace al incluirse), los plugins ocultos quedan **desactivados de forma permanente**. Hay que interceptar `pre_update_option_active_plugins` y reinsertar los ocultos.
   - Si WordPress regenera `rewrite_rules` mientras faltan plugins, sus rutas se pierden hasta el próximo flush. Hay que bloquear esa escritura durante el request y no filtrar nada si `rewrite_rules` está vacío.
   - No filtrar cuando otro mu-plugin lee la opción antes de que WordPress cargue `vars.php`.
   - Comparar por carpeta exacta (`woocommerce/`), no por prefijo, o se desactiva también `woocommerce-memberships/`.
   - Perfmatters resuelve la URL a un post ID antes de cargar WordPress (instancia `WP_Rewrite` y consulta la base). Nosotros decidimos por tipo de request y prefijo de ruta con una configuración compilada en PHP, sin consultas.
   - Perfmatters nunca filtra en REST, AJAX ni cron. Nosotros sí queremos hacerlo (es donde más se gana), pero solo con reglas por endpoint aprendidas y verificadas.
2. **Cachear la respuesta del carrito vacío.** WP Rocket guarda en un transient, por idioma, la respuesta de `get_refreshed_fragments` cuando no hay carrito. Es una idea barata y aplicable aunque usemos Store API.
3. **Purga correcta en Woo y Elementor.** Al guardar una variación se purga el producto padre. Cuando Elementor regenera su CSS global se purga todo.
4. **Sitemaps como fuente de precarga.** Yoast y Rank Math (`/sitemap_index.xml`), SEOPress (`/sitemaps.xml`), AIOSEO, The SEO Framework y Jetpack (`/sitemap.xml`), solo si el módulo de sitemap está activo.
5. **Trampas de crawler.** Calendarios (The Events Calendar: `/{eventsSlug}/20*`) y popups/menús de JetEngine/JetMenu generan URLs infinitas.
