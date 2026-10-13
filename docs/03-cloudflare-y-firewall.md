# 03 — Cloudflare y firewall

> **Importante para la implementación:** todos los endpoints, fases, campos y límites de este documento deben verificarse contra la documentación vigente de Cloudflare (developers.cloudflare.com) antes de codificarlos. Los límites por plan cambian: guardarlos en una tabla actualizable desde el hub, nunca fijos en el código.

## 1. Conexión y token

- El usuario crea un **API Token** (no la Global API Key) limitado a la zona, con permisos:
  - Zone → Zone: Read
  - Zone → Zone Settings: Edit
  - Zone → Cache Rules: Edit (o el permiso de Rulesets equivalente)
  - Zone → Zone WAF: Edit (reglas personalizadas y rate limiting)
  - Zone → Cache Purge: Purge
  - Zone → Analytics: Read
  - Zone → Config Rules / Transform Rules: Edit (solo si se usan; ver 4.3)
- El admin muestra un enlace a la plantilla de creación de token con instrucciones paso a paso.
- `TokenVault`: cifrar con `sodium_crypto_secretbox` usando una clave derivada de `AUTH_KEY` y `SECURE_AUTH_SALT`. Guardar en una opción autoload = no. Mostrar solo los últimos 4 caracteres. Nunca incluir en exportaciones.
  - **Rotación de salts**: si alguien cambia las claves de `wp-config.php` (plugins de seguridad, limpieza tras un hackeo), el token ya no se puede descifrar. Guardar junto al cifrado un identificador de la clave (`hash` corto de la clave derivada); si no coincide, marcar Cloudflare como "desconectado: reconectar el token", registrar el evento y no reintentar.
- Verificación: `GET /user/tokens/verify`, luego `GET /zones?name={dominio}` para obtener el `zone_id` y `GET /zones/{zone_id}` para el plan.
  - *Implementado en la fase 2b:* primero `GET /zones?name=` probando el host y sus dominios padre (`www.tienda.com` → `tienda.com`), que funciona con tokens de usuario y de cuenta. Después `GET /user/tokens/verify`; si no sirve (tokens de cuenta, prefijo `cfat_` o sin prefijo si son anteriores a 2026), `GET /accounts/{account_id}/tokens/verify` con la cuenta de la zona. El token debe estar `active`.
  - El plan sale de `plan.legacy_id`, que Cloudflare marcó como obsoleto (el reemplazo, `/zones/{id}/subscription`, exige permisos de facturación). Un valor desconocido o ausente se trata como Free; lo definitivo lo decide `?dry_run=true` al aplicar.
  - Permisos de lectura sondeados sin cambiar nada: ajustes (`GET /settings/http3`), reglas (`GET` del entrypoint de caché, 404 = permiso presente) y analíticas (consulta GraphQL mínima). Los de escritura los valida `dry_run`.
  - Enlace de creación del token: `https://dash.cloudflare.com/profile/api-tokens?permissionGroupKeys=…` con las claves documentadas (`zone` read, `zone_settings` edit, `analytics` read, `cache` purge). Reglas de caché, Transform Rules y WAF no tienen clave de plantilla documentada: la pantalla pide añadirlas a mano.
- No aplicar nada en dominios de staging (lista `core.staging_domains` de la matriz) ni si el host del sitio no coincide con la zona.

## 2. Cliente de API (`Client`)

- Base: `https://api.cloudflare.com/client/v4`. Usar `wp_remote_request` con timeout de 15 s.
- Reintentos con backoff exponencial ante 429 y 5xx (máximo 3). Respetar el límite global de la API (del orden de 1.200 peticiones cada 5 minutos por usuario; verificar).
- Toda respuesta se valida (`success`, `errors[]`) y se registra en el `EventLog` (sin el token).
- *Implementado en la fase 2b:* espera 1, 2 y 4 s entre reintentos. Un 429 con `Retry-After` de más de 8 s no se espera dentro del request: vuelve a quien llama (la cola de purga reprograma con esa espera). Límite global verificado: 1.200 peticiones cada 5 minutos por usuario o token de cuenta. La URL base solo cambia con la constante `ITB_CF_API_BASE` (simulador de pruebas, `tests/e2e/mock-cloudflare.php`); nunca desde el admin. Un 404 en una lectura (fase sin ruleset) no se registra como error.

