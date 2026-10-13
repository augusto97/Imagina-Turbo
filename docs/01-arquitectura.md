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
   1. Revisa el kill switch (constante `ITB_DISABLE`, archivo `wp-content/imagina-turbo/disable`, cookie de bypass de admin válida). Si aplica, termina sin hacer nada.
   2. Revisa el modo seguro (`wp-content/imagina-turbo/safe-mode.flag`). Si existe, solo carga el perfilador pasivo.
   3. Incluye `wp-content/imagina-turbo/config.php` (array PHP, OPcache). Si no existe o la versión no coincide, entra en modo seguro.
   4. Clasifica el request con `RequestClassifier` (solo `$_SERVER`, `$_GET`, constantes ya definidas).
   5. Ejecuta la **guardia PHP** del escudo (bloqueos baratos: XML-RPC, enumeración de autores, 404 de assets) antes de cargar plugins.
   6. Registra el filtro de `option_active_plugins` si la carga condicional está activa para ese tipo de request.
   7. Registra el `shutdown` para detectar fatales.
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
| `StaticMiss` | extensión de asset (`.jpg .png .webp .avif .css .js .woff2 .svg .ico .map`) que llegó a PHP: el archivo no existe |
| `Checkout`, `Cart`, `Account` | rutas resueltas de las páginas de Woo (config compilada, incluye traducciones) |

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

## Modo seguro y kill switch

- **Kill switch** (desactiva todo excepto el admin del plugin):
  - `define('ITB_DISABLE', true);` en `wp-config.php`.
  - Archivo `wp-content/imagina-turbo/disable`.
  - Cookie `itb_bypass` = `expira|hmac(AUTH_KEY)`, emitida desde el admin. Se valida en el loader sin funciones pluggable. Sirve para probar el sitio "como si el plugin no existiera" solo para ese admin.
- **Modo seguro automático**: el loader registra `register_shutdown_function`; si `error_get_last()` es fatal (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR) en un request donde se filtraron plugins, incrementa un contador en archivo con ventana de 5 minutos. Con 3 fatales en la ventana, crea `safe-mode.flag`, registra el evento y notifica (correo al admin + hub).
- Al salir del modo seguro (manual desde el admin), se recompila la configuración.

## Coexistencia

| Plugin detectado | Comportamiento |
|---|---|
| LiteSpeed Cache | Adaptador LiteSpeed. Nuestro plugin no hace caché de página ni optimiza HTML. Sincroniza purgas en ambos sentidos. |
| WP Rocket | Adaptador WP Rocket. Igual que arriba. Además, alimenta sus exclusiones de Delay JS con la matriz (`rocket_delay_js_exclusions`). |
| Perfmatters | Apaga carga condicional de plugins, gestor de assets y ajustes equivalentes. Muestra aviso de migración. |
| Autoptimize, W3TC, WP Super Cache, WP Fastest Cache, FlyingPress, NitroPack, WP-Optimize, Breeze, Hummingbird, SG Optimizer | Aviso de conflicto. Apaga módulos equivalentes. Recomienda un solo plugin de caché. |
| Plugin oficial de Cloudflare | Se coordina: nuestro módulo gestiona reglas y purga; recomendar desactivar APO del plugin oficial si está activo. |

## Almacenamiento

- Tablas propias (prefijo `{$wpdb->prefix}itb_`): `events`, `metrics_hourly`, `rum_hourly`, `route_samples`, `cf_snapshots`. Detalle en `docs/04-admin-y-api.md`.
- Archivos: `wp-content/imagina-turbo/` (config compilada, banderas, contador de fatales, caché de la matriz). Proteger con `index.php` vacío y reglas de denegación.
- Retención: rollups horarios 30 días, eventos 180 días, muestras crudas 48 h.
