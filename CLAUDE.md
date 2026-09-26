# CLAUDE.md: Gestor interno de proyectos de Audax Studio

## Al empezar cualquier sesión
Lee, en este orden:
1. `SPEC.md`: la fuente de verdad del producto.
2. `docs/PROGRESO.md`: qué está hecho, en curso y pendiente.
3. `docs/DECISIONES.md`: decisiones tomadas, incluidas las que cambian el SPEC.
4. `docs/SERVIDOR.md`, `docs/RUNBOOK-DESPLIEGUE.md` y `docs/SERVIDOR-CAMBIOS.md`, si vas a tocar el servidor.

Las reglas de la **sección 16 del SPEC (servidor)** prevalecen sobre todo lo demás. Ante la duda, para y pregunta. **Todo** cambio en el servidor se anota en `docs/SERVIDOR-CAMBIOS.md` y va seguido de `scripts/server/verificar.sh` y `scripts/server/comparar-webs.sh`.

## Estado de las fases
| Fase | Estado |
|---|---|
| 0. Fundaciones | Casi terminada: desplegada y revisada; falta que el propietario apruebe `/styleguide` |
| 1. Núcleo | Pendiente (dudas resueltas: D-019 a D-024) |
| 2. Informes | Pendiente |
| 3. Carga | Pendiente |
| 4. Gantt | Pendiente |
| 5. Portal | Pendiente |
| 6. Chat | Pendiente |
| 7. Pulido | Pendiente |

## Entorno (D-002, D-018)
- **Desarrollo:** se desarrolla en `https://projects.audaxstudio.com` (servidor `svr.ztudio.es`, Plesk) hasta que la plantilla empiece a usar la app. No hay Docker local.
- **Mac:** esta carpeta es la copia de trabajo Git, con PHP 8.4, Composer y GNU rsync de Homebrew y Node 25.
- **SSH** (clave `~/.ssh/audax_projects_ed25519`, puerto 5222):
  - `audax-projects` → usuario de la app `audaxprojects`: **el día a día**, que solo puede tocar su webspace,
  - `audax` → root: **solo** para cambios de sistema aprobados (systemd, Docker, Plesk), anotados en `SERVIDOR-CAMBIOS.md`.
- **Servidor:**
  - app en `/var/www/vhosts/projects.audaxstudio.com/app/{current → releases/dev, shared/{.env,.env.testing,storage}}`,
  - PHP en `/opt/plesk/php/8.4/bin/php` (**nunca** el `php` del PATH del sistema, que es 8.2),
  - Composer en `/opt/psa/var/modules/composer/composer.phar`.
- **Datos:** PostgreSQL 18 en `127.0.0.1:15432` (bases `audax_projects` y `audax_projects_test`) y Valkey 9 en `127.0.0.1:16379`, en Docker (`/opt/audax/compose.yml`). **Nunca** el Redis compartido del 6379.
- **Procesos:** `audax-horizon.service` y `audax-scheduler.service` (systemd, `system-audax.slice`, límites de memoria y CPU).
- **Git:** `git@github-audax:administracionaudax/audax-projects.git` (deploy key `~/.ssh/audax_github_ed25519`). Rama de trabajo: `fase-0`.

## Comandos
```bash
export PATH=/usr/local/opt/php@8.4/bin:$PATH      # en el Mac, siempre PHP 8.4

# Calidad y tests en el Mac (PHP en SQLite en memoria, vía .env.testing local)
vendor/bin/pint                 # formato PHP
vendor/bin/phpstan analyse      # Larastan, nivel ≥ 6
vendor/bin/pest                 # tests PHP (rápidos, SQLite)
npx vp check                    # lint (Oxlint) + formato (Oxfmt)  → npx vp check --fix
npx tsc --noEmit                # tipos
npx vp test run                 # Vitest (formateadores, contraste AA del tema, componentes)
npx vp build                    # compila assets (genera antes las rutas tipadas con Wayfinder)

# Desplegar en el servidor de desarrollo (build + rsync + composer si cambia + migrate + reinicio de Horizon)
scripts/desplegar-dev.sh                 # añade --tests para ejecutar Pest contra PostgreSQL en el servidor
scripts/server/comparar-webs.sh          # las 38 webs del servidor siguen como en la línea base
ssh audax "bash -s -- '<T0>' \"\$(cat /root/audax-backup/ULTIMO)\"" < scripts/server/verificar.sh   # batería V
```
- **Tareas pesadas en el servidor:** siempre con `scripts/heavy.sh`, que da prioridad mínima, usa los núcleos 6 y 7, aplica un candado y aborta si hay poca memoria o mucha carga.
- **Seeder de datos de ejemplo:** **nunca** se ejecuta en el servidor. El primer admin se crea con `php artisan app:install`.

## Convenciones
- **Idioma:**
  - la interfaz y los textos, en español de España con tuteo, y siempre a través de `t()` (`lang/es.json`) en React o de `__()` (`lang/es/*.php`) en PHP,
  - el código, las tablas y las variables, en inglés,
  - las URLs visibles en español y los nombres de ruta en inglés.
- **Datos:** horas en **minutos enteros**; importes en `decimal` (nunca float); instantes en UTC y mostrados en `Europe/Madrid` (`resources/js/lib/format.ts`); la semana empieza en lunes.
- **Tema:**
  - solo tokens de `resources/css/app.css` (AA verificado por test) y radio de 3 px,
  - DM Sans 400/500, nunca negritas,
  - degradado de marca solo en el login, la cabecera del portal y los estados vacíos grandes,
  - gráficas con `--chart-1..6` en orden fijo (D-012).
- **Commits:** Conventional Commits en español, pequeños. Una rama por fase.
- **Tests obligatorios** para cualquier regla de negocio y permiso: Pest, Vitest y Playwright (E2E, en la CI).
- **Secretos:** nunca en Git. Usa `.env.example` con valores ficticios.
- **Dependencias:** comprueba la versión estable y la licencia antes de instalar (MIT/Apache/BSD/ISC; las fuentes, OFL). Nada de GPL en el navegador sin avisar.

## Estructura
- `app/`: Laravel. En `Http/Middleware` están `active`, `internal`, `portal`, `2fa`, `SecurityHeaders` y las props compartidas; en `Enums` los roles y permisos; en `Search` la búsqueda global.
- `resources/js/`:
  - `pages/` (Inertia), `layouts/`, `components/` (en `ui/`, shadcn),
  - `lib/format.ts` e `i18n.ts`,
  - `routes/` y `actions/`, generados por Wayfinder y fuera de Git.
- `resources/css/app.css`: tema Audax (claro y oscuro).
- `tests/`: `Feature/` y `Unit/` (Pest), `js/` (Vitest) y `e2e/` (Playwright).
- `deploy/systemd/`: unidades del servidor. `scripts/` contiene los scripts de despliegue y verificación. `docs/`, la documentación viva.
