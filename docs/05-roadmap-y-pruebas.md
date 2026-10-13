# 05 — Roadmap y pruebas

## Principio

Se construye de lo más seguro y medible a lo más invasivo. La carga condicional de plugins es la función con más ganancia, pero también la más riesgosa: llega en la fase 5, cuando ya existen el perfilador (para saber qué filtrar) y el verificador (para probarlo sin romper sitios).

Antes de la fase 1, en paralelo: medir 3 sitios representativos (un WooCommerce, un corporativo con Elementor, un blog) con LSCache bien configurado y reglas de Cloudflare a mano. Esa es la línea base contra la que se compara cada fase.

## Fases

### Dependencias externas

- **Hub**: servicio aparte, sin especificación de servidor todavía (solo el protocolo en `docs/04`). La fase 1 envía telemetría; hasta que el hub exista, el cliente se construye contra un servidor simulado y la telemetría queda desactivada. Ninguna fase del plugin se bloquea por el hub (regla 7).
- **Verificador**: servicio aparte (Node + Playwright). Se necesita desde la fase 3 (WooCart) y es obligatorio en la fase 5. Debe especificarse y construirse en paralelo a las fases 1–2. Mientras no exista, la verificación es manual y queda registrada como tal.

### Fase 0 — Fundaciones
- Bootstrap, Composer, contenedor, `ModuleInterface`, registro de módulos.
- MU loader (instalación/actualización/desinstalación automáticas y seguras).
- `ConfigCompiler` + `CompiledConfig` con escritura atómica.
- `RequestClassifier` con pruebas para todos los tipos.
- Kill switch, cookie de bypass, modo seguro por fatales.
- `Detector` y `OverlapGuard`.
- Carga y validación de la matriz de compatibilidad (`Compat\Matrix`, `Resolver`).
- Esqueleto del admin (React + Vite) con Panel, Registro y Avanzado.
- Licencia vía Imagina Updater.
- **Aceptación:** loader < 0,3 ms y < 150 KB medidos con el benchmark; activar/desactivar el plugin no deja residuos; un fatal simulado 3 veces activa el modo seguro (fatal originado en el propio plugin, ya que en la fase 0 aún no se filtran plugins); la matriz compila solo las reglas de los plugins activos; el loader huérfano (plugin borrado por FTP) no produce errores.

### Fase 1 — Perfilador y diagnóstico (solo lectura)
- Módulo `Profiler`, `Server-Timing`, muestras y rollups.
- Pantalla Diagnóstico.
- Auditoría de `Set-Cookie` y de bfcache (lado servidor).
- Hub: registro y telemetría básica.
- **Aceptación:** ranking de plugins coherente con Query Monitor (±15 %); overhead dentro del presupuesto; telemetría llega al hub y el sitio no se afecta si el hub está caído.

### Fase 2 — Caché y Cloudflare
- `CacheCoordinator` completo (cabeceras, tags, mapa de purga, cola, adaptadores LSCache/WP Rocket/Null, recalentado).
- Módulo Cloudflare: token, inspector, plantillas de caché, ajustes de zona, purga, diff, aplicar, snapshots, revertir, deriva.
- Pantallas Caché y Cloudflare.
- Refresco de nonces para formularios en páginas cacheadas (adelantado desde la fase 3). Sin él, edge TTL máximo de 10 h.
- Verificar si LSCache y WP Rocket conservan las cabeceras propias en sus respuestas cacheadas (ver `docs/02`, módulo 2) y elegir el plan B si no.
- **Aceptación:** en el sitio WooCommerce de prueba, > 85 % de aciertos del edge en tráfico anónimo; edición de producto reflejada en < 60 s; revertir un snapshot deja la zona exactamente como estaba (comparación JSON).

### Fase 3 — WooCommerce
- `WooCart` (Store API, fragments fuera, cookies neutralizadas, geolocalización).
- **Aceptación:** criterios del módulo 3 en `docs/02-modulos.md`, probados en Astra, Flatsome y Woodmart (o los temas reales de los clientes).

