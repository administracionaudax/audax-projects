# Plan Fase 1: núcleo (clientes, proyectos, bolsas, tareas y horas)

## Contexto
La Fase 0 se cerró el 26/09/2026 (etiqueta `fase-0-cerrada`) con la app desplegada en https://projects.audaxstudio.com, autenticación con 2FA, roles, tema, CI en verde y 301 tests en PostgreSQL 18. La Fase 1 construye el **núcleo del producto** (SPEC §17 Fase 1): lo que sustituye a ClickUp para controlar proyectos, bolsas de horas e imputación. Es la fase más grande y la de reglas de negocio más críticas (bolsas, exceso, aprobación). Sigue el SPEC más las decisiones D-001 a D-026 (`docs/DECISIONES.md`), en especial:
- **D-019:** exceso en una sola entrada con `overage_minutes`.
- **D-020:** los responsables y los admins se aprueban solos; la semana puede quedar «devuelta».
- **D-021:** todos ven todos los proyectos; las horas, por ámbito.
- **D-022:** crean clientes y proyectos los admins y los responsables.
- **D-023:** varios gestores por proyecto, con sus propias alertas.
- **D-024:** varios responsables por departamento.

## Modo autónomo (concedido por el propietario el 26/09/2026, hasta el final de todas las fases)
- **Qué decido solo:** trabajo fase tras fase sin esperar aprobaciones. Tomo las decisiones de producto según el SPEC y las decisiones previas, y cada una se anota en `docs/DECISIONES.md` bajo «Decisiones tomadas en autonomía» para que el propietario las revise cuando quiera. El plan de cada fase (2 a 7) queda en `docs/PLAN-FASE-N.md` y se ejecuta sin parar.
- **Servidor:** autorizado lo necesario con salvaguardas.
  - **Autorizado:** recargas *graceful* de nginx y Apache vía Plesk solo por ajustes de `projects.audaxstudio.com`; unidades y timers systemd de la app; contenedores Docker propios con límites; directivas de Plesk del dominio.
  - **Prohibido siempre:** actualizaciones globales, reiniciar servicios compartidos, firewall, SSH, DNS, correo, desinstalar y tocar otras webs.
  - **Cada cambio:** copia previa, batería V, comparación de las 38 webs, vuelta atrás inmediata si algo se desvía y registro en `SERVIDOR-CAMBIOS.md`. Ante algo inesperado en el servidor: revertir, anotarlo y seguir con trabajo que no toque el servidor.
- **Entorno único:** no se separan producción y desarrollo; todo sigue en `projects` (cambia D-002). Cuando la plantilla empiece a usarla, el despliegue pasa a `deploy.sh`, con mantenimiento solo si hay migraciones y vuelta atrás automática.
- **CI:** el propietario ejecuta `gh auth login` en el Mac. Leo los resultados de GitHub Actions y corrijo sin pedir capturas. Mientras tanto reproduzco la CI en un clon limpio antes de cada push.
- **Solo contactaré al propietario para:** los datos SMTP, al final (quedarán las líneas exactas del `.env` y un comando de prueba); la lista de empleados, al final; revisar el texto RGPD (dejaré un borrador marcado «pendiente de asesor»); y cualquier imprevisto del servidor que no pueda revertir.

## Decisiones ya tomadas para esta fase
- **Copias:** volcado nocturno local de PostgreSQL (`pg_dump` en `/var/backups/audax`, rotación de 7 días y comprobación de lectura) para que la copia diaria del servidor sea siempre restaurable. Aprobado por el propietario el 26/09; se ejecuta con las salvaguardas y se registra en `SERVIDOR-CAMBIOS.md`.
- **SMTP:** lo facilitará el propietario más adelante. Mientras tanto, `MAIL_MAILER=log` y todas las notificaciones por email van por la cola `mail`, listas para funcionar al configurarlo.
- **Lista de empleados:** llegará al final. Los seeders de desarrollo generan datos realistas.

## Organización del trabajo
Siete entregas en la rama `fase-1`, cada una con tests en verde, despliegue en el servidor y verificación. Uso el patrón que funcionó en la Fase 0:
1. Fijo yo el contrato (esquema, modelos, rutas, tipos y políticas).
2. Implementan en paralelo agentes en worktrees, con ficheros asignados.
3. Integro, paso una revisión adversarial y corrijo.
4. Despliego.