## 3. Reglas propias: cómo gestionarlas sin pisar las del cliente

- Las reglas viven en **rulesets por fase** (Rulesets API):
  - `http_request_cache_settings` → reglas de caché
  - `http_request_firewall_custom` → reglas WAF personalizadas
  - `http_ratelimit` → rate limiting
  - `http_config_settings` → reglas de configuración (opcional)
  - `http_request_transform` → reescritura de URL (opcional, ver 4.3)
- Leer el ruleset de entrada: `GET /zones/{zone_id}/rulesets/phases/{phase}/entrypoint`.
  - Si no existe (404), crearlo con `PUT` sobre la misma ruta **solo con nuestras reglas**.
  - Si existe, **nunca** hacer `PUT` del ruleset completo (reemplaza todas las reglas). Usar los endpoints por regla:
    - Crear: `POST /zones/{zone_id}/rulesets/{ruleset_id}/rules`
    - Modificar: `PATCH /zones/{zone_id}/rulesets/{ruleset_id}/rules/{rule_id}`
    - Eliminar: `DELETE /zones/{zone_id}/rulesets/{ruleset_id}/rules/{rule_id}`
- Identificar las reglas propias con el campo `ref` (estable, por ejemplo `itb-cache-html`) y además con la descripción prefijada `[imagina-turbo] ...`.
- El orden importa: en caché, las reglas posteriores sobrescriben ajustes de las anteriores; en WAF, la primera acción terminal gana. Usar la posición (`position.before` / `position.after`) al crear.
- **Detección de deriva**: comparar el hash de cada regla propia con el último aplicado. Si alguien la modificó a mano, marcarla como "modificada externamente" y no sobrescribirla sin confirmación.

*Implementado en la fase 2b (verificado en la documentación y el esquema OpenAPI de Cloudflare, 2026-10-13):*

- **`ref` por sitio**: `itb-{prefijo de tags}-{plantilla}` (p. ej. `itb-k7-cache-html`). Dos sitios de la misma zona no se pisan ni se borran las reglas entre sí. Todas las expresiones empiezan por `http.host eq "<host del sitio>"`.
- `POST`, `PATCH` y `DELETE` de una regla devuelven el ruleset completo; `PATCH` exige enviar todos los campos de la regla. `ref` se acepta al crear, es único en el ruleset y se conserva.
- **Validación previa**: cada operación se envía antes con `?dry_run=true` (sintaxis, campos, plan y cupos, sin persistir; changelog 2026-09-17). Si una falla, no se toca la zona.
- **Orden**: las reglas nuevas se insertan al principio de la fase (`position: {"before": ""}`). En caché las posteriores mandan: una regla del cliente que excluye algo sigue ganando sobre las nuestras.
- **Deriva**: se guarda el hash de la regla tal como la devolvió Cloudflare tras aplicarla (no el de lo enviado: Cloudflare puede añadir valores por defecto). La tarea horaria avisa una vez por regla modificada a mano.
- **Snapshots y reversión**: antes de aplicar se guardan las reglas propias de todas las fases gestionadas (con su posición) y los ajustes editables. Si un cambio falla a mitad, se restaura el snapshot automáticamente. Restaurar deja la zona igual que en el snapshot salvo los `id` de las reglas recreadas (los asigna Cloudflare); si el ruleset de entrada no existía, queda vacío (sin efecto).

## 4. Plantillas de caché

> **Verificado en la documentación de Cloudflare (2026-10), antes de la fase 2b:**
> - `Cloudflare-CDN-Cache-Control` solo lo lee el edge de Cloudflare, tiene prioridad sobre `CDN-Cache-Control` y `Cache-Control`, y no se reenvía al visitante. `Cache-Tag` (máx. 16 KB) también se quita.
> - Con `edge_ttl: override_origin`, Cloudflare **quita el `Set-Cookie` y guarda la respuesta**. Por eso la plantilla del HTML usa siempre `respect_origin` (regla 9).
> - La llave de caché con `query_string.exclude` es solo Enterprise; el operador `matches` (regex), Business o superior. Hay que elegir la alternativa del punto 4.3 según el plan.
> - Límites de reglas de caché: Free 10, Pro 25, Business 50, Enterprise 300; expresión de hasta 4.096 caracteres.
> - Pendiente de confirmar en la zona de pruebas (fase 2b): `http.request.uri.args.names` en reglas de caché de request.

