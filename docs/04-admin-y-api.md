# 04 — Admin, API, datos y hub

## Pantallas del admin

App React montada en `admin.php?page=imagina-turbo`, con navegación lateral. Estética tipo Linear/Vercel, shadcn/ui con prefijo `itb-`, textos en español. Cada función muestra su **nivel de riesgo** (insignia verde/ámbar/roja) y su **estado de verificación**.

| Pantalla | Contenido |
|---|---|
| **Panel** | Estado general (plugin de caché detectado, Cloudflare conectado, modo seguro). Métricas clave antes/después: TTFB p75, LCP p75 móvil, tasa de aciertos del edge, memoria media por request, requests a PHP por hora, bloqueos del firewall. Alertas pendientes. |
| **Diagnóstico** | Perfilador: ranking de plugins por costo (ms y MB) por tipo de request; fases del request; consultas; endpoints más pesados. Auditoría de `Set-Cookie` en páginas cacheables. Causas de bfcache. |
| **Módulos** | Tarjetas por módulo con interruptor, riesgo, descripción, estado de verificación y enlace a su configuración. |
| **Carga condicional** | Reglas por endpoint con su estado y evidencia (pares, tiempo y memoria con y sin la regla, la diferencia si la hubo). Candidatos del perfilador con su ahorro estimado. Editor de reglas (plugins, todos los métodos, peticiones de prueba) y experimento con progreso. |
| **Caché** | Adaptador activo, TTL, tags, mapa de purga, cola de purgas, recalentado (fuentes, progreso, pausa por carga), purga manual por URL/tag/todo. |
| **Cloudflare** | Conexión del token (asistente), zona y plan, ajustes de zona, reglas propias y de terceros (solo lectura), **diff** de cambios propuestos, aplicar, historial de snapshots con revertir, deriva detectada. |
| **Firewall** | Reglas del edge que se proponen (ataques en la URL, superficie de WordPress, escáneres) y límite de peticiones a login y checkout; sus cambios pendientes en la zona con el mismo ciclo de aplicar y snapshot que Cloudflare; eventos de las últimas 24 h por regla; verificación de origen cerrado e IP real; reglas en `.htaccess` con respaldo y autoprueba. |
| **WooCommerce** | Carrito por Store API (selectores del mini-carrito, prueba en vivo), cookies neutralizadas, páginas excluidas, geolocalización. |
| **Navegación** | Modo y anticipación (prerender con confirmación), prefetch predictivo, respaldo para Safari y Firefox, View Transitions, prioridad LCP, fachadas y muestra del RUM. Resumen de navegación (especuladas, bfcache), Core Web Vitals p75 por plantilla y dispositivo, lo que el RUM decidió (páginas predichas, imagen LCP) y causas de bfcache con las páginas propias que salen con no-store. |
| **Assets** | Gestor por ruta (vista tipo script manager): previsualización solo para el admin, reglas con su estado, inventario de una página (origen, tamaño, dependientes, pie) y sugerencias de formularios, sliders y WooCommerce. |
| **Ajustes rápidos** | Escudo del servidor (interruptores, intervalos de Heartbeat, límite de filtros, prueba como visitante), guía de cron del sistema, ajustes con interruptor y riesgo (confirmación para moderados e invasivos) y migración desde Perfmatters. |
| **Base de datos** | Auditoría (autoload, opciones de plugins inactivos, transients, Action Scheduler, revisiones, postmeta huérfano, HPOS, índices, tablas más grandes), acciones con respaldo, lotes restaurables y limpieza programada. |
| **Flota** | Plantilla del hub (nombre, versión, lo ignorado), desviaciones con su riesgo, aplicar con confirmación, autorización para los lotes del hub con su endpoint, snapshots con revertir. |
| **Compatibilidad** | Plugins/tema detectados, reglas de la matriz aplicadas, conflictos y solapamientos, versión de la matriz y firma. |
| **Registro** | Todos los eventos: cambios, aplicaciones, reversiones, modo seguro, errores de API. Filtros y exportación. |
| **Avanzado** | Kill switch, cookie de bypass para el admin actual, exportar/importar ajustes (sin token), conexión con el hub, licencia (Imagina Updater), modo depuración, asistentes de IA (habilidades registradas y «Permitir cambios», `ai_write`). |

Reglas de UX:
- Ningún cambio sensible se aplica sin mostrar antes qué va a cambiar.
- Todo botón destructivo pide confirmación y ofrece la reversión.
- Las métricas siempre muestran la fuente (RUM, origen, Cloudflare) y el periodo.

## Endpoints REST del admin

Namespace `imagina-turbo/v1`. Todos requieren `manage_options` (o la capacidad propia `itb_manage`, asignada a administradores) y nonce `wp_rest`. Validación con esquemas de argumentos de WordPress.

