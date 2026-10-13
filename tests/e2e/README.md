# Pruebas en un WordPress real

Procedimiento con el que se validó la fase 0 (WordPress 7.1.3, WooCommerce 11.2.1, LiteSpeed Cache, WPS Hide Login, PHP 8.3, MariaDB 10.11, OPcache activo).

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
| Admin | `node admin-e2e.js <carpeta_capturas>` | Panel, Registro y Avanzado sin errores de consola; kill switch con confirmación; validación de formularios |

## Ejecutar la prueba del admin

```bash
npm i playwright@1
ITB_BASE_URL=http://127.0.0.1:8080 ITB_USER=admin ITB_PASS='...' ITB_LOGIN_PATH=/acceso/ node tests/e2e/admin-e2e.js /tmp/capturas
```

`ITB_CHROMIUM` permite indicar el ejecutable de Chromium si Playwright no descarga el suyo.