| Entrega | Contenido | Quién |
|---|---|---|
| **1.1 Contrato de dominio** | Todas las migraciones, modelos, enums, factorías, políticas (esqueleto), rutas y tipos TS de la Fase 1 | Yo (crítico) |
| **1.2 Administración** | Usuarios (invitación, rol, departamento, coste y tarifa, desactivar con reasignación de tareas y parada del temporizador), jornadas versionadas, departamentos con varios responsables, tipos de tarea, estados, ajustes; `app:install` ampliado (estados, tipos y proyecto «Interno – Agencia») | Agente A |
| **1.3 Clientes y proyectos** | CRUD, fichas, filtros, miembros y gestores con alertas (D-023), pestañas del proyecto | Agente A |
| **1.4 Bolsas** | Motor de consumo y exceso (D-019), políticas, alertas, renovación, cierre, horas comprometidas, vista global y detalle | Agente B (motor) + A (interfaz) |
| **1.5 Tareas** | Lista, kanban, panel lateral, subtareas, comentarios con menciones y reacciones, adjuntos, seguidores, acciones masivas, Mis tareas | Agente C |
| **1.6 Horas** | Temporizador en la cabecera, entrada manual, hoja semanal, envío, aprobación, devolución, bloqueo, validaciones; tarjetas de Inicio | Agente B |
| **1.7 Cierre** | Notificaciones (campana y email por cola), búsqueda de clientes, proyectos y tareas, seeders realistas, E2E, revisión adversarial, volcado nocturno, prueba de subida de 50 MB | Yo + revisión |

## Librerías nuevas (versión estable y licencia verificadas el 26/09)
| Paquete | Versión | Licencia | Para qué |
|---|---|---|---|
| @dnd-kit/core, /sortable y /utilities | 6.3 / 10.0 / 3.2 | MIT | Kanban y reordenar con teclado accesible |
| @tiptap/react, starter-kit, extension-mention, -link y -placeholder | 3.31 | MIT (solo el núcleo abierto; nada de Tiptap Pro) | Descripción y comentarios con menciones |
| symfony/html-sanitizer | 8.1 | MIT | Sanear en el servidor el HTML de descripciones y comentarios |
| react-day-picker + date-fns | 10.0 / 4.4 | MIT | Selector de fechas (semana desde el lunes, `es`) |
| @radix-ui/react-tabs, -popover, -progress, -switch y -radio-group | última | MIT | Componentes shadcn que faltan |

## 1.1 Esquema de datos (migraciones y modelos)
Convenciones: horas en **minutos enteros**; importes `decimal` (nunca float); instantes en UTC; `date` en local; `softDeletes` donde tiene sentido; `LogsActivity` (spatie v5, antes/después) **obligatorio** en Project, HourBank, Task y TimeEntry (SPEC §4.6), y además en Client.

| Tabla | Campos principales | Índices y notas |
|---|---|---|
| `clients` | name, tax_id, contact_name, contact_email, phone, notes, is_active, default_hourly_rate | name; tarifa oculta sin `view-financials` |
| `users` (+) | `client_id` (FK nullable; usuarios del portal en F5) | |
| `work_schedules` | user_id, valid_from, valid_to, mon…sun_minutes | (user_id, valid_from); se usa el horario vigente en cada fecha |
| `projects` | client_id (null si es interno), name, code (único, en mayúsculas), description, color, billing_type (`hour_bank`/`fixed_price`/`time_and_materials`/`internal`), status, start_date, due_date, budget_minutes, fixed_price_amount, hourly_rate, owner_user_id | client_id, status, owner |
| `project_members` | project_id, user_id, **is_manager**, **alert_preferences** (json) | PK compuesta (D-005, D-023) |
| `hour_banks` | project_id, name, department_id, total_minutes, hourly_rate, price_amount, start_date, end_date, status (`active`/`exhausted`/`closed`/`renewed`), overage_policy (`inherit`/`allow`/`block`), renewed_from_id, invoice_reference, notes, closed_at, closed_by; **caché:** consumed_minutes, overage_minutes | project_id, status |
| `hour_bank_alerts` | hour_bank_id, kind (`threshold_75`/`_90`/`_100`/`overage`), notified_on (fecha) | único (bank, kind, fecha): umbrales una sola vez y exceso como máximo uno al día |
| `task_types` | name, color, icon, department_id, is_billable_default, is_active, position | |
| `task_statuses` | name, color, category (`todo`/`in_progress`/`done`), position, is_default | |
| `tasks` | project_id, hour_bank_id, parent_task_id (1 nivel), title, description (HTML saneado), task_type_id, status_id, priority, assignee_user_id, start_date, due_date, estimated_minutes, is_billable, is_milestone, position, completed_at, created_by | (project_id, status_id, position), (assignee_user_id, due_date), hour_bank_id, parent |
| `task_watchers` | task_id, user_id | PK compuesta |
| `task_comments` | task_id, user_id, body (HTML saneado), mentioned_user_ids (json) | + `comment_reactions` (comment_id, user_id, emoji, único) |
| `attachments` | polimórfico (tarea o comentario), user_id, disk, path, original_name, mime, size, thumbnail_path | disco privado; descarga con URL firmada y política |
| `time_entries` | user_id, task_id, project_id y hour_bank_id (**desnormalizados**), date, minutes, **overage_minutes** (D-019), started_at, ended_at, description, is_billable, hourly_rate_snapshot, hourly_cost_snapshot, status (`draft`/`submitted`/`approved`/`locked`), approved_by, approved_at, locked_at, created_by (si imputa otra persona) | (date, user_id), (project_id, date), hour_bank_id, task_id, (user_id, status) |
| `active_timers` | user_id (PK), task_id, started_at, warned_at | uno por usuario |
| `timesheet_periods` | user_id, week_start (lunes), status (`open`/`submitted`/`returned`/`approved`/`locked`), submitted_at, reviewed_by, reviewed_at, review_comment | único (user_id, week_start) |
| `notifications` | tabla estándar de Laravel | |

