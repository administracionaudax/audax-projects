# DEPLOY.md: desplegar, volver atrás y restaurar Audax Proyectos

Guía de operación del día a día (D-077). El detalle de cómo se montó el servidor está en `docs/RUNBOOK-DESPLIEGUE.md`, y el historial de cada cambio en `docs/SERVIDOR-CAMBIOS.md`.

**Regla de oro (SPEC §16):** el servidor es compartido con otras webs. Todo cambio de sistema lleva:
1. copia previa,
2. batería V (`scripts/server/verificar.sh`),
3. comparación de webs (`scripts/server/comparar-webs.sh`),
4. una fila en `docs/SERVIDOR-CAMBIOS.md`.

Nunca se reinician servicios compartidos, ni se tocan el firewall, el SSH, el DNS, el correo u otras webs. Ante algo inesperado, se vuelve atrás.

## 1. Qué hay en el servidor

| Pieza | Dónde | Notas |
|---|---|---|
| App | `/var/www/vhosts/projects.audaxstudio.com/app/current` → `releases/dev` | Usuario `audaxprojects`, PHP 8.4 en `/opt/plesk/php/8.4/bin/php` |
| Entorno | `app/shared/.env` (600) | Nunca en Git. Copias `.env.antes-*` al lado |
| Almacenamiento | `app/shared/storage` | Adjuntos privados en `storage/app/private` |
| PostgreSQL 18 | Docker `audax-pg`, `127.0.0.1:15432` | Bases `audax_projects` y `audax_projects_test` |
| Valkey 9 | Docker `audax-valkey`, `127.0.0.1:16379` | Colas, caché y sesiones. **Nunca** el Redis del 6379 |
| Transcripción | Docker `audax-whisper`, `127.0.0.1:18091` | whisper.cpp 1.9.4, modelo `small`, núcleos 6-7 |
| Tiempo real | `audax-reverb.service`, `127.0.0.1:18080` | nginx pasa `/app/` (directiva adicional del dominio en Plesk) |
| Colas | `audax-horizon.service` | Todas salvo la de transcripciones |
| Transcriptor | `audax-transcriber.service` | Un proceso, cola `redis-transcriptions` |
| Tareas programadas | `audax-scheduler.service` | `schedule:work` |
| Copia nocturna | `audax-backup.timer` (03:40) | `/var/backups/audax` (7 diarias, 4 semanales, 6 mensuales) |
| Prueba de restauración | `audax-restore-check.timer` (día 2, 05:10) | Base temporal; resultado para la app |
| Copia externa | `audax-offsite.timer` (04:40) | **Preparada, sin activar** hasta tener destino |

Todas las unidades van en `system-audax.slice` (1280 MiB) y cuelgan de `audax-projects.target`. Su origen está en `deploy/systemd/`.

## 2. Desplegar

Desde el Mac, en la rama que se quiere desplegar y con los tests y la CI en verde:

```bash
export PATH=/usr/local/opt/php@8.4/bin:$PATH
scripts/desplegar-dev.sh --tests
```

El script hace, por orden:
1. compila los assets,
2. simula el `rsync` y luego lo ejecuta (respeta `.rsync-filter`: nunca toca `.env`, `storage` ni `vendor`),
3. `composer install` si cambió `composer.lock` (con `scripts/heavy.sh`),
4. migraciones,
5. `horizon:terminate`, que reinicia Horizon y le hace leer el entorno nuevo,
6. con `--tests`, ejecuta Pest contra `audax_projects_test`, con 45 min de límite y prioridad mínima,
7. comprueba `/login` y `/health`.

Después de desplegar:

```bash
T0="AAAA-MM-DD HH:MM:SS"   # hora de inicio del despliegue
ssh audax "bash -s -- '$T0' \"\$(cat /root/audax-backup/ULTIMO)\"" < scripts/server/verificar.sh
scripts/server/comparar-webs.sh
```

- **Si se cambia el `.env`**, hay que reiniciar los procesos largos para que lo lean:
  - como `audaxprojects`, `php artisan horizon:terminate`;
  - como root, `systemctl restart audax-reverb audax-transcriber`.
- **Cuando haya uso real** (D-028), las migraciones con riesgo se despliegan con modo mantenimiento (`php artisan down --render=errors::503` antes y `up` después). Se prueba antes la vuelta atrás.

## 3. Volver atrás

- **Código:** volver a desplegar la etiqueta anterior (`git checkout fase-N-cerrada && scripts/desplegar-dev.sh`).
- **Base de datos:** `php artisan migrate:rollback --step=N`, solo si las migraciones de esa versión no tienen datos que perder. Si no, se restaura la copia (punto 4).
- **Servicios de la Fase 6:**
  - `systemctl disable --now audax-reverb audax-transcriber`,
  - restaurar `system-audax.slice` desde la copia,
  - `daemon-reload`,
  - en el `.env`, `BROADCAST_CONNECTION=log`: la app sigue funcionando con consultas periódicas.
