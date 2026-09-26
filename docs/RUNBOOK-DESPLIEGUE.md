# Runbook de despliegue: Fase 0 (projects.audaxstudio.com)
> Documento de referencia generado el 26/09/2026 con 3 diseños independientes, 2 jueces, síntesis, 3 revisores adversariales (34 objeciones; 1 bloqueante) y corrección.
> **Estado: pendiente de aprobación.** Resumen para decidir en `SERVIDOR.md` §11. Nada de esto se ha ejecutado todavía.


> Estado: **pendiente de aprobación del propietario** (SPEC §16.2). Esta propuesta no cambia nada en el servidor.
> Se basa en la auditoría en solo lectura del 26/09/2026 (11:35 CEST) y en la línea base HTTP de las 11:37.
> Enfoque elegido: **primero el panel**. La suscripción Plesk es propia, con PHP 8.4 FPM dedicado y Let's Encrypt. Docker se usa solo para PostgreSQL 18 y Valkey, publicados en 127.0.0.1. Los procesos largos van en systemd, con el usuario de la suscripción, dentro de un slice propio anidado en `system.slice` y con límites de cgroup v1.
> Datos de partida confirmados por el usuario: SSH en el **puerto 5222** (D-001 ya corregido). El registro `projects.audaxstudio.com` está en **nube gris** (DNS only) en Cloudflare. El repositorio es `github.com/administracionaudax/audax-projects`.

Alternativas descartadas (una línea cada una):
- **Todo el stack en Docker**: la operativa diaria exigiría root o sudoers, se perderían el WAF y el PHP-FPM del panel, y toda la app dependería de un Docker congelado.
- **Colas, caché y sesiones en PostgreSQL, sin Redis ni Horizon**: se aparta del §2 y obliga a migrar los drivers justo cuando haya datos reales.
- **MariaDB 10.3 compartida**: está en fin de vida y por debajo del mínimo 10.11 que exige el §2.
- **Redis 5 compartido**: no tiene contraseña ni `maxmemory` (incumple §16.3.5), y otros sitios usan db0 y db3.
- **PostgreSQL de Debian 10**: sería la versión 11 (fin de vida) y exigiría tocar apt.
- **Subdominio dentro de la suscripción `audaxstudio.com`**: compartiría el usuario de sistema con el WordPress, que podría leer el `.env`.
- **Laravel Toolkit o Node.js Toolkit de Plesk**: cambian el docroot y lanzan composer y npm sin `nice`.
- **Supervisor**: no está instalado. systemd ya está y no añade paquetes.

---

### 1. Arquitectura en este servidor

```
Internet ── Cloudflare DNS (nube GRIS: A projects.audaxstudio.com → 185.33.65.98)
   │  80/443 (ya abiertos; el firewall de Plesk no se toca)
   ▼
nginx 1.30.4 (Plesk, COMPARTIDO) ── ~38 sitios existentes: sin cambios
   └─ vhost projects.audaxstudio.com  (suscripción Plesk NUEVA, usuario audaxprojects, bloqueada frente al plan)
        ├─ estáticos (public/build/*)  → nginx directamente
        ├─ resto → Apache 2.4.59 :7081 (modo proxy por defecto; .htaccess de Laravel; ModSecurity)
        │            → PHP-FPM 8.4.25 DEDICADO (maestro propio; pm=ondemand, máx. 6 hijos)
        └─ [aplazado] location ^~ /app/ → 127.0.0.1:18080 Reverb (WebSocket)

/var/www/vhosts/projects.audaxstudio.com/            (webspace de la suscripción)
   ├─ .bash_profile → carga .bashrc (PATH con PHP 8.4 primero)
   └─ app/
      ├─ current -> releases/dev     ← docroot: app/current/public
      ├─ releases/dev/               ← destino del rsync desde el Mac (D-002)
      └─ shared/  .env (600) · .env.testing (600) · storage/ · .heavy.lock

Laravel 13 ──TCP 127.0.0.1:15432──► audax-pg      (Docker, postgres:18-bookworm, 1 GiB, 1,5 CPU, oom_score_adj 900)
   │                                   BD audax_projects (rol audax_app)
   │                                   BD audax_projects_test (rol audax_test)
   ├──TCP 127.0.0.1:16379──► audax-valkey  (Docker, valkey 9.0, requirepass, maxmemory 192 MB, límite 448 MiB)
   │
   └─ systemd, system-audax.slice (dentro de system.slice; tope conjunto 768 MiB / 150 % CPU / CPUShares 256)
        User=audaxprojects, OOMScoreAdjust=1000
        ├─ audax-horizon.service     (colas; 512 MiB, 100 % CPU)
        ├─ audax-scheduler.service   (schedule:work; 256 MiB, 50 % CPU)
        ├─ [aplazado] audax-reverb.service       127.0.0.1:18080
        └─ [aplazado] audax-transcriber.service  → audax-whisper (Docker) 127.0.0.1:18091

Datos de Docker: /var/lib/audax/{pg,valkey} (propiedad del uid del contenedor)  ·  compose y secretos: /opt/audax, /etc/audax (root, 600/700)
Script auxiliar: /usr/local/lib/audax/esperar-puerto.sh (root, 755)
NO SE USAN: MariaDB 10.3 · Redis :6379 · Postfix local · clamav-1.4 · puerto 5432
```

**Alcance de la Fase 0 (lo que se instala ahora):**
- la suscripción,
- el pool PHP 8.4 FPM dedicado,
- el certificado Let's Encrypt,
- PostgreSQL 18 y Valkey en Docker,
- Horizon y el scheduler en systemd,
- la estructura de directorios, el flujo rsync y los tests contra una base `_test`.

**Lo que se aplaza, y por qué:**

| Pieza | Cuándo | Motivo |
|---|---|---|
| Reverb y la directiva nginx `/app/` | Fase 6 (chat), o antes si se aprueba tiempo real para las notificaciones | Hasta entonces la campana puede hacer polling. Así se ahorran una pieza y una recarga de nginx |
| Transcriptor whisper.cpp | Fase 6, tras medir los modelos (§12) | No hay audios hasta el chat |
| Backups propios con copia externa | **Antes de meter datos reales** (hito de separación prod/dev de D-002), a más tardar en la Fase 7 | Durante D-002 no hay datos reales, y el destino externo está pendiente |
| Separación prod/dev (`deploy.sh`) | Cuando la plantilla empiece a usar la app | D-002 |
| Límite de subida de 50 MB en ModSecurity y nginx | Fase 1 (adjuntos) | Se comprueba en solo lectura y se ajusta solo para este dominio |

**Decisiones de diseño clave:**
- **Suscripción propia, no un subdominio de `audaxstudio.com`.** Tiene usuario de sistema, vhost, pool, cron y logs propios, y se elimina entera con un comando. El servidor lo permite (`forbid-create-dns-subzone: false`). Se **bloquea frente a la sincronización con el plan** para que un cambio futuro en «Default Domain», que usan otros sitios, no le cambie el handler ni los ajustes de PHP.
- **Handler `plesk-php84-fpm-dedicated`.** Tiene su propio maestro FPM, así que cambiar sus ajustes no debería recargar `plesk-php84-fpm`, que sirve a otros 7 pools. No se da por supuesto: se comprueba en el journal en cada paso (batería V).
- **Sin directivas nginx en la Fase 0.** La cabecera `X-Robots-Tag: noindex, nofollow` y `robots.txt` los pone Laravel (middleware), así que no se toca nginx.
- **Laravel en el disco, no en contenedores.** PHP lo aporta Plesk (8.4.25 con `pdo_pgsql`, `redis`, `pcntl`, `posix`, `intl`, `bcmath`, `gd`, `imagick`, `sodium` y `zip`, verificado). Los comandos de desarrollo se ejecutan como `audaxprojects`, sin root ni sudo.
- **Scheduler con `schedule:work` como proceso largo**, no como tarea de cron por minuto. `schedule:work` sigue lanzando cada minuto un `php artisan schedule:run`, así que los 1440 arranques diarios de PHP **no desaparecen**. Lo que se evita es pasar cada minuto por `cron`, PAM, logind y dbus, que llegaron a cargarse mucho (el bucle de cron quedó resuelto el 26/09; ver `SERVIDOR-CAMBIOS.md`). Además, el coste queda dentro del slice y con límite. La CPU real se mide tras el paso 9.
- **Assets compilados en el Mac o en GitHub Actions por defecto.** Se sube `public/build` y no hay `node_modules` en el servidor. Node 22 de Plesk (`/opt/plesk/node/22`) queda solo como alternativa.
- **CLI de PHP siempre con la ruta absoluta `/opt/plesk/php/8.4/bin/php`**, en el runbook, en `heavy.sh` y en las unidades. El `php` del PATH del sistema es el 8.2.11, y el shebang de `/usr/local/bin/composer` lo usaría. El PATH del usuario se ajusta además en `.bash_profile` y `.bashrc` (Plesk no crea ninguno de los dos) como red de seguridad, no como mecanismo principal.
- **Imágenes y PDF se procesan solo en jobs de Horizon**, nunca en la petición web. Imagick reserva memoria fuera de `memory_limit` y el pool FPM no tiene cgroup; dentro del slice, en cambio, hay límite duro.

---

### 2. Qué hay que instalar o cambiar: runbook

**Reglas que se aplican a todos los pasos:**
- **Nunca** se reinicia `docker.service` ni se toca `/etc/docker/daemon.json`. `clamav-1.4` tiene `restart=no` y no volvería a arrancar.
- **Nunca** se edita a mano nada de `/etc/nginx/plesk.conf.d/`, `/etc/apache2/plesk.conf.d/` ni los pools de `/opt/plesk/php/*/etc/php-fpm.d/`.
- **Antes de cada paso** se anota la hora (`T0=$(date '+%F %T')`) y la memoria disponible. **Después** se ejecuta la batería de comprobaciones **V** (definida al final de esta sección) y se anota el cambio en `docs/SERVIDOR-CAMBIOS.md`: fecha, comando, motivo, resultado y cómo revertirlo.
- Si algo se desvía de la línea base o de los umbrales de V, **se revierte ese paso y se avisa**.
- Los pasos marcados como «compartido: sí» se hacen solo con aprobación expresa y **en una ventana de poco tráfico**. En este servidor `restart-apache` vale `0`: Plesk reinicia Apache y recarga nginx **después de cada operación**, sin agruparlas. Por eso los pasos 2, 3 y 5 suponen **al menos 4 recargas separadas**, y entre una y otra se pasa la batería V.
- **Quién ejecuta:** los pasos con root los ejecuta quien tenga root. Si no tenemos root, el propietario ejecuta los comandos exactos que se indican (§16.3.8).

