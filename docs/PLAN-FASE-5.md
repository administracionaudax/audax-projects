# Plan Fase 5: portal de cliente

## Contexto
La Fase 0 dejó la estructura del portal: la ruta `/portal`, su layout y el middleware `portal`. Un cliente nunca entra en la app interna; desde el 27/09 esto se comprueba en **todas** las rutas (`tests/Feature/Portal/ClientIsolationTest`). La Fase 5 (SPEC §11 y §17) lo llena de contenido:
- usuarios del portal por invitación,
- sus bolsas con el consumo, las entradas, el histórico y el PDF,
- el acceso opcional a la vista del proyecto y al Gantt de solo lectura,
- los avisos por email al cliente,
- la identidad de la empresa (SPEC §14).

**Aceptación (SPEC §17):** tests de aislamiento. Un cliente nunca puede acceder a datos de otro cliente ni a la app interna, verificado con tests de todas las rutas.

Se trabaja en modo autónomo (D-027). Depende de la Fase 2 (PDF de consumo de bolsa) y de la Fase 4 (componente Gantt con `readOnly`): los agentes empiezan cuando esas fases están integradas.

## Decisiones de la fase

### D-063 · Usuarios del portal **[concreta el SPEC §11]**
- **Quiénes son:** usuarios con rol `client` y `client_id`.
- **Alta:** por **invitación** (broker `invitations`, enlace de 7 días, D-017) desde la ficha del cliente. Pueden invitar:
  - el admin,
  - los responsables (D-022),
  - los gestores de algún proyecto de ese cliente.
- **Gestión desde la ficha:** reenviar la invitación, **revocar** el acceso (desactivar y cerrar sus sesiones al momento) y reactivarlo. Nunca se borra un usuario.
- **Cuenta:** recuperación de contraseña y **2FA opcional** (el ajuste `require_2fa` es solo para la plantilla). Sus ajustes de cuenta (perfil, contraseña, 2FA, sesiones y apariencia) se ven con el layout del portal.
- **Si el cliente se desactiva**, sus usuarios pierden el acceso al portal (`PortalScope::for` → 403).

### D-064 · Qué ve el cliente **[concreta el SPEC §11]**
Todo sale de `App\Domain\Portal\PortalScope`:
- **Bolsas:** las de los proyectos de **su** cliente, en cualquier estado, porque las cerradas y renovadas forman el histórico.
- **Horas visibles:** por defecto, las **aprobadas y bloqueadas**. Por cliente se pueden ampliar a las **enviadas**; los borradores, nunca.
  - Las **cifras de la bolsa** del portal (consumido, dentro, exceso, restante y %) salen **solo de esas horas** (`PortalBankFigures`), para que la barra, el listado y el PDF cuadren.
  - Por dentro la bolsa puede ir más avanzada (borradores y semanas sin aprobar); en el portal se explica con una nota.
- **Personas:** con el nombre (por defecto), las iniciales o «Equipo», según el cliente.
- **Proyecto:** la vista de tareas y estados solo si el admin lo abre al portal.
  - Sin comentarios, adjuntos internos ni personas por tarea.
  - Las horas totales por tarea solo si también se activan; nunca las horas por persona.
- **Gantt:** de solo lectura, con hitos, solo si se abre aparte (`portal_gantt_visible`).
- **Nunca:** costes, tarifas, importes, precio de las bolsas, otros clientes, comentarios internos ni el chat.

### D-065 · Avisos al cliente **[concreta el SPEC §11]**
- **Qué:** un email (cola `mail`) a los usuarios del portal **activos** cuando una bolsa llega al **90 %** y al **100 %** de lo que ven.
- **Cuándo:** solo si el cliente lo tiene activado; por defecto, no. Cada umbral avisa **una sola vez** por bolsa (`hour_bank_alerts`, clave `client:90` o `client:100`).
- **Cómo se comprueba:** al cambiar una entrada de la bolsa (alta, edición, aprobación, borrado), una vez por bolsa y transacción (`PortalBankAlerts`, `PortalServiceProvider`).
- **Contenido:** sin importes, con enlace al detalle de la bolsa en el portal.

### D-066 · PDF para el cliente **[concreta el SPEC §11 y D-045]**
- **Qué PDF:** el mismo PDF de consumo de bolsa de la Fase 2, en modo portal.
  - Solo las horas que ve el cliente (según su ajuste) y las personas como las ve él.
  - Sin datos económicos nunca.
- **Desde el portal:** `/portal/bolsas/{bolsa}/pdf`.

