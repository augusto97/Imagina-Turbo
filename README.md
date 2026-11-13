# Imagina Turbo: paquetes de distribución

Esta rama es **huérfana**: no comparte historial con `main` y solo guarda los ZIPs listos para instalar en WordPress. El código fuente está en `main`.

## Versiones

| Versión | ZIP | Generado desde `main` | Fecha |
|---|---|---|---|
| 0.22.1 | [`imagina-turbo-0.22.1.zip`](imagina-turbo-0.22.1.zip) | `040ce29` | 2026-11-02 |

Las versiones anteriores a la 0.22.1 no están aquí: se entregaron como archivo en su momento.

## Cómo se genera cada ZIP

Desde el commit de `main` de esa versión:

```bash
bin/build.sh <directorio_de_salida>
```

El script compila el admin (`npm ci` + Vite), minifica los scripts del frontend, instala las dependencias de producción con `classmap-authoritative`, quita lo que no se ejecuta (repositorios git, pruebas, documentación de las dependencias) y comprueba la sintaxis PHP.

## Comprobar un ZIP

```bash
sha256sum -c SHA256SUMS
```

## Convención

- Un ZIP por versión, con el nombre `imagina-turbo-<versión>.zip`. Un ZIP publicado no se reemplaza: una corrección es una versión nueva.
- Cada versión añade su fila a la tabla y su línea a `SHA256SUMS`, en un commit propio.
- La licencia la inyecta Imagina Updater al subir el ZIP; estos paquetes son los que se suben ahí.
