# Revisión de formularios, desplegables y selectores

_06/10/2026 · rama `revision-formularios` (desde `fase-10`) · decisiones D-310 a D-312_

Encargo del propietario: «revisa que todos los dropdowns, selectores y formularios en general funcionen, todos y cada uno de ellos», tras tres fallos en la Previsión (un desplegable en «Cargando…» para siempre, un selector con fondo y borde dobles dentro de su recuadro y un formulario con «escalones»).

## Cómo se ha revisado

1. **Revisión del código** de los 474 componentes y páginas de `resources/js` (salvo `ui/`, revisados como compartidos), en cinco áreas, buscando los tres patrones de partida y los de la lista del encargo (props diferidas canceladas, desplegables que se quedan vacíos, valores mal conectados, errores que no se ven, envíos dobles, formularios que no se reinician, alturas y bordes distintos, etiquetas que se parten, botones `outline` usados como campo, radios hechos a mano). Se ha leído el código de Inertia 3.7.1 para confirmar cuándo se cancela una carga diferida.
2. **Prueba real en el navegador** contra un servidor local (`php artisan serve` en el puerto 8060, SQLite con el `DemoDataSeeder` y todos los módulos encendidos) con un recorrido de Playwright que, por cada página y rol:
   - abre la página y cada diálogo, panel o selector que encuentra (botones «Nuevo…», «Editar…», «Añadir…», disparadores de diálogo y comboboxes),
   - comprueba que cada desplegable trae opciones (ninguno se quedó vacío ni en «Cargando…»),
   - envía vacíos los formularios sin datos para ver que el error sale junto al campo,
   - mide en el DOM: radios redondeados, bordes dobles, alturas distintas en una fila, cajas desalineadas en filas de varias columnas («escalones»), etiquetas partidas, campos con borde de botón, desbordes y textos cortados,
   - y hace capturas, que se han mirado una a una buscando lo que no mide el DOM.
3. **Cobertura:** admin, responsable, empleado y cliente del portal; claro a 1440 px (con diálogos), oscuro a 1440 px y móvil a 375 px (páginas). 189 páginas, 248 diálogos y paneles abiertos y 350 selectores y comboboxes desplegados con sus opciones, más 19 flujos de varios pasos (chat, panel de tarea, enviar y programar informes, Mi espacio, plan del día, sugerencias, asistente, avisos de la Weekly…).

**Resultado:** 75 hallazgos (A: proyectos y tareas, B: horas, bolsas e informes, C: admin, clientes y portal, D: Weekly, E: chat, plan del día, Previsión y compartidos) más 6 del recorrido en el navegador (R1 a R6): 74 arreglados, 2 aceptados con su motivo y 5 preguntas de producto (más una sobre las ayudas). Todos los fallos de comportamiento llevan su test (Vitest o E2E).

## Fallos encontrados y arreglos, por tipo

### 1. Cargas que se cancelan o se pierden (el fallo n.º 1 de la Previsión)
- **Props diferidas canceladas por otra visita (H-C1, y cualquier página con `Inertia::defer`).** Inertia cancela las cargas diferidas en curso si se hace una visita a otra ruta (p. ej. invitar al portal o unirse a la Weekly del cliente antes de que llegue todo) y no vuelve a pedirlas: la sección se quedaba en «Cargando…» y el diálogo «Editar» del cliente perdía el responsable. **Arreglo global:** `installDeferredPropsGuard` (`resources/js/lib/deferred-props.ts`, en `app.tsx`) vuelve a pedir, al terminar cualquier visita, las diferidas de la página que sigan sin llegar. Tests: `tests/js/deferred-props.test.ts` y E2E `form-review.spec.ts`.
- **Props diferidas que vuelven a `undefined` al guardar (H-A2, H-A6, H-E17).** Tras guardar, Inertia vuelve a pedirlas y, mientras, los diálogos que dependían de ellas se desmontaban (asignaciones de Planificación y del previsto: un error de validación cerraba el diálogo y lo reabría vacío) y las secciones «Plantilla» y «Tareas recurrentes» perdían lo escrito. **Arreglo:** `useLastDefined` (último valor recibido) y botón «Asignación» desactivado hasta que llegan las opciones.
- **Prop opcional borrada por una recarga en vivo (H-D1).** «Unirme a clientes» decía «No hay clientes» cuando `useWeeklyLive` recargaba la página. El diálogo guarda la lista recibida, distingue «cargando», «error con Reintentar» y «ninguno coincide con la búsqueda».
- **Destinos de «Mover a otro proyecto» de otra tarea (H-A1)** y **recargas que fallan sin aviso (H-A3)**.
- **Opciones de los filtros de informes con un fallo de red en caché (H-B10)**, **histórico de un cliente con respuestas cruzadas (H-D15)**.

### 2. Formularios que parten de datos viejos
- **`useResetOnOpen`** (`resources/js/hooks/use-reset-on-open.ts`): reinicia el formulario cada vez que el diálogo pasa a abierto, también si lo abre el padre. Con Inertia 3 la página no se vuelve a montar al guardar: «Editar» un previsto mostraba los datos de la primera carga y **al guardar deshacía cambios posteriores** («Hacer segura», la edición anterior) (H-E1). También en asignación, «Perdido», «Vincular», «Crear proyecto real» (H-E2), «Estoy fuera» (H-D7) y «Nuevo grupo» del chat (H-E14).
- «Invitar al portal» salía con la persona anterior (H-C2); las reglas nuevas de los avisos se borraban y creaban en cada «Guardar» (H-D6); si fallaba el envío de Mi weekly, lo escrito se daba por guardado (H-D5); el bloqueo de horas bloqueaba lo que había en el formulario y no lo previsualizado (H-B1) y un error de la vista previa vaciaba el formulario (H-B2).

### 3. Errores del servidor que no se veían
- Acciones sin `onError`: plan del día (H-E3), cerrar e iniciar semana y renunciar a la exención (H-D9), borrar tableros o secciones con contenido (H-D11), acciones de la Previsión (H-E4). Ahora avisan con `toastVisitErrors`, y `toastUnshownErrors` avisa de los errores sin campo donde pintarse.
- Errores anidados sin pintar en «Editar informe» (H-D10), errores juntos al pie en «Editar línea» (H-E12), error duplicado al añadir un miembro (H-A7).
- **(R1)** Mensajes genéricos con el nombre del campo en inglés («El campo subtitle es obligatorio»): nuevos nombres en `lang/es/validation.php` y test en `HelpCenterTest`.

### 4. Envíos dobles y confirmaciones que no se cierran
- **`ConfirmDialog` no controlada se cierra al terminar la acción.** Tras borrar una semana destacada del Histórico la confirmación **seguía abierta apuntando a otra semana** y un segundo clic la borraba (H-D2). También «Enviar sin apuntes» y «Regenerar» (H-D3, H-D4), que además no desactivaban su botón.
- Ctrl/⌘+Intro publicaba dos veces el comentario (H-A4, H-D16); el roadmap perdía un movimiento (H-D16); Google Sheets se podía lanzar dos veces (H-B13).

### 5. Valores que no están entre las opciones
- Responsable del cliente desactivado (H-C3): pasa a «Automático». Cliente inactivo del previsto y persona que ya no se puede asignar (H-E16): aparecen en su selector. Filtro «Persona» de clientes mientras llega la lista (H-C5). Select de Facturación no controlado (H-B11). Filtros que volvían al valor anterior mientras cargaban (H-C8, `useOptimisticValue`). Rango de fechas que saltaba a «Mes» (H-B3). Papelera de plantillas (H-C7).

### 6. Aspecto: campos, alturas, bordes y escalones (los fallos n.º 2 y 3)
- **Campos con borde azul de botón** (H-B6, H-B7, H-E6, H-E13, H-E21): nueva variante `field` de `Button` para el selector de fecha y todos los comboboxes (tareas, personas, destinos, informes, plantillas, emoji del canal). Test estático: ningún combobox `outline`.
- **`NativeSelect`** (H-E18, H-E15): sin fondo propio (sobre tarjetas blancas se veía gris), opciones con el color del desplegable, `color-scheme: dark` en el tema oscuro y prop `bare` para meterlo en un recuadro con etiqueta.
- **Escalones por celdas que se estiran** (H-B8 y el recorrido): celdas `grid` de filas de varias columnas sin `content-start`; si la vecina tenía ayuda o error, la caja bajaba (en el alta de proyecto, tres cajas de «Planificación» 10 px por debajo del presupuesto). Arreglado en 12 formularios y vigilado por un test estático.
- **Ayudas largas en una columna** (H-D8, H-B14, H-C6): a todo el ancho bajo la fila (nueva tarea de Mi espacio, franja horaria, ausencia parcial, tipo de tarea, usuario).
- **Alturas mezcladas** (H-A5, H-A8, H-A11, H-E9, H-D12, H-D13): filtros múltiples con `size="sm"`, barra masiva, calendarios, plan del día, conmutadores de la Weekly, asistente.
- **Etiquetas partidas** (H-E7): «Hasta (opcional)». **Panel de tarea** (H-A10). **Importar festivos** (H-B9). **«Crear tarea» del chat** (H-E5). **«Días que se pueden cerrar»** (H-C4).
- **(R2)** Textos cortados en filtros: «Todas las prioridades» y «Todos los departamentos» pasan a «Todas»/«Todos», como en Mis tareas.
- **(R3)** «Personas de Audax» salía dos veces en enviar y programar (`MultiSelectFilter labelInside={false}`).
- **(R4)** «Cancelar» de los diálogos de la Previsión, del canal del chat y de reprogramar pasa a secundario, como en los otros 52 diálogos (D-311).
- **(R5)** `DurationInput` con `preview="overlay"` en lugar de selectores arbitrarios; la vista previa ya no se pisa con el error (H-E8, H-E20).
- **(R6)** La guía de estilo enseñaba un radio nativo y campos hechos a mano (H-E23).

