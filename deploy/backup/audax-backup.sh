#!/bin/bash
# Copia nocturna de Audax Proyectos (D-029, RUNBOOK-DESPLIEGUE.md A3). La lanza audax-backup.timer
# como root a las 03:40 (Europe/Madrid) con prioridad mínima (Nice 19, E/S idle, CPUQuota 50 %).
#
# - Volcado de PostgreSQL en formato custom (pg_dump -Fc) desde el contenedor propio audax-pg.
# - tar.gz de los adjuntos privados (shared/storage/app/private).
# - Antes de rotar comprueba el código de salida, un tamaño mínimo y que pg_restore --list lee el
#   volcado. Si algo falla, no rota nada y termina con error (queda en el journal: audax-backup).
# - Rotación: 7 diarias, 4 semanales (domingos) y 6 mensuales (día 1), con enlaces duros.
# - Las copias diarias del servidor entero (del propietario) incluyen /var/backups/audax.
set -euo pipefail
umask 077

DEST=/var/backups/audax
APP=/var/www/vhosts/projects.audaxstudio.com/app
LOCK="$APP/shared/.heavy.lock"
STAMP=$(date +%F)
MIN_DUMP_BYTES=10240

log() { echo "$(date '+%F %T') $*"; }

install -d -m 700 "$DEST" "$DEST/daily" "$DEST/weekly" "$DEST/monthly"

# Mismo candado que los despliegues y las tareas pesadas (scripts/heavy.sh).
exec 9>>"$LOCK"
if ! flock -w 1800 9; then
  log "ERROR: el candado $LOCK sigue ocupado tras 30 min"
  exit 1
fi

avail=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo)
if [ "$avail" -lt 2048 ]; then
  log "ERROR: poca memoria disponible ($avail MB); se aplaza la copia"
  exit 1
fi

tmp="$DEST/.tmp-$STAMP"
rm -rf "$tmp"
install -d -m 700 "$tmp"
trap 'rm -rf "$tmp"' EXIT

log "volcando la base audax_projects"
docker exec audax-pg pg_dump -U audax_admin -Fc audax_projects > "$tmp/audax_projects.dump"

size=$(stat -c %s "$tmp/audax_projects.dump")
if [ "$size" -lt "$MIN_DUMP_BYTES" ]; then
  log "ERROR: volcado demasiado pequeño ($size bytes)"
  exit 1
fi

# Se puede leer y contiene los datos de TODAS las tablas que hay ahora en la base.
docker exec -i audax-pg pg_restore --list < "$tmp/audax_projects.dump" > "$tmp/contenido.txt"
tables=$(docker exec audax-pg psql -U audax_admin -d audax_projects -Atc \
  "select tablename from pg_tables where schemaname = 'public' order by 1")
if [ -z "$tables" ] || ! grep -qx users <<< "$tables"; then
  log "ERROR: no se ha podido leer la lista de tablas de la base"
  exit 1
fi
for table in $tables; do
  if ! grep -q "TABLE DATA public $table " "$tmp/contenido.txt"; then
    log "ERROR: el volcado no contiene los datos de la tabla $table"
    exit 1
  fi
done

if [ -d "$APP/shared/storage/app/private" ]; then
  log "empaquetando los adjuntos"
  tar -C "$APP/shared/storage/app" -czf "$tmp/adjuntos.tar.gz" private
  tar -tzf "$tmp/adjuntos.tar.gz" > /dev/null
fi

(cd "$tmp" && sha256sum ./* > SHA256SUMS)

rm -rf "$DEST/daily/$STAMP"
mv "$tmp" "$DEST/daily/$STAMP"
trap - EXIT

if [ "$(date +%u)" = 7 ]; then
  rm -rf "$DEST/weekly/$STAMP"
  cp -al "$DEST/daily/$STAMP" "$DEST/weekly/$STAMP"
fi
if [ "$(date +%d)" = 01 ]; then
  rm -rf "$DEST/monthly/$STAMP"
  cp -al "$DEST/daily/$STAMP" "$DEST/monthly/$STAMP"
fi

rotate() {
  local dir=$1 keep=$2
  find "$dir" -mindepth 1 -maxdepth 1 -type d -name '20*' | sort | head -n "-$keep" | xargs -r rm -rf
}
rotate "$DEST/daily" 7
rotate "$DEST/weekly" 4
rotate "$DEST/monthly" 6

date '+%F %T' > "$DEST/ULTIMA-CORRECTA"

use=$(df --output=pcent "$DEST" | tail -1 | tr -dc '0-9')
if [ "$use" -ge 85 ]; then
  log "AVISO: el disco de $DEST está al $use %"
fi

log "copia correcta: $DEST/daily/$STAMP ($(du -sh "$DEST/daily/$STAMP" | cut -f1); volcado de $size bytes)"
