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
| GET | `/profiler/summary` · `/profiler/plugins` · `/profiler/routes` | Datos del perfilador |
| GET / POST / DELETE | `/plugin-filter/rules` | Reglas de carga condicional |
| POST | `/plugin-filter/experiments` | Lanzar experimento de una regla |
| POST | `/cache/purge` | Purga manual (`url` / `tag` / `all`) |
| POST | `/cache/warmup` | Iniciar/pausar recalentado |
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
| POST | `/safety/kill-switch` · `/safety/bypass-cookie` · `/safety/safe-mode/exit` | Seguridad operativa |

Endpoints públicos (frontend), con límite de tasa y sin datos sensibles:
- `GET /imagina-turbo/v1/nonce?action=...` — refresco de nonces para formularios en páginas cacheadas. Solo acciones en lista blanca (formularios detectados). Respuesta `Cache-Control: private, no-store`.

## Datos

### Opciones (todas autoload = no, salvo indicación)

| Opción | Contenido |
|---|---|
| `itb_settings` | Ajustes de todos los módulos (JSON validado por esquema) |
| `itb_cf_token` | Token cifrado |
| `itb_cf_state` | zone_id, plan, ids de rulesets, hashes de reglas propias |
| `itb_site_key` | Clave del sitio para el hub |
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

route_samples (                       -- crudo, retención 48 h
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  request_type VARCHAR(20) NOT NULL,
  route_key VARCHAR(191) NOT NULL,    -- plantilla/endpoint normalizado
  path_hash CHAR(16) NOT NULL,
  ms SMALLINT UNSIGNED, mem_kb INT UNSIGNED, queries SMALLINT UNSIGNED,
  cacheable TINYINT, via_cf TINYINT,
  plugins_cost TEXT NULL,             -- JSON top 10
  KEY created_at (created_at), KEY route (request_type, route_key)
)

metrics_hourly (                      -- retención 30 días
  hour DATETIME NOT NULL, request_type VARCHAR(20) NOT NULL, route_key VARCHAR(191) NOT NULL,
  n INT UNSIGNED, ms_p50 SMALLINT, ms_p95 SMALLINT, mem_p50 INT, mem_p95 INT, queries_avg SMALLINT,
  PRIMARY KEY (hour, request_type, route_key)
)

rum_hourly (                          -- si no hay hub
  hour DATETIME NOT NULL, template VARCHAR(100) NOT NULL, device CHAR(1) NOT NULL,
  n INT UNSIGNED, lcp_p75 SMALLINT, inp_p75 SMALLINT, cls_p75 DECIMAL(5,3), ttfb_p75 SMALLINT,
  prerendered INT UNSIGNED, bfcache INT UNSIGNED,
  PRIMARY KEY (hour, template, device)
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

- **Autenticación**: cabeceras `X-ITB-Site` (id del sitio), `X-ITB-License` (clave de licencia de Imagina Updater), `X-ITB-Timestamp` y `X-ITB-Signature` = HMAC-SHA256(cuerpo + timestamp, `itb_site_key`). Rechazar timestamps con más de 5 minutos de diferencia.
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