| Método | Ruta | Uso |
|---|---|---|
| GET | `/status` | Estado general, entorno detectado, alertas |
| GET / PUT | `/modules` · `/modules/{id}` | Leer/cambiar estado y ajustes de un módulo |
| GET | `/profiler/summary` · `/profiler/plugins` · `/profiler/routes` | Datos del perfilador (`period`: 1h, 24h, 48h desde muestras; 7d, 30d desde agregados · `type`: tipo de request) |
| GET | `/profiler/workers` | Trabajadores PHP ocupados (48 h de muestreo aleatorio): media, hora pico, procesos del servidor si LiteSpeed los expone (`LSAPI_CHILDREN`) y qué rutas ocupan el tiempo |
| GET | `/diagnostics/platform` | Ajustes de Elementor, WoodMart, WooCommerce (caché de pedidos HPOS, tabla de atributos), caché de objetos e índices, con recomendación. Solo lectura |
| GET | `/profiler/per-visit` | Peticiones AJAX, REST y Store API de visitantes sin sesión (48 h de muestreo aleatorio): por día, CPU por día y, con datos del RUM, por página vista; regla de carga condicional y qué hacer |
| PUT / POST | `/profiler/settings` · `/profiler/intensive` | Tasa de muestreo · muestreo intensivo de 15 min |
| GET · PUT | `/verifier` | Verificador externo (0.20.0): configuración (URL, id, estado de la clave), páginas que se prueban, bloque para `sites.json` y últimas corridas · guardar `{url, site, new_key}` (la primera vez, o con `new_key`, devuelve la clave nueva una sola vez) |
| GET · POST · GET · POST | `/verifier/health` · `/verifier/runs` · `/verifier/runs/{id}` · `/verifier/baseline` | Prueba de conexión · pedir una corrida con las páginas clave (`lighthouse`) · resultado · tomar sus capturas como referencia visual. Todo firmado hacia el verificador; 502 si no responde |
| GET · POST | `/diagnostics/images` · `/diagnostics/images/scan` | Auditoría de imágenes (0.19.0): último escaneo, estado de EWWW, optimizadores activos y recomendaciones · avanzar el escaneo de la biblioteca (8 s por paso; `restart: true` empieza de cero). Solo lectura sobre los medios y el optimizador |
| GET / POST | `/diagnostics/audit` | Última auditoría de cacheabilidad · ejecutarla ahora (loopback) |
| GET / PUT / DELETE | `/hub` | Estado · URL del hub · olvidar el registro |
| POST | `/hub/register` · `/hub/send` | Registrar el sitio · enviar telemetría ahora |
| GET | `/plugin-filter/status` | Módulo (y si otro plugin lo apaga), plugins activos, reglas con su evidencia, candidatos del perfilador, Imagina Search, fallos pendientes |
| POST | `/plugin-filter/rules` | `{key, plugins, all_methods?, probes?}`: guarda la regla en prueba y recompila. 409 con el módulo apagado; 400 si la clave está excluida o un plugin está protegido o inactivo |
| POST | `/plugin-filter/rules/{id}/step` · `/recheck` | Avanza el experimento (presupuesto de ~12 s; se repite hasta terminar) · revisa una regla activa con 5 pares |
| POST / DELETE | `/plugin-filter/rules/{id}/disable` · `/plugin-filter/rules/{id}` | Desactivar · eliminar (recompila y registra) |
| GET | `/cache/status` | Adaptador detectado, ajustes, cola, última sincronización, recalentado |
| PUT | `/cache/settings` | Modo (`sync` / `headers`), TTL, SWR, SIE, refresco de nonces, recalentado, caché de fragmentos (`fragments`, 0.17.0) y variantes por cookie en el edge (`edge_variants`, 0.18.0, purga todo al cambiar). Pasar a `headers` exige `confirm: true` (428 sin ella); activar los fragmentos también, salvo que haya una verificación aprobada en los últimos 7 días |
| POST | `/cache/fragments/verify` | Compara cada página de muestra (más carrito y cuenta) renderizada de cero con la misma servida con los menús de la caché de fragmentos (loopback con cookie firmada `itb_frag`, `?itb_frag=b\|f\|h`). Guarda el resultado en `itb_frag_verify` y lo devuelve en `/cache/status` (`fragments.verify`) |
| POST | `/cache/purge` | Purga manual (`url` / `tag` / `all`; `all` exige `confirm: true`) |
| POST | `/cache/warm` | `seed` (recalentar el sitemap) / `stop` (vaciar la cola de recalentado) |
| POST | `/cache/inspect` | Pide una URL del sitio como anónimo y devuelve sus cabeceras de caché |
| GET | `/cloudflare/status` | Token (estado y últimos 4 caracteres), zona, plan, permisos de lectura detectados, bloqueo por staging o zona ajena, enlace de creación del token |
| POST / DELETE | `/cloudflare/connect` | Verificar y guardar el token (busca la zona del host) · desconectar (borra token, estado y analíticas; las reglas aplicadas se quedan) |
| GET | `/cloudflare/zone` | Ajustes actuales vs. lo que se aplicará (con su valor recomendado, opciones y si se gestiona), alertas (SSL Flexible, TTL mínimo del plan), reglas propias y del cliente por fase con el máximo del plan; desde 0.21.0 también Smart Shield (solo lectura) y los enlaces al panel (Crawler Hints, AI Crawl Control) |
| PUT | `/cloudflare/zone-prefs` | `{prefs: {ajuste: valor \| "keep"}}` (0.21.0): valor elegido para cada ajuste de la zona; solo se guarda lo que difiere de lo recomendado. 400 con un ajuste de solo lectura o un valor que no es una opción. No toca Cloudflare: el cambio aparece en el plan |
| GET | `/cloudflare/plan` | Cambios propuestos: crear, modificar, eliminar, sin cambios, modificada a mano (deriva) o bloqueado (con el motivo) |
| POST | `/cloudflare/apply` | `{ids, confirm}`: valida con `dry_run`, guarda snapshot, aplica y restaura solo si algo falla. Exige módulo activo y dominio permitido (409) y `confirm` (428) |
| GET / POST | `/cloudflare/snapshots` · `/cloudflare/snapshots/{id}/restore` | Historial (50 por zona) · restaurar (`confirm`), guardando antes un snapshot del estado actual |
| POST | `/cloudflare/remove-all` | Quitar todo lo del plugin de la zona (`confirm`, 428): reglas propias y ajustes devueltos a su valor original, con snapshot previo. No exige el módulo activo. `DELETE /cloudflare/connect` exige `confirm` si quedan reglas o ajustes (`footprint` en `/cloudflare/status`) |
| GET | `/cloudflare/zaraz` | Zaraz (0.18.0): IDs detectados en los plugins y en el último escaneo, herramientas actuales en Zaraz (propias o a mano), e-commerce, inyección automática, permiso del token y qué reemplaza hoy |
| POST | `/cloudflare/zaraz/scan` | Escanea la portada (petición loopback) en busca de GA4, etiqueta de Google, GTM y píxeles de Meta |
| POST | `/cloudflare/zaraz/apply` | `{ga4, meta, meta_token, confirm}` (428 sin `confirm`): escribe las herramientas en Zaraz, publica si la zona trabaja en vista previa y, si salió bien, apaga las etiquetas del sitio (recompila y purga) |
| POST | `/cloudflare/zaraz/revert` | `{confirm}`: devuelve primero las etiquetas del sitio y después quita las herramientas propias de Zaraz |
| GET | `/cloudflare/analytics` | Peticiones de las últimas 24 h por estado de caché y rutas que más llegan al origen (se renueva cada hora) |
| GET | `/firewall/status` | Reglas elegidas, cambios pendientes del firewall en la zona (fases WAF y rate limit), notas del plan, eventos guardados, origen cerrado (muestras de 7 días), IP real, estado del `.htaccess` y su bloque |
| PUT | `/firewall/settings` | `{rules: {exploits, surface, bots, facets}, ratelimit}` (recompila; se aplican desde `/cloudflare/apply`). `facets` (0.21.0) viene apagada |
| GET | `/firewall/events` | Eventos del firewall de las últimas 24 h agrupados por regla y acción (`firewallEventsAdaptive`, se renueva cada hora) |
| POST | `/firewall/htaccess` | `{action: apply\|remove, confirm}`: respaldo, escritura del bloque al principio, autoprueba (portada, un producto, `/wp-json/`) y restauración si alguna no responde 200. 428 sin `confirm`, 409 en nginx |
| GET / PUT | `/shield/status` · `/shield/settings` | Escudo: interruptores, intervalos de Heartbeat, límite de filtros, qué requieren los plugins activos (XML-RPC, usuarios), guía de cron. 0.21.0: `facets`, `facets_robots` y `facets_views` (combinaciones de filtros para crawlers); 0.21.1: `facets_scope` (`combos` o `all`) y `facets_empty404` y el estado del robots.txt (si es físico y las reglas para copiarlas); cambiar sus reglas purga `/robots.txt` |
| GET · PUT · DELETE | `/turnstile` | Turnstile (0.21.0): módulo, claves (la secreta solo con sus últimos 4 caracteres), si pasaron la prueba, formularios, pasarelas exentas y activas, otros plugins de captcha · guardar `{site_key?, secret?, forms?, skip?}` (cambiar una clave apaga los formularios hasta repetir la prueba) · quitar las claves y apagar todo |
| POST | `/turnstile/test` | `{token}` del widget del admin: `siteverify` con la clave secreta guardada, sin fallar abierto. Si pasa, guarda la firma de las claves y recompila |
| POST | `/shield/test` | Pide como anónimo un asset inexistente, `?author=1`, `xmlrpc.php` y `/wp/v2/users` (y, con la guardia de filtros activa, dos filtros de la tienda como crawler; con el 404 de filtros vacíos, un precio imposible como visitante): estado, cabecera `X-ITB-Shield` y tiempo |
| GET / PUT | `/tweaks/status` · `/tweaks/settings` | Ajustes rápidos con su riesgo · cambiarlos (encender uno no seguro exige `confirm`, 428), revisiones y autoguardado |
| GET / POST | `/tweaks/perfmatters` · `/tweaks/perfmatters/import` | Equivalencias de `perfmatters_options` (lo que se mapea y lo que no, con dónde vive) · importarlas (`confirm`) |
| GET / PUT | `/woo/status` · `/woo/settings` | Carrito por Store API: tema y selectores en uso (verificados o no), selectores propios, productos vistos, geolocalización · guardar selectores propios y productos vistos (recompila la config) |
| POST | `/woo/inspect` | Pide la portada sin carrito y con carrito: cabecera del edge, script del carrito, cart-fragments original y Set-Cookie |
| GET | `/navigation/status` | Módulos, ajustes (incluye `lazy_render`, `lazy_elements` y `lazy_documents`), si el core trae la API de Speculation Rules, enlaces permanentes, informe del RUM (7 días), insights, páginas propias con no-store (48 h), reglas de renderizado diferido sugeridas para el tema, `prefetch_probe` (`refused` si Cloudflare rechaza las precargas), si Elementor está activo y la última detección de documentos (`lazy_detected`) |
| PUT | `/navigation/settings` | `spec_mode`, `spec_eagerness`, `spec_predictive`, `spec_fallback`, `view_transitions`, `hints_lcp`, `hints_facades`, `rum_rate`, `lazy_elements`, `lazy_documents` (`[{id, mode: v\|i, height}]`, hasta 30). Pasar a prerender y activar los elementos diferidos exigen `confirm` (428) |
| POST | `/navigation/lazy-documents/scan` | Inventaría las páginas de muestra y lista los documentos de Elementor impresos con tamaño, etiquetas, imágenes, dónde (menú, cabecera, pie, contenido) y la sugerencia |
| GET / POST | `/navigation/report?days=7\|30` · `/navigation/insights` | Informe del RUM (desde 0.19.0 con `site`, el p75 del sitio entero por dispositivo, y el peso de la carga en cada fila) · recalcular ahora las páginas predichas y la imagen LCP |
| PUT · POST | `/navigation/budgets` · `/navigation/budgets/check` | Presupuestos de rendimiento (0.19.0): guardar `{budgets: {lcp, inp, cls, ttfb, req, js, css, kb}}` (claves desconocidas, 400) y comparar · comparar ya. `/navigation/status` trae `budgets: {values, defaults, state}` |
| GET | `/compat/report` | Reglas aplicadas y conflictos |
| GET | `/assets/status` | Módulo, reglas, si la previsualización está activa, última tanda de sugerencias, plantillas y tipos de contenido del sitio |
| POST | `/assets/inspect` · `/assets/suggest` | Inventario de una ruta (loopback firmado como visitante anónimo) · inventariar páginas de muestra y calcular sugerencias |
| POST / DELETE | `/assets/rules` · `/assets/rules/{id}/activate\|disable\|test` · `/assets/rules/{id}` | Crear en prueba · activar (`confirm`, 428; guarda quién y cuándo verificó y purga la caché) · desactivar · volver a prueba · eliminar |
| POST | `/assets/preview` | `{on}`: cookie firmada `itb_assets_prev` (HttpOnly, 2 h) para ver las reglas en prueba; purga la caché privada de ese admin (LSCache, WP Rocket) |
| POST | `/assets/scripts` | Carga de scripts (0.14.0): `script_defer`, `script_defer_exclude` (handles), `script_delay` (patrones), `script_delay_timeout` (0–30 s). Recompila, purga la caché y queda en el registro. `GET /assets/status` lo devuelve en `scripts` junto con `cache_defers` y `cache_delays` |
| GET / POST | `/db/status` · `/db/audit` | Última auditoría, lotes de respaldo, ajustes · auditar ahora (solo lectura) |
| POST | `/db/actions/{transients\|action_scheduler\|revisions\|autoload}` | Con el módulo activo y `confirm` (428). `autoload` recibe `options[]` y solo acepta opciones atribuidas a plugins inactivos |
| POST / PUT | `/db/restore/{lote}` · `/db/settings` | Restaurar un lote (`confirm`) · `db_keep_revisions`, `db_schedule`, `db_schedule_days`, `db_as_retention` (0, o 3–30 días de historial de Action Scheduler) |
| GET / POST | `/fleet/status` · `/fleet/sync` | Plantilla, desviaciones, última aplicación, autorización del hub y snapshots · consultar el hub ahora |
| POST | `/fleet/apply` · `/fleet/revert/{id}` | Aplicar la plantilla (`confirm`, 428) · volver a los ajustes de un snapshot (`confirm`) |
| PUT | `/fleet/settings` | `fleet_remote`: autorizar que el hub aplique la plantilla (activarlo exige `confirm`) |
| GET | `/events` | Registro |
| POST | `/safety/kill-switch` · `/safety/bypass-cookie` · `/safety/diag-cookie` · `/safety/safe-mode/exit` | Seguridad operativa |

