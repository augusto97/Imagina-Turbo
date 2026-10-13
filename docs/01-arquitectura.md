# 01 — Arquitectura

## Estructura de carpetas

```
imagina-turbo/
├── imagina-turbo.php            # Bootstrap: constantes, autoload, registro del plugin
├── uninstall.php                # Limpieza: tablas, opciones, MU loader, archivos compilados
├── composer.json
├── package.json / vite.config.ts
├── mu-loader/
│   └── imagina-turbo-loader.php # Se copia a wp-content/mu-plugins/ al activar
├── src/
│   ├── Core/
│   │   ├── Plugin.php
│   │   ├── Container.php
│   │   ├── ModuleInterface.php
│   │   ├── ModuleRegistry.php
│   │   ├── RiskLevel.php                 # enum: Safe, Moderate, Invasive
│   │   ├── Config/
│   │   │   ├── Settings.php              # Lectura/escritura de la opción de ajustes (admin)
│   │   │   ├── ConfigCompiler.php        # Genera wp-content/imagina-turbo/config.php
│   │   │   └── CompiledConfig.php        # Lectura tipada del archivo compilado
│   │   ├── Request/
│   │   │   ├── RequestType.php           # enum (ver abajo)
│   │   │   └── RequestClassifier.php     # Sin WordPress cargado: solo $_SERVER/$_GET
│   │   ├── Environment/
│   │   │   ├── Detector.php              # Servidor, plugin de caché, Woo, idioma, moneda, Cloudflare
│   │   │   └── OverlapGuard.php          # Apaga módulos que se solapan con otros plugins
│   │   ├── Safety/
│   │   │   ├── KillSwitch.php
│   │   │   ├── SafeMode.php
│   │   │   └── AdminBypassCookie.php     # Cookie HMAC para desactivar por request
│   │   └── Events/EventLog.php           # Registro de cambios + snapshots
│   ├── Compat/
│   │   ├── Matrix.php                    # Carga y valida data/compat-matrix-*.json
│   │   ├── Resolver.php                  # Reglas aplicables a los plugins/tema activos
│   │   └── RemoteUpdater.php             # Descarga matriz firmada desde el hub
│   ├── Modules/
│   │   ├── Profiler/
│   │   ├── PluginFilter/
│   │   ├── CacheCoordinator/
│   │   │   ├── Adapters/{LiteSpeedAdapter,WpRocketAdapter,NullAdapter}.php
│   │   │   ├── TagBuilder.php
│   │   │   ├── HeaderEmitter.php
│   │   │   ├── PurgeMap.php
│   │   │   ├── PurgeQueue.php
│   │   │   └── Warmer.php
│   │   ├── Cloudflare/
│   │   │   ├── Client.php
│   │   │   ├── TokenVault.php
│   │   │   ├── ZoneInspector.php
│   │   │   ├── RulesetManager.php
│   │   │   ├── Templates/{CacheRules,WafRules,RateLimitRules,ZoneSettings}.php
│   │   │   ├── PlanDiff.php
│   │   │   ├── Snapshot.php
│   │   │   ├── Analytics.php
│   │   │   └── Optimizer.php
│   │   ├── Firewall/{EdgeRuleBuilder,HtaccessWriter,PhpGuard,OriginLockCheck}.php
│   │   ├── WooCart/
│   │   ├── Speculation/
│   │   ├── Shield/
│   │   ├── Tweaks/
│   │   ├── AssetManager/
│   │   ├── FrontendHints/
│   │   ├── Database/
│   │   ├── Rum/
│   │   └── Hub/
│   ├── Integrations/ImaginaSearch.php
│   ├── Licensing/ImaginaUpdater.php
│   ├── Rest/                             # Controladores REST del admin
│   ├── Admin/{Menu,AssetsLoader}.php
│   ├── Repository/                       # Acceso a tablas propias
│   └── Support/
├── admin/                                # App React (src/, components/ui con prefijo itb-)
├── assets/js/                            # Scripts frontend vanilla
│   ├── itb-cart.js                       # Mini-carrito por Store API
│   ├── itb-nonce.js                      # Refresco de nonces en páginas cacheadas
│   ├── itb-rum.js                        # Métricas reales muestreadas
│   └── itb-prefetch-fallback.js          # Prefetch para navegadores sin Speculation Rules
├── data/compat-matrix-v1.json
├── languages/
├── tests/{Unit,Integration,Performance}
└── docs/
```

## Ciclo de vida de un request

