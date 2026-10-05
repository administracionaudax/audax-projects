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
| PDF de informes | Docker `audax-gotenberg`, `127.0.0.1:18092` | Gotenberg 8.37 (Chromium), núcleos 6-7, 768 MB (Fase 9, sección 9) |
| Tiempo real | `audax-reverb.service`, `127.0.0.1:18080` | nginx pasa `/app/` (directiva adicional del dominio en Plesk) |
| Colas | `audax-horizon.service` | Todas salvo la de transcripciones |
| Transcriptor | `audax-transcriber.service` | Un proceso, cola `redis-transcriptions` |
| Tareas programadas | `audax-scheduler.service` | `schedule:work` |
| Copia nocturna | `audax-backup.timer` (03:40) | `/var/backups/audax` (7 diarias, 4 semanales, 6 mensuales) |
| Prueba de restauración | `audax-restore-check.timer` (día 2, 05:10) | Base temporal; resultado para la app |
| Copia externa | `audax-offsite.timer` (04:40) | **Preparada, sin activar** hasta tener destino |

Todas las unidades van en `system-audax.slice` (1280 MiB) y cuelgan de `audax-projects.target`. Su origen está en `deploy/systemd/`.

### Reparto de la memoria de las colas (Fase 9, D-141)
Horizon tiene dos supervisores (`config/horizon.php`). `memory` es el umbral a partir del cual Horizon reinicia un worker al acabar el job.

| Supervisor | Colas | Procesos | `memory` | Total |
|---|---|---|---|---|
| `supervisor-1` | `default` | 1 a 2 | 128 MB | 256 MB |
| `supervisor-mail` | `mail`: correos y el PDF y el Excel de los informes que se envían | 1 | 256 MB | 256 MB |

- **Por qué uno propio para `mail`:** desde la Fase 9, ahí se generan los informes de los envíos (D-141), que piden más memoria que un correo. El envío sube el `memory_limit` del proceso a esos 256 MB, porque la CLI del servidor trae 128M.
- **Cuentas:**
  - los workers suman 512 MB, el `MemoryLimit` de `audax-horizon.service`,
  - con el maestro de Horizon (64 MB), el transcriptor (256 MB) y Reverb (256 MB) suman 1088 MB, y queda sitio para el programador dentro de los 1280 MiB del slice.
- **Comprobación:** `tests/Feature/QueueConfigTest.php` lee `deploy/systemd/` y falla si un cambio en Horizon o en las unidades se sale del slice.
- **Al desplegar:** basta con `horizon:terminate`, que ya hace `scripts/desplegar-dev.sh`. No cambia ninguna unidad de systemd.

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
- **Correo:** `MAIL_*`, por el relé SMTP de Google Workspace (`smtp-relay.gmail.com:587`, STARTTLS, sin usuario ni contraseña). Google solo lo acepta desde la IP del servidor (`185.33.65.98`) y con remitentes del dominio; la regla se llama «Audax Proyectos (servidor)» (consola de Google → Gmail → Enrutamiento). Remitente: `administracion@audaxstudio.com`. Si cambia la IP del servidor, hay que cambiarla también en esa regla.
- **Tiempo real:**
  - `BROADCAST_CONNECTION=reverb`,
  - `REVERB_*`: servidor en `127.0.0.1:18080`; navegador en `projects.audaxstudio.com:443` https,
  - `REVERB_ALLOWED_ORIGINS=projects.audaxstudio.com`.
- **Transcripción:**
  - `TRANSCRIPTION_DRIVER=whisper` y `TRANSCRIPTION_QUEUE_CONNECTION=redis-transcriptions`,
  - `WHISPER_URL=http://127.0.0.1:18091`, `WHISPER_MODEL=small` y `WHISPER_TIMEOUT=2340`.
- **PDF de informes:** `REPORTS_PDF_DRIVER=gotenberg`, `GOTENBERG_URL=http://127.0.0.1:18092` y `GOTENBERG_TIMEOUT=65` (algo más que el `--api-timeout` de Gotenberg, para recibir su error en vez de cortar). En local sin Docker, `REPORTS_PDF_DRIVER=html`: la descarga «PDF» es el HTML.
- **Avisos del navegador:** `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` y `VAPID_SUBJECT`. Se generan con `php artisan push:vapid-keys` y se comprueban con `--check`. Si se cambian, las suscripciones existentes dejan de valer.
- `LINK_PREVIEWS_ENABLED=true`: previsualización de enlaces del chat, con protección SSRF.
- **Google Sheets (D-142):**
  - `GOOGLE_CLIENT_ID` y `GOOGLE_CLIENT_SECRET`: los del cliente OAuth 2.0 de tipo «Interno» del proyecto `audax-proyectos` de Google Cloud. **Los pone el propietario** en `app/shared/.env`; nunca van en Git ni en un mensaje. Sin ellos, la opción «Google Sheets» no se ofrece y Ajustes → Integraciones dice que no está disponible,
  - `GOOGLE_REDIRECT_URI=https://projects.audaxstudio.com/integraciones/google/callback`: debe coincidir exactamente con la URI autorizada en Google Cloud,
  - `GOOGLE_HOSTED_DOMAIN=audaxstudio.com` (por defecto): solo se aceptan cuentas de ese dominio,
  - la web las lee en la siguiente petición (no se usa `config:cache`); no hace falta reiniciar Horizon ni otros procesos, porque nada de Google va por colas.
