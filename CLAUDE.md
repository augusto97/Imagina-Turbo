# CLAUDE.md — Imagina Turbo

> Nombre de trabajo provisional. Slug `imagina-turbo`, namespace PHP `ImaginaTurbo\`, prefijo `itb_` / `ITB_`. Si cambia el nombre, cambiar solo estas tres cosas.

## Qué es

Plugin de rendimiento para WordPress y WooCommerce que **complementa** al plugin de caché del sitio (LiteSpeed Cache o WP Rocket) y **reemplaza a Perfmatters**. Su objetivo es que los sitios carguen casi instantáneo y consuman muchos menos recursos del servidor, sin romper funcionalidad.

No es otro plugin de caché. No minifica, no combina archivos, no genera CSS crítico y no reescribe el HTML con output buffering. Esas tareas son del plugin de caché.

Hace lo que nadie más hace bien:

1. Reduce el trabajo de PHP por request (carga condicional de plugins y assets por ruta, incluyendo AJAX y REST).
2. Hace que el HTML de WooCommerce sea siempre cacheable (carrito por Store API, navegación sin sesión).
3. Orquesta Cloudflare por API: reglas de caché, purga por tags, stale, recalentado, WAF inspirado en 8G y rate limiting, con un ciclo detectar → proponer → aplicar → vigilar → ajustar.
4. Protege el servidor del tráfico basura (404 baratos, bots, enumeración, XML-RPC, combinaciones de filtros).
5. Hace que la navegación se sienta instantánea (Speculation Rules, bfcache, View Transitions).
6. Mide todo (perfilador por plugin, Server-Timing, RUM) y solo deja activo lo que demuestra mejora.

## Contexto del entorno

- Desarrollador: Edison, Imagina WP (Manizales, Colombia). ~30 sitios de clientes en LATAM.
- Servidores: Hetzner con ServerAvatar (**OpenLiteSpeed**, LSPHP, MariaDB, Redis), Cloudflare, CrowdSec. Algunos sitios en Plesk (Apache/nginx).
- Combinaciones soportadas:
  - OpenLiteSpeed → **LiteSpeed Cache + Imagina Turbo**. Ojo: OpenLiteSpeed **no soporta ESI**. Nunca diseñar funciones que dependan de ESI.
  - Plesk / Apache / nginx → **WP Rocket + Imagina Turbo**.
  - Sin plugin de caché → Imagina Turbo funciona solo con el edge de Cloudflare.
- Licencias y actualizaciones: integración con **Imagina Updater** (repo `augusto97/imagina-updater`).
- Imagina Search (plugin propio de Edison) ya resuelve las búsquedas: **no** construir búsqueda; solo integrarse con él (ver `docs/02-modulos.md`).
- MainWP **no** forma parte de este proyecto. La gestión central se hace con el hub propio.

## Reglas no negociables

1. **Costo propio casi cero en el frontend.** Presupuesto por request: < 0,5 ms y < 200 KB de memoria propios. Sin consultas a la base de datos en el frontend. La configuración se lee de un archivo PHP compilado que vive en OPcache. Sin opciones autoload propias salvo una mínima.
2. **Nunca reescribir el HTML con output buffering.** Todo lo que se inyecte va por hooks de WordPress (`wp_head`, `wp_footer`, filtros de imágenes, filtros de speculation rules del core).
3. **Nada sensible se activa por fe.** Cada módulo tiene nivel de riesgo (seguro / moderado / invasivo). Solo los seguros vienen activos por defecto. Los demás requieren verificación (automática o manual) y quedan registrados.
4. **Todo cambio es reversible.** Snapshots antes de cada cambio en Cloudflare, `.htaccess` o configuración; botón de revertir; registro de eventos.
5. **Kill switch siempre disponible**: constante en `wp-config.php`, archivo bandera y cookie firmada de administrador. Modo seguro automático ante errores fatales.
6. **Coexistencia, no competencia.** Detectar LSCache, WP Rocket, Perfmatters, Autoptimize, etc. y apagar los módulos propios que se solapen. Solo un plugin puede filtrar `active_plugins`.
7. **El sitio nunca depende del hub ni de Cloudflare para servir una página.** Si fallan, el sitio sigue igual.
8. **No tocar DNS, SSL ni certificados de Cloudflare.** Solo leerlos y avisar.
9. **Seguridad**: token de Cloudflare cifrado (sodium + salts), endpoints propios con límite de tasa y validación estricta, JSON de compatibilidad firmado (ed25519), jamás cachear respuestas con `Set-Cookie` ni de usuarios logueados.
10. **Verificar la documentación vigente** (Cloudflare API, LSCache, WP Rocket, WordPress core) antes de implementar cualquier endpoint o hook marcado en los docs como "verificar". No inventar endpoints.

## Stack y convenciones

- PHP 8.1+ con `declare(strict_types=1);`, PSR-4 vía Composer (`classmap-authoritative` en producción), patrón Repository/Service, inyección de dependencias con un contenedor simple propio.
- Tareas en segundo plano con **Action Scheduler**, incluido siempre vía Composer y cargado en el archivo principal: su propio registro elige la versión más nueva si WooCommerce u otro plugin trae otra copia. No condicionarlo a que falte WooCommerce.
- Dependencias PHP de terceros en `vendor/` con namespace prefijado (Strauss o PHP-Scoper) para no chocar con las copias de otros plugins. Excepción: Action Scheduler, que ya gestiona sus versiones.
- Admin: React 18 + TypeScript + Vite con `@kucrut/vite-for-wp`, shadcn/ui con Tailwind v4 y prefijo `itb` (en v4 se escribe `itb:flex`, no `itb-flex`; se declara con `@import "tailwindcss" prefix(itb);`), TanStack Query y Table, Zustand, React Hook Form + Zod, Lucide, fuente Inter. Estética tipo Linear/Vercel. El reset de Tailwind (preflight) se limita al contenedor de la app para no romper los estilos del admin de WordPress.
- Scripts del frontend: JavaScript vanilla, sin dependencias, cada uno < 3 KB minificado.
- Textos de interfaz en español (con i18n preparado: text domain `imagina-turbo`).
- Estándares: WordPress Coding Standards para hooks y seguridad (nonces, capabilities, sanitización, escape), PHPStan nivel 6+.

## Mapa de documentación

| Archivo | Contenido |
|---|---|
| `docs/01-arquitectura.md` | Estructura de carpetas, loader MU, configuración compilada, clasificación de requests, modo seguro, kill switch, coexistencia. |
| `docs/02-modulos.md` | Cada módulo: qué hace, riesgo, valores por defecto, cómo implementarlo, criterios de aceptación. |
| `docs/03-cloudflare-y-firewall.md` | Cliente de API, permisos del token, plantillas de reglas, purga, analíticas, ciclo de optimización, firewall por capas (8G), límites por plan. |
| `docs/04-admin-y-api.md` | Pantallas del admin, endpoints REST, tablas, opciones, protocolo con el hub. |
| `docs/05-roadmap-y-pruebas.md` | Fases con criterios de aceptación, estrategia de pruebas, servicio verificador con Playwright, métricas. |
| `data/compat-matrix-v1.json` | Matriz de compatibilidad (411 integraciones). Esquema en `data/compat-matrix-README.md`. |

## Cómo trabajar en este repo

- Avanzar **por fases** (`docs/05-roadmap-y-pruebas.md`). No empezar una fase sin cumplir los criterios de aceptación de la anterior.
- Antes de cada fase: leer el doc del módulo, proponer el plan de archivos y esperar confirmación si hay decisiones abiertas.
- Cada módulo implementa `ModuleInterface` y se registra en el contenedor; ningún módulo carga código si está desactivado.
- Escribir pruebas unitarias junto con el código. Medir el costo del loader con el benchmark de `tests/Performance` en cada cambio que lo toque.
- Commits pequeños y descriptivos en español. Ramas por fase: `fase-0-fundaciones`, `fase-1-perfilador`, etc.
- Si una decisión de los docs resulta técnicamente inviable, detenerse y explicarlo; no improvisar un reemplazo silencioso.