#### Paso 0. Comprobaciones previas (solo lectura)
- **Comandos (root, en el servidor):**
  ```bash
  cat /sys/block/sda/queue/scheduler                 # si es mq-deadline/none, ionice no tiene efecto
  ss -ltn | grep -E ':(15432|16379|18080|18091)\b'   # debe salir vacío
  ip route; docker network ls                        # confirmar que 172.30.50.0/24 está libre (hoy: 172.17 y 172.19)
  plesk bin service_plan --info "Default Domain"     # ¿existe el plan? ¿qué activa?
  plesk bin server_pref --show | grep -iE 'restart|graceful'   # hoy restart-apache: 0
  # + en la interfaz: Herramientas y configuración > Servidor web Apache: confirmar que el reinicio es GRACEFUL.
  #   Si no lo es, se para aquí y se consulta: cada operación cortaría las peticiones de los 38 sitios.
  plesk bin subscription --help | grep -iE 'passwd|PSA_PASSWORD|lock|php-settings'
  grep -h client_max_body_size /etc/nginx/plesk.conf.d/vhosts/audaxstudio.com.conf   # valor por defecto de la plantilla
  grep -rh SecRequestBodyLimit /etc/apache2/modsecurity.d/ /etc/modsecurity/ 2>/dev/null
  dpkg -l unattended-upgrades 2>/dev/null | tail -1
  docker compose version                              # se espera el plugin v2.27.1
  nginx -t && apache2ctl configtest                   # la configuración actual debe estar sana ANTES de empezar
  grep ' /proc ' /proc/mounts                         # ¿hidepid? (visibilidad de líneas de comando ajenas)
  # Estado de cgroups ANTES de crear el slice (cgroup v1, systemd 241):
  systemctl show docker -p Delegate
  systemctl show system.slice user.slice -p CPUAccounting,MemoryAccounting
  ls /sys/fs/cgroup/cpu,cpuacct/system.slice/ /sys/fs/cgroup/memory/system.slice/ | head -50
  for p in $(pgrep -o nginx) $(pgrep -o mysqld) $(pgrep -o apache2) $(pgrep -o -f 'php-fpm: master'); do echo "== $p"; cat /proc/$p/cgroup; done
  fail2ban-client get plesk-modsecurity ignoreip      # IP de confianza actuales
  ```
  **En la interfaz (solo mirar):** si SSL It! o Let's Encrypt tienen activo «Mantener los sitios protegidos» (emisión automática en dominios nuevos). Si lo tienen, la creación del paso 2 lanzará por su cuenta una emisión y una recarga más. Se cuenta como tal y se decide antes de empezar.
- **Comandos (desde el Mac):**
  ```bash
  dig +short projects.audaxstudio.com   # solo 185.33.65.98
  dig +short CAA audaxstudio.com        # vacío, o que incluya letsencrypt.org
  rsync --version | head -1             # el de macOS es openrsync; se usará GNU rsync 3.x de Homebrew
  curl -s ifconfig.me                   # IP de salida de la oficina (para la decisión 7.9)
  ```
- **Toca algo compartido:** no.
- **Vuelta atrás:** no aplica.

#### Paso 1. Copia previa del estado (solo lectura sobre lo compartido; §16.3.6)
- **Comandos (root):**
  ```bash
  umask 077
  D=/root/audax-backup/$(date +%F-%H%M); install -d -m 700 "$D"
  tar czf "$D/confs.tgz" /etc/nginx/plesk.conf.d /etc/apache2/plesk.conf.d \
      /opt/plesk/php/8.4/etc /etc/systemd/system
  plesk db dump psa > "$D/psa.sql"                                  # estado del panel (600)
  cp -a /etc/passwd /etc/group /etc/shadow "$D/"
  plesk bin dns --info audaxstudio.com > "$D/dns-audaxstudio.txt"
  iptables-save -t filter > "$D/ipt-filter.txt"; iptables-save -t nat > "$D/ipt-nat.txt"; ip -br link > "$D/links.txt"
  docker ps -a > "$D/docker-ps.txt"; docker network ls > "$D/docker-net.txt"
  plesk bin subscription --list > "$D/subs.txt"; systemctl list-units --no-pager > "$D/units.txt"
  systemctl --failed --no-legend > "$D/failed.txt"
  ps -o pid,lstart,cmd -C php-fpm > "$D/fpm-ps.txt"
  free -m > "$D/free.txt"; uptime > "$D/uptime.txt"
  ```
  Desde el Mac: repetir la línea base HTTP y guardarla junto a `baseline-http.txt`.
- **Toca algo compartido:** no. Solo escribe en `/root/audax-backup` (700; contiene `shadow` y el volcado del panel).
- **Comprobación posterior:** `ls -la "$D"`.
- **Vuelta atrás:** `rm -rf /root/audax-backup/<fecha>`, cuando ya no haga falta.

#### Paso 2. Crear la suscripción `projects.audaxstudio.com`
Es una sola operación de Plesk: provoca **una** recarga de nginx y **un** reinicio de Apache, más la emisión automática si SSL It! la tiene activa (paso 0).
- **Comando (root)**, solo si `plesk bin subscription --help` del paso 0 confirma que `PSA_PASSWORD` se admite con `-passwd ""`:
  ```bash
  PSA_PASSWORD="$(openssl rand -base64 24)" plesk bin subscription --create projects.audaxstudio.com \
    -owner admin -service-plan "Default Domain" -ip 185.33.65.98 \
    -login audaxprojects -passwd "" -hst_type phys \
    -www-root app/current/public -php true -php_handler_id plesk-php84-fpm-dedicated \
    -shell /bin/bash -mail_service false -dns false -ssl true
  ```
  - Si `--help` **no** lo confirma, la suscripción se crea **desde la interfaz** (*Suscripciones > Añadir suscripción*) con los mismos valores y una contraseña generada en el propio formulario. Así la contraseña no pasa por el historial ni por la lista de procesos. La contraseña FTP no se usa: el acceso es solo por clave.
  - El plan depende de la decisión 7.2.
- **Toca algo compartido:** **sí**. Plesk genera el vhost, reinicia Apache (graceful, confirmado en el paso 0) y recarga nginx.
- **Validación previa:** paso 0 correcto. `plesk bin site --info projects.audaxstudio.com` debe responder que el dominio no existe.
- **Comprobación posterior:**
  - `plesk bin subscription --info projects.audaxstudio.com`: IP, handler, shell `/bin/bash` y correo desactivado.
  - `id audaxprojects`.
  - `systemctl list-units --no-pager --all 'plesk-php84-fpm*'`: aparece el maestro dedicado (se **anota el nombre exacto de su unidad**, necesario para la decisión 7.10) y `plesk-php84-fpm` sigue activo.
  - `plesk bin dns --info audaxstudio.com | diff - "$D/dns-audaxstudio.txt"`: **sin diferencias**. Además, `journalctl --since "$T0" -u bind9`: sin recargas inesperadas.
  - `curl -sI -H 'Host: projects.audaxstudio.com' http://185.33.65.98/`: página por defecto de Plesk.
  - Si hubo emisión automática, se revisa qué nombres incluye el certificado (`plesk bin certificate --list -domain projects.audaxstudio.com`).
  - Batería **V**.
- **Vuelta atrás:** `plesk bin subscription --remove projects.audaxstudio.com`. Elimina el vhost, el pool, el usuario y el certificado, y provoca otra recarga. Después, batería **V**. Si el borrado falla a medias, se restaura a partir de `$D/psa.sql` y de las copias de `passwd`, `group` y `shadow`, con el propietario.

#### Paso 3. Ajustes PHP y del pool FPM dedicado (solo este dominio)
- **Interfaz:** *Sitios web y dominios > projects.audaxstudio.com > Configuración de PHP*. Los valores generales van en sus campos; las directivas de pool, en «Directivas adicionales».
- **CLI equivalente:** `plesk bin subscription --update-php-settings projects.audaxstudio.com -settings /root/audax-backup/php-general.ini -additional-settings /root/audax-backup/php-additional.ini`
- **`php-general.ini`** (solo los ajustes generales que admite `-settings`):
  ```ini
  memory_limit = 256M
  max_execution_time = 60
  max_input_time = 120
  post_max_size = 64M
  upload_max_filesize = 55M
  display_errors = off
  log_errors = on
  opcache.enable = on
  ```
- **`php-additional.ini`:**
  ```ini
  opcache.validate_timestamps = 1
  opcache.revalidate_freq = 2
  realpath_cache_size = 4096K

  [php-fpm-pool-settings]
  pm = ondemand
  pm.max_children = 6
  pm.max_requests = 500
  pm.process_idle_timeout = 10s
  request_terminate_timeout = 300s
  ```
  - `opcache.memory_consumption` y `opcache.max_accelerated_files` **no se ponen**. Son directivas de arranque (PHP_INI_SYSTEM) que el maestro lee del `php.ini` global de 8.4, y por pool no tienen efecto. Ese `php.ini` no se toca. Se comprueba el valor real desde la app (ver comprobación posterior). Si el `max_accelerated_files` global se queda corto para Laravel, se informa al propietario en lugar de cambiarlo.