Endpoint del hub (sin sesión): `POST /imagina-turbo/v1/fleet/remote` con `{action: "status"|"apply", policy_version}`, firmado como las peticiones del sitio al hub (cadena canónica con la ruta que pide el hub, ventana de 5 minutos, nonce no repetido, 60 peticiones por hora). Antes de responder, el sitio descarga la plantilla del propio hub: el cuerpo nunca trae configuración. `apply` exige que la versión coincida (409) y que el sitio lo haya autorizado (403).

Endpoints públicos (frontend), con límite de tasa y sin datos sensibles:
- `POST /?itb-rum=1` — beacon del RUM (fase 6), atendido en el loader MU sin cargar plugins. Valida el origen; 2 KB como máximo; 30 por minuto e IP con APCu. Responde 204, 400, 403 o 429.
- `GET /imagina-turbo/v1/nonce?a[]=...&t=...&s=...` — refresco de nonces para formularios en páginas cacheadas (implementado en la fase 2a). Solo las acciones que la página imprimió firmadas con HMAC (`a` = acciones, `t` = momento de la página, `s` = firma; máximo 20 acciones y 8 días). 30 peticiones por minuto e IP (con APCu o caché de objetos persistente). Respuesta `Cache-Control: private, no-store` y `Cloudflare-CDN-Cache-Control: no-store`; LSCache recibe `no-cache`. Con sesión de WooCommerce, el nonce del visitante anónimo depende del id de sesión de Woo (`nonce_user_logged_out`), así que el endpoint inicializa la sesión de Woo para las acciones `woocommerce*`. Solo se registra con el módulo de caché activo y el refresco encendido.

