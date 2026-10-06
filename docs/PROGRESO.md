# Progreso

_Última actualización: 05/10/2026_

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

### Fase 6: chat (rama `fase-6`, desplegada el 03/10)
- **Chat:**
  - conversaciones de proyecto, directas y grupos,
  - menciones, hilos, reacciones, fijados, moderación del admin y tareas creadas desde un mensaje,
  - adjuntos, audios con transcripción en el propio servidor (whisper.cpp `small`) y búsqueda en mensajes, archivos y transcripciones (D-068 a D-072 y D-110 a D-121).
- **Tiempo real y avisos:** Reverb, presencia, «escribiendo…», leídos y avisos del navegador (Web Push).
- **En el servidor:** el contenedor `audax-whisper` y las unidades `audax-reverb` y `audax-transcriber`.
- **Revisión global:** 19 hallazgos corregidos, entre ellos borrar en proyectos archivados, la campana con texto ocultado, la duración falseada de los audios y la accesibilidad del foco.
- **Tests:** 2839 en PostgreSQL 18 en el servidor; 398 E2E con Reverb de verdad.
- **Pendiente del propietario:** pegar en Plesk la directiva de nginx `/app/` (texto en `docs/DEPLOY.md` y en RUNBOOK A1) para que el tiempo real llegue al instante. Mientras tanto, el chat funciona con consultas periódicas.
- ✅ **FASE 6 CERRADA el 03/10/2026** (etiqueta `fase-6-cerrada`).

### Fase 7: pulido (rama `fase-7`, desplegada el 03/10)
- **Notificaciones:** preferencias por evento y canal (en la app, email y navegador), resumen diario y recordatorio de los viernes (D-073).
- **Auditoría visible:** con filtros, detalle y CSV (D-074).
- **RGPD:** texto informativo con su lectura (borrador pendiente de asesor), retención configurable y exportación de los datos personales (D-075).
- **Copias:** estado para la app, prueba mensual de restauración y copia externa preparada (D-076).
- **Observabilidad y documentación:** registros en JSON y `docs/DEPLOY.md` (D-077).
- **Integración:** todo lo anterior conectado con el chat (D-122 a D-133).
- **Tests:** 3019 en PostgreSQL 18 en el servidor; 1316 de Vitest; 410 E2E con Playwright, axe y Reverb; CI en verde.
- ✅ **FASE 7 CERRADA el 03/10/2026** (etiqueta `fase-7-cerrada`).

### Mejoras de tareas (rama `mejoras-tareas`, 05/10, sin desplegar)
Tres mejoras pedidas por el propietario tras probar las tareas que vienen de ClickUp (D-170 a D-173):
- **Registrado del padre = propio + subtareas**, en la lista, el kanban, el panel (con el desglose) y Mis tareas; la estimación propia del padre se conserva y se ve.
- **Subtareas con diálogo:** responsable, fechas, estimación, tipo, prioridad y estado, heredados del padre, y «Crear otra al guardar». También «Crear con más datos» en el alta rápida.
- **Horas con inicio y fin** en el diálogo de horas (panel, `/horas` e Inicio): sin cruzar la medianoche, con aviso de solapes y la franja en las entradas de la tarea.
- **Tests:** Pest (`TaskLoggedTotalsTest`, `TimeEntryRangeTest` y alta de subtarea), Vitest (diálogos, panel y formateadores) y E2E en `tests/e2e/task-improvements.spec.ts` (sin ejecutar aún).

### Entrar con Google (rama `acceso-google`, 05/10, sin desplegar)
- **«Entrar con Google»** en `/login` y en la invitación de alta, con el cliente OAuth de Google Sheets: solo la plantilla de Workspace que ya existe en la app, activa y sin ser colaborador externo; nunca crea usuarios (D-165 a D-168).
- **Seguridad:** `state`, `nonce` y PKCE; validación del `id_token` en el servidor (dominio, `hd` y correo verificado); el 2FA propio se sigue pidiendo a quien lo tiene; registro de accesos con el método, auditoría y límite de intentos.
- **Interruptor** en `/admin/ajustes` (activado por defecto si hay credenciales).
- **Pendiente del propietario:** añadir en Google Cloud la URI `https://projects.audaxstudio.com/login/google/callback` al cliente OAuth y desplegar (la migración añade `login_events.method`).

### Menú plegable (rama `menu-colapsable`, 06/10, sin desplegar)
- **Barra lateral en secciones plegables** (D-260): Inicio y Chat fijos arriba; Proyectos, Weekly, Personas (Ausencias; nombre provisional del módulo de RR. HH.), Facturación (preparada, oculta mientras no tenga entradas) y Administración (Panel, Usuarios y Ajustes).
- **Estado por persona:** se guarda en el servidor (`users.nav_collapsed`, `PUT /menu/secciones`) y en el navegador; por defecto todo desplegado, y la sección de la página a la que entras se despliega sola.
- **Accesible:** encabezados con `aria-expanded` y `aria-controls`, teclado, modo icono sin encabezados y móvil a 375 px.
- **Tests:** Pest (`NavSectionsTest`), Vitest (`nav-sections`, `app-sidebar` y la Weekly) y E2E (`navigation.spec.ts`, con `/admin` en `SIDEBAR_PATHS`). Al desplegar hay una migración nueva.

## Siguiente: puesta en marcha (lo que falta del propietario, D-030)
1. **Datos SMTP:** hasta entonces, los emails van al registro. Hay que poner las líneas `MAIL_*` del `.env` y hacer una prueba de envío.
2. **Lista de empleados:** nombre, email, departamento, rol, jornada, coste y tarifa. Con ella se hacen las altas y salen las invitaciones.
3. **Revisión del texto RGPD** por el asesor, en `/admin/privacidad`.
4. **Destino de la copia externa:** S3 compatible o SFTP. Pasos en `docs/DEPLOY.md` §5.
5. **Directiva de nginx `/app/` en Plesk:** tiempo real al instante.
6. **Recomendaciones del servidor:**
   - tipo de CPU «host» en la máquina virtual (AVX2: transcripción mucho más rápida),
   - revisar el swap, casi lleno,
   - dos webs ajenas suspendidas en Plesk (beevo.endesarrollo.pro y staging.vitatrendy.com).

## Bloqueos: necesitamos del usuario
- [x] Aprobar `/styleguide` (26/09).
- [x] CI revisada por el propietario: todo en verde (26/09). `gh auth login` hecho el 26/09: la CI se revisa ya sin depender de él.
- [x] D-013 aprobada: se mantiene Vite+ (26/09).
- [ ] **Al final del proyecto (D-030):** datos SMTP (hasta entonces los emails van al log), lista inicial de empleados y revisión del texto RGPD.
- [x] Copias: el servidor entero se copia a diario; además, volcado nocturno local de PostgreSQL (D-029).

## Problemas abiertos
- Ninguno.