- **Bloqueo frente al plan:** después de aplicar los ajustes, `plesk bin subscription --lock-subscription projects.audaxstudio.com`, tras confirmar el nombre exacto de la opción en `--help`. Si no existe, se bloquea desde la interfaz (*Suscripción > Bloquear*/«Personalizada»), o se valora un plan propio (decisión 7.2).
- **Toca algo compartido:** **sí (posible)**. Si Plesk regenera la configuración web del dominio, con `restart-apache: 0` habrá un reinicio graceful de Apache y una recarga de nginx. Se hace en la ventana y con aprobación.
- **Validación previa:** `plesk bin subscription --show-php-settings projects.audaxstudio.com > "$D/php-antes.txt"`.
- **Comprobación posterior:**
  - `grep -rl projects.audaxstudio.com /opt/plesk/php/8.4/etc/`, para localizar el pool generado.
  - `grep -E '^pm|request_terminate' <ese fichero>`: deben salir `ondemand`, `6`, `500`, `10s` y `300s`. Si no salen, se fijan desde la interfaz, en *Configuración de PHP-FPM*.
  - `ps -o user,rss,cmd -u audaxprojects | grep php-fpm`: solo el maestro, 0 hijos en reposo.
  - `plesk bin subscription --info projects.audaxstudio.com`: estado de sincronización bloqueado o personalizado.
  - Tras el paso 8, `/health` (solo admin) devuelve `ini_get('realpath_cache_size')`, `opcache.max_accelerated_files` y `num_cached_scripts`, para ver el efecto real.
  - Batería **V**, con especial atención al journal de `plesk-php84-fpm`: cualquier «Reloading» cuenta como desviación.
- **Vuelta atrás:** reaplicar `$D/php-antes.txt` desde la interfaz, o vaciar *Directivas adicionales* y restaurar los valores por defecto (128M, 2M, 8M). Desbloquear con `--unlock-subscription` si procede.

#### Paso 4. Clave SSH, entorno de shell y estructura de directorios
- **Como root** (la clave pública del Mac la aporta el propietario):
  ```bash
  H=/var/www/vhosts/projects.audaxstudio.com
  install -d -m 700 -o audaxprojects -g psacln $H/.ssh
  install -m 600 -o audaxprojects -g psacln /root/audax-backup/mac_ed25519.pub $H/.ssh/authorized_keys
  ```
- **Como `audaxprojects`:**
  ```bash
  cd ~ && mkdir -p app/releases app/shared/storage/{app/private,framework/{cache,sessions,views},logs}
  mv app/current app/releases/dev && ln -s releases/dev app/current      # Plesk creó current/public como directorio real
  chmod 700 app/shared && install -m 600 /dev/null app/shared/.env && install -m 600 /dev/null app/shared/.env.testing
  touch app/shared/.heavy.lock
  # Plesk no crea .bashrc ni .bash_profile; un login SSH es shell de login y solo leería .bash_profile/.profile
  echo 'export PATH=/opt/plesk/php/8.4/bin:/opt/plesk/node/22/bin:$PATH' > ~/.bashrc
  echo '[ -f ~/.bashrc ] && . ~/.bashrc' > ~/.bash_profile
  ```
- **En el Mac, `~/.ssh/config`:**
  ```
  Host audax-projects
    HostName 185.33.65.98
    Port 5222
    User audaxprojects
    IdentityFile ~/.ssh/audax_projects_ed25519
  ```
- **Toca algo compartido:** no; `sshd_config` no se toca.
- **Validación previa:** `ls -la ~/app/current/public`, para ver qué dejó Plesk.
- **Comprobación posterior:**
  - `ssh -t audax-projects bash -lic 'readlink app/current; command -v php; php -v | head -1'` → `releases/dev`, `/opt/plesk/php/8.4/bin/php` y PHP 8.4.25 (**shell de login interactivo**, que es el que se usa a diario).
  - `ssh audax-projects 'php -v | head -1'` → también 8.4.25 (no interactivo).
  - `curl -sI http://projects.audaxstudio.com` sigue respondiendo.
- **Vuelta atrás:** `rm -rf ~/app ~/.ssh/authorized_keys ~/.bashrc ~/.bash_profile` (o quitar la suscripción).

#### Paso 5. Certificado Let's Encrypt y redirección a HTTPS
- **Comandos (root)**, cada uno seguido de la batería V (son **dos recargas** separadas):
  ```bash
  plesk bin extension --exec letsencrypt cli.php -d projects.audaxstudio.com -m <correo-del-administrador>
  plesk bin subscription --update projects.audaxstudio.com -ssl-redirect true
  ```
  - Solo se pide `projects.audaxstudio.com`; `www.projects` no tiene DNS.
  - Si la emisión automática del paso 2 ya generó un certificado válido solo para ese nombre, el primer comando se omite. Si incluyó nombres sin DNS (`www`, `webmail`), se reemite solo con el nombre correcto.
  - La renovación la hace Plesk automáticamente por HTTP-01, que funciona con la nube gris.
- **Toca algo compartido:** **sí**. Reinicio graceful de Apache y recarga de nginx, **una por cada comando**.
- **Validación previa:** `dig` del paso 0 correcto (A directo a 185.33.65.98 y CAA compatible).
- **Comprobación posterior:**
  - `echo | openssl s_client -connect 185.33.65.98:443 -servername projects.audaxstudio.com 2>/dev/null | openssl x509 -noout -issuer -enddate -ext subjectAltName`
  - `curl -sI http://projects.audaxstudio.com` → 301 a https.
  - Batería **V**.
- **Vuelta atrás:**
  - `plesk bin subscription --update projects.audaxstudio.com -ssl-redirect false`
  - `plesk bin certificate --list -domain projects.audaxstudio.com` y después `plesk bin certificate --remove "<nombre>" -domain projects.audaxstudio.com`.

#### Paso 6. PostgreSQL 18 y Valkey en Docker
**Preparación (root, en horas valle; las imágenes ocupan unos 500 MB):**
```bash
umask 077
install -d -m 700 /opt/audax /etc/audax /etc/audax/secrets /var/lib/audax
docker pull postgres:18-bookworm && docker pull valkey/valkey:9.0-alpine
docker run --rm --entrypoint id postgres:18-bookworm postgres    # anotar UID:GID (se espera 999:999)
docker run --rm --entrypoint id valkey/valkey:9.0-alpine valkey  # anotar UID:GID reales
docker run --rm postgres:18-bookworm postgres --version          # prueba de compatibilidad con el kernel 4.19 y Docker 26
# El directorio montado debe pertenecer al usuario del contenedor: el entrypoint de PG18 solo hace chown de $PGDATA,
# no de /var/lib/postgresql, y tras bajar a uid 999 no podría atravesar un directorio root 700.
install -d -m 700 -o <uid_pg> -g <gid_pg> /var/lib/audax/pg
install -d -m 700 -o <uid_valkey> -g <gid_valkey> /var/lib/audax/valkey
openssl rand -hex 32 > /etc/audax/secrets/pg_admin && chgrp <gid_pg> /etc/audax/secrets/pg_admin && chmod 640 /etc/audax/secrets/pg_admin
iptables-save -t filter > "$D/ipt-filter-pre6.txt"; iptables-save -t nat > "$D/ipt-nat-pre6.txt"; ip -br link > "$D/links-pre6.txt"
```

**`/etc/audax/valkey.conf`** (640, `root:<gid_valkey>`). El secreto se genera con `openssl rand -hex 32` y nunca va a Git ni a los documentos.
```
bind 0.0.0.0
protected-mode yes
requirepass <secreto>
maxmemory 192mb
maxmemory-policy volatile-lru
appendonly yes
appendfsync everysec
auto-aof-rewrite-min-size 64mb
save ""
rename-command FLUSHALL ""
```
- `bind 0.0.0.0` es solo dentro del contenedor; hacia fuera se publica únicamente en 127.0.0.1.
- Con `volatile-lru`, solo se desalojan claves con TTL (caché y sesiones), nunca las colas.
- `maxmemory` de 192 MB con un límite de contenedor de 448 MiB deja margen para el fork copy-on-write de la reescritura del AOF, los buffers y la fragmentación, que `maxmemory` no cuenta.

**`/opt/audax/compose.yml`** (root, 600):
```yaml
name: audax
networks:
  audax-net:
    driver: bridge
    ipam: { config: [ { subnet: 172.30.50.0/24 } ] }
services:
  pg:
    image: postgres:18-bookworm            # tras el pull, fijar por digest
    container_name: audax-pg
    restart: unless-stopped
    networks: [audax-net]
    ports: ["127.0.0.1:15432:5432"]
    environment:
      POSTGRES_USER: audax_admin
      POSTGRES_PASSWORD_FILE: /run/secrets/pg_admin
    volumes:
      - /var/lib/audax/pg:/var/lib/postgresql              # PG18: PGDATA=/var/lib/postgresql/18/docker
      # Alternativa PG17: /var/lib/audax/pg:/var/lib/postgresql/data (la imagen 17 declara VOLUME en .../data;
      # con el montaje de PG18 se crearía un volumen anónimo anidado y los datos NO quedarían en /var/lib/audax/pg)
      - /etc/audax/secrets/pg_admin:/run/secrets/pg_admin:ro
    command: >
      postgres -c shared_buffers=256MB -c effective_cache_size=640MB
      -c work_mem=8MB -c maintenance_work_mem=64MB -c max_connections=40
      -c jit=off -c password_encryption=scram-sha-256 -c log_min_duration_statement=500
    healthcheck: { test: ["CMD-SHELL", "pg_isready -U audax_admin -d audax_admin"], interval: 30s, retries: 5 }
    mem_limit: 1024m
    mem_reservation: 512m
    mem_swappiness: 0
    cpus: 1.5
    pids_limit: 256
    shm_size: 256m
    oom_score_adj: 900
    security_opt: ["no-new-privileges:true"]
    logging: { driver: json-file, options: { max-size: "10m", max-file: "3" } }
  valkey:
    image: valkey/valkey:9.0-alpine          # fijar por digest
    container_name: audax-valkey
    restart: unless-stopped
    networks: [audax-net]
    ports: ["127.0.0.1:16379:6379"]
    command: ["valkey-server", "/etc/valkey/valkey.conf"]
    volumes:
      - /var/lib/audax/valkey:/data
      - /etc/audax/valkey.conf:/etc/valkey/valkey.conf:ro
    mem_limit: 448m
    mem_swappiness: 0
    cpus: 0.5
    pids_limit: 64
    oom_score_adj: 900
    security_opt: ["no-new-privileges:true"]
    logging: { driver: json-file, options: { max-size: "10m", max-file: "3" } }
```