1. WordPress carga los MU-plugins. **`imagina-turbo-loader.php`** se ejecuta primero (nombre con prefijo para orden alfabético; aun así, no asumir que es el primero).
2. El loader:
   0. Comprueba que el plugin principal existe en disco (`WP_PLUGIN_DIR/imagina-turbo/imagina-turbo.php`). Si se borró por FTP sin pasar por `uninstall.php`, el loader queda huérfano: no hace nada y no lanza errores. El plugin principal, al cargarse, comprueba además que está en `active_plugins`; si el loader se ejecuta con el plugin inactivo, tampoco hace nada (la config compilada se borra al desactivar).
   1. Revisa el kill switch (constante `ITB_DISABLE`, archivo `wp-content/imagina-turbo/disable`, cookie de bypass de admin válida). Si aplica, termina sin hacer nada.
   2. Revisa el modo seguro (`wp-content/imagina-turbo/safe-mode.flag`). Si existe, termina sin hacer nada más. *(Cambio en la fase 1: el borrador decía "solo carga el perfilador pasivo". Se descartó porque el perfilador envuelve callbacks de otros plugins y escribe en la base de datos; en modo seguro el plugin no debe tocar nada del sitio. El diagnóstico se retoma al salir del modo seguro.)*
   3. Incluye `wp-content/imagina-turbo/config.php` (array PHP, OPcache). Si no existe o la versión no coincide, no aplica nada en el frontend (sin guardia ni filtros) y el plugin principal la recompila en el siguiente request de admin o cron. No se activa el modo seguro por esto: una config ausente no es un fallo, es un estado inicial.
   4. Clasifica el request con `RequestClassifier`. Lee solo `$_SERVER`, `$_GET`, `$_POST['action']` (admin-ajax recibe `action` casi siempre por POST; PHP ya parseó el cuerpo) y constantes ya definidas. La ruta se normaliza quitando el path de `home_url` (WordPress en subcarpeta) y el prefijo REST real (`rest_get_url_prefix()`, que puede cambiar por filtro) viene de la config compilada.
   5. Ejecuta la **guardia PHP** del escudo (bloqueos baratos: XML-RPC, enumeración de autores, 404 de assets) antes de cargar plugins.
   6. Registra el filtro de `option_active_plugins` si la carga condicional está activa para ese tipo de request.
   7. Registra el `shutdown` para detectar fatales.
   8. Decide si el request entra en la muestra del perfilador (cookie de diagnóstico válida, muestreo intensivo vigente o un número aleatorio contra la tasa). Solo si entra, carga `Collector` (ver `docs/02`, módulo 1).
3. Se cargan los plugins. El plugin principal registra solo los módulos activos para ese tipo de request.
4. Los módulos actúan por hooks. Ninguno usa output buffering.

## Tipos de request (`RequestType`)

| Tipo | Detección (sin WordPress cargado) |
|---|---|
| `FrontendHtml` | GET/HEAD que no cae en ningún otro tipo |
| `WcAjax` | `$_GET['wc-ajax']` presente (guardar el endpoint) |
| `AdminAjax` | ruta termina en `/wp-admin/admin-ajax.php` (guardar `action`) |
| `Rest` | ruta empieza por `/wp-json/` o existe `rest_route` (guardar namespace) |
| `StoreApi` | REST con namespace `wc/store` |
| `Cron` | `wp-cron.php` o `DOING_CRON` |
| `Cli` | `WP_CLI` |
| `Admin` | ruta bajo `/wp-admin/` (excepto admin-ajax) |
| `Login` | `wp-login.php` o slug de login personalizado (resuelto en la config compilada) |
| `XmlRpc` | `xmlrpc.php` |
| `Feed`, `Sitemap`, `Robots` | patrones de ruta conocidos |
| `StaticMiss` | extensión de asset (`.jpg .jpeg .png .gif .webp .avif .css .js .woff .woff2 .ttf .svg .ico .map`) que llegó a PHP: el archivo no existe. **Excepciones** (se tratan como `FrontendHtml`): `/favicon.ico` (el core redirige al ícono del sitio desde WP 5.4) y las rutas que la matriz declara en `static_miss_passthrough` (plugins que generan imágenes o assets bajo demanda cuando el archivo no existe). |
| `Checkout`, `Cart`, `Account` | rutas resueltas de las páginas de Woo (config compilada, incluye traducciones) |
| `Other` | frontend con método distinto de GET/HEAD (formularios que envían a una página) y scripts del core que no son páginas (`wp-comments-post.php`, `wp-signup.php`…). Nunca se cachea ni se filtra |

Orden de evaluación: CLI → cron → XML-RPC → admin-ajax → admin → login → wc-ajax → REST → scripts del core → checkout/carrito/cuenta (cualquier método) → `Other` (no GET/HEAD) → robots → sitemap → feed → `StaticMiss` → `FrontendHtml`. El namespace REST se toma hasta el segmento de versión (`wc/store/v1`, `wp/v2`, `oembed/1.0`).

## Configuración compilada

