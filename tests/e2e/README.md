# Pruebas en un WordPress real

Procedimiento con el que se validaron las fases 0 y 1 (WordPress 7.1.3, WooCommerce 11.2.1, LiteSpeed Cache, WPS Hide Login, PHP 8.3, MariaDB 10.11, OPcache activo).

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
| Admin | `node admin-e2e.js <carpeta_capturas>` | Panel, Diagnóstico, Registro y Avanzado sin errores de consola; kill switch con confirmación; validación de formularios; cookie de diagnóstico emite y retira `Server-Timing`; sin desborde horizontal en móvil |
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
