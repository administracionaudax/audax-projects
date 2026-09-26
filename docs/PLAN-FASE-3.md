# Plan Fase 3: capacidad, ausencias y carga futura

## Contexto
El SPEC llama a esto el **dolor principal**: saber **quién está sobrecargado la semana que viene** y poder repartir el trabajo (SPEC §9). La Fase 3 añade:
- festivos y ausencias con aprobación, que ya restan de la capacidad,
- el reparto de la carga planificada de las tareas,
- la vista «Carga», con su semáforo y la reasignación desde el panel,
- las bandejas «Sin planificar» y «Sin asignar»,
- en Inicio, «Mi carga» y «Mis ausencias».

**Aceptación (SPEC §17):** tests del reparto de carga para casos con festivos, ausencias parciales, tareas vencidas, tareas sin fecha de inicio y jornada parcial. Ya están en el contrato: `tests/Feature/Workload/WorkloadPlannerTest.php`.

Se trabaja en modo autónomo (D-027).

## Decisiones de la fase (D-049 y siguientes)

### D-049 · Ausencias
- **Solicitar:** cada persona solicita las suyas (tipo, fechas, día completo o parte del día con `partial_minutes`, y notas).
- **Aprobar o rechazar:** un responsable de su departamento o un admin (como las horas, D-020). El rechazo lleva un comentario.
  - Las de responsables y admins **se aprueban solas**.
  - Un admin o un responsable puede registrar una ausencia **ya aprobada** para alguien de su ámbito (una baja, por ejemplo).
- **Cancelar:**
  - la persona cancela las suyas solicitadas, o las aprobadas que aún no han empezado,
  - quien aprueba puede anular una aprobada; queda en la auditoría.
- **Validaciones:** sin solaparse con otra ausencia solicitada o aprobada de la misma persona. `partial_minutes` solo en ausencias de un día. Máximo un año por ausencia.
- **Notificaciones** (SPEC §13), en la app y por email por la cola `mail`:
  - «solicitada», a quien puede aprobar,
  - «aprobada» y «rechazada», a la persona.
- **Al imputar un día con ausencia aprobada** sale un aviso sin bloqueo (SPEC §7): nuevo aviso en `TimeEntryRules`.

### D-050 · Festivos
- **Administración:** en `/admin/festivos` (`manage-settings`) se crean, editan y borran por año.
- **Importación:**
  - **festivos nacionales de España del año**, calculados en local sin servicios externos (SPEC §15: nada a terceros): 1 y 6 de enero, Viernes Santo, 1 de mayo, 15 de agosto, 12 de octubre, 1 de noviembre y 6, 8 y 25 de diciembre;
  - los autonómicos y locales se añaden a mano o importando un fichero `.ics` o un CSV (`AAAA-MM-DD;Nombre`).
- **Alcance:** afectan a todas las personas; `scope` queda en `company`.

### D-051 · Reparto de la carga (lo que hace `WorkloadPlanner`, ya probado)
- **Qué se reparte:** el restante (estimación − imputado), a partes iguales entre los días con capacidad > 0 desde max(hoy, inicio) hasta la entrega; los minutos que sobran van a los primeros días.
- **Casos especiales:**
  - una tarea vencida lleva todo su restante a hoy y se marca,
  - sin ningún día con capacidad en el rango, todo va al primer día,
  - como mucho se calcula un año hacia delante.
- **Qué no cuenta:** los hitos, las tareas completadas y los proyectos archivados. Con subtareas, cuentan las subtareas y no el padre.
- **Bandejas:**
  - «Sin planificar»: tareas con responsable pero sin estimación o sin entrega,
  - «Sin asignar»: tareas por departamento, el de la bolsa o, si no, el del tipo.

### D-052 · Vista «Carga» y quién la ve (D-021)
- **Quién ve qué:**
  - admin: todo,
  - responsable: su departamento (y él mismo), y puede reasignar carga,
  - el resto: solo su propia fila.
  - Los gestores ven y reasignan las tareas de sus proyectos desde el panel, pero no ven la carga de personas de otros departamentos.
