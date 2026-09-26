# Servidor de producción: auditoría y propuesta de despliegue

> Auditoría **en solo lectura** hecha el 26/09/2026 entre las 11:33 y las 11:40 CEST, sin cambiar nada en el servidor. Los comandos se ejecutaron con `nice -n 19` y `ionice -c3`. La salida bruta se guardó fuera del repositorio porque contiene datos del panel.
> Acceso: `ssh audax` (root, puerto 5222, clave `~/.ssh/audax_projects_ed25519`). La clave la instaló el propietario.

## 1. Resumen

| Aspecto | Estado |
|---|---|
| Máquina | VM KVM · 8 vCPU · 23,5 GiB RAM · disco 176 GB ext4 |
| Sistema | **Debian 10.13 "buster" (sin soporte)** · kernel 4.19.0-27 (06/2024) · systemd 241 · cgroup v1 |
| Panel | **Plesk Obsidian 18.0.80.8**, con actualizaciones automáticas |
| Web | nginx 1.30.4 delante de Apache 2.4.59 (mpm_event), gestionados por Plesk |
| PHP | Plesk 7.4 → **8.4.25** y **8.5.10** (FPM y FPM dedicado disponibles) |
| Bases de datos | MariaDB **10.3.39 (sin soporte)**, con 40 bases. **PostgreSQL no está instalado en el sistema** |
| Redis | 5.0.14 compartido, **sin contraseña ni `maxmemory`** |
| Docker | 26.1.4 (repositorio de buster, sin nuevas versiones). Ya hay 3 contenedores de otros proyectos |
| Firewall | Extensión Firewall de Plesk (iptables), fail2ban (13 jaulas), Imunify360 |
| Otros sitios | 22 suscripciones y 38 sitios; `audaxstudio.com` es una de ellas |

## 2. Sistema
- **CPU:** 8 vCPU (2 × 4, "Common KVM processor", 2,8 GHz).
- **Carga media** durante la auditoría: 3,3–4,2. Uso de CPU: ~20 % usuario y ~20 % sistema, ~60 % libre. Unos 25.000 cambios de contexto por segundo.
- **RAM:** 23,5 GiB en total, ~12 GiB usados, **~10 GiB disponibles** (incluye caché).
- **Swap:** 975 MB, **ocupada al ~97 %**, con `swappiness` a 10.
- **Disco `/`:** 176 GB, **47 GB libres (73 % usado)**; inodos al 14 %.
- **Procesos que más memoria usan:** MariaDB (7,2 GB RSS, buffer pool de 5 GB), ClamAV (~1 GB), pools PHP-FPM de otros sitios (150–260 MB por proceso) y spamd.
- **Repositorios APT:** `archive.debian.org`, porque buster ya no recibe actualizaciones normales de Debian. Además hay repositorios de Plesk (con PHP hasta 8.5), TuxCare (alt-common), Imunify360, Docker (buster), BitNinja y Atomicorp.

## 3. Panel y web
- **Plesk Obsidian 18.0.80.8.** Todos los sitios pertenecen al usuario `admin` ("Ztudio").
- **Extensiones relevantes:** Let's Encrypt, Firewall, Git, Composer, Laravel Toolkit, Node.js Toolkit, Grafana, Imunify360, WP Toolkit y SSH Terminal.
- **Servidor web:** nginx 1.30.4 hace de proxy hacia Apache (7080/7081). Los vhosts están en `/etc/nginx/plesk.conf.d/vhosts/` y **los regenera Plesk**, así que no se editan a mano. `audaxstudio.com` funciona con nginx como proxy a Apache.
- **Subdominio de la app:** `projects.audaxstudio.com` **no existe todavía en Plesk**.
- **Suscripción `audaxstudio.com`:** usuario de sistema propio, shell `/bin/false`, certificado Let's Encrypt, WordPress y el subdominio `paneles.audaxstudio.com`.