### D-067 · Identidad de la empresa **[concreta el SPEC §14]**
- **Dónde:** `/admin/identidad` (admin): nombre de la empresa y **logo** (PNG, JPG o WebP, hasta 1 MB; nunca SVG). Se usan en la cabecera del portal, en los emails y en los PDF.
- **Colores:** el tema no se cambia desde la app. Sus tokens están verificados para AA (D-011 y D-012) y el degradado de marca se mantiene.

## Contrato técnico (hecho, con tests)
- **Migración:**
  - en `clients`: `portal_person_display`, `portal_entry_visibility` y `portal_notify_thresholds`,
  - en `projects`: `portal_project_visible`, `portal_show_task_hours` y `portal_gantt_visible`.
- **Enums:** `PortalPersonDisplay` y `PortalEntryVisibility` (con `statuses()`).
- **Dominio `App\Domain\Portal`:**
  - `PortalScope`: `for(User)`, `forClient(Client)` (los avisos, sin usuario), `projects`, `hourBanks`, `entries`, `bankEntries`, `visibleStatuses`, `ownsProject`, `ownsBank`, `canViewProject`, `canViewGantt`, `canViewTaskHours` y `personLabel`/`label`,
  - `PortalBankFigures`: `many`, `one` y `byMonth`,
  - `PortalBankAlerts`: `queue`, `flush` y `check`.
- **Notificación:** `App\Notifications\Portal\ClientHourBankThreshold`. **Proveedor:** `PortalServiceProvider`.
- **Fábrica:** `User::factory()->portalOf($client)`.
- **Rutas:**
  - `routes/portal/{banks,projects}.php` (cargadas dentro del grupo `/portal`),
  - `routes/app/portal-access.php` (lado interno).
- **Tipos TS** en `resources/js/types/portal.ts`; textos PHP en `lang/es/portal.php`.
- **Aislamiento:** `ClientIsolationTest` recorre todas las rutas internas. Cada área añade los tests de **otro cliente** en sus rutas del portal.

## Reparto (en paralelo, cuando las Fases 2 y 4 estén integradas)
| Área | Contenido | Ficheros propios |
|---|---|---|
| **P1 · Bolsas del portal** | Inicio del portal con las bolsas activas y el resumen; detalle de bolsa (cifras, barra dentro/exceso, consumo por mes, entradas con persona según el ajuste, histórico de renovaciones); PDF en modo portal (D-066); estados vacíos; móvil | `app/Http/Controllers/Portal/Banks/*`, `routes/portal/banks.php`, `resources/js/pages/portal/{home,banks/*}.tsx`, `resources/js/components/portal/banks/*`, `lang/ui/portal-banks.json`, `tests/Feature/Portal/Banks*Test.php`, `tests/js/portal-banks-*.test.tsx`, `tests/e2e/portal.spec.ts`; cambio mínimo en el generador de PDF de la Fase 2 (modo portal) |
| **P2 · Acceso, proyectos e identidad** | Usuarios del portal en la ficha de cliente (invitar, reenviar, revocar, reactivar); ajustes del portal del cliente y del proyecto; vista del proyecto y Gantt de solo lectura en el portal; ajustes de cuenta con el layout del portal; `/admin/identidad` (D-067); cabecera del portal con el logo | `app/Http/Controllers/Portal/Projects/*`, `app/Http/Controllers/PortalAccess/*`, `app/Http/Controllers/Admin/IdentityController.php`, `routes/portal/projects.php`, `routes/app/portal-access.php`, `resources/js/pages/portal/projects/*`, `resources/js/pages/admin/identity.tsx`, `resources/js/components/portal/{access,projects}/*`, `lang/ui/portal-access.json`, `tests/Feature/Portal/{Access,Projects,Identity}*Test.php`, `tests/js/portal-access-*.test.tsx`; cambios mínimos en la ficha de cliente, los ajustes del proyecto, `portal-layout.tsx`, `admin/index.tsx` y los ajustes de cuenta |
| **Yo** | Contrato, integración, E2E de aislamiento, revisión global y despliegue | |

## Tests (definición de hecho, §19)
- **Aislamiento:**
  - todas las rutas internas con un cliente (ya hecho),
  - todas las rutas del portal con los ids de **otro** cliente (404 o 403),
  - un usuario de un cliente desactivado o revocado ya no entra.
- **Visibilidad:**
  - estados de las horas según el ajuste,
  - personas (nombre, iniciales, «Equipo»),
  - sin importes en props, PDF ni emails,
  - proyecto y Gantt solo si están abiertos.
- **Cifras:** calculadas a mano (dentro, exceso y restante).
- **Avisos:** 90 % y 100 %, una sola vez, solo a usuarios activos y solo con el ajuste activado.
- **E2E:** un cliente entra, ve su bolsa, descarga el PDF y no puede abrir la app interna ni la bolsa de otro cliente.