### 4.1 `itb-cache-html` — hacer elegible el HTML anónimo

- **Acción:** `set_cache_settings`.
- **Parámetros:** `cache: true`; `edge_ttl: { mode: "respect_origin" }`; `browser_ttl: { mode: "respect_origin" }`; `serve_stale: { disable_stale_while_updating: false }`; `respect_strong_etags: true`.
- La duración real la decide el origen con `Cloudflare-CDN-Cache-Control` (ver módulo `CacheCoordinator`). Si el origen manda `private`/`no-store` o `Set-Cookie`, no se cachea. Así WordPress, que sabe qué es dinámico, manda; y la regla del edge solo habilita.
- **Expresión** (se genera con las rutas reales del sitio; ejemplo):

```
(http.request.method in {"GET" "HEAD"})
and not starts_with(http.request.uri.path, "/wp-admin")
and not http.request.uri.path in {"/wp-login.php" "/xmlrpc.php" "/wp-cron.php"}
and not starts_with(http.request.uri.path, "/wp-json")
and not any(http.request.uri.args.names[*] in {"wc-ajax" "add-to-cart" "rest_route" "preview" "s" "remove_item" "_wpnonce"})
and not http.cookie contains "wordpress_logged_in_"
and not http.cookie contains "wp-postpass_"
and not http.cookie contains "comment_author_"
and not http.cookie contains "itb_bypass"
and not (http.request.uri.path eq "/carrito" or starts_with(http.request.uri.path, "/carrito/"))
and not (http.request.uri.path eq "/finalizar-compra" or starts_with(http.request.uri.path, "/finalizar-compra/"))
and not (http.request.uri.path eq "/mi-cuenta" or starts_with(http.request.uri.path, "/mi-cuenta/"))
```

- **Parámetros por nombre, no por subcadena:** `http.request.uri.query contains "s="` también coincide con `filters=`, `ids=`, `post_types=`… y saca de la caché páginas que sí son cacheables. *Verificado:* `http.request.uri.args.names` existe en todos los planes, pero la página de Cache Rules no lo lista entre sus campos, y tampoco `http.request.method`. **Implementado:** la regla usa `contains` solo con nombres largos e inequívocos (`wc-ajax=`, `add-to-cart=`, `remove_item=`, `_wpnonce=`, `preview=`, `rest_route=`, `action=`…), nunca `s=`. La búsqueda la excluye el origen (`no-store`). Cloudflare no cachea POST.
- **Si la expresión no cabe en 4.096 caracteres**: primero se quitan las exclusiones por parámetro (el origen las cubre). Las cookies y rutas privadas nunca se recortan: si aun así no cabe, la regla no se propone.
- **Requisito**: la regla del HTML solo se propone con el coordinador de caché en modo cabeceras. Con `respect_origin` y sin `Cache-Control`, Cloudflare aplicaría su TTL por defecto a páginas que no lo piden. Si el coordinador deja ese modo, el plan propone retirar la regla.
- **Rutas completas:** `starts_with(path, "/carrito")` también excluye `/carritos-de-golf/`. Comparar la ruta exacta o con la barra final.
- `itb_bypass` (cookie del kill switch por admin) se excluye para que el admin vea el origen sin el plugin, no la copia del edge.

- Si el módulo `WooCart` está **inactivo**, agregar `and not http.cookie contains "woocommerce_items_in_cart"`.
- Agregar las cookies de variación/bypass de la matriz que apliquen al sitio (moneda, idioma, consentimiento). En el edge, una cookie de variación se trata como **bypass** salvo en planes que permitan cookies en la llave de caché.
- Excluir `s=` solo si Imagina Search no marca sus resultados como cacheables.
- Controlar la longitud de la expresión: hay un límite por plan. Si se excede, agrupar rutas con `starts_with` o mover exclusiones al origen (que manda `no-store`).