Todas las rutas del admin envían `litespeed_control_set_nocache` (LSCache, con sus valores por defecto, guardaría en caché privada sus GET) y aseguran el esquema de la base antes de responder.

## Datos

### Opciones (todas autoload = no, salvo indicación)

| Opción | Contenido |
|---|---|
| `itb_settings` | Ajustes de todos los módulos (JSON validado por esquema). Fase 3: `woo_count_selectors`, `woo_total_selectors` (hasta 20 selectores CSS validados) y `woo_recently_viewed`. Fase 4: `shield_*`, `tweaks` (id → bool), `tweak_revisions`, `tweak_autosave`, `firewall_rules`, `firewall_ratelimit`. Fase 6: `rum_rate`, `spec_*`, `view_transitions`, `hints_lcp`, `hints_facades`. 0.14.3–0.15.0: `delay_js_scope`, `lsdelay_timeout`, `hints_fonts`, `ai_write`. 0.16.0–0.17.0: `late_styles`, `fonts_manual`, `lazy_elements`, `lazy_documents`, `cache_fragments`. 0.18.0: `cache_edge_variants`, `zaraz_takeover` (`{ga4, meta}`, lo escribe solo el flujo de Zaraz). 0.19.0: `budgets` (también desde la flota) |
| `itb_cf_token` | Token cifrado (`sodium_crypto_secretbox`, clave derivada de `AUTH_KEY` + `SECURE_AUTH_SALT`), id corto de la clave para detectar rotación, últimos 4 caracteres |
| `itb_cf_state` | Zona, cuenta, plan, permisos de lectura detectados y, por regla propia, el hash de cómo quedó en Cloudflare tras aplicarla (deriva) |
| `itb_site_key` | Clave del sitio para el hub |
| `itb_hub` | Estado del hub: site_id, última hora enviada, fallos, próximo intento, último error |
| `itb_profiler_rollup` | Última hora agregada en `metrics_hourly` |
| `itb_audit_last` | Último resultado de la auditoría de cacheabilidad |
| `itb_purge_state` | Última sincronización de purgas (hora, qué, resultado) |
| `itb_warm_state` | Estado del recalentado (contadores, última pausa por carga) |
| `itb_cf_analytics` | Últimas analíticas del edge (24 h) |
| `itb_cf_drift` | Reglas propias detectadas como modificadas a mano (para avisar una sola vez) |
| `itb_zaraz_state` | Zaraz (0.18.0): IDs que Turbo pasó a Zaraz, herramientas propias escritas, estado previo del e-commerce, si se publicó y cuándo |
| `itb_zaraz_scan` | Último escaneo de la portada (IDs de GA4, etiqueta de Google, GTM y Meta encontrados en el HTML) |
| `itb_budgets_state` | Presupuestos (0.19.0): última comparación, presupuestos usados, p75 del sitio por dispositivo y excesos por plantilla |
| `itb_image_audit` | Auditoría de imágenes (0.19.0): escaneo en curso o terminado (cursor, contadores, listas de las más pesadas y las más grandes) |
| `itb_verifier` | Verificador (0.20.0): URL, id del sitio y la última corrida nocturna ya avisada |
| `itb_verifier_key` | Clave compartida con el verificador, cifrada como el token de Cloudflare (solo sus últimos 4 caracteres se muestran) |
| `itb_turnstile_secret` | Clave secreta de Turnstile (0.21.0), cifrada como el token de Cloudflare. Los demás ajustes de Turnstile (`turnstile_site_key`, `turnstile_forms`, `turnstile_skip`, `turnstile_verified`) van en `itb_settings`, igual que `cf_zone_prefs` y `shield_facets*` |
| `itb_cf_firewall` | Últimos eventos del firewall (24 h) |
| `itb_cf_challenge` | Desde cuándo cada regla propia del firewall está en desafío (a los 7 días se propone bloqueo) |
| `itb_origin_state` | Desde cuándo las muestras distinguen tráfico directo de local (`via_cf` 0 directo, 1 Cloudflare, 2 local, 3 Cloudflare sin IP real) |
| `itb_htaccess_backup` | Copia completa del `.htaccess` antes del último cambio |
| `itb_rum_insights` | Lo que decidió la tarea diaria del RUM: páginas predichas por plantilla, imagen LCP por plantilla (con su id de adjunto o `featured`) y fuentes del primer render por plantilla (0.15.0) |
| `itb_filter_rules` | Reglas de carga condicional: clave, plugins, métodos, peticiones de prueba, estado (`testing`, `active`, `failed`, `disabled`), motivo y evidencia |
| `itb_asset_rules` | Reglas del gestor de assets: handle, tipo, modo (`never`/`only`), plantillas, ids, tipos de contenido, estado (`testing`, `active`, `disabled`), quién y cuándo la verificó |
| `itb_db_audit` | Última auditoría de la base de datos |
| `itb_frag_verify` | Última verificación de la caché de fragmentos (hora, si pasó y el resultado por página). Sin autoload. Las copias viven en la caché de objetos, grupo `itb_frag` (`gen`, `m…` menús, `a…` AJAX) |
| `itb_db_schedule_state` | Última ejecución de la limpieza programada y sus resultados |
| `itb_fleet` | Plantilla de flota recibida (tal como llegó), desviaciones, error de la última consulta, última aplicación y resumen para la telemetría |
| `itb_fleet_snapshots` | Los últimos 10 snapshots de lo que la plantilla puede tocar, antes de cada aplicación |
| `itb_hub_nonces` | Nonces de las peticiones del hub ya vistas (10 minutos) |
| `itb_db_version` | Versión del esquema de tablas (autoload = sí, es pequeña) |