## Reglas de negocio (servicios de dominio, con tests exhaustivos)
- **`HourBankLedger::recalculate(bank)`**
  - **Bloqueo:** dentro de una transacción con `lockForUpdate` sobre la bolsa.
  - **Orden:** recorre las entradas en orden cronológico (`date`, `created_at`, `id`).
  - **Entradas bloqueadas:** conservan su `overage_minutes` (nunca cambian).
  - **Entradas no bloqueadas:** `overage = max(minutes − saldo disponible, 0)`.
  - **Actualiza la caché:** `consumed_minutes` (todas las entradas, en cualquier estado; SPEC §8.4) y `overage_minutes`.
  - **Estado:** pasa de `active` a `exhausted` y vuelve si se libera saldo.
  - **Avisos:** dispara los de umbral pendientes.
  - **Cuándo se ejecuta:** al crear, editar o borrar cualquier entrada de la bolsa, y al cambiar el total.
- **Política de exceso:**
  - **Política efectiva:** `inherit` usa el ajuste `allow_hour_bank_overage`.
  - **`block`:** rechaza la imputación que supera el saldo, con un mensaje que indica el disponible en h:mm y sin recortarla.
  - **`allow`:** la entrada guarda su exceso y el usuario ve un aviso no bloqueante.
- **Validaciones de imputación (SPEC §7), que dan errores y avisos:**
  - **Errores:**
    - proyecto archivado,
    - bolsa cerrada o renovada,
    - bolsa de otro departamento,
    - más de 24 h en el día,
    - fecha futura (según ajuste),
    - semana enviada, aprobada o bloqueada,
    - no ser miembro del proyecto.
  - **Avisos:**
    - tarea completada,
    - más de un 25 % sobre la capacidad del día,
    - bolsa agotada, cuya imputación irá como exceso.
  - **En nombre de otro:** gestores (en sus proyectos), responsables (su departamento) y admin, con registro en la auditoría.
- **Temporizador:**
  - uno por usuario; iniciar otro para el anterior,
  - al parar, redondeo según `timer_rounding_minutes` (al más cercano; menos de 1 min se descarta con aviso),
  - **si cruza la medianoche de Europe/Madrid, se parte en entradas por día**,
  - aviso cuando pasa de `timer_warning_hours` (tarea programada cada hora más indicador en la cabecera).
- **Duración:** un parser en PHP y otro en TS equivalentes (`1:30`, `1.5`, `1,5`, `90m`, `1h30`, `2h`), con tests compartidos.
- **Hoja semanal:**
  - **Rejilla:** tareas × días, editable. Si una celda tiene una sola entrada, se edita directamente; si tiene varias, se abre su detalle.
  - **Totales:** por día y semana frente a la capacidad, calculada con el `WorkSchedule` vigente. Festivos y ausencias llegan en la F3.
  - **Copiar tareas** de la semana anterior.
- **Aprobación (D-020):**
  - enviar pasa las entradas a `submitted`,
  - un responsable del departamento del usuario (cualquiera de ellos, D-024) aprueba o devuelve con comentario (semana `returned` y entradas de vuelta a `draft`),
  - responsables y administradores se aprueban solos; un usuario sin departamento lo aprueba un admin; si el ajuste está desactivado, todo se aprueba al enviar,
  - **al aprobar se congelan** la tarifa (bolsa > proyecto > cliente > usuario) y el coste,
  - **bloquear** (solo admin, por cliente o proyecto y un rango de fechas) deja las entradas en `locked`, editables solo por el admin y con auditoría,
  - reabrir queda auditado.