**Aplicación:**
```bash
docker compose -f /opt/audax/compose.yml config -q
docker compose -f /opt/audax/compose.yml up -d
```

- **Toca algo compartido:** **sí**.
  - El demonio Docker es compartido con ainia y clamav.
  - Docker crea una interfaz `br-…` y **añade reglas de iptables** en las tablas `filter` (FORWARD, DOCKER, DOCKER-ISOLATION-STAGE-1/2) y `nat` (MASQUERADE de 172.30.50.0/24 y DNAT de 127.0.0.1:15432/16379). Es un cambio de firewall en el sentido del §16.3.2, aunque las reglas del firewall de Plesk no se tocan, y por eso se aprueba de forma expresa (decisión 7.3).
  - bind9 empezará a escuchar también en 172.30.50.1:53, como ya hace en 172.17.0.1 y 172.19.0.1.
- **Validación previa:** `config -q` sin errores, puertos y subred libres (paso 0), la prueba `postgres --version` correcta y los propietarios de `/var/lib/audax/{pg,valkey}` iguales a los UID anotados. Si PG18 falla, se usa `postgres:17-bookworm` **cambiando también el montaje** como se indica en el compose.
- **Comprobación posterior:**
  - `docker ps --format '{{.Names}} {{.Status}}'` → `audax-pg` (healthy) y `audax-valkey` Up, **y `clamav-1.4`, `ainia-api` y `ainia-pg` siguen Up**.
  - `docker inspect audax-pg --format '{{json .Mounts}}'` → solo los dos bind mounts, **ningún volumen anónimo**. `ls -la /var/lib/audax/pg` → existe `18/docker` con datos.
  - `ss -ltn | grep -E ':(15432|16379)\b'` → solo en `127.0.0.1`.
  - `docker stats --no-stream`.
  - `iptables-save -t nat | diff "$D/ipt-nat-pre6.txt" - | grep -E '172\.30\.50|br-|1543|1637'` (y lo mismo con `filter`): se anotan en `SERVIDOR-CAMBIOS.md` **solo** las reglas añadidas por Docker para esta red.
  - Desde el Mac: `nc -zv -w3 185.33.65.98 15432` y `nc -zv -w3 185.33.65.98 16379` → deben fallar. Esto solo prueba el acceso desde Internet, **no** el vector de un vecino del mismo segmento L2 (ver riesgos).
  - `docker exec audax-valkey sh -c 'REDISCLI_AUTH="$(sed -n "s/^requirepass //p" /etc/valkey/valkey.conf)" valkey-cli ping'` → `PONG`. La contraseña nunca va en la línea de comandos (`-a`), porque sería visible en `/proc/<pid>/cmdline` para cualquier usuario del servidor.
  - Batería **V**.
- **Vuelta atrás:**
  - `docker compose -f /opt/audax/compose.yml down`: quita los contenedores, la red y sus reglas. Los datos quedan en `/var/lib/audax`.
  - `docker image rm postgres:18-bookworm valkey/valkey:9.0-alpine`.
  - Borrar `/var/lib/audax`, `/etc/audax` y `/opt/audax` solo cuando el propietario confirme que no hay datos que conservar.

#### Paso 7. Roles y bases de datos
- **Comando (root):** `docker exec -it audax-pg psql -U audax_admin -d audax_admin`
  ```sql
  CREATE ROLE audax_app  LOGIN; \password audax_app
  CREATE DATABASE audax_projects      OWNER audax_app;
  CREATE ROLE audax_test LOGIN; \password audax_test
  CREATE DATABASE audax_projects_test OWNER audax_test;
  REVOKE CONNECT, TEMPORARY ON DATABASE audax_projects      FROM PUBLIC;
  REVOKE CONNECT, TEMPORARY ON DATABASE audax_projects_test FROM PUBLIC;
  ```
  Las contraseñas se teclean en el servidor y se guardan solo en `app/shared/.env` y `.env.testing` (600). Nunca se usa `PGPASSWORD` en la línea de comandos.
- **Toca algo compartido:** no.
- **Validación previa:** `audax-pg` en estado healthy.
- **Comprobación posterior:** `docker exec -it audax-pg psql "host=127.0.0.1 user=audax_test dbname=audax_projects"` debe fallar con *permission denied for database*.
- **Vuelta atrás:** `DROP DATABASE audax_projects_test; DROP DATABASE audax_projects; DROP ROLE audax_test; DROP ROLE audax_app;`

#### Paso 8. Primer despliegue de la app (D-002)

**Desde el Mac**, con GNU rsync 3.x (`brew install rsync`; el `rsync` de macOS es openrsync y no se usa) y siempre primero con `--dry-run`. El fichero de filtros `.rsync-filter` está versionado en Git. Los patrones **no llevan barra final**, porque en el servidor `storage`, `.env` y `.env.testing` son enlaces simbólicos, y un patrón terminado en `/` solo coincide con directorios: `--delete` borraría el enlace `storage` en cada sincronización.
```
# .rsync-filter  (P = proteger en el receptor; - = excluir)
P /.env
P /.env.testing
P /storage
P /vendor
P /bootstrap/cache/*.php
P /public/storage
- /.env
- /.env.testing
- /storage
- /vendor
- /node_modules
- /.git
- /bootstrap/cache/*.php
- /public/hot
- /public/storage
```
```bash
RSYNC=/opt/homebrew/bin/rsync
npm ci && npm run build                                   # public/build compilado en el Mac o en CI
$RSYNC -az --delete --dry-run --itemize-changes --filter='merge .rsync-filter' ./ audax-projects:app/releases/dev/ | grep -E 'deleting|storage|\.env'
$RSYNC -az --delete           --filter='merge .rsync-filter' ./ audax-projects:app/releases/dev/
ssh audax-projects 'cd app/releases/dev && test -L storage && test -d storage/framework && test -L .env && echo OK'
```

**En el servidor, como `audaxprojects`.** `scripts/heavy.sh` va en el repositorio:
```sh
#!/bin/sh
set -eu
LOCK=/var/www/vhosts/projects.audaxstudio.com/app/shared/.heavy.lock
avail=$(awk '/MemAvailable/{print int($2/1024)}' /proc/meminfo); load=$(cut -d' ' -f1 /proc/loadavg)
[ "$avail" -ge 3072 ] || { echo "MemAvailable ${avail} MB < 3072: abortado"; exit 75; }
awk -v l="$load" 'BEGIN{exit !(l<6)}' || { echo "Carga ${load} >= 6: abortado"; exit 75; }
exec flock -n "$LOCK" nice -n 19 ionice -c3 taskset -c 6,7 "$@"
```
```bash
PHP=/opt/plesk/php/8.4/bin/php
cd ~/app/releases/dev
ln -sfn ../../shared/.env .env; ln -sfn ../../shared/.env.testing .env.testing
rm -rf storage && ln -s ../../shared/storage storage
# Primer composer install: COMPARTIDO (ver abajo), en ventana y con vigilancia de Imunify/maldet desde otra sesión root
sh scripts/heavy.sh env COMPOSER_MEMORY_LIMIT=1G $PHP /usr/local/bin/composer \
   install --no-interaction --prefer-dist --optimize-autoloader
$PHP artisan key:generate --force        # solo la primera vez; el .env debe contener la línea "APP_KEY="
$PHP artisan migrate --force
sh scripts/heavy.sh timeout 20m $PHP -d memory_limit=512M artisan test    # sin --parallel
```

**Vigilancia del primer `composer install` (root, en paralelo):** crea entre 10.000 y 20.000 ficheros PHP en el webspace. Imunify y `maldet` (monitor por inotify) los escanean desde sus propios demonios, que son compartidos y **no** heredan `nice`, `ionice`, `taskset` ni el candado.
```bash
while sleep 5; do cat /proc/loadavg; top -b -n1 -o %CPU | head -15 | grep -Ei 'imunify|maldet|inotify|clam|php'; done
```
- Si la carga supera 8 durante más de 1 minuto, se interrumpe composer y se reintenta en otro momento.
- Al terminar: `imunify360-agent malware malicious list` o `imunify-antivirus malware malicious list` (según el producto instalado), y el registro de `maldet`, para confirmar que **no hay ficheros de `vendor/` en cuarentena**.

**Plantilla del `.env`** (sin secretos). Incluye las líneas `APP_KEY=` y `REDIS_PASSWORD=`, porque `key:generate` falla si no existe la línea `APP_KEY`:

| Grupo | Valores |
|---|---|
| Aplicación | `APP_ENV=staging`, `APP_KEY=` (vacía hasta `key:generate`), `APP_DEBUG=false`, `APP_URL=https://projects.audaxstudio.com` |
| Base de datos | `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=15432`, `DB_DATABASE=audax_projects`, `DB_USERNAME=audax_app` |
| Valkey | `REDIS_CLIENT=phpredis`, `REDIS_HOST=127.0.0.1`, `REDIS_PORT=16379`, `REDIS_PASSWORD=<secreto>`, `REDIS_PREFIX=audaxp_`, `REDIS_DB=0`, `REDIS_CACHE_DB=1` |
| Colas, caché y sesiones | `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, **`SESSION_DRIVER=database`** (D-014: la pantalla de sesiones activas necesita las sesiones en PostgreSQL; la app se niega a arrancar fuera de local/testing con otro driver) |
| Tiempo real | `BROADCAST_CONNECTION=log`, hasta que se active Reverb |
| Correo | `MAIL_MAILER=log`, hasta tener los datos SMTP |
| Logs | `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS=14` |

**Salvaguardas en el código (repositorio):**
- En `config/database.php`, los valores por defecto de Redis pasan a `REDIS_PORT=16379`. Así, si el `.env` no se carga, la app **no** cae en silencio al Redis compartido del 6379, que no tiene contraseña y guarda db0 y db3 de otros sitios.
- Un `AppServiceProvider` aborta el arranque fuera de `testing` si `REDIS_PASSWORD` está vacío o si el puerto de Redis es 6379.
- `/health` comprueba que el puerto de Redis es 16379.
- `config/queue.php`: `retry_after=180` en la conexión `redis`, mayor que el `timeout=120` de Horizon. Para la Fase 6 se prevé una conexión `redis-transcripciones` con `retry_after=1000`.
- Imagick: `Imagick::setResourceLimit()` (MEMORY, MAP, AREA) al arrancar la app, y el procesado de imágenes y PDF solo en jobs, nunca en la petición web.

**Tests:**
- `phpunit.xml` y `.env.testing` apuntan a `audax_projects_test` con el rol `audax_test`, y usan `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`, `BROADCAST_CONNECTION=null` y `MAIL_MAILER=array`. Los tests no tocan Valkey.
- `tests/Pest.php` aborta si la base activa no termina en `_test`.
- **Prohibido `config:cache`** mientras dure D-002: con la configuración cacheada, los tests irían contra la base real.
- Sin `--parallel`: Laravel crearía bases `_test_N`, lo que exige `CREATEDB` y rompe la guarda. Si más adelante hace falta paralelizar, se usaría un PostgreSQL efímero en tmpfs.

**Datos del paso:**
- **Toca algo compartido:**
  - **Sí, en el primer `composer install`**, por la carga de escaneo de Imunify y `maldet`. Se hace en la ventana, con aprobación y con la vigilancia descrita.
  - Los rsync y composer posteriores son incrementales y pequeños. Composer y los tests quedan limitados a los núcleos 6 y 7, con `nice 19`, un candado y la guarda de memoria y carga.
- **Validación previa:** rsync con `--dry-run`, revisando que no aparece `deleting` sobre `storage`, `.env`, `.env.testing` ni `vendor`.
- **Comprobación posterior:**
  - `test -L storage && test -d storage/framework` (tras cada rsync).
  - `curl -s -o /dev/null -w '%{http_code}' https://projects.audaxstudio.com/login` → 200.
  - `curl -sI https://projects.audaxstudio.com | grep -i x-robots-tag`.
  - `/health` → 200.
  - Nada en cuarentena.
  - Batería **V**.
- **Vuelta atrás:** `rm -rf ~/app/releases/dev/*` (solo el espacio de la app) y `migrate:reset` en la base de desarrollo.

#### Paso 9. Unidades systemd: slice, Horizon y scheduler

El slice se anida en `system.slice` (`system-audax.slice`) en lugar de colgar de la raíz. Colgado de la raíz pesaría en CPU lo mismo que **todo** `system.slice`, donde están nginx, Apache, los PHP-FPM y MariaDB. Anidado, compite como un servicio más, con un peso bajo.

`/usr/local/lib/audax/esperar-puerto.sh` (root:root, 755):
```bash
#!/bin/bash
# esperar-puerto.sh PUERTO SEGUNDOS: espera a que 127.0.0.1:PUERTO acepte conexiones
p=$1; t=${2:-60}
for ((i=0; i<t; i+=2)); do
  (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null && exit 0
  sleep 2
done
echo "127.0.0.1:$p no responde tras ${t}s" >&2
exit 1
```

`/etc/systemd/system/system-audax.slice`:
```ini
[Unit]
Description=Audax Projects - tope conjunto de los procesos de la app
[Slice]
MemoryLimit=768M
CPUQuota=150%
CPUShares=256
TasksMax=128
```

`/etc/systemd/system/audax-horizon.service`:
```ini
[Unit]
Description=Audax Projects - Horizon (colas)
After=network-online.target docker.service
Wants=network-online.target
PartOf=audax-projects.target
StartLimitIntervalSec=0

[Service]
Type=simple
User=audaxprojects
Group=psacln
Slice=system-audax.slice
WorkingDirectory=/var/www/vhosts/projects.audaxstudio.com/app/current
Environment=XDEBUG_MODE=off
ExecStartPre=/usr/local/lib/audax/esperar-puerto.sh 16379 60
ExecStartPre=/usr/local/lib/audax/esperar-puerto.sh 15432 60
ExecStart=/opt/plesk/php/8.4/bin/php -d memory_limit=256M artisan horizon
TimeoutStartSec=150
KillSignal=SIGTERM
TimeoutStopSec=150
Restart=always
RestartSec=15
MemoryLimit=512M
CPUQuota=100%
TasksMax=64
Nice=5
IOSchedulingClass=best-effort
IOSchedulingPriority=7
OOMScoreAdjust=1000
LimitNOFILE=4096
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=full
ProtectKernelTunables=yes
ProtectControlGroups=yes
SyslogIdentifier=audax-horizon

[Install]
WantedBy=audax-projects.target
```
- `TimeoutStopSec=150` es mayor que el `timeout=120` de los jobs: un stop o restart no mata un job a mitad.
- `StartLimitIntervalSec=0` y `RestartSec=15`: si Valkey o PostgreSQL tardan (arranque en frío, recreación de un contenedor), la unidad sigue reintentando cada 15 s en lugar de quedarse en `failed` sin que nadie pueda recuperarla sin root. Los reinicios se vigilan con `NRestarts` en la batería V y en `/health`.

`/etc/systemd/system/audax-scheduler.service` es igual que la de Horizon, salvo por:
- `Description=Audax Projects - scheduler`
- `ExecStart=/opt/plesk/php/8.4/bin/php artisan schedule:work`. Los `schedule:run` hijos usan el `memory_limit` CLI global de 128M; las tareas pesadas se encolan.
- `MemoryLimit=256M`, `CPUQuota=50%`, `Nice=10`
- `TimeoutStopSec=60`
- `SyslogIdentifier=audax-scheduler`

`/etc/systemd/system/audax-projects.target`:
```ini
[Unit]
Description=Audax Projects
[Install]
WantedBy=multi-user.target
```

**Horizon** (`config/horizon.php`, entorno `staging`): supervisor `default`, colas `default` y `mail`, `balance=auto`, `minProcesses=1`, `maxProcesses=2`, `memory=128`, `tries=3`, `timeout=120`, `nice=5`. El acceso al panel de Horizon queda limitado al rol admin.

**Aplicación (root):**
```bash
install -D -m 755 -o root -g root esperar-puerto.sh /usr/local/lib/audax/esperar-puerto.sh
systemd-analyze verify /etc/systemd/system/system-audax.slice /etc/systemd/system/audax-horizon.service /etc/systemd/system/audax-scheduler.service
systemctl daemon-reload
systemctl enable audax-horizon.service audax-scheduler.service audax-projects.target
systemctl start audax-projects.target
```

- **Toca algo compartido:** **sí**.
  - `daemon-reload` recarga el gestor de systemd (PID 1). No reinicia servicios, pero es global.
  - Además, en systemd 241, `MemoryLimit=` y `CPUShares=` activan la contabilidad de memoria y CPU, y systemd la extiende a las unidades hermanas del mismo slice (`system.slice`) y a los slices padre. Eso **puede crear cgroups de cpu y memoria para servicios compartidos y mover allí sus procesos**, un cambio global en el reparto de CPU (§16.3.2). Si el paso 0 muestra `Delegate=yes` en `docker.service` y ya existen `/sys/fs/cgroup/{cpu,cpuacct,memory}/system.slice/<servicio>.service`, esa contabilidad ya está activa y el efecto adicional es nulo. Si no, se declara y se aprueba expresamente (decisión 7.1).
- **Validación previa:** `systemd-analyze verify` sin errores, Valkey y PostgreSQL respondiendo, y el estado de cgroups del paso 0 guardado.
- **Comprobación posterior:**
  - `systemctl show -p MemoryLimit,CPUQuotaPerSecUSec,OOMScoreAdjust,NRestarts audax-horizon`
  - `cat /sys/fs/cgroup/memory/system.slice/system-audax.slice/memory.limit_in_bytes` → 805306368.
  - `cat /sys/fs/cgroup/memory/system.slice/system-audax.slice/audax-horizon.service/memory.limit_in_bytes` → 536870912.
  - `cat /sys/fs/cgroup/cpu,cpuacct/system.slice/system-audax.slice/cpu.shares` → 256.
  - Para los PID de nginx, mysqld, Apache y el maestro php-fpm: `cat /proc/<pid>/cgroup` igual que en el paso 0 (o, si cambia, solo por la contabilidad declarada), y `systemctl show system.slice -p CPUAccounting,MemoryAccounting` comparado con el paso 0.
  - `/opt/plesk/php/8.4/bin/php artisan horizon:status` → running.
  - **Prueba de arranque en frío** (en la ventana): `docker stop audax-valkey`, esperar 60 s, `docker start audax-valkey` → Horizon vuelve a `running` sin intervención y `systemctl --failed` no muestra unidades `audax-*`.
  - Tras 30 minutos: `systemd-cgtop -b -n 3 | grep audax`, para medir la CPU real del scheduler.
  - Batería **V**.
- **Tras cada rsync**, sin sudo: `/opt/plesk/php/8.4/bin/php artisan horizon:terminate`. systemd relanza Horizon con el código nuevo. Durante D-002 el symlink no cambia y el scheduler no necesita reinicio.
- **Vuelta atrás:**
  ```bash
  systemctl stop audax-projects.target audax-horizon.service audax-scheduler.service
  systemctl disable audax-projects.target audax-horizon.service audax-scheduler.service
  rm /etc/systemd/system/audax-{horizon,scheduler}.service /etc/systemd/system/audax-projects.target /etc/systemd/system/system-audax.slice
  rm -rf /usr/local/lib/audax
  systemctl daemon-reload
  systemctl reset-failed
  ```

#### Pasos aplazados (se proponen ahora; se ejecutan en su fase y con aprobación)

**A1. Reverb (Fase 6 o antes, si se aprueba):**
- **Unidad:** `audax-reverb.service`, con el esqueleto de Horizon y:
  - `ExecStart=… artisan reverb:start --host=127.0.0.1 --port=18080 --no-interaction`
  - `MemoryLimit=256M`, `CPUQuota=50%`, `LimitNOFILE=8192`, `TimeoutStopSec=30`
  - `system-audax.slice` sube a `MemoryLimit=1024M`.