## Componentes compartidos que cambian
| Componente | Cambio |
|---|---|
| `ui/button.tsx` | Variante `field`: borde gris de los campos, sin fondo propio y texto normal. |
| `admin/native-select.tsx` | Fondo transparente, opciones con el color del desplegable y prop `bare`. |
| `confirm-dialog.tsx` | Sin `open`, se cierra sola al terminar la acción (`processing` de true a false). |
| `domain/duration-input.tsx` | `preview="reserve" \| "overlay"` y `onTextChange`. |
| `domain/date-picker.tsx` | Variante `field` y `aria-describedby`. |
| `reports/multi-select-filter.tsx` | `size`, `labelInside` y `className`. |
| `templates/deferred-section.tsx` | Usa lo último recibido mientras se vuelve a pedir. |
| `hooks/use-reset-on-open.ts`, `use-last-defined.ts`, `use-optimistic-value.ts` | Nuevos (D-310). |
| `lib/deferred-props.ts` | Red de seguridad de las props diferidas (D-310). |
| `admin/visit-errors.ts` | `toastUnshownErrors`. |
| `resources/css/app.css` | `color-scheme: dark` en `.dark`. |

`rounded-md`, `rounded-sm` y `shadow-xs` de `Input`, `NativeSelect`, `Checkbox` y `RadioGroup` **sí** quedan planos: el tema pone `--radius-*` a 0 y `--shadow-*` a `none` (comprobado en el CSS compilado y en el recorrido: ningún campo con radio). No hay `rounded-[…]`, `shadow-[…]` ni `rounded` suelto en `resources/js`.

## Aceptado, sin cambiar
- **Ayuda corta o vista previa de la duración en una sola columna** (H-A9, parte de H-B14 y H-C6: icono del cliente, «Hora de Madrid.», bolsa del panel de tarea, «Vence a los (días)»): las cajas de la fila están alineadas; solo queda una línea de hueco bajo la otra columna. Sacar estas ayudas a todo el ancho las separaba de su campo (con un error en «Nombre», la ayuda del icono parecía del nombre).
- **`SelectTrigger` con `w-fit` por defecto** (H-E19): las barras de filtros cuentan con él; se pone `w-full` donde faltaba.
- **Tablas de tareas más anchas que la pantalla** (`min-w-[62rem]`): se desplazan dentro de su recuadro, no es un fallo de formulario.
- El campo de hora (`<input type="time">`) y el de fichero usan el control del navegador (formato y textos del sistema).

## Preguntas de producto (no se han cambiado)
1. **H-B12 · «Devolver semana»** conserva el comentario si se cancela y se vuelve a abrir (también para otra persona). ¿Es un borrador intencionado o debe empezar vacío?
2. **H-C9 · «Invitar persona»** (admin) conserva lo escrito al cancelar, al contrario que el resto de diálogos de administración. ¿Se deja como borrador?
3. **H-C10 · Clientes:** los filtros de la Weekly (tipo de proyecto, persona, «Mis proyectos») salen aunque la Weekly esté apagada. ¿Se ocultan con el módulo apagado?
4. **H-C11 · Auditoría:** «Desde» y «Hasta» usan la fecha nativa del navegador (y lanzan una visita por cada dígito del año) en vez del selector de fecha de la app. ¿Se cambia?
5. **H-E11 · Inicio:** marcar como hecha una línea de «Mi día» con tarea no pregunta si marcar también la tarea, como sí hace `/dia`. ¿Debe preguntar?
6. **Ayudas en una columna** (ver «Aceptado»): ¿se prefiere siempre la ayuda a todo el ancho aunque quede lejos de su campo?

## Inventario
Una fila por formulario, diálogo, barra de filtros o desplegable. «Estado»: OK si no había nada que arreglar; si no, el hallazgo y lo que se ha hecho.

### A · Proyectos, tareas, plantillas, Gantt y calendario