### 4.2 `itb-cache-static` — assets

- Expresión: extensión en `{"css" "js" "jpg" "jpeg" "png" "gif" "webp" "avif" "svg" "woff" "woff2" "ttf" "ico" "mp4" "webm" "pdf"}` (usar `http.request.uri.path.extension`; verificar disponibilidad del campo, alternativa `ends_with`).
- `edge_ttl: override_origin 30 días`, `browser_ttl: respect_origin`.
- *Implementado:* con `status_code_ttl` 400–499 → 600 s y 500–599 → 0 (un 404 de un asset que aparece después no queda guardado 30 días).
- Purga por URL al reemplazar un adjunto (`wp_update_attachment_metadata`, plugins de optimización de imágenes).

### 4.3 Parámetros de campañas (llave de caché)

Objetivo: que `?fbclid=...` o `?utm_source=...` no generen copias nuevas. Estrategia según plan:

1. **Llave de caché personalizada** en `itb-cache-html` con `cache_key.custom_key.query_string.exclude.list` = `core.ignore_query_params`, si el plan lo permite. *Verificado: solo Enterprise.*
2. Si no: **regla de transformación de URL** que reescriba el query eliminando esos parámetros (función de eliminación de argumentos del lenguaje de reglas, si está disponible en el plan). El navegador conserva la URL original, así que la analítica en JavaScript sigue leyendo los parámetros. *Verificado e implementado:* fase `http_request_transform`, acción `rewrite`, `uri.query.expression = remove_query_args(http.request.uri.query, "utm_source", …)`. La función solo existe en reescrituras y no consta restricción de plan. Riesgo moderado: el servidor recibe la URL sin esos parámetros, así que un plugin que los lea en PHP deja de verlos.
3. Si ninguna está disponible: no hacer nada en el edge y documentarlo en el panel (el origen sí los ignora en LSCache/WP Rocket).

### 4.4 Ajustes de zona (`ZoneSettings`)

| Ajuste | Acción |
|---|---|
| HTTP/3 | Activar |
| Early Hints | Activar (aprovecha los `Link: preload` del origen) |
| Smart Tiered Cache | Activar si el plan lo permite |
| Rocket Loader | Desactivar (choca con el delay JS del plugin de caché) |
| Browser Cache TTL | "Respetar cabeceras existentes" |
| SSL/TLS | **Solo lectura.** Alertar si está en Flexible; recomendar Full (strict) |
| Always Use HTTPS, certificados, DNS | **Solo lectura** |
| Bot Fight Mode | **No activar automáticamente**: en el plan Free no se puede excluir por ruta y bloquea webhooks de pasarelas de pago. Mostrar como recomendación con advertencia |
| Bloqueo de crawlers de IA | Decisión del cliente; mostrar la opción gestionada de Cloudflare |

Endpoint general: `GET/PATCH /zones/{zone_id}/settings/{setting}` con cuerpo `{"value": …}`. *Verificado:* `http3`, `early_hints`, `rocket_loader` (`on`/`off`), `browser_cache_ttl` (0 = respetar cabeceras, todos los planes), `ssl` y `always_use_https` (solo lectura). El `GET`/`PATCH` masivo de `/settings` está obsoleto: se leen uno por uno. Smart Tiered Cache: `GET/PATCH /zones/{zone_id}/cache/tiered_cache_smart_topology_enable` `{"value":"on"}`, todos los planes. Rocket Loader se propone con riesgo moderado (apagarlo puede cambiar un sitio que lo usa a propósito).

## 5. Purga

