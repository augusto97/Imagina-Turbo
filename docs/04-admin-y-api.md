# 04 — Admin, API, datos y hub

## Pantallas del admin

App React montada en `admin.php?page=imagina-turbo`, con navegación lateral. Estética tipo Linear/Vercel, shadcn/ui con prefijo `itb-`, textos en español. Cada función muestra su **nivel de riesgo** (insignia verde/ámbar/roja) y su **estado de verificación**.

| Pantalla | Contenido |
|---|---|
| **Panel** | Estado general (plugin de caché detectado, Cloudflare conectado, modo seguro). Métricas clave antes/después: TTFB p75, LCP p75 móvil, tasa de aciertos del edge, memoria media por request, requests a PHP por hora, bloqueos del firewall. Alertas pendientes. |
| **Diagnóstico** | Perfilador: ranking de plugins por costo (ms y MB) por tipo de request; fases del request; consultas; endpoints más pesados. Auditoría de `Set-Cookie` en páginas cacheables. Causas de bfcache. |
| **Módulos** | Tarjetas por módulo con interruptor, riesgo, descripción, estado de verificación y enlace a su configuración. |
| **Carga condicional** | Reglas por tipo de request/endpoint. Candidatos sugeridos con su ahorro estimado. Experimentos en curso y su evidencia. Botón "probar regla" (lanza el verificador). |
| **Caché** | Adaptador activo, TTL, tags, mapa de purga, cola de purgas, recalentado (fuentes, progreso, pausa por carga), purga manual por URL/tag/todo. |
| **Cloudflare** | Conexión del token (asistente), zona y plan, ajustes de zona, reglas propias y de terceros (solo lectura), **diff** de cambios propuestos, aplicar, historial de snapshots con revertir, deriva detectada. |
| **Firewall** | Reglas por capa (edge, servidor, PHP), modo de cada regla (desafío/bloqueo), eventos recientes, excepciones (webhooks, IPs), verificación de origen cerrado. |
| **WooCommerce** | Carrito por Store API (selectores del mini-carrito, prueba en vivo), cookies neutralizadas, páginas excluidas, geolocalización. |
| **Navegación instantánea** | Modo y eagerness, exclusiones, prefetch predictivo (top transiciones), View Transitions, métricas de prerender. |
| **Assets** | Gestor por ruta (vista tipo script manager con previsualización). |
| **Ajustes rápidos** | Lista de tweaks con interruptor individual. |
| **Base de datos** | Auditoría, acciones con respaldo, programación. |
| **Compatibilidad** | Plugins/tema detectados, reglas de la matriz aplicadas, conflictos y solapamientos, versión de la matriz y firma. |
| **Registro** | Todos los eventos: cambios, aplicaciones, reversiones, modo seguro, errores de API. Filtros y exportación. |
| **Avanzado** | Kill switch, cookie de bypass para el admin actual, exportar/importar ajustes (sin token), conexión con el hub, licencia (Imagina Updater), modo depuración. |

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
| PUT / POST | `/profiler/settings` · `/profiler/intensive` | Tasa de muestreo · muestreo intensivo de 15 min |
| GET / POST | `/diagnostics/audit` | Última auditoría de cacheabilidad · ejecutarla ahora (loopback) |
| GET / PUT / DELETE | `/hub` | Estado · URL del hub · olvidar el registro |
| POST | `/hub/register` · `/hub/send` | Registrar el sitio · enviar telemetría ahora |
| GET / POST / DELETE | `/plugin-filter/rules` | Reglas de carga condicional |
| POST | `/plugin-filter/experiments` | Lanzar experimento de una regla |
| GET | `/cache/status` | Adaptador detectado, ajustes, cola, última sincronización, recalentado |
| PUT | `/cache/settings` | Modo (`sync` / `headers`), TTL, SWR, SIE, refresco de nonces, recalentado. Pasar a `headers` exige `confirm: true` (428 sin ella) |
| POST | `/cache/purge` | Purga manual (`url` / `tag` / `all`; `all` exige `confirm: true`) |
| POST | `/cache/warm` | `seed` (recalentar el sitemap) / `stop` (vaciar la cola de recalentado) |
| POST | `/cache/inspect` | Pide una URL del sitio como anónimo y devuelve sus cabeceras de caché |
| POST | `/cloudflare/connect` | Guardar y verificar token |
| GET | `/cloudflare/zone` | Plan, ajustes, reglas |
| GET | `/cloudflare/plan` | Diff de cambios propuestos |
| POST | `/cloudflare/apply` | Aplicar (todos o una selección) |
| GET / POST | `/cloudflare/snapshots` · `/cloudflare/snapshots/{id}/restore` | Historial y reversión |
| GET | `/cloudflare/analytics` | Métricas del edge |
| GET / PUT | `/firewall/rules` | Reglas y modo |
| GET | `/firewall/origin-check` | Estado del cierre del origen |
| GET | `/compat/report` | Reglas aplicadas y conflictos |
| GET / POST | `/db/audit` · `/db/actions/{task}` | Auditoría y acciones |
| GET | `/events` | Registro |
| POST | `/safety/kill-switch` · `/safety/bypass-cookie` · `/safety/diag-cookie` · `/safety/safe-mode/exit` | Seguridad operativa |