| Dónde (página / ruta) | Componente | Controles | Rol | Estado | Arreglo |
|---|---|---|---|---|---|
| Listado de proyectos `/proyectos` | `components/projects-list/project-filters.tsx` + `filter-select.tsx` | Input búsqueda (debounce), Switch «Solo mis proyectos», 5 Select Radix (cliente, estado, tipo, responsable, departamento), «Quitar filtros» | interno | OK | — |
| Alta de proyecto `/proyectos/nuevo` | `pages/projects/create.tsx` + `projects-list/project-fields.tsx` | Nombre, Select tipo, Select cliente, código, RadioGroup color, Textarea, Select estado, 2 DatePicker, DurationInput presupuesto, MoneyField ×2 | admin / responsable | OK | — |
| Alta de proyecto → «Estructura inicial» | `templates/template-start-fields.tsx` (+ `hour-banks/hour-bank-fields`) | RadioGroup desde cero / plantilla, NativeSelect plantilla, DatePicker «Día 1», bloque de primera bolsa | admin / responsable | OK | — |
| Alta de proyecto → Equipo | `projects-list/person-select.tsx`, `people-checklist.tsx` | Select gestor principal, buscador + Checkbox de miembros | admin / responsable | OK | — |
| Ajustes `/proyectos/{id}/ajustes` → Datos | `pages/projects/settings.tsx` (`ProjectDataForm`) | `ProjectFields` + primera bolsa (`HourBankFields`) al pasar a bolsas | gestor del proyecto / admin | Arreglado (H-A6) | `DeferredSection` usa lo último recibido mientras se vuelve a pedir. |
| Ajustes → Equipo | `projects-list/project-members.tsx` | Switch gestor por miembro, ConfirmDialog quitar, alta: Select persona + Checkbox «como gestor» + botón | gestor / admin | Arreglado (H-A7) | Error del alta junto al selector; los de cada miembro en su fila. |
| Ajustes → Gestor principal | `settings.tsx` (`OwnerForm`) | Select persona + botón | quien gestiona miembros | OK | — |
| Ajustes → Alertas | `projects-list/project-alerts.tsx` | Switch por alerta y gestor | gestor (las suyas) / admin | OK | — |
| Ajustes → Plantilla (prop diferida `templating`) | `templates/project-template-section.tsx`, `deferred-section.tsx` | Aplicar: NativeSelect plantilla, DatePicker, NativeSelect bolsa, ConfirmDialog. Guardar como plantilla: Input nombre, Textarea | gestor / admin | Arreglado (H-A6) | `DeferredSection` usa lo último recibido mientras se vuelve a pedir. |
| Ajustes → Tareas recurrentes (prop diferida `recurring`) | `recurring/recurring-rules-section.tsx`, `recurring-rule-dialog.tsx` | Diálogo: Input, Textarea, NativeSelect responsable/tipo/bolsa/prioridad, DurationInput, RadioGroup frecuencia, IntegerInput ×2-3, NativeSelect día, DatePicker ×2, Switch; activar o desactivar; ConfirmDialog borrar | gestor / admin | Aceptado (H-A9) | Ayuda corta o vista previa en una columna: las cajas ya están alineadas (ver «Aceptado»). |
| Ajustes → Archivar | `settings.tsx` (`ArchiveButton`) | ConfirmDialog | quien puede archivar | OK | — |
| Tareas `/proyectos/{id}/tareas` → barra | `tasks/task-filters.tsx` | ToggleGroup vista, Switch «mías» y «completadas», 5 Select sm + Select agrupar, «Quitar filtros» | interno / colaborador | OK | — |
| Tareas → alta rápida | `tasks/quick-add-task.tsx` | Input + botón «más datos» + BankSelect | quien crea tareas | OK | — |
| Tareas → «Nueva tarea» / subtarea | `tasks/task-create-dialog.tsx` | Input, AssigneePicker (Command), TypeSelect, DatePicker ×2, DurationInput, PrioritySelect, StatusSelect, BankSelect, Switch «crear otra» | quien crea tareas | Aceptado (H-A9) | Ayuda corta o vista previa en una columna: las cajas ya están alineadas (ver «Aceptado»). |
| Tareas → acciones masivas | `tasks/task-bulk-bar.tsx` | StatusSelect, AssigneePicker, Popover fechas (DatePicker ×2), BankSelect + ConfirmDialog | quien edita | Arreglado (H-A8) | «Cambiar fechas» y «Quitar la selección» a h-9. |
| Tareas → kanban y lista | `tasks/task-kanban.tsx`, `task-list.tsx` | DropdownMenu «Mover a», Checkbox de selección, alta rápida por columna o grupo | interno / colaborador | OK | — |
| Tareas → calendario | `planning/task-calendar.tsx` | botones de navegación, ToggleGroup mes/semana, DatePicker «Asignar fecha» en «Sin fecha» | interno | Arreglado (H-A11) | Selector de vista a h-8 como la navegación. |
| Panel de tarea (`?tarea=`), en Tareas y `/calendario` | `tasks/task-panel.tsx`, `task-panel-fields.tsx` | Título en línea, Status/Priority/Assignee/Bank/Type, DatePicker ×2, DurationInput, Switch facturable/hito, ConfirmDialog bolsa, RescheduleDialog, menú «Más» | interno / colaborador | Arreglado (H-A10) | «Facturable» e «Hito» en su propia fila. |
| Panel → descripción, subtareas, dependencias, adjuntos | `task-description.tsx`, `task-subtasks.tsx`, `planning/task-dependencies.tsx`, `task-attachments.tsx` | editor de texto enriquecido, Checkbox completar, Popover+Command con búsqueda remota (`shouldFilter=false`), selector de ficheros | quien edita | OK | — |
| Panel → comentarios | `tasks/task-comments.tsx` | editor de texto enriquecido (Ctrl+Intro), adjuntos, Popover de reacciones, editar y borrar | quien comenta | Arreglado (H-A4) | Ctrl/⌘+Intro no publica dos veces (marca de envío en curso). |
| Panel → «Mover a otro proyecto» (`moveTargets` opcional) | `tasks/move-task-dialog.tsx` | Select proyecto, BankSelect | quien edita | Arreglado (H-A1, H-A3) | Calendario: al cambiar de tarea se olvidan los destinos de «Mover» y se vuelven a pedir. Aviso y «Reintentar» si no llegan los destinos o los proyectos del calendario. |
| Mis tareas `/mis-tareas` | `pages/my-tasks/index.tsx`, `my-tasks/my-task-filter-bar.tsx` | SearchField h-8, MultiSelectFilter ×4, Select prioridad/vencimiento sm, DatePicker ×2 h-8, Switch, Select orden, «Cargar más» | interno | Arreglado (H-A5) | `MultiSelectFilter size="sm"` en Mis tareas y calendario. |
| Calendario del equipo `/calendario` → filtros | `calendar/calendar-filter-bar.tsx` | SearchField, MultiSelectFilter ×4, Select departamento/prioridad sm, 4 Switch | interno / colaborador | Arreglado (H-A5) | `MultiSelectFilter size="sm"` en Mis tareas y calendario. |
| Calendario → barra | `calendar/team-calendar.tsx` | botones de navegación, ToggleGroup vista, Switch «Por personas», Popover «+N más» | interno | Arreglado (H-A11) | Selector de vista a h-8 como la navegación. |
| Calendario → crear tarea en un día (`creatable` opcional) | `pages/calendar/index.tsx` + `gantt/new-task-dialog.tsx` | Select proyecto, Input, Checkbox hito, DatePicker ×2, Select bolsa | quien crea | Arreglado (H-A3) | Aviso y «Reintentar» si no llegan los destinos o los proyectos del calendario. |
| Gantt `/gantt` → filtros y herramientas | `gantt/gantt-filters.tsx`, `gantt-toolbar.tsx` | 4 Select (FilterSelect), 3 ToggleGroup, «Hoy» | interno | OK | — |
| Gantt (`/gantt` y `/proyectos/{id}/gantt`) → diálogos | `gantt/new-task-dialog.tsx`, `dates-dialog.tsx`, `dependency-dialog.tsx`, `conflict-dialog.tsx`, `gantt-task-menu.tsx`, `unscheduled-list.tsx` | véase arriba; DatePicker ×2; RadioGroup + Command (value = id, único); 3 botones; DropdownMenu | quien edita | OK | — |
| Planificación `/proyectos/{id}/planificacion` | `pages/projects/planning.tsx` (+ `forecast/allocation-dialog`, `assign-gap-dialog`) | «Añadir asignación», editar, «Asignar» hueco | gestor | Arreglado (H-A2) | `useLastDefined`: el diálogo de asignación no se desmonta tras un error; «Asignación» espera a las opciones. |
| Horas `/proyectos/{id}/horas` | `pages/projects/time.tsx` | Select persona/bolsa/estado/facturable, DatePicker ×2, «Quitar filtros», TimeEntryDialog (área Horas) | interno | OK | — |
| Archivos `/proyectos/{id}/archivos` | `pages/projects/files.tsx` | 2 Select sm, ConfirmDialog borrar | interno | OK | — |
| Bolsas `/proyectos/{id}/bolsas` y una bolsa | `pages/projects/hour-banks.tsx`, `hour-bank.tsx` | Switch «todas»; diálogos del área Bolsas | gestor | OK | — |
| Ficha `/proyectos/{id}` y cabecera | `pages/projects/show.tsx`, `projects/project-shell.tsx`, `projects-list/list-pagination.tsx` | solo enlaces y botones | interno | OK | — |
| Admin → editor de plantilla `/admin/plantillas/{id}/editar` | `templates/template-editor.tsx`, `template-row-pickers.tsx`, `integer-input.tsx` | por fila: Input, ParentPicker (Command, value `ref:` único), NativeSelect tipo/prioridad, DurationInput, Checkbox hito, IntegerInput ×2, DependencyPicker (casillas); errores `structure.tasks.N.campo` por celda | admin | OK | — |
| Admin → importar plantilla | `templates/import-template-dialog.tsx` | Input file | admin | OK | — |

### B · Horas, bolsas, informes, carga y ausencias