- `POST /zones/{zone_id}/purge_cache` con uno de: `{"tags": [...]}`, `{"files": [...]}`, `{"prefixes": [...]}`, `{"hosts": [...]}`, `{"purge_everything": true}`.
- Preferir tags. La purga por tag está disponible en todos los planes desde el 2025-04-03 (verificado en la documentación de Cloudflare en 2026-10). Si falla con error de permisos/plan, pasar a purga por URL resolviendo las URLs de cada tag. Solo se purga por tag cuando la caché de origen reproduce `Cache-Tag` en sus aciertos (LSCache); con WP Rocket, por URL (ver `docs/02`, módulo 2).
- Lotes de hasta 100 elementos por llamada. En Free, 5 peticiones de purga por minuto por cuenta (ráfaga de 25): la cola agrupa 30 s; si la purga del edge falla, se reencola y se reintenta a los 5 min (el cliente de la API de la fase 2b añade el respeto de `retry-after`).
- *Verificado:* error 429 con código 1134 al superar el límite. El endpoint `invalidate_cache` (2026-09-28, marca como viejo y revalida con ETag/Last-Modified) comparte permisos y límites; no se usa todavía porque WordPress rara vez responde 304.
- *Implementado:* hasta 100 elementos por llamada y como máximo 5 llamadas por vuelta en Free (20 en Pro, 40 en Business; `data/cloudflare-limits.json`). Lo que no entra vuelve a la cola sin repetir lo ya purgado. Solo se purgan URLs del host del sitio. Nunca en dominios de staging ni si el sitio no pertenece a la zona.
- `purge_everything` solo con confirmación del usuario o en eventos de "todo" (cambio de tema, Elementor CSS global) **y** seguido de recalentado.
- Sincronizar siempre con el adaptador del plugin de caché (purgar ambos niveles).

## 6. Firewall por capas

### Capa 1 — Edge (Cloudflare WAF), la principal

Reglas personalizadas en `http_request_firewall_custom`. En el plan Free hay pocas reglas y **sin regex** (usar `contains`, `starts_with`, `ends_with`, `in`, `wildcard` si está disponible, `lower()`). El orden es:

1. **`itb-fw-skip-webhooks`** — acción `skip` para webhooks y APIs legítimas: `/wc-api/`, `?wc-api=`, rutas REST de pasarelas de pago presentes en la matriz (`requires.webhook_paths`, incluye pasarelas de LATAM: Wompi, ePayco, PayU, Mercado Pago), IPs/ASN de servicios que el usuario agregue. Va primero para que nada las bloquee ni las desafíe.
   - **Ojo:** en reglas personalizadas, `skip` con "reglas personalizadas restantes" salta **todas** las que siguen en la fase, incluidas las del cliente, no solo las nuestras. Por eso la condición debe ser lo más estrecha posible (ruta exacta + método) y en el diff se avisa si el cliente tiene reglas propias después de esta. Si eso es un problema, la alternativa es no usar `skip` y agregar `and not (<condición de webhooks>)` a cada regla propia.
2. **`itb-fw-exploits`** (inspirada en 8G) — acción `block`. **El query llega codificado** (`%3Cscript`, `union+select`, `..%2F`): sin decodificar, casi ninguna condición coincidiría. Usar `url_decode()` *(verificar función y opciones, p. ej. `"r"` para decodificación recursiva y `+` como espacio)*:

```
(url_decode(http.request.uri.query) contains "../")
or (lower(url_decode(http.request.uri.query)) contains "base64_")
or (lower(url_decode(http.request.uri.query)) contains "union select")
or (lower(url_decode(http.request.uri.query)) contains "<script")
or (lower(url_decode(http.request.uri.query)) contains "eval(")
or (url_decode(http.request.uri.query) contains "GLOBALS=")
or (url_decode(http.request.uri.query) contains "_REQUEST=")
or (url_decode(http.request.uri.query) contains "/etc/passwd")
or (http.request.uri.path contains "/.git")
or (http.request.uri.path contains "/.env")
or (ends_with(http.request.uri.path, ".sql"))
or (ends_with(http.request.uri.path, ".bak"))
or (http.request.uri.path contains "/vendor/phpunit")
or (http.request.uri.path contains "phpinfo")
or (http.request.method in {"TRACE" "TRACK" "DEBUG"})
```