### Tablas (`{prefix}itb_`)

```sql
events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  module VARCHAR(40) NOT NULL,
  type VARCHAR(40) NOT NULL,          -- apply, revert, safe_mode, error, purge, experiment...
  severity TINYINT NOT NULL,          -- 0 info, 1 warning, 2 error
  message VARCHAR(255) NOT NULL,
  context LONGTEXT NULL,              -- JSON
  KEY created_at (created_at), KEY module_type (module, type)
)

route_samples (                       -- crudo, retención 48 h (implementada en la fase 1, esquema v2)
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  request_type VARCHAR(20) NOT NULL,
  route_key VARCHAR(150) NOT NULL,    -- plantilla/endpoint normalizado; 150 para que el índice compuesto
                                      -- quepa en 767 bytes con utf8mb4 (MySQL 5.6 / COMPACT)
  path_hash CHAR(16) NOT NULL,
  status SMALLINT UNSIGNED,
  ms MEDIUMINT UNSIGNED, mem_kb INT UNSIGNED, queries SMALLINT UNSIGNED,
                                      -- ms MEDIUMINT: SMALLINT se queda corto en requests de admin o cron lentos
  cacheable TINYINT, via_cf TINYINT,
  source TINYINT UNSIGNED,            -- 0 aleatoria, 1 intensiva, 2 cookie de diagnóstico (fuera de informes)
  overhead_us INT UNSIGNED,           -- costo propio de la medición
  set_cookies VARCHAR(255) NULL,      -- nombres de las cookies enviadas (auditoría pasiva)
  cache_control VARCHAR(100) NULL,
  phases TEXT NULL,                   -- JSON fase → ms
  plugins_cost TEXT NULL,             -- JSON top 15: [{c: componente, l: carga ms, h: hooks ms, k: KB}]
  KEY created_at (created_at), KEY route (request_type, route_key)
)

metrics_hourly (                      -- retención 30 días
  hour DATETIME NOT NULL, request_type VARCHAR(20) NOT NULL, route_key VARCHAR(150) NOT NULL,
  n INT UNSIGNED, ms_p50 MEDIUMINT, ms_p95 MEDIUMINT, mem_p50 INT, mem_p95 INT, queries_avg SMALLINT,
  PRIMARY KEY (hour, request_type, route_key)
)

rum_hist (                            -- fase 6, esquema v7; retención 30 días
  hour DATETIME NOT NULL, template VARCHAR(40) NOT NULL, device CHAR(1) NOT NULL,
  metric VARCHAR(8) NOT NULL,         -- lcp, inp, cls, ttfb, nav
  bucket SMALLINT UNSIGNED NOT NULL,  -- intervalo (25 ms LCP/TTFB, 10 ms INP, 0,01 CLS) o tipo de navegación
  n INT UNSIGNED NOT NULL,
  PRIMARY KEY (hour, template, device, metric, bucket)
)

rum_events (                          -- fase 6, esquema v7; retención 30 días
  day DATE NOT NULL, kind VARCHAR(8) NOT NULL,   -- lcp, next, nrr
  template VARCHAR(40) NOT NULL, device CHAR(1) NOT NULL,
  value VARCHAR(120) NOT NULL,        -- ruta de la imagen, ruta siguiente o causa de bfcache
  n INT UNSIGNED NOT NULL,
  ip_bits BIGINT UNSIGNED NOT NULL,   -- un bit por hash de IP (sal diaria): visitantes distintos estimados
  PRIMARY KEY (day, kind, template, device, value)
)

purge_queue (                         -- fase 2a, esquema v3
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind CHAR(1) NOT NULL,              -- t tag, u URL, a todo, w recalentar
  item_hash CHAR(32) NOT NULL,        -- md5(kind|item): repetidos se ignoran (INSERT IGNORE)
  item TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY item_hash (item_hash), KEY kind (kind, id)
)

cf_snapshots (                        -- fase 2b, esquema v4; se conservan 50 por zona
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  zone_id VARCHAR(64) NOT NULL,
  reason VARCHAR(100) NOT NULL,
  payload LONGTEXT NOT NULL,          -- JSON: reglas propias por fase (con su posición) + ajustes editables
  KEY zone (zone_id, id)
)

db_backups (                          -- fase 7, esquema v8; se conservan 30 días
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch CHAR(12) NOT NULL,            -- un lote por acción: se restaura entero
  created_at DATETIME NOT NULL,
  action VARCHAR(40) NOT NULL,        -- transients, action_scheduler, revisions, autoload
  tbl VARCHAR(64) NOT NULL,           -- tabla sin prefijo: options, posts, postmeta, actionscheduler_*
  op VARCHAR(10) NOT NULL,            -- insert (volver a insertar la fila) o update (volver a poner los valores)
  row_data LONGTEXT NOT NULL,         -- JSON de la fila tal como estaba
  restored_at DATETIME NULL,
  KEY batch (batch)
)
```

