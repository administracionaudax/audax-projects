# CLAUDE.md: Gestor interno de proyectos de Audax Studio

## Al empezar cualquier sesión
Lee, en este orden:
1. `SPEC.md`: la fuente de verdad del producto.
2. `docs/PROGRESO.md`: qué está hecho, en curso y pendiente.
3. `docs/DECISIONES.md`: decisiones tomadas, incluidas las que cambian el SPEC.
4. `docs/SERVIDOR.md` y `docs/SERVIDOR-CAMBIOS.md`, si vas a tocar el servidor.

Las reglas de la **sección 16 del SPEC (servidor)** prevalecen sobre todo lo demás. Ante la duda, para y pregunta.

## Estado de las fases
| Fase | Estado |
|---|---|
| 0. Fundaciones | En curso: esperando el acceso SSH y el repositorio de GitHub |
| 1. Núcleo | Pendiente |
| 2. Informes | Pendiente |
| 3. Carga | Pendiente |
| 4. Gantt | Pendiente |
| 5. Portal | Pendiente |
| 6. Chat | Pendiente |
| 7. Pulido | Pendiente |

## Entorno (decidido el 26/09/2026, ver DECISIONES)
- **No hay entorno local con Docker.** Se desarrolla en el servidor `svr.ztudio.es`, directamente en `projects.audaxstudio.com`, hasta que se separen producción y desarrollo.
- Esta carpeta es la **copia de trabajo Git**. El código se sincroniza al servidor con `rsync` por SSH, y Composer, Artisan, los tests y la compilación se ejecutan por SSH con `nice`/`ionice`.
- Alias SSH: `audax` (clave `~/.ssh/audax_projects_ed25519`). Pendiente de configurar cuando haya usuario.
- Remoto Git: GitHub, privado, en la cuenta `administracion@audaxstudio.com`. Pendiente.

## Comandos
_Se completan en la Fase 0.2, cuando exista la app._

## Convenciones
- **Idioma:** la interfaz y los textos, en español (España); el código, las tablas y las variables, en inglés. Las URLs visibles van en español (`/proyectos`) y los nombres de ruta en inglés (`projects.index`).
- **Datos:** horas en **minutos enteros**; importes en `decimal` (nunca float); instantes en UTC y mostrados en `Europe/Madrid`; la semana empieza en lunes.
- **Commits:** Conventional Commits, pequeños. Una rama por fase (`fase-0`, `fase-1`, …).
- **Tests obligatorios** para cualquier regla de negocio y permiso: Pest (backend), Vitest (frontend) y Playwright (E2E, solo en la CI).
- **Secretos:** nunca en Git. Usa `.env.example` con valores ficticios.
- **Dependencias:** comprueba la versión estable y la licencia antes de instalar (MIT/Apache/BSD/ISC; las fuentes, OFL). Nada de GPL en el navegador sin avisar.

## Estructura
_Se completa en la Fase 0.2._