## 4. PHP
- **Versiones de Plesk:** 7.4, 8.0, 8.1, 8.2, 8.3, **8.4.25** y **8.5.10**, con handlers `fpm` y `fpm-dedicated` activos. Además está PHP 8.2 del sistema (`/usr/bin/php`, 8.2.11).
- **Pools:** hay 7 pools en PHP 8.4 y 16 en PHP 8.3.
- **Extensiones de PHP 8.4:** tiene todas las que necesita Laravel 13:
  - base de datos: `pdo_pgsql`, `pgsql`, `redis`,
  - procesos: `pcntl`, `posix`,
  - resto: `intl`, `bcmath`, `gd`, `imagick`, `sodium`, `zip`, `exif`, `opcache`.
- **Valores por defecto de la CLI:** `memory_limit` 128M, `upload_max_filesize` 2M, `post_max_size` 8M. Se pueden cambiar por dominio desde Plesk.
- **Herramientas:** Composer 2.8.12 (global), Node.js de Plesk 12 → **22.23.3** y git 2.20.1.

## 5. Datos y servicios
- **MariaDB 10.3.39:** escucha en todas las interfaces; el firewall bloquea el 3306 desde fuera. 40 bases, `max_connections` 100.
- **PostgreSQL:** no hay binarios ni servicio en el sistema. Plesk tiene registrada una entrada antigua "postgresql localhost:5432" que no apunta a nada.
- **Redis 5.0.14:** en 127.0.0.1:6379 con `protected-mode`, **sin contraseña** y **sin `maxmemory`** (`noeviction`). Otros sitios usan las bases db0 (~20.000 claves) y db3 (~43.000 claves).
- **Docker 26.1.4:** driver cgroupfs, cgroup v1, y avisa de "No swap limit support". Contenedores en marcha:
  - `clamav-1.4`,
  - `ainia-api` (127.0.0.1:8080, límites de 2 GB y 2 CPU),
  - `ainia-pg` (pgvector, PostgreSQL 17, sin puertos publicados).
- **Correo:** Postfix 3.4.23 y Dovecot (Plesk), con SpamAssassin y Dr.Web.
- **Supervisor:** no está instalado. Los procesos de larga duración del sistema se gestionan con systemd.

## 6. Red y firewall
- **Puertos abiertos al exterior (iptables, Plesk Firewall):**
  - aceptados: 80, 443, 8443 (panel) y **5222 (SSH)**,
  - descartados: 22, 3306, 5432 y 8000.
- **Puertos locales ya ocupados** (no se pueden reutilizar):
  - 127.0.0.1: 53, 783, 953, 3000, 3030, **6379**, **8080**, 11234, 12346 y 12768,
  - en todas las interfaces: 3306, 7080 y 7081.
- **SSH:** puerto 5222 con `PermitRootLogin yes`.
- **fail2ban:** 13 jaulas de Plesk (incluida `plesk-modsecurity`) más `recidive`. También está Imunify360.

## 7. Cron y tareas
- El crontab de root está vacío. Hay muchas tareas programadas por usuario (tareas de Plesk) y ficheros en `/etc/cron.d` de Plesk, Imunify, maldet y awstats.
- Timers de systemd estándar (limpieza, logrotate, apt).

## 8. Sitios alojados (solo para saber qué no se puede tocar)
- **Suscripciones (22):**
  - ztudio.es, edicionesmonoculo.com, endesarrollo.pro, franciscosanahuja.com, climalgarbenidorm.es, mfautomobile2021.com, audaxstudio.com,
  - chocolateshigon.com, sergicanos.com, impactoworld.com, impactosagunto.com, estepark.es, carwashbenidorm.es, impactoparquets.com,
  - impactocespedartificial.com, climalgar.com, impactotoldos.es, vitatrendy.com, emineo.es, meetingpack.com, powerworks.es, impactodeck.com.
