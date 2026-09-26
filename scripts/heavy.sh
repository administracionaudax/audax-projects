#!/bin/sh
# Ejecuta una tarea pesada (composer, tests) sin molestar al resto del servidor:
# aborta si hay poca memoria o mucha carga, una sola a la vez (candado), prioridad mínima y núcleos 6-7.
set -eu
LOCK=/var/www/vhosts/projects.audaxstudio.com/app/shared/.heavy.lock
avail=$(awk '/MemAvailable/{print int($2/1024)}' /proc/meminfo)
load=$(cut -d' ' -f1 /proc/loadavg)
[ "$avail" -ge 3072 ] || { echo "MemAvailable ${avail} MB < 3072: abortado"; exit 75; }
awk -v l="$load" 'BEGIN{exit !(l<6)}' || { echo "Carga ${load} >= 6: abortado"; exit 75; }
exec flock -n "$LOCK" nice -n 19 ionice -c3 taskset -c 6,7 "$@"