3. **`itb-fw-wp-surface`** — acción `block`: `xmlrpc.php` (salvo que la matriz indique un plugin que lo necesita, por ejemplo Jetpack), enumeración (`path eq "/"` y query con `author=`), cualquier `.php` bajo `/wp-content/uploads/`, `readme.html`, `license.txt`, `wp-config` en cualquier forma.
4. **`itb-fw-bad-bots`** — acción `block` para escáneres conocidos (sqlmap, nikto, nmap, masscan, zgrab, wpscan, etc.); lista "suave" opcional con `managed_challenge` (python-requests, Go-http-client, curl) desactivada por defecto porque algunas integraciones legítimas la usan.
5. **Reservada** para el cliente o para la regla de emergencia del optimizador.

Notas:
- **No usar la presencia de una cookie como excepción de seguridad** (es falsificable). Solo para reducir falsos positivos en reglas de baja severidad.
- **Modo de prueba**: la acción `log` en reglas personalizadas es exclusiva de planes Enterprise. En Free/Pro/Business, las reglas nuevas entran con `managed_challenge` durante 7 días (un humano pasa, un bot no) y luego pasan a `block`. Las rutas de API/webhooks ya quedaron excluidas por la regla 1.
- Antes de reutilizar patrones del 8G, revisar su licencia y dar crédito a su autor (Jeff Starr, Perishable Press) en el código y el README.

**Implementado en la fase 4** (verificado en la documentación de Cloudflare el 2026-10-13):

- Reglas personalizadas: Free 5, Pro 20, Business 100. Sin regex por debajo de Business: solo `contains`, `starts_with`, `ends_with`, `in`, `lower()` y `url_decode()`. Ninguna regla propia lleva `matches`.
- **No se usa la regla `skip`**: saltaría también las reglas del cliente que vienen después. Cada regla propia lleva `and not (<webhooks>)`: `/wc-api/`, `?wc-api=` y los `requires.webhook_paths` de la matriz (validados con un patrón de ruta; los inválidos se descartan).
- Tres reglas: `itb-fw-exploits`, `itb-fw-surface` (XML-RPC solo si ningún plugin lo requiere) e `itb-fw-bots`. Todas con el host del sitio, riesgo moderado y fuera de la selección por defecto.
- **Desafío → bloqueo**: entran con `managed_challenge`. `itb_cf_challenge` guarda cuándo se vio cada una en desafío en la zona; a los 7 días el plan propone pasarla a `block` (cambio normal, con snapshot). Si el módulo se apaga, el plan propone eliminarlas.
- El token necesita **Zone WAF: Edit**. Al conectar se comprueba la lectura de la fase del firewall (`waf_read`).
- Eventos: `firewallEventsAdaptive` (24 h, hasta 1000), agrupados por descripción de regla y acción, con la ruta y el país más frecuentes. Se renuevan cada hora con las analíticas.
- **Pendiente de la zona real**: que `url_decode()` coincide con `%2e%2e` y `+`, el comportamiento de `dry_run` en estas fases y que los eventos llegan con la descripción de la regla.

### Rate limiting (`http_ratelimit`)

En Free hay una sola regla, con características y periodos limitados (típicamente por IP, ventanas cortas y condiciones restringidas a la ruta; verificar). Plantilla por defecto:

- Rutas: `/wp-login.php` y `/wp-json/wc/store/v1/checkout` (protege contra fuerza bruta y contra **card testing** en WooCommerce del checkout por bloques).
- **Limitación del plan Free:** la expresión solo puede usar la ruta *(verificar)*. El checkout clásico va a `/?wc-ajax=checkout` (ruta `/`) y **no se puede cubrir** en Free. En Free, el panel lo dice explícitamente y la protección del checkout clásico queda en: Turnstile/reCAPTCHA de la pasarela, CrowdSec y la guardia PHP (límite por IP con APCu/Redis si están disponibles, fase 4). En Pro o superior, agregar regla con `any(http.request.uri.args["wc-ajax"][*] eq "checkout")`.
- Umbral inicial: 5 peticiones / 10 s por IP → bloqueo por el tiempo que permita el plan.
- En planes superiores agregar reglas para búsquedas (`?s=`), combinaciones de filtros y `?add-to-cart=`.