- **Sitios web:** 38, incluidos los subdominios de `endesarrollo.pro`, `paneles.audaxstudio.com` y los `staging.*`.

### Línea base HTTP (26/09/2026 11:37, desde fuera)
Sirve para compararla después de cada cambio (regla 16.3.7).
- **Responden:**
  - con `200`: 19 sitios,
  - con `301`/`303`: redirecciones normales,
  - con `401`/`403`: `nueva.climalgarbenidorm.es` (401), `paneles.audaxstudio.com` (403) y `trading.endesarrollo.pro` (403).
- **Problemas que ya existían antes de empezar:**
  - **certificado SSL no válido** (por HTTPS sin validar responden 301/303): emineo.es, endesarrollo.pro, ezplus, hn, iamanagers, importaco y musicson (`*.endesarrollo.pro`), y staging.edicionesmonoculo.com,
  - **sin DNS**: impactocespedartificial.com, impactoparquets.com e impactosagunto.com.
- Listado completo con códigos y tiempos: `docs/servidor-baseline-2026-09-26.txt`. Se compara con `scripts/server/comparar-webs.sh` (ver `RUNBOOK-DESPLIEGUE.md`, batería V).

## 9. Permisos
- Usuario `root`, sin restricciones. La responsabilidad de no tocar nada ajeno a la app es total (sección 16 del SPEC).

## 10. Observaciones del servidor (ajenas a la app, para el propietario)
Solo se han observado; **no se ha tocado nada**. Algunas afectan a la estabilidad de todos los sitios:
1. **Sistema operativo sin soporte.** Debian 10 terminó su soporte LTS en junio de 2024. El kernel y los paquetes base no reciben parches de seguridad desde entonces. Plesk aún publica versiones para buster, pero conviene planificar la migración a Debian 12 u otro servidor.
2. ~~Un `grep` atascado consumía un núcleo entero desde el 22/09.~~ **Resuelto el 26/09 a las 11:53** (terminado con autorización; ver el registro de cambios).
3. **`cron` y `dbus-daemon`: carga constante.** Diagnóstico del 26/09:
   - **Qué pasa:** cada minuto, `cron` (PID 429) pasa **unos 40 segundos seguidos** (del segundo :02 al :41) consultando en bucle unos **20 nombres de usuario que ya no existen**. Algunos ejemplos: `neitmedia`, `akira`, `zadmin`, `sysuser_6`, `pulsoweb_dev`, `admin-colors`, `admin-mayores`, `ztudio_vitrendy`. No están en `/etc/passwd`, ni en la base de datos de Plesk, ni en ningún fichero de `/etc` o de `/var/spool/cron`.
   - **Por qué carga D-Bus:** `/etc/nsswitch.conf` tiene `passwd: files systemd`, así que cada búsqueda fallida acaba en una llamada a systemd por D-Bus (`LookupDynamicUserByName`). Son unas **1.300 llamadas por segundo** mientras dura el bucle.
   - **Consumo en ese intervalo:** `dbus-daemon` ~80 % de un núcleo y `cron` ~35 %.
   - **Qué lo dispara:** una hipótesis razonable es que Imunify reescribe `/etc/cron.d/imunify-notifier-timed-trigger` cada minuto, lo que obliga a cron a recargar toda su configuración. Como los nombres no aparecen en disco, lo más probable es que **cron arrastre en memoria datos de usuarios borrados** desde que arrancó (20/08).
   - **Ningún servicio usa `DynamicUser=`**, así que el módulo `systemd` de NSS no aporta nada en este servidor.
   - **Resuelto el 26/09 a las 12:09** reiniciando cron, con autorización. La carga de D-Bus y cron es ahora prácticamente nula y las tareas vuelven a lanzarse puntuales. Hay que vigilar si reaparece: si lo hace, la solución de fondo es quitar `systemd` de `passwd:`/`group:` en `nsswitch.conf`.