Jobs de limpieza diarios por Action Scheduler (grupo `imagina-turbo`).

## Protocolo con el hub

El hub es un servicio aparte (puede extender el servidor de Imagina Updater). El plugin funciona completo sin él.

- **Autenticación**: cabeceras `X-ITB-Site` (id del sitio), `X-ITB-License` (clave de licencia de Imagina Updater), `X-ITB-Timestamp`, `X-ITB-Nonce` (16 bytes aleatorios en hex) y `X-ITB-Signature` = HMAC-SHA256 sobre la cadena canónica `MÉTODO\nRUTA\nTIMESTAMP\nNONCE\nsha256_hex(cuerpo)` con `itb_site_key`.
  - Concatenar `cuerpo + timestamp` sin separador es ambiguo y no cubre método ni ruta; la cadena canónica evita que una firma válida sirva para otro endpoint.
  - Rechazar timestamps con más de 5 minutos de diferencia y nonces ya vistos en esa ventana (anti-replay). Comparar firmas con `hash_equals`.
- **Endpoints del hub** (versionados):

| Método | Ruta | Uso |
|---|---|---|
| POST | `/v1/sites/register` | Alta del sitio, entrega `site_key` |
| POST | `/v1/telemetry` | Lote horario: métricas agregadas, inventario de plugins/tema (slug + versión), estado de módulos, eventos de severidad ≥ 1. Gzip. |
| POST | `/v1/rum` | Beacons de RUM directos desde el navegador (sin HMAC; validación por origen permitido y límite de tasa) |
| GET | `/v1/compat/latest` | Matriz de compatibilidad + firma ed25519 |
| GET | `/v1/fleet/policy` | Plantilla de configuración estándar para el sitio |
| POST | `/v1/verify/request` | Pedir una corrida del verificador (pruebas de una regla o módulo) |
| GET | `/v1/verify/{id}` | Resultado del verificador |

