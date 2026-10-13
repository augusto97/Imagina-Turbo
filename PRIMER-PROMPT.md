# Primer mensaje para Claude Code

Copia y pega esto en Claude Code, abierto en la carpeta del repositorio:

---

Lee CLAUDE.md y todos los archivos de docs/ antes de escribir código. Es la especificación completa del plugin, acordada en una conversación previa y corregida tras una revisión técnica.

Las fases 0 (fundaciones) y 1 (perfilador y diagnóstico) están completadas: mira "Estado de la fase 0" y "Estado de la fase 1" en docs/05-roadmap-y-pruebas.md y el código en src/.

1. Resúmeme en pocas líneas qué entendiste del proyecto, las reglas no negociables y las decisiones abiertas.
2. Comprueba que todo sigue en verde: `composer install`, `composer test`, `composer analyse`, `composer lint`, `composer bench` y `cd admin && npm ci && npm run build`. Si algo falla, arréglalo antes de seguir.
3. Propón el plan de archivos de la fase 2 (caché y Cloudflare) y espera mi confirmación. Usa el perfilador de la fase 1 para medir el antes y el después. El hub sigue sin existir: el cliente está probado contra tests/e2e/mock-hub.php.
4. Cualquier endpoint de Cloudflare, hook de LSCache/WP Rocket o API del core marcado como "verificar" debe confirmarse en la documentación oficial vigente antes de usarse.

Trabaja en la rama fase-2-cache-cloudflare y haz commits pequeños en español.