- **Entrar con Google (D-165):** el mismo cliente OAuth que Google Sheets,
  - en Google Cloud (proyecto `audax-proyectos` → Credenciales → el cliente OAuth → «URIs de redirección autorizadas»), **añadir** `https://projects.audaxstudio.com/login/google/callback` junto a la de Google Sheets,
  - `GOOGLE_LOGIN_REDIRECT_URI=https://projects.audaxstudio.com/login/google/callback` (opcional: vacía, la app usa esa misma ruta de `APP_URL`),
  - `GOOGLE_LOGIN_DOMAINS=audaxstudio.com` (por defecto; varios, separados por comas),
  - se activa o desactiva en `/admin/ajustes` → Seguridad → «Entrar con Google» (activado por defecto; sin credenciales no se ofrece).

## 7. Comprobaciones rápidas

- **Estado general:** `curl -s https://projects.audaxstudio.com/health` muestra base de datos, Valkey, colas, disco y sesiones.
- **Servicios:** `systemctl status audax-horizon audax-scheduler audax-reverb audax-transcriber`.
- **Colas:** Horizon en `/horizon` (solo admin). Las transcripciones, en `/admin/transcripciones`.
- **Copias:** `cat /var/backups/audax/ULTIMA-CORRECTA` y el estado que lee la app en `shared/storage/app/backup-status.json`. Si algo falla, `app:check-storage` avisa al admin cada día.
- **Tiempo real desde fuera:** abrir el chat en dos navegadores; el aviso «escribiendo…» llega al instante.

## 8. Importar ClickUp

`php artisan app:import-clickup {ruta} {--personas=} {--dry-run} {--invitar}` trae de ClickUp clientes, proyectos, bolsas, tareas y horas (D-135 y D-136).

- **Qué necesita:**
  - **el export** de la API v2 de ClickUp en una carpeta fuera de Git y fuera del webspace público: `tree.json` (espacios, carpetas y listas), `tasks.json` (tareas con `include_closed` y `subtasks`) y `time_entries.json` (registros de horas). `team.json` y `fields.json` no se usan,
  - **el fichero de personas**, `personas.json` en la misma carpeta o donde diga `--personas`. Tampoco entra en Git, porque lleva correos reales:

    ```json
    {
      "default_manager": "correo-en-la-app@…",
      "people": [
        {
          "clickup_email": "correo-en-clickup@…",
          "email": "correo-en-la-app@…",
          "name": "Nombre Apellido",
          "role": "admin | department_manager | employee | collaborator",
          "department": "Diseño",
          "is_department_manager": false,
          "import": true, "active": true, "lists": ["901234567"]
        }
      ]
    }
    ```

    - `department` se crea si no existe y puede ser `null`,
    - `is_department_manager` solo vale con `admin` o `department_manager`,
    - `import: false` deja fuera a la persona: ni cuenta, ni tareas, ni horas,
    - `default_manager` es el gestor principal de los proyectos donde ningún admin ni responsable tiene horas.
- **Cómo se ejecuta en el servidor:**
  1. Copia de la base antes de nada, con el formato de la copia nocturna, para poder volver atrás con la sección 4:

     ```bash
     docker exec audax-pg pg_dump -U audax_admin -Fc audax_projects > /var/backups/audax/antes-de-clickup.dump
     ```
  2. Simulación, que no guarda nada y muestra el informe:

     ```bash
     scripts/heavy.sh /opt/plesk/php/8.4/bin/php artisan app:import-clickup /ruta/al/export --dry-run
     ```

  3. Si los recuentos cuadran con ClickUp, la importación de verdad, con el mismo comando sin `--dry-run`. En el ensayo local (SQLite) con el export completo tardó entre 1 min 20 s y 5 min 20 s, según la carga del Mac, no pasó de 82 MB de memoria y dejó una base de 19 MB.
  4. Las invitaciones, solo con el visto bueno del propietario: `--invitar`, que invita a las personas importadas que aún no han entrado nunca. También se pueden enviar desde la administración.