**Implementado en la fase 4**: una regla `itb-rl-login`, objeto `ratelimit` en la regla (no en `action_parameters`), `characteristics: [cf.colo.id, ip.src]` (`cf.colo.id` es obligatorio), 5 peticiones en 10 s. Free: expresión solo con ruta y bloqueo de 10 s; Pro o superior: host, checkout clásico (`?wc-ajax=checkout`) y bloqueo de 10 minutos. El login personalizado (WPS Hide Login y otros) se toma de las rutas detectadas.

### Capa 2 — Cerrar el origen

- Si el VPS acepta tráfico directo, todo lo anterior se puede saltar.
- `OriginLockCheck`:
  - En el sitio: muestrear requests que llegan **sin** `CF-Connecting-IP`/`CF-Ray` (excluyendo localhost, cron, el warmer y WP-CLI). Si hay tráfico externo directo, alertar.
  - Desde el verificador: pedir la IP del servidor directamente con la cabecera `Host` del sitio; si responde con el sitio, alertar.
- Guía en el admin: permitir 80/443 solo desde los rangos de IP de Cloudflare (lista oficial publicada por Cloudflare) en el firewall del VPS (ServerAvatar/UFW/Plesk), o usar Authenticated Origin Pulls si el servidor lo soporta.
- Verificar que OpenLiteSpeed use la IP real (`CF-Connecting-IP`) solo desde IPs de Cloudflare de confianza, para que CrowdSec y los logs vean al visitante real.

**Implementado en la fase 4** (`OriginLock`): el perfilador ya guarda en cada muestra por dónde llegó el request, sin coste extra. `via_cf`: 0 directo desde IP pública, 1 Cloudflare con la IP real en `REMOTE_ADDR`, 2 local (CLI, loopback, red privada), 3 Cloudflare con la IP del proxy en `REMOTE_ADDR` (el servidor no restaura la IP real: CrowdSec y los límites por IP ven a Cloudflare). Con 20 muestras o más de los últimos 7 días (sin cron), la pantalla dice si el origen está abierto, cerrado o si el tráfico no pasa por Cloudflare. Las muestras anteriores a la 0.6 no distinguían local y se ignoran. La comprobación desde fuera (pedir la IP del servidor con el `Host` del sitio) queda para el verificador.

### Capa 3 — Servidor

- **CrowdSec** (ya instalado): reputación de IPs y bloqueo por comportamiento. El plugin no lo duplica; solo verifica que reciba la IP real.
- **`HtaccessWriter`** (sitios Apache/Plesk): subconjunto de reglas 8G entre marcadores `# BEGIN Imagina Turbo Firewall` / `# END Imagina Turbo Firewall`. Antes de escribir: respaldo. Después: pedir la home, una página de producto y `/wp-json/`; si alguna no responde 200, restaurar el respaldo automáticamente.
- **OpenLiteSpeed**: solo interpreta parte del `.htaccess` y puede requerir recarga del servidor. Ofrecer las reglas como opcionales y avisar que dependen de la configuración del servidor. La protección principal en OLS es Cloudflare + CrowdSec.

**Implementado en la fase 4** (`HtaccessWriter`): el bloque va **al principio** del archivo (detrás del de WordPress, la regla `[L]` de `index.php` terminaría antes). Cubre métodos TRACE/TRACK/DEBUG, recorrido de directorios, `base64_`, `union select`, `<script`, `GLOBALS`/`_REQUEST` como parámetro, `/etc/passwd` y `eval(` en el query (salvo `/wc-api/` y `?wc-api=`), archivos sensibles, PHP en uploads, `readme.html` y `license.txt`. Respaldo completo en `itb_htaccess_backup`, autoprueba de portada, un producto y `/wp-json/` con un parámetro único, y restauración automática si alguna no responde 200. Probado en OpenLiteSpeed: lee las reglas después de reiniciar el servidor; quitar el bloque deja el archivo idéntico al original.

### Capa 4 — Guardia PHP mínima

Ver módulo `Shield` (bloqueos baratos antes de cargar plugins). Sin WAF completo en PHP.

## 7. Analíticas y ciclo de optimización (`Analytics`, `Optimizer`)

