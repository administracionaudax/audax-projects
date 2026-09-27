# Progreso

_Última actualización: 27/09/2026 02:20_

## Hecho

### Fase 0: servidor (runbook aprobado y ejecutado el 26/09)
- **Auditoría en solo lectura** (`docs/SERVIDOR.md`) y línea base HTTP de las 38 webs.
- **Anomalías corregidas con autorización:**
  - `grep` atascado terminado,
  - swap vaciada,
  - bucle de `cron` y `dbus` corregido: la carga bajó de ~4,4 a ~2,4.
- **Suscripción Plesk** `projects.audaxstudio.com`: usuario `audaxprojects`, PHP 8.4 FPM dedicado, Let's Encrypt y redirección a HTTPS.
- **Datos:** PostgreSQL 18 y Valkey 9 en Docker (solo 127.0.0.1, con límites y contraseña).
- **Procesos:** Horizon y scheduler en systemd, con límites. Prueba de arranque en frío superada.
- **Acceso y fail2ban:**
  - IP de la oficina como IP de confianza en fail2ban,
  - despliegue **sin root** con el usuario de la app (clave añadida por el propietario).
- **Otras webs:** 35/35 comparables iguales a la línea base después de cada paso (`SERVIDOR-CAMBIOS.md`).

### Fase 0: aplicación (rama `fase-0`, desplegada)
- **Base:** starter kit de Laravel con React con 2FA y confirmación de contraseña. Sin registro público.
- **Tema y marca:**
  - tema Audax claro y oscuro con tokens AA verificados por test,
  - paleta de datos validada para daltonismo,
  - logotipo y favicon oficiales (D-025),
  - DM Sans autoalojada.
- **Roles y acceso:** roles, permisos y gates; middleware `active`, `internal`, `portal` y `2fa`; cabeceras de seguridad (CSP con nonce, HSTS y noindex).
- **Instalación y administración:** `app:install`, departamentos con **varios responsables** (D-024) y ajustes.
- **Seguridad de cuentas:**
  - registro de accesos (incluidos los 2FA fallidos),
  - sesiones activas que se pueden cerrar de verdad, también las de «Recordarme».
- **Interfaz y funciones base:**
  - interfaz en español con la navegación del SPEC,
  - login con el degradado de marca,
  - Inicio con el panel personal (estados vacíos por fase),
  - portal de cliente (estructura),
  - búsqueda global con Ctrl/Cmd+K (páginas y personas, sin acentos en PostgreSQL),
  - `/health`, PWA (manifest, service worker y página sin conexión).
- **`/styleguide` pública** en https://projects.audaxstudio.com/styleguide.
- **CI** en GitHub Actions (php, js, security y e2e con Playwright y axe). Pendiente de su primera ejecución en GitHub.
- **Revisión adversarial:** 42 hallazgos confirmados, todos corregidos (D-026).
- **Tests:** 300 de Pest en PostgreSQL 18 (en el servidor) y 284 de Vitest. PHPStan nivel 7 sin errores; lint y formato limpios.
- **Primer admin creado:** `desarrollo@audaxstudio.com`, con enlace de un solo uso para fijar la contraseña.

### Aceptación de la Fase 0 (26/09)
- ✅ **`/styleguide` aprobada** por el propietario (15:20).
- ✅ **El propietario entró** con `desarrollo@audaxstudio.com` (15:17, en el registro de accesos).
- ✅ **E2E con Playwright y axe en local:** 5/5 (accesibilidad AA en claro y oscuro, contraste sobre el degradado, aislamiento del portal, y login con navegación, tema y cierre de sesión).
- ✅ Tests de permisos, `SERVIDOR.md` y propuesta aprobada, primer despliegue sin afectar a otras webs.
- ✅ **CI de GitHub Actions en verde** (run #7: php, js, security y e2e). Ha requerido dos correcciones: la guarda de Redis bloqueaba `composer install` sin `.env`, y laravel-vite-plugin bloqueaba Vitest con `CI=true`.
- ✅ **FASE 0 CERRADA el 26/09/2026.** `fase-0` fusionada en `main` (etiqueta `fase-0-cerrada`). Servidor desplegado con el mismo código: 301 tests en PostgreSQL y 35/35 webs iguales.

### Fase 1: núcleo (rama `fase-1`, desplegada el 27/09)
- **Entregas 1.1 a 1.7 integradas:** clientes, proyectos, bolsas (D-019), tareas, horas, temporizador, aprobación, notificaciones, búsqueda, invitaciones, seeders de 12 meses y copia nocturna (D-029).
- **Revisión global:** 26 hallazgos confirmados y corregidos con sus tests (D-053 a D-055).
- **Aislamiento del cliente** comprobado en **todas** las rutas (`tests/Feature/Portal/ClientIsolationTest`). Quién puede entrar se decide antes de buscar los modelos de la URL.
- **Tests:** 1363 de Pest en local; 1359 en PostgreSQL 18 en el servidor (todos en verde); 539 de Vitest; 232 E2E con Playwright y axe.
- **Subida de 50 MB** comprobada contra el servidor: no hace falta tocar nginx, ModSecurity ni PHP.

## En curso (modo autónomo, D-027)
- **Fase 2 (informes):** contrato hecho (`docs/PLAN-FASE-2.md`, D-043 a D-048); agentes R1, R2 y R3 implementando.
- **Fase 3 (carga):** contrato hecho (`docs/PLAN-FASE-3.md`, D-049 a D-052); agentes W1 y W2 implementando.
- **Fase 4 (Gantt):** contrato hecho (`docs/PLAN-FASE-4.md`, D-056 a D-062); agentes G1, G2 y G3 implementando.

## Siguiente
1. Integrar las Fases 2, 3 y 4 en orden, con revisión global, E2E y despliegue de cada una.
2. Fase 5 (portal), Fase 6 (chat, Reverb y transcripción) y Fase 7 (pulido).

## Bloqueos: necesitamos del usuario
- [x] Aprobar `/styleguide` (26/09).
- [x] CI revisada por el propietario: todo en verde (26/09). `gh auth login` hecho el 26/09: la CI se revisa ya sin depender de él.
- [x] D-013 aprobada: se mantiene Vite+ (26/09).
- [ ] **Al final del proyecto (D-030):** datos SMTP (hasta entonces los emails van al log), lista inicial de empleados y revisión del texto RGPD.
- [x] Copias: el servidor entero se copia a diario; además, volcado nocturno local de PostgreSQL (D-029).

## Problemas abiertos
- Ninguno.