- Los ajustes del admin viven en la opción `itb_settings` (**autoload = no**).
- `ConfigCompiler` genera `wp-content/imagina-turbo/config.php` cada vez que cambian los ajustes, se activa o desactiva un plugin, cambia el tema, cambian las páginas de Woo o se actualiza la matriz.
- Escritura atómica: archivo temporal + `rename()`, luego `opcache_invalidate()`.
- Contenido (ejemplo):

```php
<?php return [
  'v'        => 3,                       // versión del formato
  'hash'     => 'a1b2c3',                // hash de ajustes + plugins activos
  'modules'  => ['profiler' => true, 'woo_cart' => true, /* ... */],
  'paths'    => ['cart' => ['/carrito/'], 'checkout' => ['/finalizar-compra/'], 'login' => ['/acceso/']],
  'filter'   => [                         // carga condicional, solo plugins a QUITAR
     'WcAjax:add_to_cart' => ['elementor-pro/elementor-pro.php', 'wordpress-seo/wp-seo.php'],
     'Rest:imagina-search/v1' => [/* ... */],
  ],
  'shield'   => ['xmlrpc' => 'block', 'author_enum' => 'block', 'static_miss' => true],
  'tags'     => ['prefix' => 'k7'],       // prefijo por sitio para Cache-Tag
  'bypass_cookies' => ['wordpress_logged_in_', 'wp-postpass_', 'comment_author_'],
];
```

- Las regex y la matriz completa **nunca** se leen en el frontend; el compilador deja solo lo que aplica a ese sitio.
- Además de `v` y `hash`, la config incluye `home_path` (path de `home_url`, sin barra final) y `rest_prefix` para que el clasificador no dependa de WordPress.
- **Multisite**: un archivo por sitio, `config-{blog_id}.php`. El loader resuelve el `blog_id` con `$GLOBALS['blog_id']` (ya definido cuando cargan los MU-plugins). En instalación simple es `config.php`. La carga condicional en multisite queda fuera del alcance hasta la fase 5.

### Escrituras en el frontend (excepción acotada a la regla 1)

La regla "sin consultas en el frontend" admite solo estas excepciones, todas muestreadas o fuera del camino crítico:

- Muestras del perfilador (1 %, con la auditoría pasiva de `Set-Cookie` en la misma fila): se escriben en `shutdown` con un solo `INSERT`. *(Cambio en la fase 1: no se llama a `fastcgi_finish_request()`/`litespeed_finish_request()`. Cerrar la respuesta antes de tiempo hace que se pierda la salida que otros plugins emiten en `shutdown`, y ese fallo aparecería solo en el 1 % de los requests, imposible de diagnosticar. El `INSERT` cuesta décimas de milisegundo y solo en la muestra.)*
- Caché de atribución del perfilador (`wp-content/imagina-turbo/profiler-symbols.php`): archivo PHP escrito de forma atómica solo en requests muestreados que descubren funciones o clases nuevas; se sirve desde OPcache.
- Contador de fatales del modo seguro: archivo, no base de datos.
- Endpoint RUM local (solo sin hub): `SHORTINIT`, un `INSERT ... ON DUPLICATE KEY UPDATE`.
- Cola de purga (fase 2a): solo cuando un request del frontend **cambia contenido** (un pedido en el checkout que baja el stock, un comentario aprobado automáticamente). Se agrupa todo en `shutdown` en un `INSERT IGNORE` por tipo. Ver una página nunca escribe.

Cualquier otra escritura en el frontend es un error.

## Modo seguro y kill switch

- **Kill switch** (desactiva todo excepto el admin del plugin):
  - `define('ITB_DISABLE', true);` en `wp-config.php`.
  - Archivo `wp-content/imagina-turbo/disable`.
  - Cookie `itb_bypass` = `user_id|expira|hmac_sha256(user_id|expira, clave)`, con `clave = hash_hmac('sha256', 'itb_bypass', AUTH_KEY . AUTH_SALT)`. Emitida desde el admin (`HttpOnly`, `Secure` si el sitio es HTTPS, `SameSite=Lax`, máximo 8 h). Se valida en el loader sin funciones pluggable y con `hash_equals`. Sirve para probar el sitio "como si el plugin no existiera" solo para ese admin.
  - **Ojo con el edge**: la cookie no evita que Cloudflare entregue páginas ya cacheadas. Por eso `itb_bypass` forma parte de las `bypass_cookies` de la config y de la expresión de la regla `itb-cache-html` (ver `docs/03`).
