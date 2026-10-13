# Imagina Turbo (nombre provisional)

Plugin de rendimiento para WordPress y WooCommerce que complementa a LiteSpeed Cache o WP Rocket, reemplaza a Perfmatters y orquesta Cloudflare (caché, purga, firewall y rate limiting) para que los sitios carguen casi instantáneo y consuman menos recursos del servidor.

- Especificación para Claude Code: [`CLAUDE.md`](CLAUDE.md)
- Documentación técnica: [`docs/`](docs)
- Matriz de compatibilidad: [`data/`](data)

Estado: **fases 0 (fundaciones) y 1 (perfilador y diagnóstico) completadas**. Las siguientes fases siguen `docs/05-roadmap-y-pruebas.md`. Para continuar con Claude Code, usa [`PRIMER-PROMPT.md`](PRIMER-PROMPT.md).

## Requisitos

PHP 8.1+, WordPress 6.4+. Recomendado: OPcache activo.

## Desarrollo

```bash
composer install            # dependencias y herramientas
composer test               # pruebas unitarias
composer analyse            # PHPStan nivel 8
composer lint               # WordPress Coding Standards
composer bench              # presupuesto del loader (< 0,3 ms y < 150 KB)

cd admin && npm ci && npm run build   # admin React (Vite)
npm run dev                            # servidor de desarrollo (requiere SCRIPT_DEBUG en wp-config.php)

bin/build.sh                # ZIP de distribución en dist/
```

Una copia de desarrollo sin `vendor/` funciona igual (autoloader propio), pero sin Action Scheduler: las tareas usan WP-Cron. Para producción, instala siempre el ZIP de `bin/build.sh`.

## Kill switch

Si algo sale mal y no tienes acceso al admin: agrega `define( 'ITB_DISABLE', true );` a `wp-config.php` o crea el archivo `wp-content/imagina-turbo/disable`.

## Licencia

GPLv2 o posterior. Incluye datos de compatibilidad derivados de WP Rocket y Perfmatters (ambos GPLv2+). Las reglas de firewall (Cloudflare y `.htaccess`) se inspiran en el firewall 8G de Jeff Starr (Perishable Press, https://perishablepress.com/8g-firewall/): los patrones están reescritos y reducidos, no se copia su código.
