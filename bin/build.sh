#!/usr/bin/env bash
# Genera el ZIP de distribución: dependencias de producción (classmap-authoritative), admin compilado
# y solo los archivos que necesita el sitio. Uso: bin/build.sh [directorio_de_salida]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/dist}"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/imagina-turbo.php" | head -n1)"
WORK="$(mktemp -d)"
BUILD="$WORK/imagina-turbo"
trap 'rm -rf "$WORK"' EXIT

echo "Imagina Turbo $VERSION"

echo "→ Admin (npm ci + vite build)"
( cd "$ROOT/admin" && npm ci --no-audit --no-fund && npm run build )

echo "→ Copiando archivos"
mkdir -p "$BUILD"
cp "$ROOT/imagina-turbo.php" "$ROOT/uninstall.php" "$ROOT/composer.json" "$ROOT/composer.lock" "$ROOT/README.md" "$BUILD/"
cp -R "$ROOT/src" "$ROOT/mu-loader" "$ROOT/data" "$ROOT/assets" "$BUILD/"
mkdir -p "$BUILD/languages" "$BUILD/admin"
cp -R "$ROOT/admin/dist" "$BUILD/admin/dist"
rm -f "$BUILD/admin/dist/vite-dev-server.json"
if [ -d "$ROOT/languages" ]; then cp -R "$ROOT/languages/." "$BUILD/languages/"; fi

echo "→ Scripts del frontend (minificados, < 3 KB cada uno)"
for js in "$BUILD"/assets/js/*.js; do
  min="${js%.js}.min.js"
  "$ROOT/admin/node_modules/.bin/esbuild" "$js" --minify --target=es2017 --log-level=warning --outfile="$min"
  size=$(wc -c < "$min")
  if [ "$size" -ge 3072 ]; then echo "✗ $(basename "$min") pesa $size bytes (máximo 3 KB)"; exit 1; fi
done

echo "→ Dependencias de producción"
( cd "$BUILD" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --classmap-authoritative --no-interaction --no-progress --quiet )
rm -f "$BUILD/composer.json" "$BUILD/composer.lock"
# Solo lo que se ejecuta: sin repositorios git, pruebas, documentación ni archivos de desarrollo de las
# dependencias (un .git dentro de un plugin hace saltar los escáneres de seguridad de los hostings).
find "$BUILD/vendor" \( -name .git -o -name .github -o -name tests -o -name docs \) -type d -prune -exec rm -rf {} +
find "$BUILD/vendor" -maxdepth 3 \( -name 'package*.json' -o -name 'composer.lock' -o -name '.editorconfig' -o -name '.gitattributes' -o -name '.gitignore' -o -name 'phpcs.xml*' -o -name 'phpunit.xml*' -o -name 'Gruntfile.js' -o -name 'codecov.yml' \) -type f -delete
if find "$BUILD" -name .git | grep -q .; then echo "✗ Quedó un .git en el paquete"; exit 1; fi

echo "→ Verificando sintaxis"
find "$BUILD/src" "$BUILD/mu-loader" "$BUILD"/*.php -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

mkdir -p "$OUT"
ZIP="$OUT/imagina-turbo-$VERSION.zip"
rm -f "$ZIP"
( cd "$WORK" && zip -qr "$ZIP" imagina-turbo )
echo "✓ $ZIP"