Fuentes de datos:
- **GraphQL de Cloudflare** (`POST /graphql`): conjuntos de datos de requests HTTP agregados (por estado de caché y ruta) y eventos de firewall. La disponibilidad, el detalle y la retención dependen del plan (en Free son limitados): detectar qué hay disponible y degradar con elegancia.
  - *Verificado e implementado (fase 2b):* `httpRequestsAdaptiveGroups` y `firewallEventsAdaptive` están en todos los planes, con retención de al menos 31 días desde 2026-10-02. Cada hora se consultan las últimas 24 h del host (`clientRequestHTTPHost`), solo visitantes (`requestSource: "eyeball"`), por `cacheStatus`, y las 15 rutas que más llegan al origen (`miss`, `expired`, `bypass`, `dynamic`). Se cuentan como servidos desde caché `hit`, `stale`, `updating` y `revalidated`.
- **Muestras del origen**: todo request que llega a PHP es un miss o un bypass del edge. El perfilador registra ruta, tipo, cookies presentes (solo nombres), parámetros y si la respuesta fue cacheable. Esta fuente funciona en cualquier plan.
- **RUM**: experiencia real de los visitantes.

Optimizaciones automáticas (cada una con nivel de riesgo y registro):

| Detección | Acción | Riesgo |
|---|---|---|
| Parámetro de query frecuente que no cambia la respuesta (mismo hash normalizado) | Proponer agregarlo a parámetros ignorados; auto-aplicar si coincide con patrones de campaña (`*clid`, `utm_*`, `mc_*`, `hsa_*`) | Seguro |
| URL muy visitada que siempre llega al origen | Diagnóstico del motivo (cookie, `Set-Cookie`, cabecera, regla) y recomendación | Seguro |
| Regla WAF en `managed_challenge` 7 días sin afectar tráfico legítimo (sin desafíos resueltos por humanos, si el dato está disponible) | Pasar a `block` | Moderado |
| Umbral de rate limit | Calcular el p99 de peticiones por IP en esa ruta en tráfico normal × 3 | Moderado |
| Carga del servidor > núcleos × 1,5 durante 2 min **y** misses > 5 × línea base | Modo emergencia: desafío en búsquedas/filtros, rate limit más estricto; revertir solo a los 30 min; notificar | Invasivo (opcional) |

## 8. Ciclo de aplicación (UI y backend)

1. **Detectar**: `ZoneInspector` lee plan, rulesets, ajustes y SSL; `Detector` aporta el contexto del sitio.
2. **Proponer**: `PlanDiff` genera la lista de cambios (crear/modificar/eliminar/sin cambios) con el JSON exacto que se enviará.
3. **Aplicar**: `Snapshot` guarda el estado previo (reglas propias + ajustes tocados) en `itb_cf_snapshots`; luego se aplican los cambios. Seguros: automáticos. Sensibles: requieren aprobación o entran en modo prueba.
4. **Vigilar**: jobs de Action Scheduler (cada hora: analíticas y muestras; diario: optimizador).
5. **Ajustar**: el optimizador propone o aplica según el riesgo.
6. **Revertir**: restaurar un snapshot afecta solo las reglas propias (`ref` con prefijo `itb-`) y los ajustes registrados.

## 9. Límites por plan (orientativos — verificar y mantener en el hub)

*Verificado el 2026-10-13 y guardado en `data/cloudflare-limits.json` (actualizable desde el hub):* reglas de caché y Transform Free 10 / Pro 25 / Business 50 / Enterprise 300; WAF personalizadas 5 / 20 / 100 / 1.000; rate limiting 1 / 2 / 5 / 100. Regex solo Business y Enterprise. TTL mínimo del edge: Free 2 h, Pro 1 h, Business y Enterprise 1 s. Rate limiting en Free: solo campos de ruta y bot verificado, periodo y bloqueo de 10 s.

| | Free | Pro | Business |
|---|---|---|---|
| Reglas de caché | ~10 | ~25 | ~50 |
| Reglas WAF personalizadas | ~5 | ~20 | ~100 |
| Reglas de rate limiting | 1 | 2 | 5 |
| Regex en expresiones | No | No | Sí |
| Acción `log` en WAF | No | No | No (solo Enterprise) |

El plugin adapta las plantillas: en Free combina condiciones en menos reglas; en planes superiores separa reglas para tener mejor visibilidad.
