# Plan Fase 4: Gantt, hitos, dependencias, plantillas y tareas recurrentes

## Contexto
Las Fases 1 a 3 dejan el núcleo (tareas con fechas, estimación e hitos `is_milestone`), los informes y la carga futura, que reparte la estimación de cada tarea entre su inicio y su entrega. La Fase 4 (SPEC §17) añade la **planificación visual**:
- el Gantt por proyecto y multiproyecto (SPEC §6.1),
- los hitos con sus próximos vencimientos (SPEC §5.1 y §6),
- las dependencias fin-inicio con detección de ciclos y aviso de conflictos,
- las plantillas de proyecto y las tareas recurrentes (SPEC §4.3 y §14),
- la vista **Calendario** de tareas, que la D-037 pasó de la F1 a la F4.

**Aceptación (SPEC §17):**
- crear, mover y enlazar desde el Gantt se refleja en la lista y en la carga,
- no se pueden crear ciclos.

Se trabaja en modo autónomo (D-027): el plan se ejecuta sin esperar aprobación.

## Decisiones de la fase

### D-056 · Dependencias **[concreta el SPEC §4.3 y §6.1]**
- **Tipo:** solo fin-inicio (`finish_to_start`), y solo entre tareas **del mismo proyecto**. Los hitos participan como cualquier tarea.
- **Reglas:**
  - una tarea no puede depender de sí misma,
  - no se pueden crear ciclos, ni directos ni indirectos (búsqueda en anchura sobre las dependencias del proyecto),
  - enlazar dos veces lo mismo no duplica.
- **Quién:** quien puede editar **las dos** tareas (`TaskPolicy::update`).
- **Tareas borradas:** una tarea en la papelera no cuenta en las dependencias. Al borrarla del todo, sus dependencias se borran en cascada.
- **Contrato:** `App\Domain\Schedule\DependencyService` y las rutas `schedule.dependencies.*`.

### D-057 · Conflictos al mover **[concreta el SPEC §6.1]**
- **Cuándo hay conflicto:** una sucesora está en conflicto si empieza (o, si no tiene inicio, vence) el mismo día o antes de que acabe su predecesora.
- **Propuesta:** llevar cada sucesora en conflicto al día siguiente del fin de su predecesora.
  - Conserva su duración en **días naturales**, que es sencillo y predecible.
  - Sigue **en cascada** por las sucesoras de las sucesoras.
  - Mover una tarea **antes** no propone nada: las sucesoras nunca se adelantan solas.
- **Nunca se aplica sola:** `POST /tareas/{task}/reprogramar/propuesta` devuelve la propuesta sin cambiar nada. `POST /tareas/{task}/reprogramar` guarda las fechas nuevas y solo desplaza las sucesoras si se confirma (`shift_successors`).
  - La propuesta se **recalcula en el servidor** al confirmar: nunca se aceptan fechas del cliente para las sucesoras.
  - Cada sucesora exige `TaskPolicy::update`. O se aplica todo o nada.
- **Las dependencias son una ayuda:** se puede guardar una fecha en conflicto sin desplazar nada. El Gantt marca el conflicto (enlace en rojo, con icono y texto).

### D-058 · Plantillas de proyecto **[concreta el SPEC §4.3, §6 y §14]**
- **Estructura** (JSON en `project_templates.structure`):
  - tareas con `ref`, `parent_ref` (un nivel), título, tipo, prioridad, estimación, hito, `start_offset_days` y `duration_days`,
  - dependencias `from_ref → to_ref`.
  - Todo se valida con `ProjectTemplateService::normalize`: referencias únicas, subtareas de un solo nivel, dependencias existentes, sin ciclos y un máximo de 500 tareas.
- **Aplicar:**
  - al **crear un proyecto** («desde plantilla», SPEC §6) lo puede hacer quien crea proyectos: admin y responsables (D-022),
  - también en un proyecto existente, desde **Ajustes**, quien lo gestiona: se añaden las tareas y no se toca nada de lo que ya hay.
  - Las fechas se calculan desde el inicio que se elija (por defecto, el del proyecto). Si el proyecto es de bolsas, todas las tareas van a la bolsa que se elija.
- **Guardar como plantilla:** desde Ajustes del proyecto, quien lo gestiona. Solo se guardan títulos, tipos, estimaciones, fechas relativas y dependencias; nunca personas, horas ni estados.
- **Gestión** en `/admin/plantillas`: el admin crea, edita la estructura, desactiva y borra (papelera). Los responsables las ven y las aplican.

### D-059 · Tareas recurrentes **[concreta el SPEC §4.3 y §14]**
- **Regla:**
  - **semanal:** cada N semanas, en un día (lunes = 1),
  - **mensual:** un día del mes; si el mes no lo tiene, el último día (31 → 28/29 en febrero, 30 en abril),
  - desde `starts_on` y, opcionalmente, hasta `ends_on`,
  - con la plantilla de la tarea: título, descripción, tipo, responsable, bolsa, estimación, prioridad y vencimiento `due_offset_days` días después de la fecha de cada instancia.
