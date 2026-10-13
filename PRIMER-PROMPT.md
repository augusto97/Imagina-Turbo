# Primer mensaje para Claude Code

Copia y pega esto en Claude Code, abierto en la carpeta del repositorio:

---

Lee CLAUDE.md y todos los archivos de docs/ antes de escribir código. Es la especificación completa del plugin, acordada en una conversación previa y corregida tras una revisión técnica.

La fase 0 (fundaciones) está completada: mira "Estado de la fase 0" en docs/05-roadmap-y-pruebas.md y el código en src/.

1. Resúmeme en pocas líneas qué entendiste del proyecto, las reglas no negociables y las decisiones abiertas.
2. Comprueba que la fase 0 sigue en verde: `composer install`, `composer test`, `composer analyse`, `composer lint`, `composer bench` y `cd admin && npm ci && npm run build`. Si algo falla, arréglalo antes de seguir.
3. Propón el plan de archivos de la fase 1 (perfilador y diagnóstico) y espera mi confirmación. Ten en cuenta que el hub todavía no existe: la telemetría se construye contra un servidor simulado.
4. Cualquier endpoint de Cloudflare, hook de LSCache/WP Rocket o API del core marcado como "verificar" debe confirmarse en la documentación oficial vigente antes de usarse.

Trabaja en la rama fase-1-perfilador y haz commits pequeños en español.