| Dónde (página / ruta) | Componente | Controles | Rol | Estado | Arreglo |
|---|---|---|---|---|---|
| Hoja semanal `/horas?semana=&persona=` (barra) | `pages/time/index.tsx` | Navegación de semana (Link), Select de persona, «Añadir entrada», TaskPicker «Añadir fila», «Copiar semana anterior», Enviar/Retirar/Reabrir (ConfirmDialog), Aprobar + Devolver | empleado; responsable/admin para persona y revisión | Arreglado (H-B7) | Variante `field` en `TaskPicker`. |
| Hoja semanal, rejilla editable | `components/time/timesheet-grid.tsx` | DurationInput por celda (cola de guardado), botón de celda con varias entradas, quitar fila | empleado / quien imputa por otros | OK | — |
| Diálogo «Imputar horas» / editar entrada (hoja, Inicio, tarea, cabecera, plan del día) | `components/time/time-entry-dialog.tsx` (+ `use-entry-options.ts`) | Select persona, TaskPicker, RadioGroup duración/franja, DatePicker, DurationInput o 2× `<Input type=time>`, Textarea, Switch facturable, borrar con confirmación en línea | empleado, gestor, responsable, admin | Arreglado (H-B7, H-B14) | Variante `field` en `TaskPicker`. Ayudas largas (franja, ausencia parcial) bajo la fila; «Hora de Madrid.» se queda (aceptado). |
| Buscador de tareas (combobox) | `components/time/task-picker.tsx` + `use-loggable-tasks.ts` | Popover + Command (shouldFilter=false, búsqueda en el servidor, error visible) | todos los internos | Arreglado (H-B7) | Variante `field` en `TaskPicker`. |
| Diálogo de entradas de una celda | `components/time/cell-entries-dialog.tsx` | Editar / borrar (confirmación en línea), añadir | empleado; admin para las bloqueadas | OK | — |
| Diálogo «Devolver semana» | `components/time/return-week-dialog.tsx` | Textarea obligatoria | responsable / admin | Pregunta (H-B12) | ¿Borrador intencionado? Ver «Preguntas». |
| Diálogo «No se ha podido imputar» del temporizador | `components/time/timer-stop-dialog.tsx` | DurationInput, TaskPicker, Textarea condicional, Descartar / Seguir / Imputar | todos los internos | Arreglado (H-B7) | Variante `field` en `TaskPicker`. |
| Chip del temporizador (cabecera) | `components/time/timer-chip.tsx` | Parar, DropdownMenu (ir a tarea, descartar) + Dialog de confirmación | todos los internos | OK | — |
| Iniciar temporizador (cabecera) | `components/time/timer-start-button.tsx` | TaskPicker con disparador propio | todos los internos | OK | — |
| Botón del temporizador por tarea | `components/time/timer-button.tsx` | Botón play/stop | todos los internos | OK | — |
| Aprobaciones `/horas/aprobaciones` | `pages/time/approvals.tsx` (+ `use-week-entries.ts`) | Checkbox «todas» y por semana, aprobar varias (ConfirmDialog), aprobar, devolver, detalle plegable con reintento, reabrir en el histórico | responsable / admin | OK | — |
| Aviso de ausencias pendientes (en aprobaciones) | `components/absences/pending-absences-notice.tsx` | fetch al montar, oculto si falla | responsable / admin | OK | — |
| Bloqueo de horas `/horas/bloqueo` (formulario + vista previa) | `pages/time/locks.tsx` | RadioGroup cliente/proyecto, Select cliente o proyecto, 2× DatePicker, Input referencia, «Ver vista previa» (GET), «Bloquear» (ConfirmDialog), deshacer bloqueo | admin | Arreglado (H-B1, H-B2) | Se bloquea lo previsualizado; si cambian los datos, pide repetir la vista previa. Con error de validación se conserva lo escrito. |
| Bolsas `/bolsas` (filtros) | `pages/hour-banks/index.tsx` (+ `projects-list/filter-select.tsx`) | 3× FilterSelect (cliente, departamento, estado), Switch «próximas a agotarse», limpiar | admin / gestor | OK | — |
| Alta y edición de bolsa (ficha de proyecto `/proyectos/{p}/bolsas`) | `components/hour-banks/hour-bank-form-dialog.tsx` + `hour-bank-fields.tsx` | Input nombre, DurationInput total, Select departamento, 2× DatePicker, RadioGroup política, 2× MoneyField, Input referencia, Textarea | gestor / admin | Arreglado (H-B8) | `content-start` en las celdas. |
| Renovar bolsa | `components/hour-banks/hour-bank-renew-dialog.tsx` (+ `hour-bank-fields.tsx`) | Los mismos campos + Checkbox «mover tareas abiertas» | gestor / admin | Arreglado (H-B8) | `content-start` en las celdas. |
| Acciones de bolsa | `components/hour-banks/hour-bank-actions.tsx` | Renovar, editar, cerrar, reabrir y borrar (ConfirmDialog; el error `hour_bank` lo pinta la página) | gestor / admin | OK | — |
| Bolsas: tablas y gráficas (card, history, entries, tasks, breakdown, mini-meter, weekly-chart, overview-table) | `components/hour-banks/*` | Sin controles de formulario | — | OK | — |
| Barra de filtros de todos los informes (`/informes/direccion`, `/informes/personas/{u}`, `/informes/departamentos/{d}`, `/informes/proyectos/{p}`, `/informes/clientes/{c}`, `/informes/facturacion`, `/informes/detalle`) | `components/reports/report-filter-bar.tsx` | Select de periodo, anterior/Hoy/siguiente o 2× DatePicker (rango), Switch comparar, 6× MultiSelectFilter, Select facturable, limpiar | internos según alcance | Arreglado (H-B3, H-B6, H-B10) | `rangePatch`: la otra fecha se arrastra. Variante `field`; en envíos, la etiqueta una sola vez (`labelInside={false}`). El fallo no se queda en caché; «Reintentar». |
| Selector múltiple (combobox) | `components/reports/multi-select-filter.tsx` | Popover + Command, `value` único (`nombre id`) | — | Arreglado (H-B6) | Variante `field`; en envíos, la etiqueta una sola vez (`labelInside={false}`). |
| Facturación `/informes/facturacion` | `pages/reports/billing.tsx` + `r2-report-body.tsx` | Select de cliente, barra de filtros, exportar | gestor / admin | Arreglado (H-B4, H-B11) | `isReportPageVisit`: solo las visitas del informe lo atenúan. Select siempre controlado (`value=""`). |
| Informe de proyecto `/informes/proyectos/{p}` | `pages/reports/project.tsx` + `r2-report-body.tsx` | Barra de filtros, varios ExportMenu | gestor / admin | Arreglado (H-B4) | `isReportPageVisit`: solo las visitas del informe lo atenúan. |
| Dashboards R1 (dirección, persona, departamento) | `pages/reports/{direction,person,department}.tsx` + `r1-report-state.tsx` | Barra de filtros, ExportMenu, reintentar; departamento con `<Deferred future_load>` | responsable / admin / la propia persona | Arreglado (H-B5) | La carga futura diferida ya no atenúa el dashboard; el indicador sigue a su visita. |
| Cliente `/informes/clientes/{c}` | `pages/reports/client.tsx` | Barra de filtros, ExportMenu | gestor / admin | OK | — |
| Detalle `/informes/detalle` (tabla dinámica) | `pages/reports/detail.tsx` + `r3-pivot-controls.tsx` | 3× Select (filas, columnas, medida), intercambiar, barra de filtros, 2× ExportMenu, reintentar | todos los internos | OK | — |
| Índice `/informes` | `pages/reports/index.tsx` | Solo enlaces | internos | OK | — |
| Menú «Exportar ▾» | `components/reports/export-menu.tsx` | DropdownMenu (versión con RadioItem; xlsx/csv/pdf/imprimir como enlaces; enviar; programar) | internos | OK | — |
| Google Sheets (dentro del menú) | `components/reports/delivery/sheets-export-item.tsx` | DropdownMenuItem + Dialog «Conecta tu cuenta» | internos sin colaboradores | Arreglado (H-B13) | No se puede lanzar dos veces la misma hoja. |
| Menú PDF de la bolsa (informe de cliente) | `components/reports/r2-bank-pdf-menu.tsx` | DropdownMenu de enlaces | gestor / admin | OK | — |
| «Enviar por correo…» | `components/reports/delivery/send-report-dialog.tsx` + `delivery-fields.tsx` + `use-delivery-people.ts` | Versión (RadioGroup), formatos (Checkbox), destinatarios (MultiSelectFilter + correos externos), asunto, mensaje | internos | Arreglado (H-B6) | Variante `field`; en envíos, la etiqueta una sola vez (`labelInside={false}`). |
| «Programar envío…» (desde Exportar y desde `/informes/envios`) | `components/reports/delivery/schedule-report-dialog.tsx` | RadioGroup de frecuencia, DatePicker / NativeSelect día de la semana / NativeSelect día del mes, `<Input type=time>`, NativeSelect periodo relativo, formatos, destinatarios, asunto, mensaje | internos | Arreglado (H-B6, H-B14) | Variante `field`; en envíos, la etiqueta una sola vez (`labelInside={false}`). Ayudas largas (franja, ausencia parcial) bajo la fila; «Hora de Madrid.» se queda (aceptado). |
| Envíos programados `/informes/envios` | `pages/reports/schedules/index.tsx` + `delivery/schedule-status.tsx` | DropdownMenu «Programar un envío» (`modal={false}`), pausar/reanudar/enviar ahora/borrar (sm) | internos (admin ve todos) | OK | — |
| Detalle de envío `/informes/envios/{id}` | `pages/reports/schedules/show.tsx` | Editar (diálogo de programar), acciones | internos | OK | — |
| Carga `/carga` (barra) | `components/workload/workload-toolbar.tsx` | ToggleGroup de horizonte, 4× MultiSelectFilter, limpiar | responsable/admin (equipo); empleado (solo cliente/proyecto) | Arreglado (H-B6) | Variante `field`; en envíos, la etiqueta una sola vez (`labelInside={false}`). |
| Carga: panel de celda (Sheet) y bandejas | `components/workload/workload-cell-panel.tsx`, `workload-trays.tsx` | Collapsible «Reasignar/replanificar» con el editor | gestor / responsable / admin | OK | — |
| Editor de tarea en Carga | `components/workload/workload-task-editor.tsx` | PersonLoadPicker, 2× DatePicker, DurationInput, guardar/deshacer | gestor / responsable / admin | Arreglado (H-B8) | `content-start` en las celdas. |
| Selector de responsable con carga | `components/workload/person-load-picker.tsx` | Popover + Command (`value` único, borde `border-input`, sin azul) | gestor / responsable / admin | OK | — |
| Carga: matriz, «Mi carga» diferida (`my_forecast`), alertas, leyenda | `pages/workload/index.tsx`, `workload-matrix.tsx` | Botones de celda; `router.visit({only:['cell']})` (recarga parcial, no cancela la diferida en Inertia 3.7) | — | OK | — |
| Mis ausencias `/ausencias` (`?solicitar=1`) | `pages/absences/index.tsx` | Solicitar, cancelar (ConfirmDialog); `router.replace` al montar para quitar `?solicitar` (sin props diferidas: correcto) | todos los internos | OK | — |
| Diálogo de ausencia (solicitar / registrar / modificar) | `components/absences/absence-dialog.tsx` | NativeSelect persona, NativeSelect tipo, RadioGroup duración, DatePicker(s), DurationInput, Textarea | empleado; responsable/admin | Arreglado (H-B14) | Ayudas largas (franja, ausencia parcial) bajo la fila; «Hora de Madrid.» se queda (aceptado). |
| Ausencias del equipo `/ausencias/equipo` | `pages/absences/team.tsx` + `absence-actions.tsx` + `team-calendar.tsx` | NativeSelect departamento, aprobar, rechazar (Dialog con Textarea obligatoria), anular, modificar, navegación de mes | responsable / admin | OK | — |
| Festivos `/admin/festivos`: alta y edición | `components/absences/holiday-dialog.tsx` | DatePicker, Input | admin | OK | — |
| Festivos: importar `.ics`/CSV | `components/absences/holiday-import.tsx` | `<Input type=file>`, vista previa, confirmar, descartar | admin | Arreglado (H-B9) | Caja y botón en una fila; ayuda y error debajo. |
| Tarjeta «Mis ausencias» (Inicio) y marco con pestañas | `components/absences/my-absences-card.tsx`, `absences-frame.tsx` | Enlaces | todos | OK | — |

### C · Administración, ajustes, clientes, portal y avisos