- **Generación:**
  - el comando `tasks:generate-recurring`, diario a las 06:00 de Madrid (`withoutOverlapping`),
  - cada instancia se crea con `TaskWriter`; no se duplica gracias a la clave única (regla, fecha),
  - recupera como máximo 31 días atrasados,
  - se salta los proyectos archivados, las bolsas cerradas o renovadas y a los responsables desactivados (la tarea queda sin responsable).
  - Al crear o reactivar una regla, se genera ya la instancia de hoy si toca.
- **Quién:** las reglas de un proyecto las gestiona quien gestiona el proyecto, desde su pestaña **Ajustes**. El admin tiene la vista global en `/admin/tareas-recurrentes`.

### D-060 · Gantt: componente propio **[concreta el SPEC §2 y §6.1]**
- **Por qué propio:** se evaluaron SVAR React Gantt (MIT, pero parte de sus funciones son de pago) y frappe-gantt (MIT, sin React ni accesibilidad de teclado). Se hace un **componente propio** (React y SVG/HTML con pointer events, sin librerías nuevas) porque:
  - pedimos control total del teclado y de la accesibilidad AA,
  - usa los tokens del tema claro y oscuro,
  - está en español,
  - sin reprogramación automática.
  - Sirve también para el Gantt de solo lectura del portal (F5), con `readOnly`.
- **Funciones:**
  - **Marcas:** barras para las tareas y rombos para los hitos. Subtareas sangradas bajo su padre, con la barra del padre como resumen.
  - **Escalas:** día, semana y mes, con una marca de **hoy**.
  - **Arrastrar para mover y redimensionar** (con el ratón y con el teclado: flechas mueven un día; Mayús + flechas cambian la entrega). Al soltar, se pide la propuesta (D-057) y, si hay conflicto, un diálogo ofrece «Mover también las sucesoras», «Solo esta tarea» o «Cancelar».
  - **Crear dependencias** arrastrando desde el conector del final de una barra hasta otra. Alternativa con teclado: «Añadir dependencia» en el menú de la barra. Las flechas de las dependencias van en rojo, con icono, si hay conflicto.
  - **Colores:**
    - por **estado:** color de su categoría,
    - o por **responsable:** `--chart-1..6` en orden fijo de aparición; el resto en gris «Otros». Siempre con leyenda, y el nombre en la barra o el tooltip.
  - **Tareas sin fechas:** en una lista aparte, con «Asignar fechas».
  - **Crear una tarea** desde el Gantt con sus fechas (el alta rápida de la F1 con fechas).
  - **Tabla accesible alternativa:** título, responsable, inicio, entrega, dependencias.
- **Multiproyecto:** en `/gantt`, con filtros por cliente, departamento, responsable y estado del proyecto. Proyectos agrupados y plegables, con un máximo de 60 proyectos o 1.500 tareas por vista; si hay más, se avisa y se pide filtrar.
- **Quién:** lo ve cualquier interno (D-021). Mueve y enlaza quien puede editar las tareas; al resto, en solo lectura.

### D-061 · Calendario de tareas **[concreta el SPEC §6 y D-037]**
- **Dónde:** tercera vista de la pestaña **Tareas**, junto a Lista y Kanban.
- **Qué muestra:**
  - un mes, con la semana empezando en lunes,
  - cada tarea el día de su **entrega**; las tareas con inicio también como franja del inicio a la entrega,
  - los hitos con su rombo.
- **Cambiar fechas:** arrastrando o con el teclado, pasando por reprogramar (D-057). Pulsar una tarea abre su panel.
- **Tareas sin fecha:** en una lista lateral.

### D-062 · Hitos **[concreta el SPEC §5.1 y §6]**
- **En el panel de la tarea:**
  - una casilla «Hito»: sin horas y con la entrega como única fecha, como hasta ahora,
  - la sección **Dependencias**, con las predecesoras y sucesoras, para añadir y quitar (D-056).
- **Resumen del proyecto:** «Próximos hitos», los 5 siguientes sin completar, más los vencidos destacados con icono y texto.
- **Inicio, «Mis próximos hitos»:** los de los proyectos donde soy miembro, vencidos y de los próximos 30 días, máximo 8, con enlace a su tarea.
- **Chat del proyecto:** el mensaje de sistema «hito completado» llega con el chat (F6).

## Contrato técnico (hecho, con tests)
- **Esquema:**
  - `task_dependencies` (única predecesora-sucesora),
  - `project_templates` (estructura JSON y papelera),
  - `recurring_task_rules`,
  - en `tasks`, las columnas `recurring_task_rule_id` y `occurrence_date` (única).
- **Modelos:**
  - `TaskDependency`,
  - `ProjectTemplate`,
  - `RecurringTaskRule`, con `occurrencesBetween()`,
  - `Task`, con `predecessorLinks()` y `successorLinks()`.