- **Directiva de nginx `/app/`:** vaciar el campo en Plesk; Plesk recarga nginx.

## 4. Restaurar una copia

Las copias están en `/var/backups/audax/{daily,weekly,monthly}/AAAA-MM-DD/`:
- `audax_projects.dump`: `pg_dump -Fc`,
- `adjuntos.tar.gz`,
- `recuentos.txt`,
- `SHA256SUMS`.

1. Comprobar la copia: `cd /var/backups/audax/daily/<fecha> && sha256sum -c SHA256SUMS`.
2. Parar los procesos: `systemctl stop audax-horizon audax-scheduler audax-transcriber audax-reverb`, y `php artisan down`.
3. Restaurar la base, sin crear otra encima de la buena:
   ```bash
   docker exec -i audax-pg pg_restore -U audax_admin -d audax_projects --clean --if-exists --no-owner --role=audax_app < audax_projects.dump
   ```
4. Restaurar los adjuntos:
   ```bash
   tar -C /var/www/vhosts/projects.audaxstudio.com/app/shared/storage/app -xzf adjuntos.tar.gz
   chown -R audaxprojects:psacln …/storage/app/private
   ```
5. Volver a levantar: `php artisan up` y `systemctl start audax-projects.target`.
6. Comprobar: `/health`, entrar como admin, batería V y webs.

Para practicar sin tocar nada, `audax-restore-check.sh` restaura en una base temporal (`audax_restore_check`) y la borra al terminar.

## 5. Copia externa (pendiente del destino)

Cuando el propietario indique el destino, ya sea S3 compatible u otro servidor por SFTP:
1. Descargar el binario oficial de `restic`, verificar su SHA-256 e instalarlo en `/usr/local/lib/audax/restic` (755).
2. Crear `/etc/audax/restic.env` (600) con:
   - `RESTIC_REPOSITORY`,
   - `RESTIC_PASSWORD_FILE=/etc/audax/restic.pass` (600; esa clave se guarda también fuera del servidor),
   - y, para S3, sus credenciales.
3. Instalar `deploy/backup/audax-offsite.sh` en `/usr/local/lib/audax/` y las unidades `audax-offsite.{service,timer}`. Después, `daemon-reload` y `systemctl start audax-offsite.service`: la primera vez inicializa el repositorio.
4. Comprobar `restic snapshots` y activar el temporizador con `systemctl enable --now audax-offsite.timer`.
5. Anotarlo y hacer la batería V. `app:check-storage` avisará al admin si la copia externa falla.

## 6. Variables de entorno

Las de ejemplo, con valores ficticios, están en `.env.example`. En el servidor:

- **Aplicación:**
  - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://projects.audaxstudio.com`,
  - `LOG_STACK`: diario, en JSON con `daily_json` (D-077).
- **Base de datos y Valkey:**
  - `DB_*` apuntan a `127.0.0.1:15432`; `REDIS_*` a `127.0.0.1:16379` con contraseña,
  - `REDIS_QUEUE_RETRY_AFTER=660`, por encima del tiempo máximo de la exportación de datos personales.
- **Correo:** `MAIL_*`. **Pendiente:** los datos SMTP del propietario; hasta entonces, `MAIL_MAILER=log`.
- **Tiempo real:**
  - `BROADCAST_CONNECTION=reverb`,
  - `REVERB_*`: servidor en `127.0.0.1:18080`; navegador en `projects.audaxstudio.com:443` https,
  - `REVERB_ALLOWED_ORIGINS=projects.audaxstudio.com`.
- **Transcripción:**
  - `TRANSCRIPTION_DRIVER=whisper` y `TRANSCRIPTION_QUEUE_CONNECTION=redis-transcriptions`,
  - `WHISPER_URL=http://127.0.0.1:18091`, `WHISPER_MODEL=small` y `WHISPER_TIMEOUT=2340`.
- **Avisos del navegador:** `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` y `VAPID_SUBJECT`. Se generan con `php artisan push:vapid-keys` y se comprueban con `--check`. Si se cambian, las suscripciones existentes dejan de valer.
- `LINK_PREVIEWS_ENABLED=true`: previsualización de enlaces del chat, con protección SSRF.

## 7. Comprobaciones rápidas

- **Estado general:** `curl -s https://projects.audaxstudio.com/health` muestra base de datos, Valkey, colas, disco y sesiones.
- **Servicios:** `systemctl status audax-horizon audax-scheduler audax-reverb audax-transcriber`.
- **Colas:** Horizon en `/horizon` (solo admin). Las transcripciones, en `/admin/transcripciones`.
- **Copias:** `cat /var/backups/audax/ULTIMA-CORRECTA` y el estado que lee la app en `shared/storage/app/backup-status.json`. Si algo falla, `app:check-storage` avisa al admin cada día.
- **Tiempo real desde fuera:** abrir el chat en dos navegadores; el aviso «escribiendo…» llega al instante.