| Dónde (página / ruta) | Componente | Controles | Rol | Estado | Arreglo |
|---|---|---|---|---|---|
| Clientes · `/clientes` | `pages/clients/index.tsx` (barra de filtros) | Input búsqueda, NativeSelect estado, NativeSelect tipo de proyecto, NativeSelect persona (prop diferida `people`), Checkbox «Mis proyectos», Button ghost «Limpiar» | interno (todos) | Arreglado / Pregunta (H-C5, H-C10) | Opción «Cargando…»/«Otra persona» mientras no está en la lista. Ver «Preguntas». |
| Clientes · «Nuevo cliente» (diálogo) | `components/clients/client-dialog.tsx` | Input ×6-7 (Field), Select Radix «Responsable» (diferida `people`), Textarea notas | admin / responsable | Arreglado (H-C5, H-C6) | Opción «Cargando…»/«Otra persona» mientras no está en la lista. Tipo de tarea y usuario reordenados; la ayuda del icono del cliente se queda (aceptado). |
| Ficha de cliente · `/clientes/{id}` · «Editar» | `components/clients/client-dialog.tsx` | ídem | admin / responsable | Arreglado (H-C3, H-C6) | Responsable no disponible → «Automático». Tipo de tarea y usuario reordenados; la ayuda del icono del cliente se queda (aceptado). |
| Ficha de cliente · desactivar / reactivar | `pages/clients/show.tsx` (ConfirmDialog + `router.post`) | botón con confirmación | admin / responsable | OK | — |
| Ficha de cliente · pestañas Weekly (`?pestana=`) | `components/weeklies/weekly-tabs.tsx` (Link) + `<Deferred data="weekly">` | enlaces (visita completa) | interno con Weekly | OK | — |
| Ficha de cliente · «Equipo» · Unirme / Dejar | `components/weeklies/insights/client-weekly-panels.tsx:420-426, 576-586` (fuera de mi área, afecta a esta página) | Button outline sm, `only: ['weekly']` | interno con Weekly | Arreglado (H-C1) | Red de seguridad global: se vuelven a pedir las diferidas canceladas (`installDeferredPropsGuard`). |
| Ficha de cliente · Acceso al portal (prop diferida `portal`, con rescate) | `components/portal/access/client-portal-section.tsx` | carga / error con «Reintentar» / null | admin / quien gestiona el portal | OK | — |
| Ficha de cliente · «Invitar al portal» | `components/portal/access/invite-portal-user-dialog.tsx` | Input nombre, Input correo (Field), Alert de error `client` | quien gestiona el portal | Arreglado (H-C1, H-C2) | Red de seguridad global: se vuelven a pedir las diferidas canceladas (`installDeferredPropsGuard`). Se vacía de verdad al abrir (`setDefaults` + `setData`). |
| Ficha de cliente · ajustes del portal | `components/portal/access/client-portal-settings-dialog.tsx` | RadioGroup ×2, SwitchField | quien gestiona el portal | Arreglado (H-C1) | Red de seguridad global: se vuelven a pedir las diferidas canceladas (`installDeferredPropsGuard`). |
| Ficha de cliente · acciones por usuario del portal | `components/portal/access/portal-user-list.tsx` | DropdownMenu (reenviar, revocar con ConfirmDialog, reactivar) | quien gestiona el portal | Arreglado (H-C1) | Red de seguridad global: se vuelven a pedir las diferidas canceladas (`installDeferredPropsGuard`). |
| Ajustes del proyecto · «Portal del cliente» | `components/portal/access/project-portal-section.tsx` | SwitchField ×3, guardar si hay cambios | responsable del proyecto / admin | OK | — |
| Portal · bolsa `/portal/bolsas/{id}` · filtro de mes | `components/portal/banks/portal-bank-entries.tsx` | NativeSelect mes (`router.get` con `only`), «Reintentar» | cliente del portal | Arreglado (H-C8) | `useOptimisticValue` en el mes del portal y el filtro de avisos. |
| Portal · tareas del proyecto | `components/portal/projects/portal-task-list.tsx` | ToggleGroup outline (estado local) | cliente del portal | OK | — |
| Portal · Gantt / diálogo de tarea | `pages/portal/projects/gantt.tsx`, `portal-task-dialog.tsx` | diálogo de solo lectura | cliente del portal | OK | — |
| Portal · inicio, proyectos y bolsas | `pages/portal/home.tsx`, `projects/index.tsx`, `projects/show.tsx`, `banks/show.tsx` | sin formularios | cliente del portal | OK | — |
| Admin · Usuarios `/admin/usuarios` (filtros) | `pages/admin/users/index.tsx` | Input, NativeSelect rol, departamento y estado, «Limpiar» | admin / responsables (lectura) | OK | — |
| Admin · «Invitar persona» | `components/admin/invite-user-dialog.tsx` + `user-form-fields.tsx` | Input ×3-5, NativeSelect rol y departamento | admin | Arreglado / Pregunta (H-C6, H-C9) | Tipo de tarea y usuario reordenados; la ayuda del icono del cliente se queda (aceptado). Ver «Preguntas». |
| Admin · ficha `/admin/usuarios/{id}` (datos) | `pages/admin/users/edit.tsx` + `user-form-fields.tsx` | ídem, `fieldset disabled` sin permiso | admin | Arreglado (H-C6) | Tipo de tarea y usuario reordenados; la ayuda del icono del cliente se queda (aceptado). |
| Admin · ficha · reenviar invitación / reactivar / borrar jornada | `pages/admin/users/edit.tsx` | botones y ConfirmDialog | admin | OK | — |
| Admin · ficha · jornada (diálogo) | `components/admin/schedule-dialog.tsx` + `week-minutes-input.tsx` + `day-minutes-input.tsx` | DatePicker, 7 campos de minutos con vista previa | admin | OK | — |
| Admin · ficha · exportaciones de datos personales | `components/privacy/admin-personal-data-exports.tsx` | botón y sondeo `router.reload({only})` | admin | OK | — |
| Admin · baja `/admin/usuarios/{id}/baja` | `pages/admin/users/deactivate.tsx` | NativeSelect «Pasar todas a», NativeSelect por tarea y por proyecto, errores `assignments.N.*` y `owners.N.*` | admin | OK | — |
| Admin · Departamentos | `pages/admin/departments/index.tsx` + `components/admin/department-dialog.tsx` | Input, ColorPicker (radios nativos como muestras), lista de Checkbox | admin | OK | — |
| Admin · Tipos de tarea | `pages/admin/task-types/index.tsx` + `components/admin/task-type-dialog.tsx` | Input, NativeSelect departamento, ColorPicker, IconPicker, Switch ×2; ReorderButtons; borrar o desactivar | admin | Arreglado (H-C6) | Tipo de tarea y usuario reordenados; la ayuda del icono del cliente se queda (aceptado). |
| Admin · Estados | `pages/admin/statuses/index.tsx` + `components/admin/status-dialogs.tsx` | Input, NativeSelect categoría, ColorPicker, Checkbox por defecto; diálogo de borrado con NativeSelect de sustituto | admin | OK | — |
| Admin · Ajustes `/admin/ajustes` | `pages/admin/settings.tsx` | Input, Switch (Toggle) ×15, NativeSelect redondeo, audio, tono y días editables; umbrales dinámicos; WeekMinutesInput; `type=time` | admin | Arreglado (H-C4) | `w-full sm:w-80`. |
| Admin · Identidad `/admin/identidad` | `pages/admin/identity.tsx` | Input nombre, Input file logo (validación local), quitar logo | admin | OK | — |
| Admin · Privacidad `/admin/privacidad` | `pages/admin/privacy.tsx` + `components/privacy/retention-fields.tsx` | Textarea markdown con vista previa, Input number y Checkbox «Sin límite» por plazo, avisos de disco | admin | OK | — |
| Admin · Auditoría `/admin/auditoria` | `components/audit/audit-filters.tsx`, `audit-table.tsx` | NativeSelect ×3, Input `type=date` ×2, «Limpiar», exportar CSV | admin | Pregunta (H-C11) | Ver «Preguntas». |
| Admin · Transcripciones | `pages/admin/transcriptions.tsx` | «Actualizar» (`reload only`), «Relanzar» una o todas (ConfirmDialog) | admin | OK | — |
| Admin · Plantillas (lista) | `pages/admin/templates/index.tsx` | Input, NativeSelect estado, botón «Papelera» (aria-pressed), acciones por fila | admin | Arreglado (H-C7) | Sin «Limpiar filtros» falso; limpiar no sale de la papelera. |
| Admin · Plantilla (editor) | `pages/admin/templates/edit.tsx` | Input, Textarea, Switch (+ TemplateEditor, fuera de mi área) | admin | OK | — |
| Admin · Recurrentes | `pages/admin/recurring/index.tsx` | NativeSelect estado y proyecto | admin | OK | — |
| Admin · Festivos | `pages/admin/holidays/index.tsx` | navegación por año, añadir nacionales, borrar; HolidayDialog y HolidayImport en `components/absences` (fuera de mi área) | admin | OK | — |
| Admin · Uso de IA | `pages/admin/ai-usage.tsx` | enlaces de rango | admin | OK | — |
| Ajustes · Perfil `/ajustes/perfil` | `pages/settings/profile.tsx` (`<Form>`), `components/settings/avatar-field.tsx` | Input ×3, PasswordInput condicional, avatar con recorte; `<Deferred weeklyStats>` | todos | OK | — |
| Ajustes · Seguridad | `pages/settings/security.tsx` (+ ManageTwoFactor) | PasswordInput ×3 | todos | OK | — |
| Ajustes · Notificaciones | `pages/settings/notifications.tsx` | Switch por evento y canal, resumen diario | internos | OK | — |
| Ajustes · Sesiones / Integraciones / Mis datos / Apariencia | `pages/settings/sessions.tsx`, `integrations.tsx`, `my-data.tsx`, `appearance.tsx` | ConfirmDialog, botones, sondeo | todos | OK | — |
| Auth · login, recordar contraseña, restablecer, aceptar invitación, 2FA, confirmar contraseña | `pages/auth/*.tsx`, `components/auth/google-sign-in-button.tsx` | `<Form>` con InputError, Checkbox recordar, InputOTP | anónimo | OK | — |
| Privacidad `/privacidad` | `pages/privacy/show.tsx`, `privacy-notice-banner.tsx` | «He leído» (`router.post` + toast de error) | interno | OK | — |
| Notificaciones `/notificaciones` | `pages/notifications/index.tsx` | ToggleGroup filtro (`router.get`), «Marcar todo como leído», paginación | interno | Arreglado (H-C8) | `useOptimisticValue` en el mes del portal y el filtro de avisos. |
| Campana de notificaciones (cabecera) | `components/notifications/notification-bell.tsx` | Popover con `fetch` (catch y estado de error), sondeo cada 60 s | interno | OK | — |