- **Privacidad**: nunca enviar datos de clientes finales, correos, pedidos, IPs ni contenido de formularios. Solo agregados técnicos.

### Implementación de la fase 1 (cliente)

- **Apagado por defecto**: sin URL del hub no hay ninguna petición. `https` obligatorio salvo `localhost`/`127.0.0.1` (pruebas).
- **Registro** (`POST /v1/sites/register`, sin firma): cuerpo `{site_url, plugin_version, wp_version, php_version}` y cabecera `X-ITB-License`. Respuesta esperada `{site_id, site_key}` (`site_id` de 4–64 caracteres `[A-Za-z0-9_-]`, `site_key` de 32–256). El primer lote lleva las últimas 24 h ya agregadas. Cambiar la URL del hub borra el registro anterior.
- **Telemetría** (`POST /v1/telemetry`, firmada, cuerpo gzip): `{schema: 1, site_id, plugin_version, generated_at, window: {from, to}, environment, inventory: {plugins: [{slug, version}], theme}, modules: {id: bool}, metrics: [filas de metrics_hourly], fleet, budgets: {checked_at, budgets, site, breaches} (0.19.0), events: [{created_at, module, type, severity, message}]}`. Los eventos van **sin contexto** (puede llevar rutas o datos del sitio). Solo horas completas que ya pasaron por el agregado horario (si el agregado lleva más de 2 h parado, no frena el resto del lote).
- **Fallos**: tiempo de espera de 8 s; reintento a 1, 2, 4 y 6 h (máximo); lo que queda fuera de la ventana de 24 h se descarta. Un fallo nunca afecta al sitio: todo corre en Action Scheduler o desde el admin.
- **Hub simulado** para pruebas: `tests/e2e/mock-hub.php` (verifica firma, ventana de 5 min y nonces repetidos; guarda cada lote en JSON).