- **Tareas:**
  - estimación del padre = suma de las subtareas (solo lectura),
  - al mover una tarea de bolsa o proyecto **no se mueven las horas** (aviso antes de confirmar),
  - `completed_at` se fija cuando el estado es de categoría `done`,
  - las menciones notifican.
- **Renovación:** crea una bolsa nueva con los mismos parámetros (editables) y `renewed_from_id`. La anterior pasa a `renewed`. Opcionalmente se mueven las tareas abiertas; **las horas nunca**.
- **Cierre manual:** se registra el saldo no consumido.
- **Horas comprometidas:** suma de `max(estimada − imputada, 0)` de las tareas abiertas de la bolsa, con aviso si consumido + comprometido supera el total.

## Permisos (políticas, cubiertas por una matriz de tests por rol)
- **Proyectos y clientes:**
  - **ver:** todos los internos (D-021),
  - **crear:** admin y responsables (D-022),
  - **gestionar un proyecto:** admin, responsables y sus gestores.
- **Tareas:** crear y editar, los miembros del proyecto y quienes lo gestionan; comentar, cualquier interno que lo vea.
- **Bolsas:**
  - % de consumo visible para todos,
  - detalle por persona y datos económicos, solo para gestores, responsables y admin (la parte económica, con `view-financials`),
  - crear, renovar y cerrar, quienes gestionan el proyecto,
  - `view-hour-banks` pasa a incluir a los gestores de proyecto.
- **Horas (D-021):**
  - el empleado ve las suyas,
  - el responsable, las de su departamento (y las aprueba),
  - el gestor, las de sus proyectos,
  - el admin, todas.
  - Costes y tarifas solo con `view-financials`.

## Pantallas y rutas (URLs en español)
- **Clientes:** `/clientes` (lista, búsqueda y filtro de activos) y `/clientes/{id}` (ficha: datos, proyectos, bolsas activas con consumo, horas del mes y del año).
- **Proyectos:**
  - `/proyectos` (filtros: cliente, estado, tipo, responsable y departamento implicado),
  - ficha `/proyectos/{id}/{pestaña}` con **resumen, tareas (lista y kanban), bolsas, horas, archivos y ajustes (miembros y gestores con sus alertas)**,
  - Gantt y Chat se ven como pestañas «Fase 4» y «Fase 6»,
  - la vista **Calendario** de tareas pasa a la F4, junto al Gantt.
- **Tareas:**
  - panel lateral de edición (`?tarea=id`, sin página nueva) y creación rápida en línea,
  - `/tareas/{id}` redirige a su proyecto con el panel abierto,
  - `/mis-tareas` con las secciones Hoy, Esta semana, Próximas, Vencidas y Sin fecha.
- **Bolsas:** `/bolsas` (vista global ordenada por % de consumo) y `/proyectos/{id}/bolsas/{bolsa}` (detalle: consumo por semana, por persona, por tipo y entradas).
- **Horas:**
  - `/horas` (hoja semanal `?semana=2026-W40` y entrada manual),
  - `/horas/aprobaciones` (responsables y admin),
  - `/horas/bloqueo` (admin).
- **Temporizador:** en la cabecera, con play y stop en cada tarea.
- **Administración:** `/admin/usuarios` (con jornadas), `/admin/departamentos`, `/admin/tipos-de-tarea`, `/admin/estados` y `/admin/ajustes`.
- **Notificaciones:** campana en la cabecera y `/notificaciones`.
- **Adjuntos:** `/adjuntos/{id}`, con URL firmada y política.
- **Exportación** a XLSX y CSV de la pestaña de horas: **F2** (Laravel Excel, con los informes).
- **Inicio:** tarjetas de la F1 activas: tareas de hoy y de la semana con temporizador, temporizador activo, horas de hoy y de la semana frente a la capacidad, estado de la hoja semanal y días sin imputar.

## Notificaciones en la F1
- **Umbrales de bolsa** (75/90/100, configurables): en la app y por **email por cola** (log hasta tener SMTP), a los gestores que tengan la alerta activada (D-023), a los responsables del departamento de la bolsa y a los admins.
- **Exceso:** como máximo un aviso al día por bolsa.
- **Solo en la app:** asignación de tarea, mención, comentario en una tarea que sigo, horas devueltas o aprobadas, y temporizador de más de X horas.
- **Mensaje de sistema en el chat del proyecto:** llega con el chat (F6).
- **Preferencias por canal y resúmenes:** F7.