### D · Weekly: weeklies, Mi espacio, equipo, ayuda, sugerencias y asistente

| Dónde (página / ruta) | Componente | Controles | Rol | Estado | Arreglo |
|---|---|---|---|---|---|
| /weeklies (Resumen), «Unirme a clientes» | `components/weeklies/weekly-dialogs.tsx` `JoinClientsDialog` (prop opcional `joinable_clients`) | Buscador Input, lista de Checkbox, enviar | todos | Arreglado (H-D1) | Lista guardada en el diálogo, error con «Reintentar» y «Ningún cliente coincide…». |
| /equipo/{user}, «Asignar clientes» | mismo `JoinClientsDialog` (prop opcional `assignable_clients`) | ídem | quien gestiona | Arreglado (H-D1) | Lista guardada en el diálogo, error con «Reintentar» y «Ningún cliente coincide…». |
| /weeklies (Resumen y Histórico) y /weeklies/{id}, «Configurar día límite» | `weekly-dialogs.tsx` `DeadlineDialog` | DatePicker | quien gestiona | OK | — |
| /weeklies (Resumen), «Eximir» | `weekly-dialogs.tsx` `ExemptDialog` | RadioGroup (semana / vacaciones / ausente), Textarea o DatePicker | quien gestiona | OK | — |
| /weeklies, «Quitar exención» y «Dejar cliente» | `weekly-overview.tsx` `RemoveExemption`, `LeaveClient` (ConfirmDialog) | confirmación | gestiona / todos | OK | — |
| /weeklies, «Iniciar la semana» | `weekly-overview.tsx` `NoActiveCycle` | botón POST | quien gestiona | Arreglado (H-D9) | Errores en aviso (`toastVisitErrors`). |
| /weeklies y /weeklies/{id}, «Cerrar semana» | `weekly-close-dialog.tsx` | confirmación | quien gestiona | Arreglado (H-D9) | Errores en aviso (`toastVisitErrors`). |
| /weeklies?pestana=historico, «Borrar semana» (tarjetas destacadas y tabla) | `weekly-history.tsx` `DeleteCycle` | ConfirmDialog | quien gestiona | Arreglado (H-D2) | `ConfirmDialog` se cierra al terminar; `key` por semana. |
| /weeklies/{id}, acciones del informe (escritorio y hoja móvil) | `weekly-report-view.tsx` | Generar texto o audio (ConfirmDialog si está editado a mano), Editar, Copiar, Plazo, Borrar, Cerrar, «Solo los míos», pantalla completa, ExportMenu | gestiona / todos | Arreglado (H-D4, H-D13) | `processing` y cierre automático. Iconos a h-8, «Dictar» alineado abajo, botón del asistente a 40 px. |
| /weeklies/{id}, «Editar informe» | `report-edit-dialog.tsx` | Textarea, Select de estado, listas de Input (riesgos, pasos), hitos (2 Input) | quien gestiona | Arreglado (H-D10) | `maxLength` y error junto a cada fila. |
| /weeklies/{id}, «Reportes originales» | `weekly-report-view.tsx` (Dialog de solo lectura) | ninguno | todos | OK | — |
| /weeklies/{id}, audio (velocidad) | `weekly-audio.tsx` (DropdownMenuRadioGroup) | radio del menú | todos | OK | — |
| /weeklies/avisos, reglas, viernes y plantillas | `pages/weeklies/reminders.tsx`, `reminders/reminder-rules-editor.tsx`, `reminders/template-editor.tsx` | Switch, NativeSelect de día y canal, Input time, Input y Textarea de plantilla, Restaurar | quien gestiona | Arreglado (H-D6) | Tras guardar se resincronizan las reglas con sus ids. |
| /weeklies/avisos, «Enviar recordatorio» | `reminders/send-reminders-dialog.tsx` | RadioGroup, Checkbox de personas y canales, NativeSelect | quien gestiona | OK | — |
| /weeklies/avisos, filtros del registro | `reminders/reminder-log.tsx` | 2 NativeSelect (router.get only) | quien gestiona | OK | — |
| /weeklies, /equipo y otras, «Recordar» | `reminders/remind-button.tsx` | botón POST | quien gestiona | OK | — |
| /weeklies/estado-proyectos, filtros | `insights/project-status-view.tsx` | 2 NativeSelect, Limpiar, conmutador Tarjetas/Tabla hecho a mano | quien gestiona | Arreglado (H-D12) | Alturas de su fila (h-8 / h-9). |
| /mi-espacio?semana={id}, «Mi weekly» | `my-weekly-editor.tsx`, `client-entry-box.tsx`, `use-weekly-autosave.ts` | Textarea por cliente, Select de proyecto, dictado, Popover+Command «Añadir cliente», Enviar (ConfirmDialog si está vacía), renunciar a la exención | empleado | Arreglado (H-D3, H-D5, H-D13) | `processing` y cierre automático. Si el envío falla, se reanuda el autoguardado. Iconos a h-8, «Dictar» alineado abajo, botón del asistente a 40 px. |
| Dictado (Mi weekly y notas de tareas) | `dictation-button.tsx`, `use-dictation.ts` | grabar, parar, cancelar | empleado | OK | — |
| «Estoy fuera» (menú de usuario, aviso de Mi weekly, ficha de persona) | `away-dialog.tsx` | RadioGroup, DatePicker, Checkbox «solicitar ausencia», Quitar | empleado / gestiona | Arreglado (H-D7) | `useResetOnOpen`; sin fecha se desmarca «Solicitar la ausencia». |
| /mi-espacio?pestana=tareas, barra superior | `tasks/my-space-tasks.tsx` | conmutador Todas/Pendientes/Completadas hecho a mano, Archivadas, Nueva tarea, Generar con IA | empleado | Arreglado (H-D12) | Alturas de su fila (h-8 / h-9). |
| /mi-espacio?pestana=tareas, «Nueva tarea» | `tasks/my-space-task-dialogs.tsx` `NewTaskDialog` + `my-space-task-fields.tsx` | Input, ClientPicker/ProjectPicker (Select o Popover+Command si hay ≥ 9), BankPicker, PrioritySelect, DatePicker | empleado | Arreglado (H-D8) | Ayuda bajo la fila; la bolsa a todo el ancho. |
| /mi-espacio?pestana=tareas, «Editar tarea» | `my-space-task-dialogs.tsx` `EditTaskDialog` | Input, PrioritySelect, DatePicker | empleado | Arreglado (H-D14) | «Tarea guardada». |
| /mi-espacio?pestana=tareas, fila de tarea | `tasks/my-space-task-row.tsx`, `tasks/task-notes-field.tsx` | Checkbox de hecha, Textarea de notas con autoguardado y dictado, archivar, borrar (ConfirmDialog) | empleado | OK | — |
| /mi-espacio?pestana=tareas, «Tareas sugeridas (IA)» | `tasks/task-suggestions-panel.tsx` | Checkbox, Input, pickers, PrioritySelect, DatePicker por fila; crear o descartar | empleado | OK | — |
| /equipo, filtros | `pages/team/index.tsx` | Input de búsqueda, 4 NativeSelect, Limpiar | todos | OK | — |
| /equipo/{user}, ficha | `pages/team/show.tsx` | Estoy fuera, Asignar clientes, Recordar, quitar cliente, «Ver histórico», paneles IA | todos / gestiona | Arreglado (H-D1) | Lista guardada en el diálogo, error con «Reintentar» y «Ningún cliente coincide…». |
| «Ver histórico» (ficha y pestaña Equipo del cliente) | `insights/client-history-dialog.tsx` | fetch paginado, «Ver más» | todos | Arreglado (H-D15) | Solo cuenta la última respuesta; «Reintentar». |
| Ficha del cliente, pestaña Equipo: Unirme / Dejar | `insights/client-weekly-panels.tsx` `ClientTeamPanel` | botón POST/DELETE (`only: ['weekly']`) | todos | OK | — |
| Paneles «Resumen con IA» | `insights/ai-summary-panel.tsx` | Generar / Regenerar, sondeo `only:[prop]` | gestiona | OK | — |
| /ayuda (General), «Recursos» | `help/help-dialogs.tsx` `HelpSettingsDialog` | Input url, Input file (PDF), Checkbox quitar | admin | OK | — |
| /ayuda (General), «Nueva versión» y editar | `help-dialogs.tsx` `ReleaseDialog` | Input number, 2 NativeSelect (grid-cols-3), Textarea, lista de cambios con subir y bajar, Checkbox | admin | OK | — |
| /ayuda (General), «Nueva actualización» | `help-dialogs.tsx` `ManualUpdateDialog` | DatePicker, Input, Textarea, RichTextEditor | admin | OK | — |
| /ayuda (General), filtros, «me gusta», detalle, ocultar o borrar | `help/help-general.tsx` | Input, 2 NativeSelect, ConfirmDialog | todos / admin | OK | — |
| /ayuda?pestana=tutoriales, crear o editar, borrar, reordenar | `help/help-tutorials.tsx`, `help/sortable-list.tsx` | Input, Textarea, NativeSelect de versión, Input file por trozos con progreso y cancelar | admin | OK | — |
| /ayuda?pestana=preguntas, secciones y preguntas | `help/help-faq.tsx` | Input; NativeSelect, Input y RichTextEditor; DeleteButton; reordenar | admin | Arreglado (H-D11) | Se cierra diciendo por qué. |
| /ayuda?pestana=sugerencias, Feedback | `suggestions/suggestion-feed.tsx`, `use-suggestion-query.ts` | Input de búsqueda, NativeSelect de orden y categoría, Añadir, voto | todos | OK | — |
| /ayuda?pestana=sugerencias, Roadmap | `suggestions/suggestion-roadmap.tsx` | Input, NativeSelect, Checkbox de estados, arrastrar o menú «Mover a» | todos / gestiona | Arreglado (H-D16) | Sin doble envío; movimiento del roadmap asíncrono. |
| Nueva o editar sugerencia y «Reportar un bug» (`?nueva=bug`) | `suggestions/suggestion-composer.tsx`, `suggestions-tab.tsx` | Input, NativeSelect de tablero y categoría, RichTextEditor, adjuntos | todos | OK | — |
| /ayuda/sugerencias/{id}, moderación y borrado | `suggestions/suggestion-detail.tsx` | NativeSelect de estado, Textarea de nota; ConfirmDialog | gestiona / autor | OK | — |
| Comentarios y respuestas | `suggestions/suggestion-comments.tsx` | RichTextEditor (Ctrl+Intro), adjuntos, reacciones, borrar | todos | Arreglado (H-D16) | Sin doble envío; movimiento del roadmap asíncrono. |
| «Gestionar categorías» | `suggestions/suggestion-taxonomy.tsx` | ItemDialog (Input, Input, Textarea, Checkbox), DeleteItem, reordenar | gestiona | Arreglado (H-D11) | Se cierra diciendo por qué. |
| /ia, asistente | `pages/assistant/index.tsx`, `assistant/use-assistant.ts` | Textarea (Intro envía), botón de enviar, sugerencias, reiniciar | todos | Arreglado (H-D13) | Iconos a h-8, «Dictar» alineado abajo, botón del asistente a 40 px. |
| Tarjeta de inicio, aviso global, permisos de avisos, Mi weekly en inicio | `home-weekly-card.tsx`, `global-banner.tsx`, `push-prompt.tsx`, `my-weekly-callout.tsx` | botones y enlaces | todos | Arreglado (H-D7) | `useResetOnOpen`; sin fecha se desmarca «Solicitar la ausencia». |