4. ~~La swap estaba llena al 97 %.~~ **Vaciada el 26/09 a las 11:57** (con autorización).
5. **MariaDB 10.3 y Redis 5 están sin soporte.** Además, **Redis no tiene contraseña**: cualquier proceso del servidor, de cualquier web, puede leer y escribir sus datos.
6. **En Plesk, la suscripción `audaxstudio.com` tiene en "Descripción para el administrador" algo con aspecto de contraseña.** No la reproducimos aquí. Si lo es, conviene cambiarla y guardarla en un gestor de contraseñas.
7. **SSH admite login de root** (con clave; la contraseña también está permitida por defecto). Recomendación, no urgente: `PermitRootLogin prohibit-password`.
8. **Certificados caducados o no válidos** en varios sitios de `endesarrollo.pro`, emineo.es y staging.edicionesmonoculo.com (ver la línea base).

## 11. Propuesta de despliegue (resumen, **pendiente de aprobación**)

> El runbook completo, con los comandos exactos, las comprobaciones, la vuelta atrás de cada paso y los riesgos, está en **[`RUNBOOK-DESPLIEGUE.md`](RUNBOOK-DESPLIEGUE.md)**.
> Se elaboró con 3 diseños independientes, 2 jueces y 3 revisores adversariales; se incorporaron 34 objeciones, 1 de ellas bloqueante.

### Arquitectura
```
Internet → Cloudflare (nube GRIS) → nginx de Plesk (compartido; los otros 38 sitios no cambian)
  └─ projects.audaxstudio.com = SUSCRIPCIÓN PLESK PROPIA (usuario audaxprojects)
       → Apache → PHP-FPM 8.4 DEDICADO (ondemand, máx. 6 procesos)
       → Laravel: webspace/app/current/public (current → releases/dev)

Docker (solo en 127.0.0.1): PostgreSQL 18 :15432 (1 GiB, 1,5 CPU) · Valkey 9 :16379 (contraseña, 448 MiB)
systemd (usuario audaxprojects, grupo con techo de 768 MiB y 1,5 CPU): Horizon (colas) · scheduler
Aplazados a su fase: Reverb (websockets), whisper (transcripción), backups (antes de meter datos reales)
```

### Por qué así
- **Suscripción propia:** tiene usuario, pool PHP, logs y certificado propios. El WordPress de `audaxstudio.com` no puede leer el `.env`, y todo se elimina con un solo comando.
- **Docker solo para lo que el servidor no tiene en buenas condiciones:**
  - no hay PostgreSQL,
  - MariaDB 10.3 está en fin de vida,
  - el Redis compartido no tiene contraseña.
  Ya hay otro proyecto (`ainia`) funcionando así.
- **Nada escucha fuera de `127.0.0.1`.** No se abren puertos ni se toca el firewall de Plesk.
- **Todo lleva límite de RAM y CPU.** Uso típico: 0,8–1,4 GB; techo permanente: ~3,9 GiB de los ~9–10 GiB disponibles.

### Qué afecta a lo compartido (requiere tu aprobación y una franja de poco tráfico)
1. **Unos 4 reinicios _graceful_ de Apache con recarga de nginx**, al crear la suscripción, ajustar PHP, emitir el certificado y activar la redirección HTTPS. Plesk los hace uno a uno. No cortan conexiones, y entre uno y otro se comprueban las 38 webs.
2. **Docker añade reglas de iptables propias y una red interna** (172.30.50.0/24) para los dos contenedores. El firewall de Plesk no se toca.
3. **El primer `composer install`** crea unos 15.000 ficheros que Imunify y maldet escanearán. Se vigila y se aborta si la carga se dispara.
4. **Un `systemctl daemon-reload`** para registrar los servicios de la app.

### Vuelta atrás global
Parar y borrar las unidades `audax-*`, `docker compose down`, eliminar la suscripción y borrar el registro de Cloudflare. El procedimiento completo y sus comprobaciones están en el runbook, §5.
