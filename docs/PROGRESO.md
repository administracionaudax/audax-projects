# Progreso

_Última actualización: 26/09/2026 13:20_

## Hecho

### Fase 0: servidor (runbook aprobado y ejecutado el 26/09)
- **Auditoría en solo lectura:** `docs/SERVIDOR.md`, con la línea base HTTP de las 38 webs.
- **Anomalías del servidor corregidas con autorización:**
  - `grep` atascado terminado,
  - swap vaciada,
  - bucle de `cron` y `dbus` corregido reiniciando cron: la carga media bajó de ~4,4 a ~2.
- **Suscripción Plesk** `projects.audaxstudio.com`: usuario `audaxprojects`, PHP 8.4 FPM dedicado (`ondemand`, máx. 6) y bloqueada frente al plan.
- **SSL:** Let's Encrypt (hasta el 25/12/2026) con redirección a HTTPS.
- **Datos:** PostgreSQL 18 y Valkey 9 en Docker, solo en 127.0.0.1, con límites y contraseña. Bases `audax_projects` y `audax_projects_test` aisladas.
- **Procesos:** Horizon y scheduler en systemd, con techo de 768 MiB y 1,5 CPU. Prueba de arranque en frío superada.
- **fail2ban:** la IP de la oficina (93.175.243.255) está como IP de confianza.
- **Primer despliegue:** 29/29 tests del kit en verde contra PostgreSQL. `/login` responde 200.
- **Otras webs:** 35/35 comparables siguen igual que en la línea base después de cada paso (`SERVIDOR-CAMBIOS.md`).

### Fase 0: código
- **Base:** starter kit oficial de Laravel con React con solo 2FA y confirmación de contraseña. Pest 5, PHP 8.4 y PostgreSQL.
- **Tema Audax:** claro y oscuro con tokens AA verificados por test y paleta de datos validada para daltonismo (D-011 y D-012). DM Sans autoalojada.
- **Formateadores** es-ES y Europe/Madrid con tests, incluidos los cambios de horario.
- **Salvaguardas:** los tests no pueden tocar una base que no sea `_test`, y la app no puede usar el Redis compartido.
- **Contrato de la Fase 0.4:** rutas en español, middleware, props compartidas y tipos.
- **Despliegue:** `scripts/desplegar-dev.sh`, `scripts/heavy.sh`, unidades systemd y `scripts/server/*`.

## En curso
- **Implementación en paralelo** (ramas `wf/backend`, `wf/frontend` y `wf/styleguide-pwa-ci`):
  - **backend:** roles y permisos, middleware, `app:install`, `/health`, búsqueda, sesiones, cabeceras de seguridad y tests Pest de la matriz de permisos,
  - **frontend:** interfaz en español, navegación, login con el degradado, ajustes, portal y paleta Ctrl/Cmd+K,
  - **styleguide, PWA y CI:** `/styleguide`, manifest, service worker, GitHub Actions y E2E con Playwright y axe.
- Después: integración, revisión adversarial, despliegue y `/styleguide` pública para tu aprobación.

## Siguiente
1. Aprobación de `/styleguide`. Es un criterio de aceptación de la Fase 0.
2. Crear el primer admin con `app:install --email=desarrollo@audaxstudio.com` (lo indicó el propietario el 26/09) y probar el login.
3. Cerrar las dudas abiertas de `DECISIONES.md` antes de la Fase 1.

## Bloqueos: necesitamos del usuario
- [x] Clave SSH del usuario `audaxprojects` para desplegar sin root (hecho por el propietario el 26/09, D-018).
- [ ] Confirmar la propuesta D-013 (Vite+ en lugar de ESLint y Prettier).
- [ ] Más adelante: datos SMTP, logo SVG y favicon, destino de los backups.

## Problemas abiertos
- Ninguno.
