# Pruebas en un WordPress real

Procedimiento con el que se validaron las fases 0, 1 y 2a (WordPress 7.1.3, WooCommerce 11.2.1, LiteSpeed Cache 7.9.1, WPS Hide Login, PHP 8.3, MariaDB 10.11, OPcache activo). Desde la fase 2a el sitio corre en **OpenLiteSpeed** (ver abajo), igual que los servidores de producción.

## Preparación

1. Instalar WordPress con enlaces permanentes `/%postname%/`, WooCommerce con sus páginas en español (`/carrito/`, `/finalizar-compra/`, `/mi-cuenta/`) y un producto.
2. Generar el ZIP con `bin/build.sh` y descomprimirlo en `wp-content/plugins/`. Activar desde el admin.
3. Copiar `zz-itb-probe.php` a `wp-content/mu-plugins/` (**solo en pruebas**): agrega la cabecera `X-ITB-Probe` con lo que decidió la etapa temprana (tipo de request, kill switch, modo seguro, config cargada).

## Qué se comprueba

| Prueba | Cómo | Resultado esperado |
|---|---|---|
| Activación | Admin → Plugins → Activar | Loader en `mu-plugins/`, `wp-content/imagina-turbo/config.php` con las rutas de Woo, log sin errores |
| Clasificación | `curl -D -` a portada, tienda, carrito, checkout, cuenta, `?wc-ajax=`, REST, Store API, feed, robots, imagen inexistente, `/favicon.ico`, login | Tipo correcto en `X-ITB-Probe`; `/favicon.ico` sigue redirigiendo al ícono del sitio |
| Recompilación | Activar WPS Hide Login con `whl_page=acceso` | `/acceso/` se clasifica como `Login` sin tocar nada |
| Coordinación | Activar LiteSpeed Cache | Aparece en "Coordinados", sin conflictos |
| Kill switch | `POST /safety/kill-switch` | Archivo `disable`; el frontend no carga config; el admin sigue funcionando |
| Cookie de bypass | `POST /safety/bypass-cookie` | Solo el navegador con la cookie ve el sitio sin el plugin; una cookie falsificada se ignora |
| Modo seguro | 3 fatales en un archivo del plugin (`?itb_probe_fatal=1` con `zz-test-fatal.php` dentro del plugin) | `safe-mode.flag` al tercer fatal; evento y correo en el siguiente request de admin; fatales de **otros** plugins no cuentan |
| Licencia | Inyectar la protección con el generador real de imagina-updater | Sin licencia el plugin no arranca y el loader se desarma solo; con licencia arranca y el panel la muestra |
| Desactivar | Admin → Desactivar | Sin loader, sin config y sin directorio de datos (se respeta un `disable` puesto a mano) |
| Loader huérfano | Borrar la carpeta del plugin por FTP | El sitio y el admin siguen respondiendo 200, sin errores |
| Desinstalar | Admin → Borrar | Sin tablas `itb_*`, sin opciones `itb_*`, sin capacidad `itb_manage`, sin tareas pendientes, sin loader ni directorio de datos |
| Admin | `node admin-e2e.js <carpeta_capturas>` | Panel, Diagnóstico, Caché, Registro y Avanzado sin errores de consola; kill switch con confirmación; validación de formularios; cookie de diagnóstico emite y retira `Server-Timing`; Caché: activar el módulo y pasar a cabeceras con confirmación, la portada anónima sale cacheable y el carrito con `no-store`, purga por tag, tag inválido rechazado, purga completa con confirmación; sin desborde horizontal en móvil |
| Caché: cabeceras | `curl -D -` a portada, página, producto, carrito, cuenta, login, búsqueda y un 404, como anónimo, dos veces | Cacheables: `Cloudflare-CDN-Cache-Control` y `Cache-Tag` en el fallo y en el acierto de LSCache. Resto: `no-store` para el edge |
| Caché: purga | Cambiar el stock de un producto (o purgar el tag `p-ID` desde la pantalla) | Producto, categoría, tienda y portada pasan a `miss` al instante; tras el vaciado de la cola (30 s) y el recalentado vuelven a `hit` en < 60 s |
| Caché: nonces | Página cacheada con un formulario con nonce; adelantar el reloj del navegador 11 h | Una petición a `/nonce` y los valores reemplazados. Firma o acciones alteradas → 400 |
| Caché: WP Rocket | Instalar WP Rocket desde su código (sin licencia basta para el adaptador) | Purgar un post llama a `rocket_clean_files` una vez; `rocket_clean_domain` se replica como "todo"; fuera de Apache, la config compila `edge_html = false` |
| Actualización 0.1 → 0.2 | Reemplazar la carpeta del plugin y abrir el admin | Esquema v2 (tablas `itb_route_samples` e `itb_metrics_hourly`), config recompilada con el perfilador |
| Muestreo | Muestreo intensivo desde Diagnóstico y navegar | Muestras de todos los tipos de request; kill switch o módulo apagado: ninguna |
| Costo propio | Columna `overhead_us` de las muestras | < 1 ms en páginas de WooCommerce |
| Ranking vs A/B | Muestreo intensivo con y sin el plugin activo, comparar la columna `ms` | Costo atribuido dentro de ±15 % del A/B en plugins que superan el ruido |
| Auditoría | Diagnóstico → Revisar ahora, con un mu-plugin que envía `Set-Cookie` a anónimos | La página aparece con la cookie y la pista del plugin probable |
| Hub | `php -S 127.0.0.1:8099 tests/e2e/mock-hub.php` y URL `http://127.0.0.1:8099` en Avanzado | Registro, envío, lote en `ITB_HUB_DIR`; con el archivo `fail` en ese directorio (503) o el hub parado, el sitio sigue en 200 y se programa el reintento |