- **Matriz personas × días (o semanas en el horizonte de 3 meses):**
  - horizontes: semana actual, **semana que viene (por defecto)**, próximas 4 semanas y próximos 3 meses,
  - agrupada por departamento, con totales,
  - filtros: departamento, persona, cliente y proyecto.
- **Semáforo de cada celda** (horas planificadas / capacidad), el de `components/charts/thresholds.ts`, siempre con icono y texto:
  - gris: sin capacidad, con su motivo (festivo o ausencia),
  - azul: menos del 70 %,
  - verde: del 70 % al 100 %,
  - ámbar: del 100 % al 120 %,
  - rojo: más del 120 %.
- **Panel de una celda:** las tareas que forman esa carga, con los minutos de ese día, y se reasignan ahí mismo (responsable y fechas) con las reglas de Tareas (`TaskPolicy::update`, `TaskWriter`). La matriz se recalcula al momento.
- **Bandejas «Sin planificar» y «Sin asignar»:** en la misma página, con acciones rápidas para poner la estimación, las fechas o el responsable.

## Contrato técnico ✅ hecho (rama `fase-3`)
- **Tablas y modelos:** `holidays` y `absences` (`Holiday` y `Absence`, con los *scopes* `approved` y `overlapping`). Enums `AbsenceType` y `AbsenceStatus`. Factorías.
- **`App\Domain\Time\Capacity`:** resta festivos y ausencias aprobadas. `details()` explica cada día (base, minutos, festivo y ausencia).
- **`App\Domain\Workload\WorkloadPlanner` y `WorkloadPlan`:**
  - `load`, `contributions`, `capacity`, `unplanned`, `unassigned` y `overdue`,
  - `loadBetween` y `capacityBetween`,
  - `openTasks()` reutilizable.
- **Tests:**
  - `tests/Feature/Workload/WorkloadPlannerTest.php`: la aceptación de la fase,
  - `tests/Feature/Time/CapacityTest.php`: ajustado a un número fijo de consultas.

## Reparto
| Agente | Ficheros propios |
|---|---|
| W1 · Festivos y ausencias | `app/Http/Controllers/{Admin/HolidayController,Absences/*}`; `app/Domain/Absences/*` (servicios de solicitud y aprobación, festivos nacionales e importación); `app/Notifications/Absences/*`; `app/Policies/AbsencePolicy.php`; `resources/js/pages/{admin/holidays,absences}/**`; `components/absences/**`; `lang/ui/absences.json`; `lang/es/absences.php`; `routes/app/absences.php`; tests de su área. **Cambios permitidos** (se anotan): el aviso de ausencia en `TimeEntryRules`, la tarjeta de `/admin` y la tarjeta «Mis ausencias» de Inicio |
| W2 · Vista Carga | `app/Http/Controllers/Workload/*`; `app/Domain/Workload/*` nuevos (sin cambiar `WorkloadPlanner`); `resources/js/pages/workload/**`; `components/workload/**`; `lang/ui/workload.json`; `lang/es/workload.php`; `routes/app/workload.php` (sustituye el `/carga` provisional de `routes/web.php`); tests de su área. **Cambios permitidos** (se anotan): la tarjeta «Mi carga» de Inicio y la «Carga futura» del informe de departamento, si ya existe |
| Yo | Contrato, integración, datos de ejemplo (festivos y ausencias en el `DemoDataSeeder`), E2E, revisión global y despliegue |

## Tests
- **Reparto:** ya está (aceptación).
- **Ausencias:**
  - flujo completo: solicitar, aprobar o rechazar con comentario, autoaprobación y cancelar,
  - solapes,
  - notificaciones,
  - permisos por rol,
  - aviso al imputar,
  - capacidad de los informes con ausencias.
- **Festivos:** crear, borrar, importar los nacionales (Viernes Santo de 2026 = 3 de abril y de 2027 = 26 de marzo) y el `.ics` o CSV.
- **Carga:**
  - matriz por rol (D-052),
  - horizontes y por defecto la semana que viene,
  - totales por departamento,
  - panel y reasignación (la carga se recalcula),
  - bandejas,
  - número de consultas con el `DemoDataSeeder`.
- **E2E:** pedir una ausencia y que la apruebe el responsable (la capacidad baja en Carga); reasignar una tarea desde la celda de una persona sobrecargada.
