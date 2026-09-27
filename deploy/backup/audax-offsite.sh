#!/bin/bash
# Copia fuera del servidor de Audax Proyectos (D-076, SPEC §15). PREPARADA, NO ACTIVA: se activa
# cuando el propietario indique el destino (S3 compatible u otro servidor por SFTP). Pasos en
# docs/DEPLOY.md («Copia externa»).
#
# - restic (binario oficial, verificado con su SHA-256) en /usr/local/lib/audax/restic.
# - Configuración en /etc/audax/restic.env (600, root; nunca en Git):
#     RESTIC_REPOSITORY=s3:https://…/audax-proyectos   (o sftp:usuario@host:/ruta)
#     RESTIC_PASSWORD_FILE=/etc/audax/restic.pass       (600; clave del repositorio cifrado)
#     y, para S3, AWS_ACCESS_KEY_ID y AWS_SECRET_ACCESS_KEY.
# - Sube la última copia diaria de /var/backups/audax (volcado cifrado por restic), aplica la
#   rotación (7 diarias, 4 semanales y 6 mensuales) y los domingos comprueba una parte de los datos.
# - Deja el resultado en el estado de las copias que lee la app (last_offsite_at y
#   last_offsite_ok): si falla, app:check-storage avisa al admin.
set -euo pipefail
umask 077

DEST=/var/backups/audax
APP=/var/www/vhosts/projects.audaxstudio.com/app
LOCK="$APP/shared/.heavy.lock"
STATUS="$APP/shared/storage/app/backup-status.json"
ESTADO=/usr/local/lib/audax/estado-copias.py
RESTIC=/usr/local/lib/audax/restic
ENV_FILE=/etc/audax/restic.env

log() { echo "$(date '+%F %T') $*"; }

finish() {
  local code=$?
  if [ -x "$ESTADO" ]; then
    if [ "$code" -eq 0 ]; then
      "$ESTADO" "$STATUS" last_offsite_at=now last_offsite_ok=true || true
    else
      "$ESTADO" "$STATUS" last_offsite_failed_at=now last_offsite_ok=false || true
    fi
  fi
  exit "$code"
}
trap finish EXIT

if [ ! -x "$RESTIC" ] || [ ! -f "$ENV_FILE" ]; then
  log "ERROR: la copia externa no está configurada ($RESTIC, $ENV_FILE)"
  exit 1
fi
set -a
# shellcheck source=/dev/null
. "$ENV_FILE"
set +a

exec 9>>"$LOCK"
if ! flock -w 1800 9; then
  log "ERROR: el candado $LOCK sigue ocupado tras 30 min"
  exit 1
fi

latest=$(find "$DEST/daily" -mindepth 1 -maxdepth 1 -type d -name '20*' | sort | tail -1)
if [ -z "$latest" ]; then
  log "ERROR: no hay ninguna copia diaria que subir"
  exit 1
fi

# Primera vez: inicializa el repositorio cifrado.
if ! "$RESTIC" cat config > /dev/null 2>&1; then
  log "inicializando el repositorio"
  "$RESTIC" init
fi

log "subiendo $latest"
"$RESTIC" backup --host audax-proyectos --tag audax "$latest"
"$RESTIC" forget --host audax-proyectos --tag audax --keep-daily 7 --keep-weekly 4 --keep-monthly 6 --prune

if [ "$(date +%u)" = 7 ]; then
  log "comprobando una parte de los datos del repositorio"
  "$RESTIC" check --read-data-subset=5%
fi

log "copia externa correcta"
