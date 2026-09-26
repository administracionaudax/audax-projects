#!/bin/bash
# Compara el código HTTP de las webs alojadas con la línea base (regla 16.3.7). Se ejecuta desde el Mac.
# Uso: scripts/server/comparar-webs.sh [docs/servidor-baseline-2026-09-26.txt]
set -u
BASE=${1:-"$(dirname "$0")/../../docs/servidor-baseline-2026-09-26.txt"}
iguales=0; distintos=0
while read -r site code _; do
  case "$site" in ''|\#*) continue ;; esac
  [ "$code" = "ERR" ] && continue
  if [ "$code" = "000" ]; then
    b=$(grep "^$site " "$BASE" | grep -- '(-k' | awk '{print $2}')
    [ -z "$b" ] && continue
    n=$(curl -sk -o /dev/null --max-time 20 -w '%{http_code}' "https://$site/")
  else
    b=$code
    n=$(curl -s -o /dev/null --max-time 20 -w '%{http_code}' "https://$site/")
  fi
  if [ "$b" = "$n" ]; then iguales=$((iguales+1)); else distintos=$((distintos+1)); echo "  !! $site: base $b → ahora $n"; fi
done < <(grep -vE '^#|\(-k|sin DNS' "$BASE")
echo "Webs comparadas: $((iguales+distintos)) · iguales: $iguales · distintas: $distintos"
[ "$distintos" -eq 0 ]