## Decisiones tomadas en autonomía para la Fase 1
Resuelven las 29 dudas del análisis de requisitos. Se registrarán en `docs/DECISIONES.md` como D-027 y siguientes.

**Tareas y proyectos**
- **Tareas:** las crean, editan, mueven y borran los miembros del proyecto, sus gestores, los responsables y los admins. Comenta cualquier interno. D-022 («los empleados no crean») se refiere solo a clientes y proyectos.
- **Gestores:** `owner_user_id` es el **gestor principal** y es obligatorio. Siempre es miembro con `is_manager`; los co-gestores se marcan con `is_manager`. El filtro «responsable» del listado usa `owner`.
- **Proyecto interno** (`billing_type = internal`): cualquier interno activo puede imputar sin ser miembro, para que todos puedan imputar reuniones.

**Aprobación y bloqueo de horas**
- **Bloqueo:** solo el admin, por cliente o proyecto y un rango de fechas («al facturar»). Desbloquear también solo el admin, con auditoría.
- **Reapertura:**
  - una semana enviada la puede **retirar** el propio usuario mientras no esté revisada,
  - una aprobada la reabre quien aprueba (o un admin),
  - una bloqueada, solo el admin.
  - El admin puede aprobar o devolver cualquier semana.
- **Semana devuelta:** las entradas vuelven a `draft`. El comentario va en `timesheet_periods.review_comment` y el historial en activitylog.
- **Casos límite:** se aprueba sola la semana de quien tenga el **rol** de responsable o admin. Si el departamento no tiene responsables, aprueba un admin.

**Cálculo de las bolsas**
- **Exceso retroactivo:** el recálculo es cronológico (D-019). Una entrada con fecha anterior puede pasar exceso a entradas posteriores no bloqueadas. El aviso «irán como exceso» se muestra si la bolsa ya está agotada al imputar.
- **Exceso de la bolsa** = suma de `overage_minutes` de sus entradas (lo facturable). Editar el total o la política recalcula. Con `block` solo se impiden imputaciones nuevas por encima del saldo; el exceso que ya existe se mantiene.
- **Temporizador en una bolsa `block` sin saldo:** no se puede iniciar. Si al pararlo supera el saldo, no se guarda: se abre un diálogo para ajustar la duración o cambiar de tarea, sin perder el tiempo medido.
- **Alertas:**
  - una bolsa sin departamento avisa a gestores y admins,
  - cada umbral avisa **una sola vez** por bolsa,
  - las preferencias de los admins llegan en la F7.
- **Estados:**
  - una bolsa agotada vuelve a activa si baja el consumo,
  - el admin puede reabrir una bolsa cerrada (si no está renovada),
  - «próxima a agotarse» = a partir del primer umbral (75 %),
  - el exceso se muestra en el diálogo de renovar, solo como información.
- **Barra de la bolsa:** verde por debajo del primer umbral configurado, ámbar hasta el 100 % y rojo desde el 100 %.
- **Vista global de bolsas:** los gestores ven las de sus proyectos; responsables y admins, todas. Incluye las agotadas; las cerradas y renovadas, con un filtro.

**Imputación de horas**
- **Imputar por otro:** el gestor solo en sus proyectos; el responsable solo para su departamento; el admin, para cualquiera. La persona destino debe cumplir las reglas (ser miembro y el departamento de la bolsa).
- **Tarifas y precios:** solo quien tiene `view-financials` los ve y los fija. `invoice_reference` no es un dato económico.
- **Capacidad en la F1:** solo `WorkSchedule`. Nuevo ajuste `default_work_minutes` (L-V 8 h, S-D 0). Cada usuario nuevo recibe un horario con ese valor desde su alta.
- **Temporizador:**
  - iniciar otro para el anterior y lo imputa,
  - redondeo al múltiplo más cercano; si queda en 0, se descarta con aviso,
  - aviso de más de X h en la app y en la cabecera,
  - pararlo en una semana ya enviada da un error con explicación.
- **Duración:** también acepta `1,5`, `2h` y un número suelto (= horas), con una vista previa en vivo («= 1:30»). Máximo 24 h.
- **Hoja semanal:** una celda con varias entradas abre su lista para editarlas. «Copiar la semana anterior» copia las filas (tareas), no las horas.
- **Instantáneas de tarifa y coste:** se congelan siempre que una entrada pasa a `approved`, también cuando se aprueba sola. Reabrir las borra; las bloqueadas nunca cambian.