Endpoints públicos (frontend), con límite de tasa y sin datos sensibles:
- `GET /imagina-turbo/v1/nonce?a[]=...&t=...&s=...` — refresco de nonces para formularios en páginas cacheadas (implementado en la fase 2a). Solo las acciones que la página imprimió firmadas con HMAC (`a` = acciones, `t` = momento de la página, `s` = firma; máximo 20 acciones y 8 días). 30 peticiones por minuto e IP (con APCu o caché de objetos persistente). Respuesta `Cache-Control: private, no-store` y `Cloudflare-CDN-Cache-Control: no-store`; LSCache recibe `no-cache`. Con sesión de WooCommerce, el nonce del visitante anónimo depende del id de sesión de Woo (`nonce_user_logged_out`), así que el endpoint inicializa la sesión de Woo para las acciones `woocommerce*`. Solo se registra con el módulo de caché activo y el refresco encendido.

Todas las rutas del admin envían `litespeed_control_set_nocache`: LSCache, con sus valores por defecto, guardaría en caché privada sus GET.

## Datos

### Opciones (todas autoload = no, salvo indicación)

| Opción | Contenido |
|---|---|
| `itb_settings` | Ajustes de todos los módulos (JSON validado por esquema) |
| `itb_cf_token` | Token cifrado |
| `itb_cf_state` | zone_id, plan, ids de rulesets, hashes de reglas propias |
| `itb_site_key` | Clave del sitio para el hub |
| `itb_hub` | Estado del hub: site_id, última hora enviada, fallos, próximo intento, último error |
| `itb_profiler_rollup` | Última hora agregada en `metrics_hourly` |
| `itb_audit_last` | Último resultado de la auditoría de cacheabilidad |
| `itb_purge_state` | Última sincronización de purgas (hora, qué, resultado) |
| `itb_warm_state` | Estado del recalentado (contadores, última pausa por carga) |
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

rum_hourly (                          -- si no hay hub
  hour DATETIME NOT NULL, template VARCHAR(100) NOT NULL, device CHAR(1) NOT NULL,
  n INT UNSIGNED, lcp_p75 SMALLINT, inp_p75 SMALLINT, cls_p75 DECIMAL(5,3), ttfb_p75 SMALLINT,
  prerendered INT UNSIGNED, bfcache INT UNSIGNED,
  PRIMARY KEY (hour, template, device)
)

purge_queue (                         -- fase 2a, esquema v3
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind CHAR(1) NOT NULL,              -- t tag, u URL, a todo, w recalentar
  item_hash CHAR(32) NOT NULL,        -- md5(kind|item): repetidos se ignoran (INSERT IGNORE)
  item TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY item_hash (item_hash), KEY kind (kind, id)
)

cf_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  reason VARCHAR(100) NOT NULL,
  payload LONGTEXT NOT NULL           -- JSON: reglas propias por fase + ajustes tocados
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
- **Telemetría** (`POST /v1/telemetry`, firmada, cuerpo gzip): `{schema: 1, site_id, plugin_version, generated_at, window: {from, to}, environment, inventory: {plugins: [{slug, version}], theme}, modules: {id: bool}, metrics: [filas de metrics_hourly], events: [{created_at, module, type, severity, message}]}`. Los eventos van **sin contexto** (puede llevar rutas o datos del sitio). Solo horas completas que ya pasaron por el agregado horario (si el agregado lleva más de 2 h parado, no frena el resto del lote).
- **Fallos**: tiempo de espera de 8 s; reintento a 1, 2, 4 y 6 h (máximo); lo que queda fuera de la ventana de 24 h se descarta. Un fallo nunca afecta al sitio: todo corre en Action Scheduler o desde el admin.
- **Hub simulado** para pruebas: `tests/e2e/mock-hub.php` (verifica firma, ventana de 5 min y nonces repetidos; guarda cada lote en JSON).