## Ejecutar la prueba del admin

```bash
npm i playwright@1
ITB_BASE_URL=http://127.0.0.1:8080 ITB_USER=admin ITB_PASS='...' ITB_LOGIN_PATH=/acceso/ node tests/e2e/admin-e2e.js /tmp/capturas
```

`ITB_CHROMIUM` permite indicar el ejecutable de Chromium si Playwright no descarga el suyo.

## OpenLiteSpeed + LSCache (fase 2a)

LSCache solo cachea de verdad con un servidor LiteSpeed; con `php -S` o Apache no se puede probar el coordinador de caché.

1. Repositorio de LiteSpeed: `deb https://rpms.litespeedtech.com/debian/ <distribución> main` (claves `lst_repo.gpg` y `lst_debian_repo.gpg` en `https://rpms.litespeedtech.com/debian/`, copiadas a `/etc/apt/trusted.gpg.d/`). Instalar `openlitespeed` y `lsphp83` con `lsphp83-mysql`, `lsphp83-curl`, `lsphp83-intl` y `lsphp83-opcache`.
2. Virtual host con `docRoot` en la instalación de WordPress, `rewrite { enable 1; autoLoadHtaccess 1 }` y `module cache { storagePath ... }`. El `.htaccess` necesita el bloque de reescritura de WordPress (OLS no lee `mod_rewrite` sin él) para que funcionen `/wp-json/` y los enlaces permanentes.
3. Los archivos del sitio deben pertenecer al usuario de OLS (`nobody:nogroup` por defecto): después de copiar el plugin, `chown -R nobody:nogroup wp-content/plugins/imagina-turbo`.
4. Reiniciar con `/usr/local/lsws/bin/lswsctrl restart` tras cambiar la configuración.
5. Ojo al probar desde la línea de comandos: un `wp eval` (o cualquier PHP en CLI) consume la cola de purga diferida de LSCache (`litespeed.purge.queue`) sin poder enviarla al servidor. Para probar la purga diferida, escribir la opción directamente en la base de datos y hacer un request HTTP.
6. LSCache viene con la caché privada de usuarios logueados y la de REST activas: las pruebas del admin cubren que nuestras rutas no se guardan.