- **Repetir:** el comando es idempotente (`import_refs`). El día del cambio se vuelve a descargar el export y se ejecuta otra vez: actualiza lo que cambió, añade lo nuevo y nunca toca las horas bloqueadas. Las horas de la semana anterior que llegaron en borrador pasan a aprobadas y bloqueadas.
- **El informe:**
  - recuentos por tipo (creados, actualizados, sin cambios y omitidos), horas por persona y registros descartados con su motivo,
  - avisos: personas sin mapear, listas sin el patrón `TIPO+N - Hh - …`, subtareas aplanadas, registros de más de 24 h partidos por días…,
  - queda una sola entrada en la auditoría, «Importación de ClickUp», con los recuentos.
- **No se importan:** comentarios, adjuntos ni etiquetas (D-135), ni los espacios personales y «Recursos».

## 9. Gotenberg (PDF de los informes, Fase 9)

Los PDF de los informes (y el de consumo de bolsa, también el del portal) se maquetan en HTML con la hoja de documentos de Audax y los convierte **Gotenberg**, un Chromium sin interfaz en Docker (licencia MIT, D-140). La app le envía el HTML con todo dentro (CSS, DM Sans y el logo) a `POST /forms/chromium/convert/html`; Gotenberg no tiene que cargar nada de fuera.

**Instalación** (la hace el propietario en la 9.5, con copia previa, batería V y webs): el bloque está en `deploy/gotenberg/compose-service.yml`. Se pega bajo `services:` de `/opt/audax/compose.yml`, se añade su red bajo `networks:` y se arranca solo ese servicio con `docker compose -f /opt/audax/compose.yml up -d gotenberg`. Comprobación: `curl -s http://127.0.0.1:18092/health` responde `"status":"up"`.

| Opción | Por qué |
|---|---|
| `image: gotenberg/gotenberg:8.37.0` | Versión estable fijada (la última de septiembre de 2026). Se actualiza a mano, nunca con `latest`. |
| `ports: 127.0.0.1:18092:3000` | Solo escucha en el propio servidor; no se abre a internet ni hace falta tocar el firewall. |
| `networks: [audax-gotenberg]` | Una red propia: Gotenberg no ve la base de datos ni Valkey. |
| `restart: unless-stopped` | Vuelve solo tras un reinicio del servidor o un fallo. |
| `--api-timeout=60s` | Tiempo máximo por documento: si un PDF tarda más, responde 503 y la app lo explica («ha superado su tiempo máximo»). |
| `--api-download-from-disable=true` | Desactiva `downloadFrom`, que haría que Gotenberg descargase URLs que le pasen (riesgo de SSRF). |
| `--webhook-disable=true` | Sin webhooks: Gotenberg nunca llama a ninguna URL. |
| `--chromium-auto-start=true` | Chromium arranca con el contenedor: el primer PDF del día no espera al arranque (≈150 MB en reposo). |
| `--chromium-restart-after=50` | Reinicia Chromium cada 50 conversiones para que no acumule memoria. |
| `--chromium-max-queue-size=10` | Como mucho 10 documentos en cola; el resto recibe 503 en vez de amontonarse. |
| `--chromium-disable-javascript=true` | Los informes no llevan JavaScript; así nada del contenido puede ejecutarse. |
| `--chromium-allow-list=^(file:///tmp/\|data:)` | Chromium solo carga el propio documento (en `/tmp`) y recursos incrustados (`data:`): ninguna petición a la red. |
| `--chromium-deny-list` (la de serie) | Además, ningún fichero local fuera de `/tmp`. |
| `--libreoffice-disable-routes=true` y `--libreoffice-auto-start=false` | No se usa LibreOffice: ni rutas ni proceso (ahorra memoria). |
| `--prometheus-disable-collect=true` y `--log-level=warn` | Sin métricas que nadie lee y registro solo de avisos. |
| `tmpfs: /tmp:size=256m` | Los ficheros de cada conversión, en memoria y con tope; desaparecen al reiniciar. |
| `mem_limit: 768m`, `mem_swappiness: 0`, `oom_score_adj: 1000` | Tope de memoria; si se pasa, el sistema mata antes este contenedor que cualquier otra cosa del servidor. |
| `cpus: 2`, `cpuset: "6,7"`, `cpu_shares: 64` | Solo los núcleos 6 y 7 (los de las tareas pesadas, como whisper) y con prioridad baja frente a las webs. |
| `pids_limit: 256`, `no-new-privileges` | Límite de procesos (Chromium abre varios) y sin escalada de privilegios. |
| `logging` 3 × 10 MB | El registro no llena el disco. |

**Si Gotenberg no está:** la descarga del PDF responde con error y queda en el registro (`PdfConversionFailed`, con el motivo); Excel, CSV e Imprimir siguen funcionando, porque no lo usan.