**Detalles de tareas, proyectos y adjuntos**
- **Mis tareas:**
  - vencidas = `due` anterior a hoy,
  - hoy = `due` o `start` hoy,
  - esta semana = `due` hasta el domingo,
  - próximas = el resto con fecha,
  - sin fecha.
  Solo tareas abiertas asignadas a mí.
- **Subtareas:** mismo proyecto y **misma bolsa que el padre** (forzado). El padre puede tener horas propias. La estimación del padre es la suma de las subtareas (las que no tienen estimación cuentan 0).
- **Departamento implicado:** el proyecto tiene un miembro, una bolsa o un tipo de tarea de ese departamento.
- **Resumen del proyecto:** estimación = suma de las estimaciones (más el presupuesto aparte, si existe).
- **Estados:** «En revisión» y «Bloqueada» tienen categoría `in_progress`.
- **Borrado:** no se borra nada que tenga horas. Las tareas con horas no se borran; los proyectos se archivan; los clientes se desactivan.
- **Adjuntos:**
  - formatos permitidos: imágenes, PDF, ofimática (Office y ODF), texto, CSV y ZIP,
  - miniaturas solo de imágenes rasterizadas (Imagick con límites),
  - los SVG nunca se muestran en la página, siempre se descargan.
- **Zona gris del SPEC:**
  - **Entra en la F1:**
    - pestañas Horas (sin exportar) y Archivos, y la actividad reciente del Resumen,
    - horas del mes y del año del cliente,
    - gráficas del detalle de bolsa,
    - tarjetas de Inicio de la F1,
    - búsqueda de clientes, proyectos y tareas,
    - reacciones,
    - notificaciones de tareas y horas en la app,
    - `is_milestone` y `users.client_id`.
  - **Pasa a otra fase:** calendario de tareas y próximos hitos a la F4; exportación a la F2; campana en tiempo real a la F6 (en la F1, recuento en cada navegación y consulta cada 60 s).

## Ficheros clave y lo que se reutiliza
- **Se amplían:**
  - `app/Models/User.php` (relaciones con proyectos, horario y temporizador),
  - `app/Models/Setting.php` (`default_work_minutes`),
  - `app/Providers/AppServiceProvider.php` (gates: `view-hour-banks` para gestores; `preventLazyLoading`),
  - `app/Enums/Permission.php`, `routes/web.php`, `app/Console/Commands/InstallCommand.php`, `app/Search/GlobalSearch.php` (nuevas fuentes: clientes, proyectos y tareas, con el mismo patrón que `PeopleSource`),
  - `resources/js/components/app-header` / `app-sidebar` (temporizador y campana), `resources/js/pages/home.tsx` (tarjetas de la F1),
  - `lang/es.json`, `database/seeders/*`, `.github/workflows/ci.yml` (nuevos E2E).
- **Se reutilizan:**
  - `resources/js/components/charts/*` (`HourBankMeter`, umbrales y colores de carga),
  - `resources/js/components/styleguide/duration.ts` (se convierte en el parser oficial `lib/duration.ts` con su gemelo PHP `App\Support\Duration`),
  - los patrones de tabla y de insignias de estado de la styleguide,
  - `lib/format.ts`, `lib/i18n.ts`, `SessionTerminator` (al desactivar), `ResetPasswordNotification` (patrón de email por cola) y el middleware `active`, `internal` y `2fa`.
- **Nuevos** (dominio): `app/Domain/HourBanks/HourBankLedger.php`, `app/Domain/Time/{TimeEntryValidator, TimerService, TimesheetService, ApprovalService}.php`, `app/Policies/*`, `app/Http/Controllers/{Clients,Projects,HourBanks,Tasks,Time,Admin}/*`, `resources/js/pages/{clientes,proyectos,bolsas,horas,mis-tareas,notificaciones}/*`, `resources/js/types/domain.ts`.
- **Documentación:** `docs/DECISIONES.md` (modo autónomo, D-002 modificada, copias y D-027+), `docs/PROGRESO.md`, `CLAUDE.md`, `docs/SERVIDOR-CAMBIOS.md` y `docs/RUNBOOK-DESPLIEGUE.md` (volcado nocturno).

## Tests (definición de hecho, §19)
- **Pest, reglas de bolsas:** todas las reglas de §8 más D-019:
  - una entrada que cruza el límite queda con el exceso correcto,
  - recálculo al borrar, editar o imputar con fecha anterior,
  - las bloqueadas no cambian,
  - `block` rechaza con el saldo disponible,
  - `inherit` sigue el ajuste global,
  - restricción de departamento,
  - bolsa cerrada o renovada,
  - renovación: las tareas se mueven y las horas no,
  - cierre con saldo registrado,
  - estados agotada y activa,
  - alertas una sola vez y exceso como máximo uno al día,
  - horas comprometidas y su aviso.
