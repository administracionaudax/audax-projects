#!/bin/bash
# Despliegue de desarrollo (D-002): Mac → projects.audaxstudio.com (app/releases/dev).
# Compila los assets en el Mac, sincroniza con rsync (con --dry-run de seguridad) y en el servidor ejecuta,
# como el usuario de la app (SIN root: alias SSH audax-projects), composer install (si cambió composer.lock),
# migraciones y reinicio de Horizon.
#
# Uso: scripts/desplegar-dev.sh [--sin-build] [--tests]
set -euo pipefail
cd "$(dirname "$0")/.."

export PATH=/usr/local/opt/php@8.4/bin:$PATH
RSYNC=/usr/local/bin/rsync        # GNU rsync 3.x (el de macOS es openrsync y no se usa)
HOST=audax-projects                # usuario audaxprojects: solo puede tocar su webspace
DEST=/var/www/vhosts/projects.audaxstudio.com/app/releases/dev
PHP=/opt/plesk/php/8.4/bin/php
COMPOSER=/opt/psa/var/modules/composer/composer.phar

build=1; tests=0
for arg in "$@"; do
  case "$arg" in
    --sin-build) build=0 ;;
    --tests) tests=1 ;;
    *) echo "Opción desconocida: $arg" >&2; exit 2 ;;
  esac
done

if [ "$build" = 1 ]; then
  echo "== Compilando assets"
  npx vp build >/dev/null
fi

echo "== Simulación de rsync"
plan=$($RSYNC -az --delete --dry-run --itemize-changes --no-owner --no-group --filter='merge .rsync-filter' ./ "$HOST:$DEST/")
if echo "$plan" | grep -E '^\*deleting +(\.env|\.env\.testing|storage|vendor)(/|$)'; then
  echo "ABORTADO: rsync borraría rutas protegidas" >&2
  exit 1
fi
echo "   $(echo "$plan" | grep -c . || true) cambios"

echo "== Sincronizando"
$RSYNC -az --delete --no-owner --no-group --filter='merge .rsync-filter' ./ "$HOST:$DEST/"

echo "== Servidor (usuario audaxprojects)"
ssh -o BatchMode=yes "$HOST" "bash -s" <<EOF
set -euo pipefail
cd $DEST
test -L storage && test -L .env && test -L .env.testing || { echo "Faltan enlaces a shared/"; exit 1; }
if ! sha1sum -c --status ../../shared/.composer-lock.sha1 2>/dev/null; then
  sh scripts/heavy.sh env COMPOSER_MEMORY_LIMIT=1G $PHP $COMPOSER install --no-interaction --prefer-dist --optimize-autoloader --no-progress -q
  sha1sum composer.lock > ../../shared/.composer-lock.sha1
fi
$PHP artisan migrate --force --no-interaction
$PHP artisan horizon:terminate >/dev/null 2>&1 || true
if [ "$tests" = 1 ]; then
  sh scripts/heavy.sh timeout 20m $PHP -d memory_limit=512M vendor/bin/pest --colors=never | tail -5
fi
EOF

echo "== Comprobación"
code=$(curl -s -o /dev/null -w "%{http_code}" https://projects.audaxstudio.com/login)
hdr=$(curl -s -D - -o /dev/null https://projects.audaxstudio.com/login | wc -c | tr -d ' ')
echo "   /login  → $code (cabeceras: $hdr bytes; el proxy nginx admite unos 4 KB)"
[ "$code" = 200 ] || { echo "ERROR: /login no responde 200" >&2; exit 1; }
health_body=$(mktemp)
trap 'rm -f "$health_body"' EXIT
health_code=$(curl -s -o "$health_body" -w "%{http_code}" https://projects.audaxstudio.com/health || true)
echo "   /health → $health_code"
head -c 300 "$health_body"; echo
[ "$health_code" = 200 ] || { echo "ERROR: /health no responde 200 (estado degradado): revisa las comprobaciones de arriba" >&2; exit 1; }