### E · Chat, plan del día, Previsión, Inicio y componentes compartidos

| Dónde (página / ruta) | Componente | Controles | Rol | Estado | Arreglo |
|---|---|---|---|---|---|
| `/chat`, lista | `components/chat/conversation-list.tsx` | Input de búsqueda, 2 FilterToggle (`aria-pressed`), DropdownMenu «Nuevo», Popover de avisos push (Switch en `realtime/push-toggle.tsx`) | interno; colaborador sin «Nuevo» | OK | — |
| `/chat`, «Nuevo mensaje directo» | `components/chat/new-conversation-dialogs.tsx` (`NewDirectDialog`) | CommandDialog (cmdk) de personas, con `value` único (`nombre dpto id`) | interno | OK | — |
| `/chat`, «Nuevo grupo» | `components/chat/new-conversation-dialogs.tsx` (`NewGroupDialog`) | Input de nombre, Input de búsqueda, lista de Checkbox | interno | Arreglado (H-E14) | «Nuevo grupo» empieza vacío; cancelar un comentario lo descarta. |
| `/chat` y cabecera, «Canal» | `components/chat/channel-dialog.tsx` | Botón de emoji (Popover), Input de nombre, Checkbox «archivado» | admin | Arreglado (H-E13) | Botón del emoji con variante `field`. |
| `/chat/{id}`, «Gestionar el grupo» | `components/chat/group-settings-dialog.tsx` | Input y botón «Renombrar», lista de miembros con quitar, búsqueda y Checkbox para añadir, salir con confirmación | creador o admin; miembro (salir) | OK | — |
| `/chat`, «Moderar» | `components/chat/moderation-dialog.tsx` | CommandDialog | admin | OK | — |
| `/chat/{id}`, `/proyectos/{id}/chat`, «Crear tarea» | `components/chat/create-task-dialog.tsx` | Input de título, Select de bolsa, Select de responsable, DatePicker | interno en chat de proyecto | Arreglado (H-E5) | `w-full` en los dos selectores. |
| `/chat/{id}`, editor | `components/chat/composer.tsx`, `emoji-picker.tsx`, `emoji-popover.tsx`, `media/*` | textarea con combobox de menciones, emojis, adjuntos y audio | interno o colaborador | OK | — |
| `/chat/{id}`, cabecera y mensajes | `conversation-header.tsx`, `message-toolbar.tsx` | botones de unirse o salir, Popover de participantes, DropdownMenu de acciones | interno | OK | — |
| `/chat/buscar` | `components/chat/media/search-page.tsx` | Input y botón Buscar; filtros de tipo como enlaces | interno | OK | — |
| `/dia`, `/dia/equipo`, `/dia/semana`, navegación de día o semana | `pages/day-plan/index.tsx`, `team.tsx`, `week.tsx` | Button outline `size="icon"` y Button outline `size="sm"` («Hoy») | empleado; responsable o admin | Arreglado (H-E9) | «Hoy» a h-9. |
| `/dia`, caja «Escribe qué vas a hacer…» | `components/day-plan/line-composer.tsx` | Input combobox (`@` cliente, `#` proyecto, `~1:30`), etiquetas quitables | empleado | Arreglado (H-E10) | «Cargando clientes y proyectos…» en el selector. |
| `/dia`, nota del día | `pages/day-plan/index.tsx` (`DayNote`) | Textarea que guarda al salir | empleado | Arreglado (H-E3) | `onError: toastVisitErrors` en todas las acciones del plan del día. |
| `/dia`, lista de líneas | `components/day-plan/my-day-list.tsx`, `my-line-row.tsx` | Checkbox «hecha», DropdownMenu de acciones, reordenar (arrastrar o teclado) | empleado | Arreglado (H-E3) | `onError: toastVisitErrors` en todas las acciones del plan del día. |
| `/dia`, «Editar línea» | `components/day-plan/line-dialogs.tsx` (`EditLineDialog`) | Input de texto, TargetPicker (Popover y Command), Input de horas | empleado | Arreglado (H-E10, H-E12) | «Cargando clientes y proyectos…» en el selector. `DurationInput` y cada error junto a su campo. |
| `/dia`, «Pasar a otro día» | `line-dialogs.tsx` (`CarryDialog`) | lista de botones outline (acciones) | empleado | OK | — |
| `/dia`, «No hecha» | `line-dialogs.tsx` (`NotDoneDialog`) | Textarea de motivo | empleado | OK | — |
| `/dia`, «¿Marcar también la tarea?» | `line-dialogs.tsx` (`CompleteTaskDialog`) | 2 botones | empleado | Arreglado (H-E3) | `onError: toastVisitErrors` en todas las acciones del plan del día. |
| `/dia`, aviso de pendientes | `components/day-plan/pending-banner.tsx` (y `ChoosePendingDialog`) | botones sm; diálogo con lista de Checkbox | empleado | Arreglado (H-E3) | `onError: toastVisitErrors` en todas las acciones del plan del día. |
| `/dia`, «Desde mis tareas» | `components/day-plan/from-tasks-dialog.tsx` | lista de Checkbox (fetch con error y reintento al reabrir) | empleado | Arreglado (H-E3) | `onError: toastVisitErrors` en todas las acciones del plan del día. |
| `/dia`, ▶ «¿En qué tarea?» | `components/day-plan/line-time.tsx` (`ChooseTaskDialog`) | TaskPicker (`components/time/task-picker.tsx`), botón «Crear tarea» | empleado | Arreglado (H-E6) | Variante `field` en `TaskPicker`. |
| `/dia`, «Vincular horas» | `line-time.tsx` (`LinkEntriesDialog`) | lista de Checkbox (fetch con error) | empleado | OK | — |
| `/dia`, «Registrar lo previsto» (todas o una) | `pages/day-plan/index.tsx:244-269`, `line-time.tsx` | botón y DropdownMenuItem | empleado | Arreglado (H-E3) | `onError: toastVisitErrors` en todas las acciones del plan del día. |
| `/dia`, comentarios de línea | `components/day-plan/line-comments.tsx` | Textarea y botones sm | empleado o responsable | Arreglado (H-E14) | «Nuevo grupo» empieza vacío; cancelar un comentario lo descarta. |
| `/dia/equipo`, `/dia/semana`, filtros | `components/day-plan/team-ui.tsx` (`TeamFilters`) | NativeSelect de departamento (visita al servidor) e Input de persona (en el navegador) | responsable o admin | OK | — |
| `/dia/equipo`, «Recordar» | `pages/day-plan/team.tsx:336-366` | Button outline sm | responsable | OK | — |
| Mis tareas, «Añadir a mi día» | `components/day-plan/add-to-my-day.tsx` | DropdownMenu (hoy o mañana) | empleado | Arreglado (H-E3) | `onError: toastVisitErrors` en todas las acciones del plan del día. |
| `/` (Inicio), tarjeta «Mi día» | `components/day-plan/home-day-plan-card.tsx` | Checkbox por línea (`only: ['day_plan']`) | empleado | Arreglado / Pregunta (H-E3, H-E11) | `onError: toastVisitErrors` en todas las acciones del plan del día. Ver «Preguntas». |
| `/prevision`, barra de filtros | `pages/forecast/index.tsx:226-332`, `components/forecast/layer-toggles.tsx` | chips con NativeSelect (horizonte, departamento), ToggleGroup semanas/meses, Input de persona, 3 casillas-botón de capas | quien ve la previsión (admin o dirección) | Arreglado (H-E15) | Toda la barra sin fondo propio. |
| `/prevision`, panel de celda y «Asignar a…» | `components/forecast/forecast-cell-panel.tsx` (Sheet), `assign-gap-dialog.tsx` | PersonLoadPicker (fetch de disponibilidad) | quien ve la previsión | Arreglado (H-E4) | `toastUnshownErrors` y `toastVisitErrors`. |
| `/prevision`, listas diferidas | `components/forecast/forecast-lists.tsx` | `<Deferred>` con esqueleto; botón «Asignar» | quien ve la previsión | OK | — |
| `/prevision/proyectos` (y `?nuevo=1`) | `pages/forecast/projects/index.tsx`, `forecast-project-dialog.tsx` | ToggleGroup de estado; diálogo de alta (Input, RadioGroup, NativeSelect de cliente diferido, 2 DatePicker, DurationInput, Input de importe, Textarea) | gestor de previsión | Arreglado (H-E8) | `DurationInput preview="overlay"` (se oculta si hay error). |
| `/prevision/proyectos/{id}`, «Editar» | `pages/forecast/projects/show.tsx:686-693` y `forecast-project-dialog.tsx` | el mismo formulario, abierto de forma controlada | gestor | Arreglado (H-E1, H-E16) | `useResetOnOpen` en el formulario del previsto. La opción guardada aparece («(inactivo)», «(ya no se puede asignar)»). |
| `/prevision/proyectos/{id}`, acciones rápidas | `show.tsx:142-147, 186-322` | «Hacer segura», confirmar, reabrir, desvincular (`router.put/post/delete`) | gestor | Arreglado (H-E4) | `toastUnshownErrors` y `toastVisitErrors`. |
| `/prevision/proyectos/{id}`, asignaciones | `components/forecast/allocation-dialog.tsx` | RadioGroup quién, NativeSelect de persona o departamento, RadioGroup de modo, Input % o DurationInput, 2 DatePicker, Input de nota, borrar | gestor | Arreglado (H-E2, H-E4, H-E7, H-E16, H-E17) | `useResetOnOpen` en asignación, «Perdido», «Vincular» y «Crear proyecto real». `toastUnshownErrors` y `toastVisitErrors`. «Hasta (opcional)»; la ayuda del modo ya lo explica. La opción guardada aparece («(inactivo)», «(ya no se puede asignar)»). Botón desactivado hasta que llegan las opciones; `useLastDefined`. |
| `/prevision/proyectos/{id}`, «Asignar a…» | `assign-gap-dialog.tsx` | PersonLoadPicker | gestor | Arreglado (H-E4) | `toastUnshownErrors` y `toastVisitErrors`. |
| `/prevision/proyectos/{id}`, «Perdido», «Vincular», «Crear proyecto real» | `components/forecast/forecast-actions.tsx` | Input de motivo; NativeSelect de proyecto y Checkbox; Input, NativeSelect de cliente, 2 NativeSelect, 2 DatePicker, Input de código y Checkbox | gestor | Arreglado (H-E2, H-E4, H-E16) | `useResetOnOpen` en asignación, «Perdido», «Vincular» y «Crear proyecto real». `toastUnshownErrors` y `toastVisitErrors`. La opción guardada aparece («(inactivo)», «(ya no se puede asignar)»). |
| `/proyectos/{id}/planificacion` (página fuera de mi área; componentes míos) | `pages/projects/planning.tsx:627-656` | AllocationDialog y AssignGapDialog con `options` diferidas | responsable del proyecto | Arreglado (H-E17) | Botón desactivado hasta que llegan las opciones; `useLastDefined`. |
| `/` (Inicio) | `pages/home.tsx`, `components/home/*` | paneles reordenables, botón «Imputar» (TimeEntryDialog, fuera de área), `<Deferred>` de chat_summary, my_forecast o workload, weekly y day_plan (un solo grupo «default») | todos | OK | — |
| Cabecera global | `components/global-search.tsx` | SearchTrigger y CommandDialog (`shouldFilter: false`, `value` único `tipo-id`) | todos | OK | — |
| Editor de texto rico | `components/rich-text/rich-text-editor.tsx`, `mention-list.tsx` | barra de formato, Popover de enlace con Input | interno | OK | — |
| Guía de estilo | `components/styleguide/forms-section.tsx` | ejemplos de formularios | admin | Arreglado (H-E23) | La guía usa `RadioGroup`, `DurationInput`, `Textarea` y `Switch`. |
| Compartidos | `components/ui/*`, `components/domain/*`, `components/admin/{native-select,field,day-minutes-input,week-minutes-input,color-picker,icon-picker}.tsx` | ver la sección «Componentes compartidos» | — | Arreglado (H-E18, H-E22) | `NativeSelect` transparente y con `bare`. `aria-describedby` en `DatePicker`. |

