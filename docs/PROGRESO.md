# Progreso

_Última actualización: 03/10/2026_

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

### Fase 2: informes (rama `fase-2`, desplegada el 27/09)
- **Dashboards:** dirección, departamento, persona, cliente y proyecto, más facturación y el informe detallado. Todos tienen filtros en la URL, comparación «al mismo punto» y exportación a Excel y CSV.
- **PDF y resumen:** PDF de consumo de bolsa y resumen semanal por email (D-043 a D-048, D-078 y D-079).
- **Revisión global:** 21 hallazgos confirmados y corregidos con sus tests (D-080 a D-087). Entre ellos, los mismos céntimos en todas partes, la caché invalidada tras el commit y con el alcance de quien mira, y la capacidad por tramos.
- **Tests:** 1717 de Pest en local y **1717 en PostgreSQL 18 en el servidor**, incluidos los de rendimiento de los dashboards (< 1 s); 637 de Vitest; **290 E2E** con Playwright y axe; CI en verde.
- ✅ **FASE 2 CERRADA el 27/09/2026** (etiqueta `fase-2-cerrada`).

### Fase 3: carga (rama `fase-3`, desplegada el 02/10)
- **Capacidad real:** festivos con importación anual, ausencias con su aprobación, y capacidad que las descuenta en horas, informes y carga (D-049 a D-052).
- **Vista Carga:** personas × días o semanas, con celdas de semáforo, panel de la celda y reasignación, y bandejas «Sin planificar», «Sin asignar» y «De tus proyectos».
- **Carga futura** en el informe de departamento y en Inicio.
- **Revisión global:** 8 hallazgos corregidos con sus tests (D-088 y D-091), entre ellos el tipo de ausencia oculto a quien no supervisa (RGPD).
- **Tests:** 1996 en PostgreSQL 18 en el servidor; 338 E2E con Playwright y axe; CI en verde.
- ✅ **FASE 3 CERRADA el 02/10/2026** (etiqueta `fase-3-cerrada`).

### Fase 4: Gantt (rama `fase-4`, desplegada el 02/10)
- **Planificación:**
  - dependencias fin-inicio sin ciclos, con propuesta al mover (D-056, D-057 y D-090),
  - Gantt propio con hitos, calendario por mes y semana,
  - plantillas de proyecto con importación y exportación (D-058),
  - tareas recurrentes (D-059 y D-089).
- **Revisión global:** 7 hallazgos corregidos con sus tests, entre ellos las franjas del calendario y la serialización de los enlaces.
- **Tests:** 2321 en PostgreSQL 18 en el servidor; 385 E2E con Playwright y axe; CI en verde.
- ✅ **FASE 4 CERRADA el 02/10/2026** (etiqueta `fase-4-cerrada`).

### Fase 5: portal de cliente (rama `fase-5`, desplegada el 02/10)
- **Portal:** usuarios por invitación, bolsas con su consumo e histórico, PDF sin importes, proyectos y Gantt de solo lectura (si se abren), avisos al cliente del 90 y 100 %, e identidad de la empresa (D-063 a D-067 y D-092 a D-101).
- **Aislamiento:** comprobado en todas las rutas internas y del portal (`ClientIsolationTest` y `PortalRoutesIsolationTest`).
- **Revisión global:** 12 hallazgos corregidos. El más importante: un aviso al cliente ya no puede romper una imputación o una aprobación interna.
- **Tests:** 2450 en PostgreSQL 18 en el servidor; 391 E2E con Playwright y axe; CI en verde.
- ✅ **FASE 5 CERRADA el 02/10/2026** (etiqueta `fase-5-cerrada`).

## En curso (modo autónomo, D-027)
- **Fase 6 (chat):** desplegada el 03/10 (2839 tests en PostgreSQL 18 en el servidor, 398 E2E con Reverb local, CI en verde). Reverb y el transcriptor activos en el servidor. Falta que el propietario pegue en Plesk la directiva de nginx `/app/` (RUNBOOK A1) y cerrar la fase.
- **Fase 7 (pulido):** contrato (D-073 a D-077), preferencias de notificación, resumen diario, recordatorio de los viernes, auditoría, privacidad, retención, exportación de datos y avisos de almacenamiento hechos; integración con el chat de la Fase 6 en la rama `fase-7`.

## Siguiente
1. Cerrar la Fase 6 cuando esté la directiva de nginx `/app/`.
2. Fase 7: E2E, revisión, despliegue, copia externa y prueba de restauración en el servidor, `DEPLOY.md` y cierre.

## Bloqueos: necesitamos del usuario
- [x] Aprobar `/styleguide` (26/09).
- [x] CI revisada por el propietario: todo en verde (26/09). `gh auth login` hecho el 26/09: la CI se revisa ya sin depender de él.
- [x] D-013 aprobada: se mantiene Vite+ (26/09).
- [ ] **Al final del proyecto (D-030):** datos SMTP (hasta entonces los emails van al log), lista inicial de empleados y revisión del texto RGPD.
- [x] Copias: el servidor entero se copia a diario; además, volcado nocturno local de PostgreSQL (D-029).

## Problemas abiertos
- Ninguno.