## Simulador de Cloudflare (fase 2b)

```bash
ITB_CF_MOCK_DIR=/tmp/itb-cf php -S 127.0.0.1:8098 tests/e2e/mock-cloudflare.php
```

En `wp-config.php` del sitio de pruebas: `define( 'ITB_CF_API_BASE', 'http://127.0.0.1:8098/client/v4' );`. Con esa constante la pantalla muestra el aviso «Conectado a un simulador» y la búsqueda de zona acepta el host tal cual, aunque sea una IP.

El simulador reproduce la forma documentada de la API: zonas, verificación de tokens, rulesets de fase (404 si no existen, `PUT`, reglas con `ref` y `position`, `?dry_run=true` con cupos por plan), ajustes, Smart Tiered Cache, purga con el límite de Free (5 por minuto, 429, código 1134) y GraphQL con datos fijos. Endpoints de control: `/__mock/reset`, `/__mock/state`, `/__mock/edit` (cambiar una regla «a mano») y `/__mock/fail` (forzar errores 500).

La prueba del admin incluye Cloudflare si se define `ITB_CF_MOCK=http://127.0.0.1:8098`.

## Carrito por Store API (fase 3)

```bash
ITB_BASE_URL=http://127.0.0.1:8080 node tests/e2e/woo-e2e.js /tmp/capturas
```

Requiere un producto simple, pago contra entrega activo y los módulos `woo_cart` y `cache_coordinator` (modo cabeceras). Recorre, como visitante anónimo:

1. Carga sin carrito, sin peticiones.
2. Agregar desde la tienda.
3. Contador en otra página y tiempo de actualización.
4. Desplegable bajo demanda.
5. Quitar desde el desplegable.
6. Producto con el widget de vistos, sin `Set-Cookie`.
7. Checkout de bloques.

Para otros temas: `ITB_COUNT_SELECTOR` y `ITB_MENU_SELECTOR`. Astra: `'.ast-site-header-cart .ast-count-text'` y `'#ast-desktop-header .ast-site-header-cart'`. `ITB_POSTCODE` define un código postal válido para el país de la tienda.

## Escudo, ajustes y firewall (fase 4)

- `admin-e2e.js` recorre también **Ajustes rápidos** (prueba del escudo como visitante, confirmación de un ajuste moderado) y **Firewall** (con `ITB_CF_MOCK`: reglas propuestas, aplicar una, eventos del simulador, vista móvil). Las peticiones a hosts externos se bloquean para que el evento `load` no dependa de la red.
- A mano: `curl -D -` a un asset inexistente, `/?author=1`, `POST /xmlrpc.php`, `/wp-json/wp/v2/users` y `/?rest_route=/wp/v2/users` → 404, 404, 403, 401, 401 con `X-ITB-Shield`. Con un plugin `jetpack/jetpack.php` activo (basta la cabecera) y la config recompilada, XML-RPC vuelve a 200.
- `.htaccess` en OpenLiteSpeed: después de escribir las reglas hay que reiniciar (`lswsctrl restart`) para que las lea.

## Carga condicional (fase 5)

Plugins de prueba en el sitio (solo pruebas): uno pesado (6 MB y 15 ms), uno que guarda `active_plugins` en `init` y `shutdown` como Freemius e informa `is_plugin_active()` en la cabecera `X-ITB-Test-Heavy-Active`, uno con una regla de reescritura, uno que agrega un fragmento al mini-carrito y uno que, con `?itb_flush=1`, regenera las reglas y, con `?itb_fatal=1`, llama a una función del pesado.

1. Activar el módulo (`PUT /modules/plugin_filter` con `confirm`).
2. Una regla que quita el plugin del fragmento falla. Una que quita el pesado y el de reescritura pasa en un paso.
3. `POST /?wc-ajax=get_refreshed_fragments` 30 veces: `active_plugins` intacta y ordenada; `X-ITB-Test-Heavy-Active: no`.
4. Con `&itb_flush=1`: la ruta `^itb-test-route/?$` sigue en `rewrite_rules`.
5. Con `&itb_fatal=1`: 500, línea en `filter-faults.log`; al abrir el admin, la regla queda desactivada.
6. Imagina Search: copiar `woo-smart-search` del repositorio, activarlo y probar el candidato `Rest:wss/v1`.