- **Pest, imputación y aprobación:** todas las validaciones de §7, el temporizador (redondeo, medianoche de Madrid con cambio de hora, uno por usuario, iniciar otro), duraciones, la hoja semanal cuadra con las entradas, la aprobación de D-020 (casos límite, instantáneas, devolución y reapertura), bloqueo solo del admin y auditoría de las 4 entidades obligatorias.
- **Pest, permisos:** matriz por rol de rutas y políticas (D-021, D-022). Clientes siempre fuera. Datos económicos ocultos sin `view-financials`. Búsqueda filtrada por permisos.
- **Rendimiento:** `Model::preventLazyLoading()` en local y testing, para detectar N+1 en los tests.
- **Vitest:** parser de duración (compartido con PHP), hoja semanal, kanban con teclado, panel de tarea, temporizador de la cabecera, bolsas y avisos.
- **Playwright:** crear cliente, proyecto, bolsa y tarea; temporizador; enviar la semana; aprobarla el responsable; consumo actualizado. Con otro recorrido: bolsa agotada en `allow` (exceso) y en `block` (rechazo).
- **Seeders:** los realistas de §15 (3 departamentos, 10 usuarios, 8 clientes, 15 proyectos, bolsas en todos los estados y 12 meses de horas), **solo en local, testing y CI**. En el servidor, nunca (D-018).

## Pasos de sistema autorizados en esta fase
1. **Volcado nocturno de PostgreSQL:** unidad y timer `audax-backup` (03:40, `nice` e `ionice idle`, 7 copias, verificación con `pg_restore --list`) en `/var/backups/audax`.
2. **Subida de 50 MB:** prueba real tras desplegar los adjuntos. Si ModSecurity o nginx la bloquean, se ajusta solo para este dominio desde Plesk.
3. **Colas:** Horizon con la cola `mail` (ya existe). Sin cambios de sistema.

## Verificación (aceptación de la Fase 1)
- **Local:** Pest, Vitest, `tsc`, `vp check` y la compilación en verde. PHPStan en nivel 7.
- **Servidor:** `desplegar-dev.sh --tests` con todos los tests en PostgreSQL 18, `/health` correcto, batería V y **35/35 webs iguales**.
- **CI:** los 4 trabajos en verde en GitHub. La leo yo con `gh` tras el `gh auth login` del propietario.
- **Comprobación manual en el navegador integrado** (claro, oscuro y móvil), recorriendo el flujo completo de bolsa, tarea, temporizador y aprobación.
- **Revisión adversarial** de la fase integrada antes de cerrarla.
- Criterios de §17 (adaptados por D-019) marcados en `PROGRESO.md`. Después se fusiona en `main` con la etiqueta `fase-1-cerrada` y se pasa a la Fase 2 sin esperar.

---

# Contrato técnico (entrega 1.1, cerrada el 26/09)

Todo lo que sigue ya está en la rama `fase-1`. Las entregas 1.2 a 1.6 construyen SOBRE este contrato: no lo cambian sin anotarlo aquí.

## Dominio (backend)
- **Enums** (`app/Enums`): `BillingType`, `ProjectStatus`, `HourBankStatus`, `OveragePolicy`, `TaskPriority`, `TaskStatusCategory`, `TimeEntryStatus`, `TimesheetStatus` y `ProjectAlert`. Cada uno con `label()` en español.
- **Modelos** (`app/Models`):
  - `Client`, `WorkSchedule`, `Project` (+ pivote `ProjectMember`), `HourBank`, `HourBankAlert`, `TaskType`, `TaskStatus`, `Task`, `TaskComment`, `CommentReaction`, `Attachment`, `TimeEntry`, `ActiveTimer`, `TimesheetPeriod` y `TimeEntryLock`,
  - auditoría con el trait `LogsDomainActivity` y datos económicos ocultos con `HasFinancialAttributes`.
- **`User`:** `projects()`, `managedProjects()`, `managedDepartmentIds()`, `managedProjectIds()`, `isMemberOf()`, `isManagerOf()`, `canManageProject()`, `supervises()`, `canSeeHoursOf()`, `isDepartmentManager()`, `activeTimer()` y `workSchedules()`.
- **`App\Domain\HourBanks\HourBankLedger`:** la ÚNICA pieza que escribe el consumo, el exceso y el paso activa ↔ agotada. Emite `HourBankThresholdReached` y `HourBankOverageRecorded` (tras el commit). `TimeEntry` y `HourBank` (al cambiar el total) lo invocan solos.
- **`App\Domain\Time\TimeEntryWriter`:** la ÚNICA vía para crear, editar y borrar entradas. Aplica `TimeEntryRules` (SPEC §7 y §8) con las bolsas bloqueadas y devuelve `TimeEntryResult` (la entrada y sus avisos).
  - **Nunca** `TimeEntry::create()` fuera de tests y seeders.
