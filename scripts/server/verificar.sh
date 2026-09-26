#!/bin/bash
# Batería V (RUNBOOK-DESPLIEGUE.md): comprobaciones posteriores a cada paso en el servidor. SOLO LECTURA.
# Uso desde el Mac:  ssh audax "bash -s -- '<T0: AAAA-MM-DD HH:MM:SS>' [<dir de copia>]" < scripts/server/verificar.sh
set -u
export LC_ALL=C
T0=${1:?Falta T0 (hora de inicio del paso)}
D=${2:-}
echo "== Servicios compartidos"
for s in nginx apache2 mariadb redis-server docker bind9 fail2ban postfix dovecot sw-engine sw-cp-server php8.2-fpm \
         plesk-php74-fpm plesk-php80-fpm plesk-php81-fpm plesk-php82-fpm plesk-php83-fpm plesk-php84-fpm plesk-php85-fpm cron; do
  st=$(systemctl is-active "$s" 2>/dev/null); [ "$st" = active ] || echo "  !! $s: $st"
done
echo "  (los no listados arriba están activos)"
echo "== Unidades en failed"
if [ -n "$D" ] && [ -f "$D/failed.txt" ]; then
  systemctl --failed --no-legend | diff "$D/failed.txt" - && echo "  sin unidades nuevas en failed"
else
  systemctl --failed --no-legend
fi
echo "== Configuración web"
nginx -t 2>&1 | tail -1
apache2ctl configtest 2>&1 | tail -1
echo "== Recargas y reinicios de servicios compartidos desde $T0"
journalctl --no-pager --since "$T0" -u nginx -u apache2 -u 'plesk-php*' -u php8.2-fpm -u mariadb -u redis-server -u docker -u bind9 2>/dev/null \
  | grep -Ei 'reload|restart|start|stop' | grep -v -- '-- ' | tail -20
echo "== Contenedores"
docker ps --format '  {{.Names}} | {{.Status}}'
echo "== Recursos"
free -m | sed -n '1,3p'
echo "  load: $(cat /proc/loadavg)"
vmstat 1 3 | tail -2
