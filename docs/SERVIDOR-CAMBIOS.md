# Registro de cambios en el servidor (svr.ztudio.es)

Cada acción que modifique algo en el servidor se anota aquí **antes y después** de hacerla, con:
- fecha y hora (Europe/Madrid),
- comando exacto,
- motivo,
- resultado,
- cómo revertirla,
- comprobación de los demás sitios comparada con la línea base.

| Fecha y hora | Acción / comando | Motivo | Resultado | Cómo revertir |
|---|---|---|---|---|
| 26/09/2026 (antes de las 11:33) | **El propietario**, no Claude, añade la clave pública `claude-audax-projects` a `/root/.ssh/authorized_keys` | Dar acceso SSH para la auditoría | Acceso correcto por el puerto 5222 | Borrar esa línea de `authorized_keys` |
| 26/09/2026 11:33–11:40 | Auditoría **en solo lectura** (`hostnamectl`, `ps`, `ss`, `plesk bin … --list/--info`, `php -m`, `redis-cli INFO`, `docker ps/info`, …) con `nice -n 19 ionice -c3` | Sección 16.2 del SPEC | **Sin cambios.** Salida guardada fuera del repositorio | No aplica |
| 26/09/2026 11:37 | Línea base HTTP de los 38 sitios, **desde el Mac** (una petición `GET /` por sitio) | Referencia para las comprobaciones posteriores | Guardada en `docs/servidor-baseline-2026-09-26.txt` | No aplica |
| 26/09/2026 11:53:55 | `kill -TERM 29594`. Antes se comprobó que el PID correspondía exactamente a `grep -R -l php-fpm-pool-settings /var/www/vhosts /usr/local/psa/admin/conf /opt/psa` | Llevaba 3 días y 18 horas al 100 % de un núcleo. **Autorizado por el propietario** en el chat | Terminado al primer TERM | No aplica (era una búsqueda puntual; se puede relanzar si hiciera falta) |
| 26/09/2026 11:54:25–11:57:37 | `swapoff /dev/sda5 && swapon /dev/sda5`. Solo se ejecuta si la RAM disponible supera la swap usada + 4 GiB (había 10.483 MB disponibles y 954 MB en swap) | Vaciar la swap, que estaba al 97 %. **Autorizado por el propietario** en el chat | Swap de 954 MB a 0 MB; RAM disponible de 10.483 a 9.074 MB. Carga media sin cambios (~4,4) | No aplica: la swap sigue activa y se volverá a usar con normalidad |
| 26/09/2026 ~11:58 | Comprobación HTTP de los 38 sitios frente a la línea base | Regla 16.3.7 | **Sin cambios**: 35 sitios responden con el mismo código; los 3 sin DNS siguen igual | No aplica |
| 26/09/2026 12:00–12:05 | Diagnóstico **en solo lectura** de la carga de `dbus-daemon` y `cron` (`/proc/*/stat`, `busctl monitor` durante 2–5 s, `grep`) | Petición del propietario | Causa encontrada (ver `SERVIDOR.md` §10.3). **Sin cambios** | No aplica |
| 26/09/2026 12:09:50 | `systemctl restart cron`, lanzado en el segundo :50, después de la tanda de tareas de ese minuto (PID 429 → 26077) | Eliminar el bucle de consultas de usuarios inexistentes. **Autorizado por el propietario** en el chat | 70 s después: `dbus` con pico del 1 % (antes ~80 %), `cron` 0 % (antes ~35 %), 0 llamadas `LookupDynamicUserByName` (antes ~1.300/s). Las tareas vuelven a lanzarse en el segundo :01 (antes ~:41). Carga media de ~4,4 a ~2,3 | No aplica. Si el problema reaparece, el siguiente paso propuesto es quitar `systemd` de `passwd:`/`group:` en `/etc/nsswitch.conf` |
| 26/09/2026 12:11 | Comprobación HTTP frente a la línea base; servicios activos (`cron`, `dbus`, `nginx`, `apache2`, `mariadb`) | Regla 16.3.7 | **35/35 sitios comparables con el mismo código.** Todos los servicios, activos | No aplica |