- **`App\Domain\Time\TimerService`:** `start`, `stop` (admite otra duración u otra tarea) y `discard`, con redondeo, partición a medianoche en Madrid y el temporizador intacto si la imputación falla.
- **`App\Domain\Time\Capacity`:** capacidad por fecha según `WorkSchedule`, o el ajuste `default_work_minutes`.
- **Utilidades:**
  - `App\Support\Duration` (gemelo de `resources/js/lib/duration.ts`, con los casos de `tests/fixtures/duration-cases.json`),
  - `LocalTime` («hoy» es hoy en Madrid),
  - `RichText` (saneado del HTML de Tiptap y menciones).
- **Políticas** (`app/Policies`): `Client`, `Project`, `HourBank`, `Task`, `TaskComment`, `Attachment`, `TimeEntry` y `TimesheetPeriod`.
- **Gates:** `view-hour-banks` (también los gestores), `approve-time`, `lock-time`, más los de Fase 0.
- **Resources** (`app/Http/Resources`): son el contrato con `resources/js/types/domain.ts`. Los importes solo aparecen con `view-financials`.
- **Notificaciones:** base `App\Notifications\AppNotification` (kind, title, body, url e icon; database por la cola `default`, mail por la cola `mail`).
- **Mensajes del backend:** `lang/es/<área>.php` (ya existe `time.php`).

## Frontend
- **Props compartidas:** `timer` (temporizador activo), `notifications.unread`, `config` (umbrales de bolsa y ajustes del temporizador) y `auth.can` (crear clientes y proyectos, aprobar, bloquear, gestionar usuarios y ajustes).
- **Utilidades (`lib`):**
  - `duration.ts`: `parseDuration` (con máximo para estimaciones) y `roundToNearest`,
  - `week.ts`: semanas ISO y hoy en Madrid,
  - `urls.ts`: URLs entre áreas. **Para enlazar a otra área se usa `urls`, no Wayfinder.**
- **UI (`components/ui`):** tabs, popover, progress, switch, radio-group, textarea, table y calendar (react-day-picker en español, desde el lunes).
- **Componentes de dominio:**
  - `components/domain/{duration-input, date-picker, badges}.tsx`,
  - `components/projects/project-shell.tsx`: cabecera y pestañas del proyecto; cada pestaña es su propia página,
  - `components/time/{timer-button, time-entry-dialog}.tsx`: esqueletos con las props definitivas, que implementa el área Horas.
- **Textos:** cada área escribe en `lang/ui/<área>.json`. `lang/es.json` no se toca; cada clave vive en un solo fichero (test).
- **Tipos:** `types/domain.ts` (entidades) y `types/<área>.ts` (props de las páginas del área).
- **Rutas:** `routes/app/<área>.php`, dentro del grupo `auth`, `active`, `internal` y `2fa`.

## Reparto de la implementación (worktrees en paralelo)
| Área | Agente | Ficheros propios |
|---|---|---|
| Administración y clientes | A | `routes/app/{admin,clients}.php`, `Http/Controllers/{Admin,Clients}`, `pages/{admin,clients}`, `lang/ui/{admin,clients}.json`, `types/{admin,clients}.ts`, `InstallCommand` |
| Proyectos y bolsas | B | `routes/app/{projects,hour-banks}.php`, `Http/Controllers/{Projects,HourBanks}`, `pages/{projects/{index,summary,settings,hour-banks},hour-banks}`, `Listeners` y `Notifications` de bolsas, `lang/ui/{projects,hour-banks}.json` |
| Tareas | C | `routes/app/tasks.php`, `Http/Controllers/Tasks`, `pages/{projects/{tasks,files},my-tasks}`, `components/tasks`, notificaciones de tareas, `lang/ui/tasks.json` |
| Horas | D | `routes/app/time.php`, `Http/Controllers/Time`, `pages/{time,projects/time}`, `components/time`, cabecera con temporizador, `HomeController` y `home.tsx`, `routes/console.php`, notificaciones de horas, `lang/ui/time.json` |
| Cierre | Yo | campana y `/notificaciones`, búsqueda, seeders, E2E, integración, revisión y despliegue |
