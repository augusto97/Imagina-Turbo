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
cp -R "$ROOT/src" "$ROOT/mu-loader" "$ROOT/data" "$BUILD/"
mkdir -p "$BUILD/languages" "$BUILD/admin"
cp -R "$ROOT/admin/dist" "$BUILD/admin/dist"
rm -f "$BUILD/admin/dist/vite-dev-server.json"
if [ -d "$ROOT/languages" ]; then cp -R "$ROOT/languages/." "$BUILD/languages/"; fi

echo "→ Dependencias de producción"
( cd "$BUILD" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --classmap-authoritative --no-interaction --no-progress --quiet )
rm -f "$BUILD/composer.json" "$BUILD/composer.lock"

echo "→ Verificando sintaxis"
find "$BUILD/src" "$BUILD/mu-loader" "$BUILD"/*.php -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

mkdir -p "$OUT"
ZIP="$OUT/imagina-turbo-$VERSION.zip"
rm -f "$ZIP"
( cd "$WORK" && zip -qr "$ZIP" imagina-turbo )
echo "✓ $ZIP"