- **Dominio:**
  - `App\Domain\Schedule\{DependencyService, ScheduleConflicts, ScheduleShifter}`,
  - `App\Domain\Templates\ProjectTemplateService` (`apply`, `capture` y `normalize`),
  - `App\Domain\Recurring\RecurringTaskGenerator`,
  - el comando `tasks:generate-recurring`, programado.
- **Rutas comunes** (`routes/app/schedule.php`):
  - `POST /proyectos/{project}/dependencias` (`schedule.dependencies.store`),
  - `DELETE /dependencias/{dependency}` (`schedule.dependencies.destroy`),
  - `POST /tareas/{task}/reprogramar/propuesta` (JSON, `schedule.reschedule.preview`),
  - `POST /tareas/{task}/reprogramar` (`schedule.reschedule.store`).
- **Tipos TS:** `resources/js/types/schedule.ts` (`TaskDependencyItem`, `ShiftProposal`, `ReschedulePreview` y `RescheduleRequest`).
- **Textos PHP:** `lang/es/schedule.php`.
- **Ficheros de rutas vacíos para cada agente:** `routes/app/{gantt,planning,templates}.php`, ya cargados desde `routes/web.php`.

## Reparto (en paralelo, cada uno en su worktree)
| Área | Contenido | Ficheros propios |
|---|---|---|
| **G1 · Gantt** | Componente Gantt (D-060), pestaña Gantt del proyecto (`/proyectos/{p}/gantt`, sustituye el `PhaseBadge` 4 de `project-shell`), Gantt multiproyecto `/gantt` (y su entrada en la navegación bajo Proyectos), crear tareas desde el Gantt y el diálogo de conflictos | `app/Http/Controllers/Gantt/*`, `app/Domain/Gantt/*`, `routes/app/gantt.php`, `resources/js/components/gantt/*`, `resources/js/pages/gantt/*`, `resources/js/pages/projects/gantt.tsx`, `lang/ui/gantt.json`, `lang/es/gantt.php`, `tests/Feature/Gantt/*`, `tests/js/gantt-*.test.tsx`, `tests/e2e/gantt.spec.ts` |
| **G2 · Calendario e hitos** | Vista Calendario de Tareas (D-061), hitos y dependencias en el panel de tarea, «Próximos hitos» en el resumen del proyecto y «Mis próximos hitos» en Inicio (D-062) | `app/Http/Controllers/Planning/*`, `app/Domain/Planning/*`, `routes/app/planning.php`, `resources/js/components/planning/*`, `resources/js/pages/projects/calendar.tsx` (o vista en `tasks`), `lang/ui/planning.json`, `lang/es/planning.php`, `tests/Feature/Planning/*`, `tests/js/planning-*.test.tsx`; cambios mínimos en el panel de tarea, el resumen del proyecto (`ProjectSummary` y `projects/show.tsx`), `HomeController` y `home.tsx` (solo la tarjeta de hitos) |
| **G3 · Plantillas y recurrentes** | `/admin/plantillas` (lista, editor de estructura, papelera), crear proyecto desde plantilla, aplicar y guardar como plantilla en Ajustes, reglas recurrentes en Ajustes del proyecto y `/admin/tareas-recurrentes`, generación inmediata al crear (D-058, D-059) | `app/Http/Controllers/Templates/*`, `app/Http/Controllers/Recurring/*`, `app/Policies/{ProjectTemplate,RecurringTaskRule}Policy.php`, `routes/app/templates.php`, `resources/js/pages/admin/templates/*`, `resources/js/pages/admin/recurring/*`, `resources/js/components/templates/*`, `resources/js/components/recurring/*`, `lang/ui/templates.json`, `lang/es/templates.php`, `tests/Feature/Templates/*`, `tests/Feature/Recurring/*`, `tests/js/templates-*.test.tsx`; cambios mínimos en el alta de proyecto, la pestaña Ajustes y `admin/index.tsx` |
| **Yo** | Contrato, integración con las Fases 2 y 3 (la carga refleja los cambios del Gantt), E2E de aceptación, revisión global, despliegue | |

## Tests (definición de hecho, §19)
- **Pest:**
  - dependencias (ciclos directos e indirectos, otro proyecto, idempotencia, permisos),
  - propuesta y reprogramación (cascada, duración, hitos, sin fechas, recalculada en el servidor, todo o nada),
  - plantillas (aplicar, capturar, ida y vuelta, validación y permisos),
  - recurrentes (semanal cada N, mensual 31 → fin de mes, `ends_on`, idempotencia, recuperación, archivados, generación inmediata),
  - Gantt y calendario (props, permisos, rendimiento con el DemoDataSeeder y presupuesto de consultas).
- **Vitest:**
  - geometría del Gantt (fechas ↔ píxeles en las tres escalas),
  - teclado (mover y redimensionar),
  - diálogo de conflictos,
  - calendario (semana desde el lunes, meses de 28 a 31 días),
  - editor de plantillas.
- **Playwright:**
  - mover una tarea en el Gantt con una sucesora: aparece el aviso, se confirma y se desplaza; la lista y la carga lo reflejan,
  - enlazar con ciclo: se rechaza.
