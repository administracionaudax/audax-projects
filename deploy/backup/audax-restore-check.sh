#!/bin/bash
# Prueba mensual de restauración de Audax Proyectos (D-076, SPEC §15 «comprobación periódica de
# que las copias se pueden restaurar»). La lanza audax-restore-check.timer como root con prioridad
# mínima (Nice 19, E/S idle, CPUQuota 50 %) y el mismo candado que las copias y los despliegues.
#
# 1. Toma la última copia diaria de /var/backups/audax y comprueba sus sumas SHA-256.
# 2. La restaura en una base TEMPORAL (audax_restore_check) del contenedor propio audax-pg.
# 3. Comprueba que existen todas las tablas del volcado y que los recuentos de las tablas clave
#    cuadran con recuentos.txt (tomados justo antes del volcado; margen pequeño por lo que se
#    escribiera entre el recuento y el volcado).
# 4. Borra la base temporal, pase lo que pase, y deja el resultado en el estado de las copias
#    que lee la app (last_restore_check_at y last_restore_check_ok): si falla, app:check-storage
#    avisa al admin.
set -euo pipefail
umask 077

DEST=/var/backups/audax
APP=/var/www/vhosts/projects.audaxstudio.com/app
LOCK="$APP/shared/.heavy.lock"
STATUS="$APP/shared/storage/app/backup-status.json"
ESTADO=/usr/local/lib/audax/estado-copias.py
DB=audax_restore_check

log() { echo "$(date '+%F %T') $*"; }
psql_admin() { docker exec audax-pg psql -U audax_admin -v ON_ERROR_STOP=1 -qAt "$@"; }

finish() {
  local code=$?
  psql_admin -d postgres -c "drop database if exists $DB" > /dev/null 2>&1 || true
  if [ -x "$ESTADO" ]; then
    if [ "$code" -eq 0 ]; then
      "$ESTADO" "$STATUS" last_restore_check_at=now last_restore_check_ok=true || true
    else
      "$ESTADO" "$STATUS" last_restore_check_at=now last_restore_check_ok=false || true
    fi
  fi
  exit "$code"
}
trap finish EXIT

exec 9>>"$LOCK"
if ! flock -w 1800 9; then
  log "ERROR: el candado $LOCK sigue ocupado tras 30 min"
  exit 1
fi

avail=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo)
if [ "$avail" -lt 2048 ]; then
  log "ERROR: poca memoria disponible ($avail MB); se aplaza la prueba"
  exit 1
fi

latest=$(find "$DEST/daily" -mindepth 1 -maxdepth 1 -type d -name '20*' | sort | tail -1)
if [ -z "$latest" ] || [ ! -f "$latest/audax_projects.dump" ]; then
  log "ERROR: no hay ninguna copia diaria que probar"
  exit 1
fi
log "probando la copia $latest"
(cd "$latest" && sha256sum --quiet -c SHA256SUMS)

psql_admin -d postgres -c "drop database if exists $DB"
psql_admin -d postgres -c "create database $DB"
docker exec -i audax-pg pg_restore -U audax_admin -d "$DB" --no-owner --no-privileges --exit-on-error < "$latest/audax_projects.dump"

# Todas las tablas del volcado existen en la base restaurada.
expected=$(docker exec -i audax-pg pg_restore --list < "$latest/audax_projects.dump" \
  | awk '$4 == "TABLE" && $5 == "DATA" && $6 == "public" {print $7}' | sort -u)
restored=$(psql_admin -d "$DB" -c "select tablename from pg_tables where schemaname = 'public' order by 1")
missing=$(comm -23 <(echo "$expected") <(echo "$restored" | sort -u) | tr '\n' ' ')
if [ -n "${missing// /}" ]; then
  log "ERROR: faltan tablas en la base restaurada: $missing"
  exit 1
fi

# Recuentos de las tablas clave.
if [ -f "$latest/recuentos.txt" ]; then
  while IFS='|' read -r table recorded; do
    [ -n "$table" ] || continue
    got=$(psql_admin -d "$DB" -c "select count(*) from public.$table")
    margin=$(( recorded / 100 > 50 ? recorded / 100 : 50 ))
    if [ "$recorded" -gt 0 ] && [ "$got" -eq 0 ]; then
      log "ERROR: la tabla $table está vacía en la copia (tenía $recorded filas)"
      exit 1
    fi
    if [ "$got" -lt $(( recorded - margin )) ] || [ "$got" -gt $(( recorded + margin )) ]; then
      log "ERROR: la tabla $table tiene $got filas en la copia y $recorded al hacerla"
      exit 1
    fi
    log "  $table: $got filas (al hacer la copia, $recorded)"
  done < "$latest/recuentos.txt"
else
  log "AVISO: la copia no tiene recuentos.txt (anterior a D-076); solo se comprueban las tablas"
fi

log "restauración correcta de $latest ($(echo "$expected" | wc -w) tablas)"