- **Directiva adicional de nginx**, **solo por la interfaz de Plesk** (*Configuración de Apache y nginx > Directivas adicionales de nginx*). Plesk ejecuta `nginx -t` y **recarga nginx, que es compartido**:
  ```nginx
  location ^~ /app/ {
      proxy_pass http://127.0.0.1:18080;
      proxy_http_version 1.1;
      proxy_set_header Host $host;
      proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
      proxy_set_header X-Forwarded-Proto $scheme;
      proxy_set_header Upgrade $http_upgrade;
      proxy_set_header Connection "upgrade";
      proxy_read_timeout 300s;
      proxy_buffering off;
  }
  ```
- **Configuración de Laravel:**
  - `/apps/` no se expone: el backend publica en `127.0.0.1:18080` por http.
  - El navegador usa `VITE_REVERB_HOST=projects.audaxstudio.com`, `VITE_REVERB_PORT=443` y `VITE_REVERB_SCHEME=https`.
  - Ninguna ruta de Laravel puede empezar por `/app/`.
- **Vuelta atrás:** vaciar el campo (Plesk vuelve a recargar nginx), parar y deshabilitar `audax-reverb`, borrar la unidad y hacer `daemon-reload`.

**A2. Transcripción (Fase 6):**
- **Contenedor `audax-whisper`:** servidor HTTP de whisper.cpp, con la imagen construida en GitHub Actions (no se compila con el gcc 8 del servidor), en `127.0.0.1:18091`. Límites: `cpus: 2`, `cpuset: "6,7"`, `cpu_shares: 64`, `mem_limit: 1536m`, `mem_swappiness: 0`, `oom_score_adj: 1000`.
- **Unidad `audax-transcriber.service`:**
  - `queue:work redis-transcripciones --queue=transcriptions --max-jobs=50 --timeout=900`, con **un único proceso**.
  - `TimeoutStopSec=960`, mayor que el timeout del job; la conexión tiene `retry_after=1000`.
  - `MemoryLimit=256M`, `Nice=19`, `IOSchedulingClass=idle`, en `system-audax.slice`, que sube a `MemoryLimit=1280M`.
  - Horizon no atiende esa cola.
- El modelo se elige tras medir `tiny`, `base` y `small` con un audio de 1 minuto (§12). La medición se anota en `DECISIONES.md`.
- **Vuelta atrás:** `docker compose … rm -sf whisper`, parar y deshabilitar la unidad, y borrar la imagen.

**A3. Backups (antes de meter datos reales):**
- **Unidad** `audax-backup.service` más su `.timer`: root, cada día a las 03:40 con `RandomizedDelaySec=15m`, `Nice=19`, `IOSchedulingClass=idle`, `CPUQuota=50%`, `MemoryLimit=512M`, y el mismo candado `.heavy.lock`.
- **Qué hace:**
  - `docker exec audax-pg pg_dump -U audax_admin -Fc audax_projects > fichero.tmp`. Sin `-U`, `docker exec` se conecta como el rol `root`, que no existe, y el volcado saldría vacío.
  - Un `tar` de `shared/storage/app/private`.
  - Lo guarda todo en `/var/backups/audax`.
  - **Antes de rotar**, comprueba el código de salida, un tamaño mínimo y que `pg_restore --list fichero.tmp` funciona. Solo entonces renombra el fichero. Si algo falla, no rota y avisa.
- **Rotación y copia:** 7 diarias, 4 semanales y 6 mensuales. Copia externa con restic al destino que indique el propietario. Prueba de restauración mensual en una base temporal. Aviso si el disco supera el 85 %.
- Las copias de Plesk **no** incluyen esta base de datos, porque vive en Docker.

**A4. Límite de subidas de 50 MB en ModSecurity y nginx (Fase 1):**
- Si `SecRequestBodyLimit` es menor de unos 58 MB, se ajusta **solo para este dominio** desde *Configuración de Apache y nginx > Directivas adicionales de Apache*.
- Si el `client_max_body_size` de la plantilla de Plesk es menor de 64m, se ajusta desde el panel del dominio.
- Las dos cosas se hacen con aprobación, porque recargan servicios compartidos.

#### Batería V (después de cada paso)
1. **Desde el Mac:** repetir la línea base HTTP y compararla con `baseline-http.txt`. Deben salir los mismos códigos. Los fallos que ya existían (certificados inválidos y dominios sin DNS) no cuentan.
2. **En el servidor** (root; `T0` es la hora anotada antes del paso):
   ```bash
   systemctl is-active nginx apache2 mariadb redis-server docker bind9 fail2ban postfix dovecot \
     sw-engine sw-cp-server php8.2-fpm maldet imunify-agent-proxy \
     plesk-php74-fpm plesk-php80-fpm plesk-php81-fpm plesk-php82-fpm plesk-php83-fpm plesk-php84-fpm plesk-php85-fpm
   systemctl --failed --no-legend | diff "$D/failed.txt" -          # ninguna unidad nueva en failed
   nginx -t && apache2ctl configtest
   journalctl --no-pager --since "$T0" -u nginx -u apache2 -u 'plesk-php*' -u php8.2-fpm -u mariadb \
     -u redis-server -u docker -u bind9 | grep -Ei 'reload|restart|start|stop'   # solo lo previsto en el paso
   ps -o pid,lstart,cmd -C php-fpm | grep -v audaxprojects           # hijos de pools ajenos: sin reinicio masivo no previsto
   docker ps --format '{{.Names}} {{.Status}}'                       # clamav-1.4, ainia-api, ainia-pg Up
   systemctl show -p ActiveState,NRestarts audax-horizon audax-scheduler 2>/dev/null
   free -m; cat /proc/loadavg; vmstat 1 5
   ```
3. **Umbrales de desviación** (cualquiera de ellos obliga a revertir el paso):
   - cambia un código HTTP de la línea base;
   - aparece una recarga o un reinicio no previsto de un servicio compartido;
   - aparece una unidad nueva en `failed`;
   - `MemAvailable` baja más de 1,5 GiB por encima del consumo previsto del paso, o baja de 7 GiB;
   - la carga media de 5 minutos supera 6 durante más de 10 minutos;
   - `vmstat` muestra `si`/`so` sostenidos, es decir, actividad de swap. El swap libre no sirve de indicador porque ya está casi a cero.
4. **Si algo se desvía:** revertir ese paso de inmediato, avisar y anotarlo en `SERVIDOR-CAMBIOS.md`.

---

### 3. Impacto previsto en el resto de servicios

**Recargas y cambios en servicios compartidos (con aprobación y en ventana):**
- Fase 0, **al menos 4 reinicios graceful de Apache con sus recargas de nginx**, porque Plesk no los agrupa (`restart-apache: 0`). Entre uno y otro se pasa la batería V.
  - creación de la suscripción (paso 2), más una emisión automática si SSL It! la tiene activa;
  - posible regeneración del vhost al aplicar los ajustes PHP (paso 3);
  - certificado (paso 5);
  - redirección a HTTPS (paso 5).
- Paso 6: reglas de iptables (`filter` y `nat`) creadas por Docker para 172.30.50.0/24, una interfaz `br-` nueva y bind9 escuchando en 172.30.50.1.
- Paso 8: pico de CPU y E/S de Imunify y `maldet` al escanear `vendor/` en el primer `composer install`.
- Paso 9: `systemctl daemon-reload` y la posible activación de la contabilidad de cgroups en `system.slice`.
- Más adelante:
  - directiva nginx de Reverb (A1),
  - ajustes de ModSecurity y del tamaño de petición (A4).

**No se toca:**
- MariaDB 10.3,
- el Redis `:6379` (db0 y db3 de otros sitios); el código lo impide activamente,
- `plesk-php84-fpm` y el resto de maestros FPM (verificado en el journal),
- `php.ini` global, `my.cnf`, `sysctl` y los límites del sistema,
- las reglas del firewall de Plesk (las cadenas `DOCKER*` las gestiona Docker),
- SSH, Postfix, Dovecot y la configuración de BIND (zona de `audaxstudio.com` verificada sin cambios),
- apt y los paquetes,
- `daemon.json`,
- el plan «Default Domain» y las demás suscripciones (incluidas `audaxstudio.com` y `paneles.audaxstudio.com`),
- los contenedores `clamav-1.4`, `ainia-api` y `ainia-pg`.

**Red:** no se abre ningún puerto; todo lo nuevo escucha en 127.0.0.1. Se añade un bridge Docker (172.30.50.0/24).

**RAM y CPU:**
- Uso típico en la Fase 0: de 0,8 a 1,4 GB de RAM y menos de 0,5 vCPU de media, sobre unos 10 GiB disponibles y 8 vCPU con una carga de 3,3 a 4,2.
- Composer y los tests quedan confinados a los núcleos 6 y 7 con prioridad mínima. Los escáneres de seguridad que reaccionan a esos ficheros no lo están (ver paso 8).

**Ante falta de memoria:**
- Las unidades de la app llevan `OOMScoreAdjust=1000` y los contenedores `oom_score_adj=900`. Eso **favorece** que el OOM killer global los elija antes que `mysqld`, `clamd` o los PHP de los clientes, pero **no lo garantiza**: la puntuación depende también del tamaño del proceso, y matar un backend pequeño libera poco.
- Los hijos del pool FPM dedicado no tienen ajuste de OOM ni cgroup, salvo que se apruebe el drop-in de la decisión 7.10.
- `mem_swappiness: 0` solo se aplica a los contenedores. Las unidades systemd y el FPM pueden empujar páginas al swap global, que ya está lleno. Por eso los límites son conservadores.

**Disco:** imágenes de unos 500 MB, `vendor/` de unos 150 a 250 MB, y después el crecimiento de la base y los adjuntos, sobre 47 GB libres.

**Seguridad y WAF:**
- El tráfico de la app pasa por Apache, así que ModSecurity e Imunify siguen aplicándose a este dominio igual que al resto.
- fail2ban es global. Los falsos positivos de ModSecurity, o varios 401, pueden banear la IP de la oficina **para todo el servidor**, incluidos SSH y el panel (decisión 7.9).

**Autoactualizaciones de Plesk (activas):** pueden reiniciar el FPM dedicado o recargar nginx por su cuenta. La configuración se hace siempre por el panel o por su CLI, así que sobrevive a la regeneración de los vhosts.

---