### R · Encontrado en el recorrido por el navegador
| Dónde | Qué pasaba | Arreglo |
|---|---|---|
| Ayuda → «Añadir novedad» (y otros formularios con validación en el controlador) | «El campo subtitle es obligatorio.» (nombre del campo en inglés) | Nombres en español en `lang/es/validation.php` (R1). |
| Tareas del proyecto (Prioridad), calendario (Departamento) | «Todas las prioridade…» y «Todos los departamen…» cortados en la caja | «Todas» / «Todos» (R2, D-312). |
| Enviar por correo / Programar envío | «Personas de Audax» encima y otra vez dentro de la caja | `labelInside={false}` (R3). |
| Previsión, canal del chat, reprogramar | «Cancelar» con otro estilo que en el resto | Secundario (R4, D-311). |
| Previsto: «Horas estimadas» | La vista previa flotante se pisaba con el error | `preview="overlay"` (R5). |
| Weekly → Estado de proyectos a 375 px | Las tarjetas no cabían y cortaban las horas | Columnas `minmax(0,1fr)` (R6). |

## Tests añadidos
| Test | Qué cubre |
|---|---|
| `tests/js/form-fields.test.tsx` | Selector de fecha con borde de campo; ningún combobox `outline`; celdas de filas de varias columnas con `content-start`; acciones del plan del día con `onError`. |
| `tests/js/deferred-props.test.ts` | Qué props diferidas se vuelven a pedir. |
| `tests/js/confirm-dialog.test.tsx` | La confirmación se cierra al terminar y no se confirma dos veces. |
| `tests/js/form-review-locks.test.tsx` | El bloqueo de horas aplica lo previsualizado. |
| `tests/js/form-review-reset.test.tsx` | «Editar» un previsto parte de los datos de ahora. |
| `tests/js/form-review-join-clients.test.tsx` | «Unirme a clientes» conserva la lista y deja reintentar. |
| `tests/js/form-review-double-submit.test.tsx` | Un comentario no se publica dos veces. |
| `tests/js/form-review-reports.test.ts` | Rango sin saltos a «Mes», opciones sin fallo en caché y estado de carga solo del informe. |
| `tests/js/form-review-options.test.tsx` | Responsable no disponible → «Automático». |
| `tests/js/form-review-history.test.tsx` | Histórico de un cliente: última respuesta y reintento. |
| `tests/js/visit-errors.test.ts`, `use-last-defined.test.ts`, `use-optimistic-value.test.ts`, `weekly-autosave-resume.test.tsx` | Los ayudantes nuevos y el autoguardado tras un envío fallido. |
| `tests/js/templates-admin.test.tsx`, `clients-pages.test.tsx` (casos nuevos) | Papelera de plantillas y filtro de personas mientras carga. |
| `tests/e2e/form-review.spec.ts` | Ficha de cliente: una acción antes de que llegue la Weekly no la deja en «Cargando…»; guardar dos veces los avisos no recrea las reglas. |
| `tests/Feature/Weeklies/HelpCenterTest.php` | Errores con el nombre del campo en español. |

## Comprobaciones