Las contrapruebas (sin la protección de escritura o de `rewrite_rules`) se hacen comentando el `add_filter` correspondiente en la copia del sitio y esperando a que OPcache revalide (unos segundos).

## Navegación y RUM (fase 6)

```bash
ITB_BASE_URL=http://127.0.0.1:8080 ITB_VIDEO_URL=http://127.0.0.1:8080/entrada-con-video/ node tests/e2e/nav-e2e.js
```

Con la muestra del RUM al 100 % (`PUT /navigation/settings {"rum_rate": 1}`) y la caché purgada.

- **Qué recorre**: portada → hover y clic a un enlace interno (debe llegar como `navigational-prefetch`) → atrás y adelante (bfcache) → recarga.
- **Qué devuelve**: las reglas de especulación con sus exclusiones, los beacons y, con `ITB_VIDEO_URL`, que la fachada crea el iframe al tocarla.
- **bfcache**: Playwright lo desactiva por defecto (`--disable-back-forward-cache`); la prueba quita ese argumento. La vuelta atrás se espera con `waitUntil: 'commit'`, porque no hay evento `load`.
- **Fachadas sin red**: `zz-itb-fake-oembed.php` (MU plugin de prueba) responde el oEmbed de YouTube.
- **Insights**: se pueden sembrar contadores en `itb_rum_events` con muchos bits en `ip_bits` y recalcular con `POST /navigation/insights`.


## Assets y base de datos (fase 7)

La prueba del admin recorre las dos pantallas (inventario de la portada, borrador de regla, auditoría y confirmación de una acción). Para el flujo completo:

1. Instalar Contact Form 7 y una página `/contacto/` con su shortcode.
2. `POST /assets/suggest`: debe sugerir CF7 "solo en" esa página.
3. Crear la regla en prueba, `POST /assets/preview {"on":true}` y comparar la portada con la sesión (sin CF7) y sin ella (con CF7).
4. Activar con `confirm`: la portada en caché para visitantes ya no lleva CF7; `/contacto/` sí.
5. Base de datos: sembrar transients expirados, revisiones, acciones viejas de Action Scheduler y opciones de un plugin inactivo; ejecutar cada acción y restaurar su lote.

## Flota (fase 8)

```bash
PHP_CLI_SERVER_WORKERS=4 ITB_HUB_DIR=/tmp/hub php -S 127.0.0.1:8099 tests/e2e/mock-hub.php
```

1. Registrar el sitio en el hub (`PUT /hub {"url":"http://127.0.0.1:8099"}` y `POST /hub/register`).
2. Escribir la plantilla en `$ITB_HUB_DIR/policy.json` y `POST /fleet/sync`: aparecen las desviaciones.
3. `POST /__mock/fleet/push {"dry_run": true, "replay": true}`: estado de cada sitio y 401 al repetir la petición.
4. `POST /__mock/fleet/push {}`: 403 sin autorización; con `PUT /fleet/settings {"fleet_remote": true, "confirm": true}`, aplica.
5. Con `touch $ITB_HUB_DIR/bad-signature`, la consulta falla y se conserva la plantilla anterior.

## Todas las pantallas (0.10.3)

```bash
ITB_BASE_URL=http://127.0.0.1:8070 ITB_USER=admin ITB_PASS=... [ITB_LOGIN_PATH=/acceso/] node tests/e2e/screens-e2e.js
```

Abre cada pantalla del admin en una carga nueva y falla si alguna lanza un error de JavaScript, devuelve un 5xx o queda vacía. Correrla también en un WordPress limpio (sin LSCache ni WooCommerce): ahí aparecen los estados vacíos que el sitio de pruebas nunca tiene.