### 4. Consumo de recursos estimado y límites duros

| Componente | Límite de RAM | Límite de CPU | Mecanismo | Uso típico |
|---|---|---|---|---|
| PHP-FPM dedicado (web) | 6 × 256 MB = 1.536 MB + opcache. **Es un límite de PHP, no duro**: Imagick y otras librerías nativas reservan fuera de `memory_limit` | Sin cuota de cgroup; acotado por `pm.max_children=6` | Pool de Plesk (`ondemand`); drop-in opcional (7.10) | 0 en reposo; 300–550 MB con carga |
| Horizon | 512 MiB | 100 % (1 vCPU), `Nice=5` | systemd `MemoryLimit=`/`CPUQuota=` | 150–300 MB |
| Scheduler | 256 MiB | 50 %, `Nice=10` | systemd | < 100 MB |
| **`system-audax.slice`** (Horizon + scheduler) | **768 MiB en conjunto** | **150 %**, `CPUShares=256` frente a los demás servicios de `system.slice` | Slice de systemd (cgroup v1) | — |
| PostgreSQL 18 | 1.024 MiB (reserva de 512) | 1,5 CPU, 256 pids | Docker `mem_limit`/`cpus` | 250–500 MB |
| Valkey 9.0 | 448 MiB (`maxmemory` de 192) | 0,5 CPU, 64 pids | Docker | 20–60 MB |
| Composer (transitorio) | unos 1.024 MB (`COMPOSER_MEMORY_LIMIT=1G`) | Núcleos 6–7, `nice 19` (los escáneres, fuera de este límite) | `heavy.sh` (candado y guarda) | Minutos |
| Pest (transitorio, excluyente con composer) | 512 MB (`memory_limit`) | Núcleos 6–7, `nice 19`, `timeout 20m` | `heavy.sh` | Minutos |
| **Total permanente de la Fase 0** | **unos 3,9 GiB de techo** | — | — | **0,8–1,4 GB** |
| **Pico de la Fase 0** (con composer o tests) | **unos 4,9 GiB** | — | — | Deja unos 5 GiB libres |
| [A1] Reverb | 256 MiB (el slice sube a 1.024) | 50 % | systemd | 60–120 MB |
| [A2] Worker de transcripción | 256 MiB (el slice sube a 1.280) | 25 %, `Nice=19`, E/S idle | systemd | < 100 MB |
| [A2] whisper.cpp | 1.536 MiB | 2 CPU fijadas a los núcleos 6–7, `cpu_shares` 64 | Docker | Según el modelo (small, alrededor de 1 GB) |
| [A3] Backup nocturno (excluyente con composer y tests) | 512 MiB | 50 %, `Nice=19`, E/S idle | systemd | Minutos a las 03:40 |
| **Pico de la Fase 6** (todo más un build) | **unos 6,9 GiB** | — | — | Deja unos 3 GiB de margen sobre los 10 GiB disponibles: **ajustado** |

**Notas:**
- En cgroup v1 con systemd 241 se usan `MemoryLimit=`, `CPUQuota=` y `CPUShares=`. `MemoryMax=`, `CPUWeight=` e `IOWeight=` son de cgroup v2 y no se aplicarían.
- `CPUShares` solo reparte entre hermanos del mismo nivel. Por eso se pone en el slice, que compite con los servicios de `system.slice`, y no en Horizon, donde solo competiría con el scheduler.
- Docker indica «No swap limit support», así que `memswap_limit` no tiene efecto. Por eso se usa `mem_swappiness: 0`, que tampoco alcanza a systemd ni al FPM.
- `ionice -c3` solo actúa con los planificadores CFQ o BFQ (lo comprueba el paso 0). El freno real es `taskset` más `nice`.
- **Separación prod/dev (futura):** el entorno de desarrollo irá con límites reducidos (unos 1,5 GiB) y su propia base de datos. Con whisper y un build a la vez, el conjunto no cabe con margen: habrá que revisar la RAM de la VM antes de esa fase.

---

### 5. Plan de vuelta atrás global (dejar el servidor como estaba)

Se ejecuta en este orden, con aprobación. Los borrados de datos solo se hacen tras la confirmación expresa del propietario. No se oculta ningún error.

```bash
# 1) Procesos de la app: primero PARAR, luego deshabilitar solo las unidades que existan
systemctl stop audax-projects.target 'audax-*.service' 'audax-*.timer'
for u in audax-projects.target audax-horizon.service audax-scheduler.service audax-reverb.service \
         audax-transcriber.service audax-backup.service audax-backup.timer; do
  systemctl list-unit-files "$u" --no-legend | grep -q . && systemctl disable --now "$u"
done
systemctl list-units --all 'audax*' --no-legend     # vacío o todo inactive
pgrep -u audaxprojects -a                           # sin procesos; si los hay, investigar antes de seguir
rm -f /etc/systemd/system/audax-* /etc/systemd/system/system-audax.slice
rm -rf /usr/local/lib/audax
systemctl daemon-reload && systemctl reset-failed

# 2) (Opcional) Exportar los datos antes de borrar
docker exec audax-pg pg_dump -U audax_admin -Fc audax_projects > /root/audax-final-$(date +%F).dump \
  && pg_restore --list /root/audax-final-$(date +%F).dump >/dev/null   # (o dentro del contenedor)

# 3) Contenedores, red e imágenes propias (NO se reinicia docker.service)
docker compose -f /opt/audax/compose.yml down
docker image rm postgres:18-bookworm valkey/valkey:9.0-alpine <imagen-whisper>
rm -rf /opt/audax /etc/audax /var/lib/audax /var/backups/audax      # tras confirmación

# 4) Suscripción Plesk (vhost, pool FPM dedicado, usuario, clave SSH, certificado): reinicio graceful de Apache y recarga de nginx
plesk bin subscription --remove projects.audaxstudio.com

# 5) Comprobación de que el servidor queda igual que antes
ls /etc/systemd/system | grep -i audax            # vacío
ss -ltn | grep -E ':(15432|16379|18080|18091)\b'   # vacío
docker network ls; docker ps -a                   # igual que $D/docker-net.txt y $D/docker-ps.txt
iptables-save | grep -E '172\.30\.50|1543[0-9]|1637[0-9]'   # vacío (sin diff completo: fail2ban e Imunify cambian reglas a diario)
ip -br link | grep -c br- ; cat "$D/links.txt" | grep -c br-   # mismo número de bridges
plesk bin dns --info audaxstudio.com | diff - "$D/dns-audaxstudio.txt"   # sin cambios
id audaxprojects                                  # no existe
```

6. **En Cloudflare**, el propietario borra el registro A `projects`.
7. **Batería V completa**, y anotar el cambio en `SERVIDOR-CAMBIOS.md`.

---

### 6. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| El swap es pequeño (975 MB; vaciado el 26/09) y los ~9–10 GiB disponibles son en buena parte caché de página. Una punta de la app podría presionar a MariaDB y a los sitios | Techo duro en cada pieza y en el slice conjunto, OOM ajustado para que la app sea candidata preferente (no garantizada), FPM `ondemand`, `mem_swappiness: 0` en contenedores, guarda de `MemAvailable` de 3 GiB antes de trabajos pesados y umbrales de V en cada paso |
| El pool FPM dedicado no tiene cgroup, e Imagick reserva memoria fuera de `memory_limit` | Procesado de imágenes y PDF solo en jobs (dentro del slice), `Imagick::setResourceLimit`, `pm.max_children=6`, `request_terminate_timeout=300s` y drop-in opcional con `MemoryLimit=` y `OOMScoreAdjust=` en la unidad del maestro dedicado (7.10) |
| Reiniciar Docker tumbaría `clamav-1.4`, que tiene `restart=no` y no volvería | Prohibido reiniciar `docker.service` o tocar `daemon.json`. Tras cada paso se comprueba que ainia y clamav siguen Up |
| Si alguien reaplica el Firewall de Plesk, pueden desaparecer las cadenas `DOCKER`: nuestros puertos y los de ainia dejarían de funcionar | Documentado aquí. Recuperarlo exige reiniciar Docker, lo que solo se hace con aprobación y arrancando después `clamav-1.4` a mano. `/health` lo detectaría |
| Docker 26.1.4 (anterior a la 28): un vecino del mismo segmento L2 podría alcanzar los puertos publicados en 127.0.0.1 mediante el DNAT de Docker | La mitigación real es la autenticación: scram-sha-256, `requirepass`, roles limitados a su base y `FLUSHALL` deshabilitado. El `nc` desde Internet **no** prueba este vector. Opcional, con aprobación porque es un cambio de firewall: `iptables -t raw -I PREROUTING ! -i lo -d 127.0.0.0/8 -j DROP` (en la tabla `raw`, antes del DNAT; una regla en `DOCKER-USER` no serviría porque ahí el destino ya está traducido). Si no se aprueba, queda como riesgo aceptado |
| La imagen de PostgreSQL 18 podría no ir bien con el kernel 4.19 y el runc antiguo, o no arrancar por permisos | Prueba con `docker run --rm` antes de levantarla, directorio de datos propiedad del UID del contenedor, imagen fijada por digest y alternativa `postgres:17-bookworm` **con su propio punto de montaje** |
| Horizon y el scheduler se quedan en `failed` si Valkey o PostgreSQL tardan en arrancar | `ExecStartPre` que espera a los puertos, `RestartSec=15`, sin límite de arranques, `NRestarts` vigilado en V y en `/health`, y prueba de arranque en frío en el paso 9 |
| Un stop o restart mata jobs a mitad | `TimeoutStopSec` mayor que el timeout de cada cola (150 frente a 120 y 960 frente a 900) y `retry_after` mayor que el timeout |
| Tests que se ejecutan contra la base de desarrollo | Rol y base `_test` separados con `REVOKE CONNECT`, guarda en `tests/Pest.php`, sin `config:cache` y sin `--parallel` |
| `rsync --delete` borra `.env` o `storage`, que en el servidor son enlaces simbólicos | Reglas sin barra final y de protección (`P`) en `.rsync-filter`, GNU rsync 3.x en lugar de openrsync, `--dry-run` obligatorio y comprobación `test -L storage` tras cada sincronización |
| La app se conecta sin querer al Redis compartido del 6379 si falla el `.env` | Valores por defecto en `config/database.php` a 16379, arranque abortado sin `REDIS_PASSWORD` o con el puerto 6379, y comprobación en `/health` |
| Ejecutar composer o artisan con PHP 8.2 | Ruta absoluta `/opt/plesk/php/8.4/bin/php` en el runbook, en `heavy.sh` y en las unidades, PATH del usuario en `.bash_profile` y `.bashrc`, y `config.platform.php=8.4` |
| Picos de CPU y de E/S de Imunify y maldet al crear miles de ficheros en `vendor/`, y posibles falsos positivos en cuarentena | Primer composer tratado como paso compartido, en ventana y vigilado, con umbral de aborto. Comprobación de la cuarentena. Assets compilados fuera y sin `node_modules`. Opcional (global, con aprobación): excluir `app/releases/*/vendor` del escaneo en tiempo real |
| fail2ban banea la IP de la oficina en todo el servidor (SSH 5222, panel y app) por falsos positivos de ModSecurity o varios 401 | IP de la oficina en las IP de confianza de fail2ban, o ModSecurity del dominio en «solo detección» durante D-002 (decisión 7.9). Restricción por IP en lugar de contraseña básica. Desbloqueo documentado: `fail2ban-client set <jail> unbanip <ip>` o *Bloqueo de IP* en el panel |
| ModSecurity bloquea subidas de 50 MB o peticiones de Inertia o WebSocket | Prueba tras el despliegue y excepciones **solo para este dominio** desde el panel, con aprobación (A4) |
| Un cambio futuro en el plan «Default Domain» sobrescribe el handler o los ajustes PHP de la app | Suscripción bloqueada frente a la sincronización, o plan propio (7.2) |
| Reinicios de Apache no agrupados (`restart-apache: 0`) y, si el reinicio no fuera graceful, cortes en los 38 sitios | Comprobación del modo graceful en el paso 0, recargas contadas y espaciadas con V entre ellas, todo en ventana |
| Contraseñas visibles en la línea de comandos | `REDISCLI_AUTH` y `\password`; nunca `-a` ni `PGPASSWORD` en argumentos. Se comprueba `hidepid` en el paso 0 |
| Regeneración de vhosts por Plesk (autoupdates) | Toda la configuración va por la interfaz o la CLI oficial; no se edita ningún fichero generado |
| La nube naranja rompería la renovación HTTP-01 y cortaría los WebSockets inactivos a los 100 s | Mantener la nube gris de forma permanente |
| Se desarrolla en el servidor de producción (D-002): fuga de datos o indexación | `APP_DEBUG=false`, `noindex` y `robots.txt`, sin datos reales y restricción por IP desde Plesk (preferible a la contraseña básica, por fail2ban) |
| Tras cambiar de release con `deploy.sh`, `schedule:work` seguiría ejecutando el código antiguo | Durante D-002 el symlink no cambia. En la fase de `deploy.sh` se propondrá una regla sudoers con **nombres de unidad exactos** (sin comodines), validada con `visudo -cf` |
| Sistema en fin de vida (Debian 10, kernel 4.19, Docker congelado) | La app solo depende del PHP y del Node de Plesk. PostgreSQL y Valkey en contenedores facilitan migrar a otro servidor |