### Flota (fase 8)

- **Plantilla** (`GET /v1/fleet/policy`, firmada; 204 o 404 = sin plantilla). El hub firma la respuesta con la clave del sitio: `X-ITB-Timestamp` y `X-ITB-Signature` = HMAC sobre `RESPONSE\nRUTA\nTIMESTAMP\nNONCE de la petición\nsha256(cuerpo)`. Una respuesta sin firma válida se descarta y se conserva la plantilla anterior.

```json
{
  "schema": 1,
  "id": "estandar-woo",
  "version": 3,
  "name": "Estándar WooCommerce",
  "settings": { "spec_eagerness": "conservative", "rum_rate": 0.1, "cache_edge_ttl": 86400 },
  "modules": { "cloudflare": true, "speculation": true },
  "tweaks": { "embeds": true },
  "cloudflare": { "rules": [ "cache-static", "cache-html" ], "settings": [ "http3", "early_hints" ] }
}
```

- **Qué puede tocar**: ajustes de una lista blanca (`FleetPolicy::SETTINGS`, nunca el hub, el prefijo de tags, los dominios de staging ni estados temporales), módulos y ajustes rápidos seguros o moderados, y reglas de Cloudflare por nombre de plantilla (el sitio las genera con sus datos) o ajustes de zona por id. Lo invasivo y lo desconocido se ignora y se informa.
- **Telemetría**: el lote lleva `fleet: {policy, version, applied_version, drift: ["kind:clave"], remote}`, sin valores.
- **Lotes**: el hub llama a `/fleet/remote` de cada sitio (primero `status`, luego `apply` con la versión). Cada sitio aplica con snapshot y evento; las reglas de Cloudflare cambiadas a mano en el panel y todo lo de Cloudflare con el módulo apagado quedan fuera.