### Fase 4 — Escudo, ajustes y firewall
- `Shield`, `Tweaks` (reemplazo de Perfmatters), firewall edge (plantillas, modo desafío → bloqueo), rate limiting, verificación de origen cerrado, `HtaccessWriter` para Apache.
- Migración desde Perfmatters (detección, mapeo de sus ajustes equivalentes, aviso).
- **Aceptación:** requests de basura (404 de assets, XML-RPC, escáneres) no cargan plugins ni tema; ningún webhook de pasarela de pago bloqueado en pruebas; el firewall en desafío no afecta navegación ni checkout de usuarios reales.

### Fase 5 — Carga condicional de plugins
- `PluginFilter` con todas las protecciones (lecciones de Perfmatters MU).
- Experimentos con el verificador, promoción y vigilancia automática de reglas.
- Integración con Imagina Search.
- Resolver la decisión abierta sobre retirar o mantener el filtro de lectura.
- **Aceptación:** criterios del módulo 4; prueba de estrés con Freemius, WPML y un plugin que hace flush de rewrite rules.

### Fase 6 — Navegación instantánea y RUM
- `Rum`, `Speculation` (core + predictivo + fallback), bfcache (RUM), View Transitions, `FrontendHints` (LCP, fachadas).
- **Aceptación:** criterios de los módulos 5, 9 y 11.

### Fase 7 — Assets y base de datos
- `AssetManager` con sugerencias y verificación.
- `Database` con auditoría, acciones y respaldos.
- Alimentar exclusiones de delay JS de WP Rocket/LSCache con la matriz.

### Fase 8 — Flota
- Plantillas de configuración estándar desde el hub, detección de desviaciones, aplicación por lotes de reglas de Cloudflare a varias zonas.

## Estrategia de pruebas

### Unitarias (PHPUnit + Brain Monkey)
- `RequestClassifier` (tabla de casos con `$_SERVER` simulado).
- `ConfigCompiler` (salida determinista, escritura atómica).
- Constructores de expresiones de Cloudflare (snapshots de texto esperado, longitud máxima).
- `PlanDiff` (crear/modificar/eliminar/sin cambios, deriva).
- `TagBuilder`, `PurgeMap`.
- Protecciones de `PluginFilter` (escritura de `active_plugins`, `rewrite_rules`, coincidencia por carpeta).

### Integración (wp-env o contenedor con WordPress + WooCommerce)
- Activación/desactivación limpias.
- Flujo de purga real contra LSCache/WP Rocket instalados.
- Cliente de Cloudflare contra un servidor simulado (respuestas grabadas) y, aparte, contra una zona de pruebas real con token de pruebas.

### Rendimiento (`tests/Performance`)
- Benchmark del loader: 10.000 iteraciones del camino del frontend con config compilada; falla si supera el presupuesto.
- Comparación de memoria por tipo de request con y sin reglas de carga condicional.

### Verificador (servicio aparte, Node + Playwright, en el VPS de herramientas)
- Recibe: URL del sitio, lista de páginas clave (home, categoría, producto, carrito, checkout sin pagar, login, un formulario), y opcionalmente una cookie firmada de experimento.
- Comprueba: código de estado, errores de consola, errores PHP reportados por el sitio, diferencia visual con capturas de referencia (umbral configurable), flujo de agregar al carrito con un producto de prueba, presencia y envío de un formulario de prueba, cabeceras de caché esperadas (`cf-cache-status`).
- Devuelve pasa/falla con evidencia. El plugin revierte automáticamente lo probado si falla.
- Autenticación con firma HMAC; nunca guarda credenciales del sitio.

### Métricas de éxito del proyecto
- TTFB p75 de páginas anónimas < 100 ms en Colombia/México (desde el edge).
- LCP p75 móvil < 2,0 s en las plantillas principales.
- Requests que llegan a PHP: −70 % frente a la línea base.
- Memoria media por request en `wc-ajax`/REST: −40 %.
- Cero incidentes de funcionalidad rota sin reversión automática.
