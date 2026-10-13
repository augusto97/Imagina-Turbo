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