- **Modo seguro automático**: el loader registra `register_shutdown_function`; si `error_get_last()` es fatal (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR) **y** el request filtró plugins o el archivo del error está dentro del propio plugin, incrementa un contador en archivo con ventana de 5 minutos (escritura con `flock`). Con 3 fatales en la ventana, crea `safe-mode.flag`, registra el evento y notifica (correo al admin + hub). Los fatales ajenos (otro plugin que falla por sí solo) no cuentan: no es culpa nuestra y el modo seguro no los arreglaría.
- Al salir del modo seguro (manual desde el admin), se borra el contador y se recompila la configuración.

## Coexistencia

| Plugin detectado | Comportamiento |
|---|---|
| LiteSpeed Cache | Adaptador LiteSpeed. Nuestro plugin no hace caché de página ni optimiza HTML. Sincroniza purgas en ambos sentidos. |
| WP Rocket | Adaptador WP Rocket. Igual que arriba. Además, alimenta sus exclusiones de Delay JS con la matriz (`rocket_delay_js_exclusions`). |
| Perfmatters | Apaga carga condicional de plugins, gestor de assets y ajustes equivalentes. Muestra aviso de migración. |
| Autoptimize, W3TC, WP Super Cache, WP Fastest Cache, FlyingPress, NitroPack, WP-Optimize, Breeze, Hummingbird, SG Optimizer | Aviso de conflicto. Apaga módulos equivalentes. Recomienda un solo plugin de caché. |
| Plugin oficial de Cloudflare | Se coordina: nuestro módulo gestiona reglas y purga; recomendar desactivar APO del plugin oficial si está activo. |
| Plugins que filtran `active_plugins` (Perfmatters MU, Plugin Organizer, Freesoul Deactivate Plugins, Asset CleanUp Pro con descarga de plugins) | Apaga `PluginFilter` sin excepción: solo un plugin puede filtrar `active_plugins`. Lista en `overlapping_plugins.plugin_filtering` de la matriz. |

Mapeo de solapamientos (en `OverlapGuard`, curado): `plugin_filtering` → apaga `PluginFilter`; Perfmatters → `PluginFilter`, `AssetManager` y `Tweaks`; FlyingPress y NitroPack → `Speculation` y `FrontendHints`; el resto de plugins de caché y optimizadores antiguos solo generan aviso (no hacemos minificación ni caché de página). Más de un plugin de caché de página activo genera una alerta.

`OverlapGuard` lee solo las listas de `overlapping_plugins` por módulo. LiteSpeed Cache y el plugin oficial de Cloudflare **nunca** se tratan como conflicto aunque aparezcan en listas heredadas: se coordinan.

## Tareas en segundo plano

Action Scheduler (incluido vía Composer) se carga solo en admin, admin-ajax (su ejecutor asíncrono), cron y CLI; en el frontend no se paga su costo. Si WooCommerce está activo, ya viene cargado por él. Si Action Scheduler no está disponible, se usa WP-Cron como respaldo y se migra solo cuando aparece.

## Licencia (Imagina Updater)

Verificado en `augusto97/imagina-updater` (commit 617d439): no hay SDK que integrar. Al subir el ZIP marcado como Premium, la extensión de licencias **inyecta un bloque de protección en el archivo principal**, justo después de la comprobación de `ABSPATH`; si la licencia no es válida, ese bloque hace `return` y el resto del archivo no se ejecuta. Consecuencias para este plugin:

- `imagina-turbo.php` no declara `strict_types` ni `namespace`, conserva `if ( ! defined( 'ABSPATH' ) ) { exit; }` seguido de salto de línea, y es el único `.php` de la raíz con `Plugin Name:` (el injector toma el primero que encuentre). `uninstall.php` no debe contener esa cadena.
- Slug = `Text Domain` (`imagina-turbo`); clase inyectada `ILP_8491d0eb`; licencia en la opción `ilp_license_8491d0eb`. `Licensing\ImaginaUpdater` solo lee ese estado.
- Si el archivo principal no arranca (licencia, PHP antiguo), el loader MU lo detecta en el siguiente request de admin y borra la config compilada: deja de aplicar nada hasta que el plugin vuelva a arrancar.
- **Costo a tener en cuenta (del lado de imagina-updater):** el código inyectado llama a `get_transient()` en cada request. Sin caché de objetos persistente, un transient con expiración no es autoload y cuesta consultas a la base en el frontend, en contra de la regla 1 de este proyecto. Conviene que imagina-updater use una opción autoload pequeña o la caché de objetos.

## Almacenamiento

- Tablas propias (prefijo `{$wpdb->prefix}itb_`): `events`, `metrics_hourly`, `rum_hourly`, `route_samples`, `cf_snapshots`. Detalle en `docs/04-admin-y-api.md`.
- Archivos: `wp-content/imagina-turbo/` (config compilada, banderas, contador de fatales, caché de la matriz). Proteger con `index.php` vacío y reglas de denegación.
- Retención: rollups horarios 30 días, eventos 180 días, muestras crudas 48 h.
