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
| 0. Fundaciones | ✅ **Cerrada el 26/09/2026** (etiqueta `fase-0-cerrada`) |
| 1. Núcleo | ✅ **Cerrada el 27/09/2026** (etiqueta `fase-1-cerrada`) |
| 2. Informes | ✅ **Cerrada el 27/09/2026** (etiqueta `fase-2-cerrada`) |
| 3. Carga | ✅ **Cerrada el 02/10/2026** (etiqueta `fase-3-cerrada`) |
| 4. Gantt | ✅ **Cerrada el 02/10/2026** (etiqueta `fase-4-cerrada`) |
| 5. Portal | ✅ **Cerrada el 02/10/2026** (etiqueta `fase-5-cerrada`) |
| 6. Chat | ✅ **Cerrada el 03/10/2026** (etiqueta `fase-6-cerrada`) |
| 7. Pulido | ✅ **Cerrada el 03/10/2026** (etiqueta `fase-7-cerrada`) |
| 8. Puesta en marcha | En curso: `docs/PLAN-FASE-8.md` (colaboradores externos, importación de ClickUp y hoja de estilos) |
| 9. Exportar y enviar | En curso: `docs/PLAN-FASE-9.md` (PDF, imprimir, Google Sheets, correo y envíos programados) |
| 10. Weekly | En curso: `docs/PLAN-FASE-10.md` (fusión de WeeklySync; lista de paridad en `docs/WEEKLY-INVENTARIO.md`) |
| 11. RR. HH. («Personas») | En curso: `docs/PLAN-FASE-11.md` (sustituye a Woffu). R1, registro de jornada, en la rama `rrhh-r1`; el módulo `people` sigue apagado hasta tener R1 y R2 |

## Modo autónomo (D-027)
Desde la Fase 1 se trabaja fase tras fase sin esperar aprobaciones. El plan de cada fase queda en `docs/PLAN-FASE-N.md` y las decisiones de producto se registran en `docs/DECISIONES.md`. Solo se contacta al propietario para SMTP, lista de empleados y texto RGPD (al final), o por un imprevisto del servidor que no se pueda revertir.

## Entorno (D-002, D-018, D-028)
- **Desarrollo:** entorno único en `https://projects.audaxstudio.com` (servidor `svr.ztudio.es`, Plesk); no se separa producción (D-028). No hay Docker local.
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
- **Git:** `git@github-audax:administracionaudax/audax-projects.git` (deploy key `~/.ssh/audax_github_ed25519`). Rama principal `main`; una rama por fase (`fase-1`, …). CI en GitHub Actions en cada push a `main` y `fase-*`.

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
- **Tema** (hoja de estilos de Audax v1.2, D-137):
  - solo tokens de `resources/css/app.css` (AA verificado por test); estilo plano, sin esquinas redondeadas ni sombras, y nunca radios escritos a mano,
  - DM Sans 400/500 y 600 solo para negritas y cifras, nunca 700,
  - degradado de marca solo en el login, la cabecera del portal y los estados vacíos grandes,
  - gráficas con `--chart-1..6` en orden fijo (D-012).
- **Commits:** Conventional Commits en español, pequeños. Una rama por fase.
- **Tests obligatorios** para cualquier regla de negocio y permiso: Pest, Vitest y Playwright (E2E, en la CI).
- **Horas y bolsas:** las entradas se escriben SIEMPRE con `App\Domain\Time\TimeEntryWriter`; el consumo y el exceso solo los calcula `App\Domain\HourBanks\HourBankLedger` (contrato en `docs/PLAN-FASE-1.md`).
- **Textos por área:** `lang/ui/<área>.json` en React y `lang/es/<área>.php` en PHP; `lang/es.json` queda para la Fase 0 y el backend.
- **Secretos:** nunca en Git. Usa `.env.example` con valores ficticios.
- **Dependencias:** comprueba la versión estable y la licencia antes de instalar (MIT/Apache/BSD/ISC; las fuentes, OFL). Nada de GPL en el navegador sin avisar.

## Estructura
- `app/`: Laravel.
  - `Http/Middleware`: `active`, `internal`, `portal`, `2fa`, `SecurityHeaders` y las props compartidas (`auth`, `timer`, `notifications`, `config`).
  - `Enums`: roles, permisos y estados del dominio.
  - `Domain`: reglas de negocio. En `HourBanks/HourBankLedger` el consumo y el exceso; en `Time` `TimeEntryWriter`, `TimeEntryRules`, `TimerService` y `Capacity`.
  - `Policies`: permisos por entidad (D-021, D-022, D-031 a D-036).
  - `Http/Resources`: contrato JSON con `resources/js/types/domain.ts`.
  - `Search`: búsqueda global (páginas, proyectos, tareas, clientes y personas).
  - `Notifications/AppNotification`: base de las notificaciones en la app.
- `routes/app/<área>.php`: rutas de cada área de la Fase 1, cargadas desde `routes/web.php`.
- `resources/js/`:
  - `pages/` (Inertia), `layouts/`, `components/` (en `ui/`, shadcn),
  - `lib/format.ts` e `i18n.ts`,
  - `routes/` y `actions/`, generados por Wayfinder y fuera de Git.
- `resources/css/app.css`: tema Audax (claro y oscuro).
- `lang/`: `es.json` (Fase 0 y backend), `es/*.php` (mensajes PHP por grupo) y `ui/*.json` (textos del frontend por área).
- `tests/`: `Feature/` y `Unit/` (Pest), `js/` (Vitest), `e2e/` (Playwright) y `fixtures/` (casos compartidos PHP/TS).
- `database/seeders/DemoDataSeeder.php`: 12 meses de datos de ejemplo (solo local, tests y CI).
- `deploy/`: unidades systemd del servidor y la copia nocturna (`backup/audax-backup.sh`, D-029). `scripts/` contiene los scripts de despliegue y verificación. `docs/`, la documentación viva.