---

### 7. Decisiones que necesita el propietario

1. **Aprobar este plan y una ventana de poco tráfico** para:
   - al menos **4 reinicios graceful de Apache con sus recargas de nginx** (pasos 2, 3 y 5), más uno si SSL It! emite por su cuenta;
   - el primer `composer install` (pico de escaneo de Imunify y `maldet`);
   - el `daemon-reload` del paso 9 y la **posible activación de la contabilidad de CPU y memoria de cgroups en `system.slice`**, que es un cambio global de reparto (nulo si ya está activa por `Delegate=yes` de Docker; se verifica en el paso 0).
2. **Aprobar la suscripción propia** `projects.audaxstudio.com` con el usuario `audaxprojects` y el plan con el que se crea. Hay que confirmar que existe «Default Domain» y qué activa. La suscripción se **bloquea frente a la sincronización del plan**; la alternativa es un plan propio para la app.
3. **Aprobar Docker para PostgreSQL 18 y Valkey 9.0**, con los datos en `/var/lib/audax` y los puertos 127.0.0.1:15432 y 127.0.0.1:16379. Esto incluye **aprobar expresamente** que Docker:
   - añada reglas de iptables (tablas `filter` y `nat`) para la red 172.30.50.0/24 y el DNAT de 127.0.0.1:15432/16379;
   - cree una interfaz `br-` nueva;
   - y que bind9 escuche también en 172.30.50.1.
4. **Aprobar el shell `/bin/bash`** para `audaxprojects`, sin chroot y con acceso solo por clave en el puerto 5222. (D-001 ya corregido).
5. **Decidir quién ejecuta los comandos de root**: nosotros, con acceso root, o tú con los comandos exactos.
6. **Datos SMTP** de `audaxstudio.com` para el correo saliente. Mientras tanto, `MAIL_MAILER=log`.
7. **Destino y credenciales de la copia externa**, necesarios antes de meter datos reales.
8. **Protección adicional durante D-002**: restricción por IP (recomendada) o directorio protegido, además del `noindex`.
9. **fail2ban y WAF**, ambos con aprobación:
   - añadir la IP fija de la oficina a las IP de confianza de fail2ban (*Herramientas y configuración > Bloqueo de IP*), que es un ajuste global del panel;
   - o, alternativa o complemento, poner ModSecurity de este dominio en «solo detección» mientras dure D-002.
10. **Opcionales de endurecimiento**, cada uno con aprobación propia:
    - drop-in `/etc/systemd/system/<unidad del FPM dedicado>.d/audax.conf` con `MemoryLimit=` y `OOMScoreAdjust=1000`, si el paso 2 muestra que el maestro dedicado tiene unidad propia;
    - regla `raw PREROUTING` contra el acceso L2 a 127.0.0.1;
    - exclusión de `app/releases/*/vendor` del escaneo en tiempo real de Imunify y `maldet`.
11. **Cloudflare**: confirmar la nube gris permanente y revisar si hay registros CAA.
12. **Anomalías del apartado 8**: decidir si las revisas antes del despliegue.

---

### 8. Observaciones del servidor ajenas a la app

No las tocamos; se informan para que el propietario decida.

- **Sistema operativo en fin de vida:**
  - Debian 10.13 «buster», con las fuentes de apt en `archive.debian.org` y el kernel 4.19.0-27 de junio de 2024.
  - Hay configuradas fuentes `alt-common-els`: conviene confirmar qué soporte extendido cubren.
  - MariaDB 10.3.39 también está en fin de vida.
  - Docker está congelado en la 26.1.4, porque su repositorio está desactivado (`plesk-ext-docker.list.save`). Esa versión arrastra además el problema de exposición L2 de los puertos publicados en 127.0.0.1, que afecta también a `ainia-api` (127.0.0.1:8080).
  - Conviene planificar una migración o actualización del servidor.
- **Fuente de apt mezclada:** hay un PPA de Ubuntu «lunar» (ondrej/php) sobre Debian. De ahí vienen `php8.2-fpm.service`, activo en el sistema, y `/usr/bin/php` 8.2.11. Es una combinación frágil ante cualquier `apt upgrade`.
- **Swap:** vaciado el 26/09 (estaba en 972 de 975 MB) con `swappiness=10`, y MariaDB ocupa 7,2 GB de RSS. Conviene revisar el tamaño del swap o la RAM de la VM.
- **Reinicios de Apache sin agrupar:** `restart-apache: 0` hace que cada cambio en cualquier dominio reinicie Apache al momento. Conviene valorar un intervalo de agrupación.
- **Proceso atascado:** ~~`grep -R … php-fpm-pool-settings`~~ **resuelto el 26/09 a las 11:53.** Queda `/var/www/vhosts/grep.txt` (21 MB, de 2023), que conviene borrar.
- ~~**Consumo anómalo de `dbus-daemon` y `cron`**~~ **resuelto el 26/09 a las 12:09** reiniciando cron (ver `SERVIDOR.md` §10.3).
- **Redis compartido sin protección:** 5.0.14 sin contraseña y sin `maxmemory` (`noeviction`), con db0 (unas 20.000 claves) y db3 (unas 43.000). Solo escucha en local, pero cualquier usuario del servidor puede leer y escribir en él.
- **MariaDB escucha en todas las interfaces** (`*:3306`). El firewall lo bloquea, pero bastaría con que escuchara en local.
- **SSH con `PermitRootLogin yes`** (puerto 5222). Conviene limitarlo a clave (`prohibit-password`).
- **Posible contraseña en claro en Plesk:** el campo «Description for the administrator» de la suscripción `audaxstudio.com` contiene una cadena con aspecto de contraseña. No la reproducimos aquí. Recomendación: moverla a un gestor de contraseñas, vaciar el campo y rotar la credencial a la que corresponda.
- **Volcado SQL** (`laif.sql.zip`, permisos 644) en la raíz de la suscripción `audaxstudio.com`. No es accesible por web, pero conviene retirarlo si ya no se necesita.
- **Contenedores Docker ajenos sin límites:** `clamav-1.4` y `ainia-pg` no tienen límites de memoria ni de CPU, y `clamav-1.4` tiene `restart=no`, así que no volvería tras un reinicio del servidor o de Docker.
- **Entrada obsoleta en Plesk:** hay un servidor de bases de datos `postgresql localhost:5432`, pero no hay PostgreSQL en el host.
- **Actualizaciones automáticas:** las de Plesk están activas (incluidas las de terceros) y `apt-daily-upgrade.timer` también. Conviene confirmar si `unattended-upgrades` está instalado.
- **Sitios con problemas previos** (según la línea base):
  - Certificado inválido: `emineo.es`, `endesarrollo.pro`, `ezplus`, `hn`, `iamanagers`, `importaco`, `musicson.endesarrollo.pro` y `staging.edicionesmonoculo.com`.
  - Sin DNS: `impactocespedartificial.com`, `impactoparquets.com` e `impactosagunto.com`.
