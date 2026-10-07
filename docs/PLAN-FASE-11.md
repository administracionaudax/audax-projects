# Plan de la Fase 11: «Personas», el módulo de RR. HH. que sustituye a Woffu

_Diseño del 06/10/2026 (antes `PLAN-RRHH.md`) · **aprobado** con las respuestas del propietario del 07/10/2026 (§14.1) · rama `rrhh-r1` (sale de `fase-10`) · **entrega en curso: R1 · Registro de jornada** (§0) · las dudas de derecho laboral siguen abiertas para la asesoría (`WOFFU-INVESTIGACION.md` §F)_

> **En una frase.** Audax Proyectos ya tiene ausencias, jornadas, festivos, avisos, auditoría y RGPD, pero **no tiene registro de jornada**, que es lo único que la ley exige y Woffu hace. Proponemos un módulo «Personas» que funcione como Woffu (fichar, el diario de cada día, solicitudes, saldos, aprobaciones y cierres mensuales), con el aspecto de Audax, y que empiece por lo legalmente imprescindible: **un registro de jornada que no se pueda alterar sin dejar huella, que cada persona pueda consultar y descargar y que se pueda entregar a la Inspección al momento**.

**Documentos relacionados:**
- `docs/WOFFU-INVESTIGACION.md`: requisitos legales L-01 a L-24, recomendaciones B, dudas F para la asesoría y la segunda pasada G (estado del real decreto, sanciones verificadas y jurisprudencia).
- `docs/WOFFU-INVENTARIO.md`: inventario funcional de Woffu (W-001…), con lo que es esencial, útil o sobra para Audax.

---

## 0. Estado de la fase y contrato de R1

| Entrega | Estado |
|---|---|
| **R1 · Registro de jornada** | **En curso** (rama `rrhh-r1`). Decisiones **D-330 a D-345** |
| R2 · Acceso, cierres e Inspección | Pendiente. **R1 y R2 van juntas a producción**: el módulo sigue apagado hasta tener las dos |
| R3 · Vacaciones y permisos | Pendiente |
| R4 · Comodidades, R5 · Migración y baja de Woffu, R6 · Opcional | Pendientes |

### 0.1 Validación del modelo antes de construir (07/10/2026)
Se ha repasado el modelo de §8 contra la ley de hoy, el borrador del RD (§2 y `WOFFU-INVESTIGACION.md` G.1) y las respuestas de §14.1. Cambios frente al modelo orientativo, todos en la dirección más conservadora:

| Punto | §8 decía | R1 hace | Por qué |
|---|---|---|---|
| Anulaciones | Tabla aparte `clock_event_voids` | Fila `void` en la **misma** cadena de `clock_events` | Una anulación también tiene que tener huella: si fuera a otra tabla sin encadenar, borrarla devolvería en silencio un fichaje anulado (D-332) |
| Correcciones | Tabla con estado editable | `clock_corrections` **sin DELETE** y sin cambios una vez decidida (trigger), sellada con su huella al decidirse | «Huella clara e indeleble» de la autoría y la conformidad, también de las discrepancias, que no generan fichajes (D-335) |
| Rechazo | Estados `rejected` y `disputed` distintos | Rechazar con motivo o no contestar en 7 días deja la corrección **«en discrepancia»** | El borrador solo distingue acuerdo o discrepancia; las dos versiones quedan y cuenta la original (D-335) |
| Autoaprobación | — | **Nadie acepta su propia corrección**, tampoco un responsable o un admin | La doble conformidad no admite la excepción de las ausencias (D-049) (D-335) |
| Pausas | Tabla `pause_types` | Un único tipo, **comida**, que no computa (enum ampliable) | P3: solo se ficha la comida (D-334) |
| Verano | Tabla `schedule_profiles` compartida | Dentro de **cada versión** de `work_schedules` | Un perfil compartido, al editarse, reescribiría la jornada teórica de veranos pasados (D-336) |
| `workdays` | Tabla de resumen | Se calcula al vuelo (`WorkdayCalculator`) | Con 10 personas no hace falta; R2 congela los totales en el cierre mensual |
| `manage-people` | En R2 | **Ya en R1** | La bandeja y la jornada de la plantilla de RR. HH. lo necesitan (P5: Toni) (D-330) |
| Quién ficha | La plantilla | La plantilla interna **sujeta al registro**; colaboradores externos, no | D-331 |

**Reglas legales críticas comprobadas** (y cubiertas por los tests de `tests/Feature/People`): inicio y fin diarios con la hora del servidor (L-01, L-02); teletrabajo como modo de cada tramo (L-03); nada se sobrescribe ni se borra, ni siquiera con acceso a la base de datos sin dejar rastro (L-14 y borrador); las correcciones con autor, fecha, motivo y conformidad o discrepancia (borrador); el registro de la persona solo lo ven ella, su responsable y RR. HH. (L-11); los límites de jornada como incidencias que avisan sin bloquear (L-09); todo el exceso queda registrado para las horas extra (P6, L-07); nunca se crean fichajes a partir de las horas imputadas (§3.2).

### 0.2 Contrato de datos de R1
- **`clock_events`** (solo alta; *trigger* en PostgreSQL y en SQLite contra UPDATE y DELETE, y contra TRUNCATE en PostgreSQL; el modelo también lo impide): `user_id` (FK *restrict*: no se puede borrar a la persona), `seq` (1, 2, 3… por persona, único), `kind` (`clock_in`, `pause_start`, `pause_end`, `clock_out` o `void`), `occurred_at` (UTC; la hora del servidor salvo en lo que añade una corrección aceptada), `recorded_at` (UTC, hora del servidor), `work_mode` (`on_site`, `remote`), `pause_type` (`meal`), `source` (`web`, `pwa`, `correction`), `voided_event_id` (lo que anula una fila `void`), `correction_id`, `created_by`, `ip_hash` (HMAC, no la IP), `user_agent` (corto), `prev_hash` y `hash` (SHA-256 de una cadena canónica `v1`).
- **`clock_corrections`**: `user_id`, `date` (día de Madrid de la jornada), `proposed_by`, `reason`, `voids` (ids de fichajes efectivos que anula), `adds` (fichajes que propone: tipo, instante UTC, modo), `status` (`pending`, `accepted`, `disputed`, `withdrawn`), `decided_by`, `decided_at`, `decision_note`, `dispute_reason` (`rejected` o `no_answer`), `hash` (sello al decidirse). Sin DELETE; solo se cambia mientras está pendiente.
- **`work_schedules`** (+ columnas): `start_time_from` y `start_time_to` (margen de entrada), `expected_pause_minutes`, `summer_starts_on` y `summer_ends_on` (`MM-DD`), `summer_week` (7 valores en minutos) y `summer_expected_pause_minutes`.
- **`employment_profiles`** (1:1 con `users`): `hire_date`, `termination_date`, `subject_to_register` y `register_exemption_reason`. R2 y R3 añaden aquí la retención por litigio, el NIF, el contrato y el calendario.
- **`clock_reminders`**: un aviso de entrada, salida o jornada sin cerrar por persona, día y tipo.
- **Servicios** (`App\Domain\People`): `ClockWriter` (el único que escribe fichajes), `ClockState` (estado actual), `RegisterHasher` y `RegisterIntegrity` (la cadena y su comprobación, con `php artisan people:verify-register`), `WorkdayCalculator` (diario, totales e incidencias), `ClockCorrectionService`, `CorrectionApprovers`, `PeopleAccess` (quién ficha y quién ve a quién) y `ClockReminders`.

### 0.3 Lo que R2 necesita y R1 ya deja listo
- **Cierre mensual con confirmación** (`month_closes`: persona, mes, totales congelados, PDF con SHA-256, confirmación o desacuerdo y desconfirmación con motivo): el diario se calcula siempre igual a partir de la cadena, así que el cierre solo congela lo que `WorkdayCalculator` ya devuelve; `ClockCorrectionService` tiene un único punto (`assertDayOpen`) donde R2 impedirá corregir un mes confirmado.
- **Exportación con huella para la persona y la Inspección**: cada fichaje lleva su huella y su historial (original, anulado y añadido, con autor, fecha, motivo y conformidad); la exportación solo tiene que listar la cadena y su SHA-256.
- **Retención de 48 meses**: nada borra fichajes ni correcciones (los *triggers* lo impiden y la FK de la persona es *restrict*). R2 añade la supresión a partir del mes 49 en `app:prune-data` (quitando el *trigger* solo dentro de esa orden, con la retención por litigio en `employment_profiles`), el ancla diaria (`register_anchors`) y la comprobación nocturna de la cadena con aviso a los admins.
- **Horas extra y saldo de horas**: R1 registra todo el exceso del día; R2 lo clasifica (extra o flexibilidad) y añade `time_balance_movements`.

---

## Índice
0. Estado de la fase y contrato de R1
1. Objetivos y no objetivos
2. Ley hoy y proyecto: qué condiciona el diseño
3. Registro de jornada frente a horas imputadas
4. Lo que ya existe y en qué nos apoyamos
5. Cómo se parece a Woffu
6. Pantallas por rol
7. Reglas de cálculo
8. Modelo de datos orientativo
9. Permisos
10. Avisos
11. Garantías: inalterabilidad, conservación y acceso
12. Entregas por prioridad
13. Migración desde Woffu
14. Preguntas para el propietario
15. Riesgos

---

## 1. Objetivos y no objetivos

### Objetivos
1. **Cumplir sin margen de sanción** el art. 34.9 ET y lo que le rodea (L-01 a L-16): registro diario de inicio y fin, pausas, horas extra, 4 años de conservación y acceso de la persona, de sus representantes y de la ITSS.
2. **Estar preparados para el real decreto** de registro digital si se aprueba (G.1): todo digital, cambios con huella, acceso remoto de la ITSS y resumen mensual.
3. **Funcionar como Woffu** para que la plantilla no tenga que aprender otra forma de trabajar: fichar con un botón, el «diario» con lo previsto frente a lo hecho, solicitudes con aprobación, saldos de vacaciones, calendario del equipo y confirmación del mes.
4. **No duplicar trabajo**: quien ficha no tiene que volver a contar sus horas para imputarlas, ni al revés (§3).
5. **Dar de baja Woffu** con su histórico de 4 años guardado y los saldos traspasados (§13).

### No objetivos (por ahora)
- **Nóminas y firma electrónica cualificada.** Las hace la gestoría; si acaso, un reparto de documentos más adelante (P7).
- **Turnos y cuadrantes.** Audax trabaja con horario de oficina y flexibilidad.
- **Geolocalización y biometría** (L-12 y L-13).
- **Evaluación del desempeño, formación, objetivos y gastos.** La Weekly y los resúmenes cubren parte del seguimiento.
- **Canal de denuncias** (no es obligatorio por debajo de 50 personas).
- **Apps nativas.** Basta con la PWA (SPEC §18), que ya tiene avisos push.
- **Integración directa con el programa de nóminas** (A3, Sage…): basta un CSV para la gestoría.

---

## 2. Ley hoy y proyecto: qué condiciona el diseño

| | **Es ley hoy** (06/10/2026) | **Es proyecto** (borrador de 2025, revisado y sin publicar) |
|---|---|---|
| Qué se registra | Inicio y fin de la jornada de cada día (art. 34.9 ET). Pausas: no es obligatorio, pero si no se registran se presume trabajo efectivo todo el tiempo entre la entrada y la salida (B-1) | Además: pausas, presencial o a distancia, tipo de hora, tiempos de disponibilidad, interrupciones de la desconexión, medidas de conciliación y totales diarios y mensuales |
| Soporte | Cualquiera fiable y objetivo (L-02) | **Solo digital** |
| Quién registra | No lo dice; lo normal es la persona | La propia persona, «directa, inmediata y personal» |
| Correcciones | Que sea fiable implica que no se pueda manipular sin rastro (L-14) | Huella «clara e indeleble», con la autoría y la conformidad de las dos partes o la discrepancia anotada |
| Conservación | 4 años | 4 años |
| Acceso | Persona, representantes (cada mes, minimizado: STS 1161/2024) e ITSS (al momento en la visita) | ITSS en remoto, de inmediato y en formatos tratables; la persona recibe un resumen con la nómina |
| Horas extra | Día a día, totalizadas con la nómina y copia del resumen (art. 35.5 ET); con el convenio de publicidad, cada semana | Tipo de hora y compensación |
| Tiempo parcial | Totalización mensual y copia con la nómina; si no, se presume jornada completa (art. 12.4.c) | Igual |
| Sanción | Grave, de 751 a 7.500 € por empresa (LISOS 7.5 y 40.1, G.2). El riesgo económico mayor es perder una reclamación de horas extra (STS 372/2026) | Sin cambios conocidos |

**Regla de diseño:** se construye para la columna de la derecha. Cumple la de la izquierda y no cuesta más.

---

## 3. Registro de jornada frente a horas imputadas

### 3.1 Son dos cosas distintas

| | **Registro de jornada** (nuevo) | **Horas imputadas** (lo que ya hay) |
|---|---|---|
| Pregunta | **¿Cuándo** has trabajado? | **¿En qué** has trabajado? |
| Dato | Instantes: entrada, pausas y salida, con la hora del servidor | Minutos por tarea y día (`time_entries`) |
| Para qué | Obligación legal: límites de jornada, descansos y horas extra | Gestión: bolsas, rentabilidad, facturación |
| Quién lo ve | La persona, su responsable, el admin, la ITSS y, si la hubiera, la representación | Según los permisos de proyectos e informes |
| Se puede cambiar | **Nunca se sobrescribe**: solo correcciones con huella y conformidad | Sí, con la semana de horas y los bloqueos |
| Conservación | 4 años como mínimo, y luego se suprime | Indefinida (nunca se borran horas, D-075) |
| Base RGPD | Obligación legal (art. 6.1.c); **no se puede usar para otra finalidad** (L-11) | Interés legítimo y contrato |

**Consecuencia:** las horas imputadas **no** pueden servir de registro de jornada (lo dice el SPEC §18 y la CT 101/2019), y el registro de jornada **no** debe usarse para medir la productividad (AEPD, L-11). Lo normal es que la jornada registrada sea **mayor** que lo imputado: reuniones internas, formación, correo y tiempos muertos.

### 3.2 Cómo evitamos el doble trabajo

Principio: **cada dato se escribe una vez y en su momento; el otro lado solo sugiere, nunca rellena en silencio**.

1. **Un solo sitio en la cabecera.** El botón de fichar va junto al temporizador actual (`TimerChip`), con el estado visible: «Trabajando desde las 9:02», «En pausa (comida) desde las 14:00» o «Jornada cerrada».
2. **Empezar el temporizador sin haber fichado** abre una pregunta de un clic: «No has fichado la entrada. ¿Fichar ahora (9:14)?». Fichar es siempre un gesto de la persona, nunca algo que hace el sistema por ella.
3. **Pausar o cerrar la jornada con el temporizador en marcha** pregunta si también se para el temporizador (sí por defecto). Al volver de la pausa, se ofrece reanudarlo.
4. **«Mi día» y el plan del día** (D-250) ofrecen un «Empezar el día» que ficha la entrada y abre el plan del día.
5. **Al cerrar la jornada**, solo a la propia persona: «Hoy has trabajado 7:50 y has imputado 6:10. ¿Imputar lo que falta?», con enlace a sus horas del día. Es una ayuda para ella, no un control: el responsable **no** ve esa comparación salvo que la asesoría diga que se puede (F-5).
6. **Nunca al revés:** el sistema **no crea fichajes a partir de las horas imputadas** ni de la actividad. El registro tiene que hacerlo la persona en el momento (borrador del RD), y un fichaje deducido de las horas sería justo lo que la ITSS no acepta.
7. **Sin cierres automáticos.** Si alguien olvida fichar la salida, la jornada queda como incidencia «Falta la salida» y la persona propone la hora con una corrección (§7.4).

---

## 4. Lo que ya existe y en qué nos apoyamos

| Pieza | Dónde | Qué se reutiliza | Qué falta |
|---|---|---|---|
| **Ausencias** (D-049, D-088, D-091) | `app/Models/Absence.php`, `Domain/Absences/AbsenceService.php`, `AbsenceApprovers.php`, `AbsenceRules.php`, páginas `absences/index.tsx` y `team.tsx` | Solicitar, aprobar o rechazar con comentario, autoaprobación de responsables y admins, registrar una ya aprobada (bajas), cancelar, solapes, días laborables (`AbsenceDays`), calendario del equipo, avisos y privacidad del tipo (D-088) | Catálogo de tipos (hoy es un enum de 5), saldos, justificantes, unidad (días naturales, laborables u horas), preaviso y un segundo nivel de aprobación |
| **Jornadas versionadas** (D-036) | `WorkSchedule`, `Domain/Admin/WorkScheduleVersions.php` | Versiones sin solapes, minutos por día, tiempo parcial y reducciones | Horario (franjas de entrada y salida, flexibilidad), pausa prevista, jornada de verano y auditoría (no usa `LogsDomainActivity`) |
| **Festivos** (D-050) | `Holiday`, `HolidayImporter`, `SpanishNationalHolidays` | Importación ICS y CSV con vista previa | Calendarios por centro de trabajo; la importación «nacional» no vale para la Comunitat Valenciana (L-23) |
| **Capacidad** | `Domain/Time/Capacity.php` | Jornada − festivos − ausencias aprobadas = la **jornada teórica** de cada día | Nada: es justo lo que Woffu llama «horas teóricas» |
| **Temporizador y horas** | `TimerService`, `TimeEntryWriter`, `TimeEntryRules`, `TimesheetPeriod` | El chip de la cabecera, el aviso de temporizador largo (`timers:warn`) y el flujo enviar, aprobar y devolver de la semana, que sirve de patrón para el cierre mensual | Todo el registro de jornada |
| **Plan del día** (D-250 a D-256) | `DayPlanWriter`, `/dia` | El «Empezar el día» (§3.2.4) | — |
| **Avisos** | `AppNotification` (app, email por la cola `mail` y Web Push), `NotificationCatalog`, `NotificationPreferences` | Avisos de fichaje olvidado, solicitudes y cierres, con preferencias por persona y obligatorios con candado (D-122) | Eventos nuevos (§10) |
| **Tareas programadas** | `routes/console.php`, Horizon | Recordatorios, cierres mensuales y comprobación de la cadena de huellas | Comandos nuevos |
| **Auditoría** (D-074, D-124) | `LogsDomainActivity`, `/admin/auditoria` | Rastro de cambios de configuración, tipos, saldos y permisos | Retención propia: la auditoría del registro no puede bajar de 4 años |
| **RGPD** (D-075, D-125, D-126) | `PrivacyNotice`, `PersonalDataExporter` (ya tiene `AbsencesSection`, `WorkSchedulesSection` y `TimeEntriesSection`), `app:prune-data` | El texto con versión y lectura registrada sirve de patrón para el **documento de implantación** y la **política de desconexión**; secciones nuevas en la exportación | Excluir el registro del borrado antes de 48 meses; bloqueo por litigio (G.5) |
| **Exportaciones** | Gotenberg (PDF) y `openspout` (XLSX y CSV), `TableExporter` | Informes para la ITSS, la persona y la gestoría | Huella SHA-256 en cada fichero |
| **Adjuntos** | `attachments` (polimórfica), `AttachmentStorage` | Justificantes de ausencias | Hoy guarda la ruta por proyecto; hace falta un espacio propio con acceso restringido. El texto RGPD actual dice «nunca justificantes médicos» y habría que cambiarlo |
| **Roles y módulos** | `Role`, `Permission`, `AppModule`, `EnsureModuleEnabled`, doble factor | Módulo nuevo `people`, apagado al crearse; el rol de responsable de departamento ya aprueba ausencias | Un permiso `manage-people` (RR. HH.) y la cuenta temporal de inspección |
| **Personas** | `users`, `UserDeactivator` | Alta, baja lógica y departamento | Datos laborales: alta y baja, NIF, tipo de contrato, centro de trabajo, teletrabajo y convenio |
| **Navegación** | Sección «Personas» de la barra lateral (D-260 y D-261) | Ya existe con Ausencias y Ausencias del equipo | Las entradas nuevas (§6) |

---

## 5. Cómo se parece a Woffu

La plantilla ya sabe usar Woffu. Copiamos **su forma de trabajar** y la mejoramos donde la ley o el sentido común lo piden.

### 5.1 Lo que copiamos tal cual
| En Woffu | En «Personas» | Inventario |
|---|---|---|
| Botón «Entrar» / «Salir» con el horario del día y el tiempo en curso | Botón de fichar en la cabecera y tarjeta «Mi jornada» en Inicio | W-001, W-002, W-132 |
| Elegir motivo al pausar o salir | Tipo de pausa y presencial o a distancia al fichar | W-003, W-074 |
| Olvido → editar la jornada → «Pendiente de validar» → el responsable valida, uno a uno o en bloque | Igual, con el nombre de «corrección» | W-018, W-081 |
| Incidencias: sin fichajes, falta la salida, fichar durante una ausencia | Igual, más las legales (12 h, 6 h y 9 h) | W-083 |
| Original frente a editado y «validado por» | Igual, en el diario de cada día | W-020, W-022 |
| Confirmar las jornadas del mes a primeros de mes; desconfirmar para corregir | Cierre mensual con confirmación | W-084, W-085 |
| Horario tolerante (margen de entrada) con periodos (verano, Navidad) y media jornada en días especiales | `work_schedules` con margen, pausa y perfiles de temporada | W-028, W-030, W-034 |
| Diferencia del día, presencia comparada y categorización del exceso | Igual, sin «limitar el exceso» | W-042, W-046, W-047 |
| Solicitudes con saldos, por días u horas con franja, laborables o naturales | «Ausencias» con catálogo de tipos y saldos | W-054 a W-062 |
| Asignación anual, arrastre con fecha límite y proporcional por contrato | Saldo de vacaciones | W-060 a W-064 |
| Dos niveles de aprobación | Segundo nivel opcional por tipo | W-067 |
| Pedir la cancelación de una solicitud ya aprobada | Igual | W-069 |
| Justificantes e informe de justificantes pendientes | Igual, con acceso restringido | W-071 |
| Informes «Registro mensual de la jornada», «Anexo de horas», «Presencia diaria», «Fichajes», «Saldos» y «Actividad», en PDF, Excel y CSV | Los mismos nombres | W-089 a W-100 |
| Avisos: inicio de jornada, salida no registrada, jornada no finalizada, edición y validación, confirmar el mes | Los mismos | W-110 a W-116 |
| Guía de inspección: protocolo publicado con confirmación de lectura, incidencias validadas, jornadas confirmadas e informes listos | Es el guion de cumplimiento del módulo (§11) | W-088 |

### 5.2 Lo que hacemos mejor que Woffu
- **Una corrección no la valida solo el responsable**: hace falta la conformidad de las dos partes y, si no la hay, queda la discrepancia (borrador del RD; Woffu solo pide la validación del responsable).
- **No se puede «limitar el exceso»** para dejar de contar tiempo trabajado (W-050): el exceso se registra siempre y luego se decide qué es.
- **Sin modo «solo horario»** (W-044): siempre hay inicio y fin reales.
- **Avisos por persona**, no para toda la empresa (W-117 frente a D-122).
- **Integridad demostrable** con la cadena de huellas (§11), que Woffu no explica en su ayuda.
- **Fichar y imputar horas en el mismo sitio** (§3.2): Woffu no sabe en qué se trabaja.

### 5.3 Lo que no copiamos
Biometría, geolocalización y geovallas, QR, PIN, WhatsApp, Slack, Teams y extensión de Chrome; turnos y horarios rotativos; sedes y multiempresa; habilidades; firma electrónica; canal de denuncias; asistente de documentos; idiomas; importadores masivos (salvo para migrar). Motivo de cada uno en `WOFFU-INVENTARIO.md`.

---

## 6. Pantallas por rol

Todas dentro de la sección **«Personas»** de la barra lateral (D-260), con el aspecto de Audax.

### 6.1 Cualquier persona de la plantilla
1. **Cabecera, siempre visible:** botón de fichar junto al temporizador. Estados: «Fichar entrada», «Trabajando 3:12 · Pausa · Salir», «En pausa (comida) 0:24 · Volver» y «Jornada cerrada 7:50». Un clic para lo normal; el tipo de pausa y el modo, con un menú desplegable que recuerda la última elección.
2. **Inicio → tarjeta «Mi jornada»:** hoy (fichajes, trabajado frente a teórico), semana y saldo de horas; incidencias abiertas.
3. **Personas → Mi jornada** (`/personas/jornada`): el **diario** como en «Mi presencia» de Woffu.
   - Lista de días del mes con horario previsto, tramos, pausas, trabajado, diferencia, horas extra, modo e incidencias, más el estado: «Correcta», «Incidencia», «Corrección pendiente», «En discrepancia» o «Confirmada».
   - Al abrir un día: el **historial** de fichajes originales, anulados y añadidos, con quién, cuándo y por qué.
   - «Proponer corrección» desde el día.
   - Pestaña «**Cierre del mes**»: el resumen, «Confirmar» o «No estoy de acuerdo» con un texto, y el PDF.
4. **Personas → Ausencias** (lo que ya existe, ampliado): saldos arriba («Vacaciones 2026: 14 de 22 días disponibles · 3 pendientes de aprobar · 2 arrastrados hasta el 31/03»), solicitar con el catálogo, adjuntar justificante y «Pedir cancelación».
5. **Personas → Calendario:** mi calendario con festivos, días bloqueados y mis ausencias; el del equipo, como hoy.
6. **Personas → Mi registro:** descargar el registro de cualquier periodo en PDF o CSV y los resúmenes mensuales anteriores.
7. **Personas → Documentos:** protocolo de registro de jornada, política de desconexión y calendario laboral del año, con «He leído» (y lo que añada RR. HH.).

### 6.2 Responsable de departamento
1. **Personas → Jornada del equipo:** tabla persona × día de la semana o del mes, con el semáforo de Audax (correcto, incidencia, pendiente) y la **presencia comparada** (teórico frente a trabajado).
2. **Personas → Pendientes:** correcciones y ausencias por aprobar en una sola bandeja, con aprobación en bloque, como el panel de Woffu (W-068 y W-081).
3. **Inicio → tarjeta «Mi equipo hoy»:** trabajando, en pausa, no ha fichado, ausente y no trabaja (W-023), enlazada con «Equipo hoy» del plan del día.
4. **Desconfirmar** el mes de una persona, con motivo.
5. **Ausencias del equipo** (existe) con los saldos de su gente.

### 6.3 Admin o RR. HH. (`manage-people`)
1. **Personas → Registro de la plantilla:** como la del responsable, para todos, con los filtros de departamento, persona y periodo.
2. **Personas → Informes:** «Registro mensual de la jornada», «Anexo de horas», «Presencia diaria», «Presencia mensual», «Fichajes», «Incidencias», «Saldos», «Actividad» y «Justificantes pendientes», en PDF, XLSX y CSV con huella.
3. **Personas → Inspección:** botón «Exportar para la Inspección» (persona o plantilla y rango) y la gestión del acceso temporal de solo lectura (apagado).
4. **Personas → Configuración:** horarios y temporadas, calendarios y festivos, tipos de ausencia, tipos de pausa, reglas de horas extra, días bloqueados, documentos de RR. HH. y avisos.
5. **Ficha de la persona** (`/admin/usuarios/{id}`): pestaña «Laboral» con alta y baja, contrato, jornada, calendario, teletrabajo, saldos y ajustes con motivo, y la retención por litigio.
6. **Personas → Cierres:** estado de los cierres del mes (confirmados, en desacuerdo, sin respuesta) y reenvío del aviso.

### 6.4 Inspección de Trabajo (solo si hace falta)
Una cuenta temporal con doble factor que solo ve «Registro de la plantilla», los informes y la exportación de su ámbito, con un aviso fijo de solo lectura. Cada consulta queda en la auditoría.

---

## 7. Reglas de cálculo

### 7.1 La jornada de un día
- **Jornada teórica** = `Capacity` del día: la jornada vigente (o la de verano, si toca) menos festivos y ausencias aprobadas.
- **Trabajado** = suma de los tramos entre «entrada» o «fin de pausa» y «inicio de pausa» o «salida», con los fichajes **efectivos** (los originales menos los anulados por una corrección aceptada, más los que añade la corrección).
- **Pausas:** cada tipo dice si computa como trabajo (por ejemplo, la pausa de 15 minutos de una jornada continuada, si el convenio la considera trabajo efectivo) o no (la comida).
- **Día de Madrid:** un tramo que cruza la medianoche se atribuye al día en que **empieza** la jornada (Woffu guarda el «origen» de cada fichaje para lo mismo, W-021).
- **Modo:** presencial o a distancia, por tramo (se elige al fichar; por defecto, el último usado ese día de la semana).

### 7.2 Diferencia, horas extra y saldo de horas
- **Diferencia del día** = trabajado − teórica.
- **Horas extra:** las que pasan de la jornada ordinaria en el cómputo que fije la asesoría (F-3): semanal por el convenio de publicidad, con el tope de 80 al año (art. 35.2 ET). Se anotan con su destino: **compensar con descanso** (1 h = 80 min, convenio) o pagar.
- **Saldo de horas** («bolsa de horas» en Woffu; aquí **no** se llama así para no confundirlo con las bolsas de horas de los clientes): un libro de movimientos por persona (+ extra generada, − descanso disfrutado, − pagada, ± ajuste con motivo). Nunca se edita un saldo, solo se añaden movimientos.
- **Flexibilidad:** la diferencia diaria dentro de la flexibilidad pactada no es hora extra; se compensa dentro de la semana o el mes según diga la asesoría.

### 7.3 Incidencias del día (como las de Woffu)
- **Falta la salida** (hay entrada y no salida a las 23:59 de Madrid).
- **Sin fichajes** en un día con jornada teórica y sin ausencia.
- **Menos de 12 h de descanso** desde la salida anterior (art. 34.3).
- **Más de 6 h seguidas sin pausa** (art. 34.4).
- **Más de 9 h ordinarias** (art. 34.3).
- **Pausa abierta** al cerrar la jornada.
Las incidencias **avisan, no bloquean ni corrigen**.

### 7.4 Correcciones
- La persona (o su responsable) propone añadir, mover o anular fichajes de un día, con **motivo obligatorio**.
- **Doble conformidad:** si la propone la persona, la acepta su responsable (o un admin); si la propone el responsable, la acepta la persona. Sin acuerdo en 7 días, queda **«en discrepancia»** con las dos versiones y el cómputo usa la original (borrador del RD).
- Nunca se tocan las filas originales: la corrección añade fichajes nuevos y anotaciones de anulación.
- Un día de un **mes confirmado** solo se corrige si un responsable lo «desconfirma» antes, con motivo; queda en el historial (como Woffu).

### 7.5 Cierre mensual
- El día 1 se genera el **resumen del mes anterior** de cada persona: días, trabajado, teórica, diferencia, horas extra y su destino, horas complementarias si es a tiempo parcial y ausencias.
- La persona lo **confirma** o anota su **disconformidad** (no bloquea). Recordatorio a los 3 y a los 7 días.
- El PDF del resumen se guarda con su huella SHA-256 y queda en «Mi registro» para descargar. Sirve como la copia que exigen los arts. 12.4.c y 35.5 ET.

### 7.6 Vacaciones y permisos
- **Saldo de vacaciones** por persona y año: derecho del año (convenio: 22 días laborables, L-17) con **devengo proporcional** si se entra o sale a mitad de año, más el arrastre por IT o nacimiento (art. 38.3, con su caducidad), más los ajustes con motivo; menos lo aprobado y lo solicitado (en «pendiente»).
- **Tipos** con unidad (días naturales, laborables u horas), duración por defecto, ampliación por desplazamiento, retribuido o no, justificante, preaviso y si es dato de salud.
- **Aviso de antelación:** si quedan menos de 2 meses para unas vacaciones sin aprobar (art. 38.3).

---

## 8. Modelo de datos orientativo

Nombres en inglés, como el resto. Todo lo nuevo en una migración solo aditiva, con el módulo `people` apagado.

### 8.1 Registro de jornada
- **`clock_events`** (solo alta; **sin UPDATE ni DELETE** ni para el admin, garantizado con un *trigger* de PostgreSQL y una política del modelo):
  - `id` (ULID), `user_id`, `kind` (`clock_in`, `pause_start`, `pause_end`, `clock_out`), `pause_type_id` nulo,
  - `occurred_at` (UTC, **hora del servidor**), `recorded_at` (cuándo llegó; igual salvo en los fichajes sin conexión, §15),
  - `work_mode` (`on_site`, `remote`, `travel`), `source` (`web`, `pwa`, `correction`, `import_woffu`),
  - `correction_id` nulo (si lo añade una corrección), `created_by`,
  - `ip_hash` y `user_agent` corto, solo para la seguridad, con una retención menor si la asesoría lo pide,
  - `prev_hash` y `hash` (SHA-256 encadenado por persona).
- **`clock_event_voids`** (solo alta): `clock_event_id`, `correction_id`, `created_at`. Anula un fichaje sin borrarlo.
- **`clock_corrections`**: `id`, `user_id`, `date`, `proposed_by`, `reason`, `payload` (los fichajes propuestos), `status` (`pending`, `accepted`, `rejected`, `disputed`, `withdrawn`), `employee_decision_at`, `company_decision_by`, `company_decision_at`, `dispute_note`.
- **`pause_types`**: `name`, `counts_as_work`, `max_minutes` nulo, `active`, `sort`.
- **`workdays`** (resumen **recalculable**, nunca fuente de verdad): `user_id`, `date`, `expected_minutes`, `worked_minutes`, `pause_minutes`, `overtime_minutes`, `complementary_minutes`, `incidents` (lista), `month_close_id` nulo.
- **`month_closes`**: `user_id`, `month`, totales, `pdf_path`, `sha256`, `generated_at`, `confirmed_at`, `disagreement_note`, `unconfirmed_by`, `unconfirmed_at`, `unconfirm_reason`.
- **`time_balance_movements`** (saldo de horas, solo alta): `user_id`, `date`, `minutes` (con signo), `kind` (`overtime`, `rest_taken`, `paid`, `adjustment`, `opening_balance`), `reason`, `created_by`.
- **`legacy_clock_records`** (solo lectura): el histórico importado de Woffu (§13), con el fichero de origen y su SHA-256.
- **`register_anchors`**: una fila al día con el último `hash` de cada persona y un resumen global firmado, que se copia en la copia de seguridad y se envía por correo al admin (§11).

### 8.2 Horarios y calendarios
- **`work_schedules`** (existe) + columnas nuevas: `start_time_from`, `start_time_to` (flexibilidad de entrada), `expected_pause_minutes`, `summer_profile_id` nulo.
- **`schedule_profiles`** (jornada de verano u otras temporadas): rango de fechas recurrente (1/7–31/8, convenio) y minutos por día.
- **`work_calendars`**: «Valencia» al principio; `holidays.work_calendar_id` y `users.work_calendar_id` (la plantilla en teletrabajo usa el del centro al que está adscrita, Ley 10/2021, art. 7).

### 8.3 Ausencias
- **`absence_types`** (sustituye al enum y conserva sus 5 valores como `category`): `name`, `category`, `unit`, `default_amount`, `travel_extra`, `paid`, `requires_attachment`, `notice_days`, `health_data`, `legal_basis`, `allowance_policy` (`none`, `annual`, `per_event`), `second_approval` (sí/no), `active`.
- **`absence_allowances`**: `user_id`, `absence_type_id`, `year`, `entitled`, `carried_over`, `carry_expires_at`; lo gastado se calcula de las ausencias.
- **`absence_allowance_movements`** (ajustes con motivo, solo alta; aquí entra el saldo inicial traído de Woffu).
- **`absences`** (existe) + `absence_type_id`, `second_approved_by`, `second_approved_at`; justificantes en `attachments` con un disco y una política propios.

### 8.4 Datos laborales y documentos
- **`employment_profiles`**: `user_id`, `nif` (cifrado), `hire_date`, `termination_date`, `contract_type`, `part_time`, `collective_agreement`, `professional_group`, `work_calendar_id`, `remote_work_agreement` (días o porcentaje), `legal_hold` (retención por litigio).
- **`people_documents`** (documento de implantación, política de desconexión, calendario laboral del año) con versión y **`people_document_acknowledgements`**, con el patrón de `PrivacyNotice`.
- **`inspection_accesses`**: cuenta temporal de solo lectura (§11.3): `email`, `valid_from`, `valid_to`, `scope` (personas y fechas), `created_by`, `revoked_at`; cada consulta queda en la auditoría.

### 8.5 Servicios de dominio (nombres orientativos)
- `App\Domain\People\ClockWriter`: el **único** que escribe fichajes (como `TimeEntryWriter`); valida la secuencia (no hay dos entradas seguidas ni una pausa sin entrada).
- `ClockCorrectionService`: proponer, aceptar, rechazar, discrepar y retirar.
- `WorkdayCalculator`: fichajes efectivos + `Capacity` → `workdays`.
- `MonthCloser`, `TimeBalanceLedger` (como `HourBankLedger`), `AllowanceCalculator`.
- `RegisterIntegrity`: recalcula la cadena de huellas y avisa si se rompe.
- `InspectionExport`: PDF y CSV con huella, el historial de correcciones y un `LEEME`.

---

## 9. Permisos

| Acción | Empleado | Responsable (su departamento) | Admin / RR. HH. (`manage-people`) | Inspección (temporal) | Colaborador o cliente |
|---|---|---|---|---|---|
| Fichar | Sí, solo para sí | Sí, para sí | Sí, para sí | No | **No** (no son plantilla) |
| Ver el registro y el diario | El suyo | El de su equipo | Todos | Su ámbito, solo lectura | No |
| Proponer una corrección | La suya | Para su equipo | Para cualquiera | No | No |
| Aceptar una corrección | La que le proponen | Las de su equipo | Cualquiera | No | No |
| Confirmar el mes | El suyo | — | — | No | No |
| Desconfirmar el mes | No | Su equipo | Todos | No | No |
| Solicitar ausencias | Sí | Sí (se aprueban solas, D-049) | Sí | No | No |
| Aprobar ausencias | No | Su equipo | Todos (y el segundo nivel) | No | No |
| Ver saldos | Los suyos | Su equipo | Todos | No | No |
| Exportar para la ITSS | Su registro | No | Sí | Sí, su ámbito | No |
| Configurar horarios, calendarios y tipos | No | No | Sí | No | No |
| Ajustar saldos | No | No | Sí, con motivo | No | No |

- **`manage-people`** es un permiso nuevo para que una persona de RR. HH. o de administración lo gestione sin ser admin de toda la app. El admin lo tiene siempre.
- **El responsable no edita fichajes**: propone y la persona acepta (doble conformidad).
- **El tipo de ausencia** sigue la privacidad de D-088.

---

## 10. Avisos

Todos con `AppNotification` y las preferencias de D-073; los legales, obligatorios con candado (D-122).

| Evento | A quién | Canal por defecto |
|---|---|---|
| `people.clock_in_missing` (pasada la hora de entrada prevista + margen, en día con jornada y sin ausencia) | La persona | App y push |
| `people.clock_out_missing` (a la hora de salida prevista + margen) | La persona | App y push |
| `people.correction_requested` / `accepted` / `rejected` / `disputed` | La otra parte | App y email |
| `people.month_close_ready` (día 1) | La persona | App y email (obligatorio) |
| `people.month_close_reminder` (días 3 y 7) | La persona | App |
| `people.overtime_weekly_summary` (si hubo horas extra; convenio) | La persona | App y email (obligatorio) |
| `people.incidents_digest` (lunes) | El responsable | Email |
| `people.vacation_unplanned` (vacaciones a menos de 2 meses sin aprobar) | La persona y el responsable | App |
| `people.integrity_broken` | Admins | App y email (obligatorio) |
| Los de ausencias (ya existen) | Igual que hoy | Igual que hoy |

---

## 11. Garantías: inalterabilidad, conservación y acceso

### 11.1 Que no se pueda alterar sin dejar rastro
1. **Solo alta:** `clock_events`, `clock_event_voids` y `time_balance_movements` no admiten UPDATE ni DELETE (un *trigger* de PostgreSQL lo rechaza; el modelo de Laravel también).
2. **Hora del servidor** sincronizada por NTP; la del navegador nunca cuenta.
3. **Cadena de huellas** por persona: cada fichaje guarda la huella del anterior. `RegisterIntegrity` la comprueba cada noche.
4. **Ancla diaria:** el resumen de huellas del día va a la copia de seguridad y por correo al admin. Así, ni quien tiene acceso a la base de datos podría reescribir el pasado sin que se note al compararlo.
5. **Toda corrección** lleva autor, fecha, motivo y conformidad, y se ve en el diario de la persona y en la exportación para la ITSS.

### 11.2 Conservación
- **48 meses como mínimo**, contados desde el final del mes del fichaje. El ajuste de retención no deja poner menos.
- Después, `app:prune-data` los **suprime**, salvo que la persona tenga la retención por litigio activa (G.5).
- **Desactivar a una persona no borra nada.**
- La auditoría relacionada con el registro también se guarda 48 meses aunque la general sea menor.
- El **histórico de Woffu** se guarda como archivo de solo lectura con su huella (§13) y sigue el mismo plazo.

### 11.3 Acceso
- **La persona:** «Mi registro», con cualquier periodo, en pantalla y en PDF o CSV, al momento.
- **La representación legal**, si la hubiera: una exportación mensual con nombre, apellidos, provincia y municipio, sin el historial de cambios (STS 1161/2024).
- **La ITSS en la visita:** el admin genera al momento la exportación de la plantilla o de una persona y un rango de fechas (PDF para leer y CSV para tratar), con huella y el historial de correcciones.
- **La ITSS en remoto** (si el RD lo exige): una cuenta temporal de solo lectura con caducidad, doble factor obligatorio, ámbito limitado y cada acceso auditado. Está apagada mientras no haga falta.

---

## 12. Entregas por prioridad

Equivalencia con la primera propuesta (`WOFFU-INVESTIGACION.md` §E): E1 → R1, E2 y E3 → R2, E4 → R3 y E5 → R5.

| Entrega | Qué incluye | Por qué en este orden | Inventario y requisitos |
|---|---|---|---|
| **R1 · Registro de jornada** (lo legalmente imprescindible) | Módulo `people` (apagado al crearse); `ClockWriter` y `clock_events` solo de alta con *trigger*, hora del servidor y cadena de huellas; botón de fichar en la cabecera y la PWA, con tipo de pausa y presencial o a distancia; `work_schedules` con margen de entrada, pausa prevista y temporada de verano; «Mi jornada» con el diario, la diferencia y el historial; **correcciones** con doble conformidad y discrepancia; incidencias; avisos de entrada, salida y jornada sin cerrar; «Jornada del equipo» y bandeja de pendientes del responsable; fecha de alta y baja en `employment_profiles`; las ayudas de §3.2 (fichar al empezar el temporizador, etc.). Tests de Pest para la inmutabilidad, las correcciones y los totales (también tiempo parcial, verano y medianoche) y E2E de fichar y corregir | Sin esto Audax incumple el art. 34.9 ET desde el día que deje Woffu | W-001 a W-005, W-018, W-020 a W-022, W-027 a W-030, W-033, W-046, W-074, W-081, W-083, W-110 a W-113, W-123 a W-126, W-132 · L-01 a L-03, L-09 y L-14 |
| **R2 · Acceso, cierres e Inspección** | «Mi registro» con descarga; **cierre mensual** con confirmación o disconformidad, desconfirmar con motivo y su PDF con huella; horas extra con destino (compensar o pagar) y resumen semanal; **saldo de horas**; informes «Registro mensual de la jornada», «Anexo de horas», «Presencia diaria y mensual», «Fichajes» e «Incidencias» con huella; exportación para la ITSS; cuenta de inspección temporal (apagada); retención de 48 meses, retención por litigio y exclusión de `app:prune-data`; documentos de RR. HH. con lectura registrada (**documento de implantación del registro** y **política de desconexión**); permiso `manage-people`; sección nueva en la exportación RGPD; texto RGPD actualizado (pendiente de la asesoría); comprobación nocturna de la cadena y ancla diaria | Es lo que pide la Inspección en una visita y lo que da valor de prueba al registro. **R1 y R2 van juntas a producción** | W-042, W-047, W-052, W-053, W-084 a W-093, W-108, W-114, W-119 · L-04 a L-08, L-10 y L-11 |
| **Paralelo** | Un mes natural completo fichando en las dos herramientas, comparando cada día los totales | Detecta errores de cálculo antes de depender de la app | B-9 |
| **R3 · Vacaciones y permisos** | Catálogo de tipos (unidad, retribuido, justificante, preaviso, salud, base legal) precargado con el ET y el convenio; **saldos** con asignación anual, proporcional, arrastre con caducidad y ajustes con motivo; solicitudes por horas con franja; **justificantes** con acceso restringido; «Pedir cancelación»; segundo nivel de aprobación opcional; calendario «Valencia» con locales, 25 de enero y 24 y 31 de diciembre; días bloqueados; media jornada en días especiales; aviso de los 2 meses; informes «Saldos», «Actividad» y «Justificantes pendientes» | Woffu también lleva los saldos: sin esto no se puede dar de baja aunque el registro ya esté | W-034, W-038, W-039, W-054 a W-064, W-066, W-067, W-069, W-071, W-076, W-096, W-097, W-116 · L-17 a L-24 |
| **R5 · Migración y baja de Woffu** | §13 | Se hace cuando R1 a R3 están en producción | W-137 y §13 del inventario |
| **R4 · Comodidades** | Tarjetas «Mi equipo hoy» y «Pendientes» en Inicio; iCal de ausencias; reglas de cobertura mínima (aviso); edición masiva; trabajar en festivo | Mejora el día a día; no bloquea la baja de Woffu | W-023, W-040, W-072, W-079, W-103, W-127 |
| **R6 · Opcional** | Reparto de nóminas y documentos por persona; restricción por IP de la oficina; CSV mensual para la gestoría; perfil de solo lectura para la gestoría | Según las respuestas P6 y P7 | W-014, W-104, W-105, W-119 |

**Cuándo se puede dar de baja Woffu:** con R1, R2 y R3 en producción, un mes en paralelo sin diferencias y R5 terminada (exportación archivada y saldos cargados).

**Decisiones:** cuando el propietario apruebe el plan, las decisiones del módulo se numeran en un bloque libre de `docs/DECISIONES.md` y el plan pasa a `PLAN-FASE-N.md`.

---

## 13. Migración desde Woffu

### 13.1 Qué hay que traer
| Dato | Por qué | Cómo se trae | Cómo queda en Audax |
|---|---|---|---|
| **Registro de jornada de los últimos 4 años** (fichajes, correcciones y validaciones) | Obligación del art. 34.9: el plazo **sigue corriendo** aunque se cambie de herramienta | Informes «Fichajes» y «Presencia diaria» en CSV, persona a persona o toda la plantilla, por años; «Registro mensual de la jornada» y «Anexo» en PDF, mes a mes | **Archivo «Histórico Woffu»** de solo lectura: los ficheros originales con su SHA-256 y, además, los datos cargados en una tabla de consulta (`legacy_clock_records`) para que salgan en «Mi registro» y en la exportación para la ITSS, marcados como «Woffu». **No** se convierten en `clock_events` |
| **Jornadas confirmadas** | Prueba de la conformidad de la persona | Columna «Confirmada» del informe de presencia diaria | En el mismo archivo |
| **Saldos de vacaciones** del año en curso y arrastres | La persona no puede perder días | Informe «Saldos» el día del corte | Movimiento `opening_balance` por persona y tipo, con el motivo «Saldo inicial desde Woffu a dd/mm/aaaa» |
| **Horas extra pendientes de compensar** (bolsas) | Ídem | Informe «Saldos» | Movimiento inicial del saldo de horas |
| **Ausencias aprobadas futuras** | Que sigan en el calendario | Informe «Actividad» desde el día del corte | Se crean como ausencias aprobadas con `register` (D-049) |
| **Historial de ausencias** (4 años) | Prueba de vacaciones y permisos disfrutados | Informe «Actividad» desde hace 4 años | Archivo de solo lectura, como los fichajes |
| **Justificantes y documentos** | Si se subieron a Woffu | Descarga desde el gestor documental | Justificantes, en el espacio restringido de R3; lo demás, a quien corresponda (P7) |
| **Datos laborales** (alta, contrato, jornada, responsable) | Para los saldos y el devengo | Informe «Usuarios» | `employment_profiles` y `work_schedules` |

### 13.2 Cómo se saca
- **Con los informes de Woffu** (Excel, PDF o CSV; W-089 a W-100). Es lo que permite cualquier plan.
- **Con la API** solo si Audax tiene el plan Enterprise (W-137). Si la tiene, se usa para lo mismo y se comprueba que los totales coinciden con los informes.
- **El importador de fichajes de Woffu** no hace falta: es para meter datos en Woffu, no para sacarlos.

### 13.3 Pasos
1. **Antes de empezar R1:** preguntar por escrito a Woffu qué pasa con los datos al dar de baja la cuenta, cuánto tiempo se pueden descargar y si dan una exportación completa. Guardar la respuesta (P2).
2. **Prueba de exportación** de un mes de una persona para ver los formatos y escribir el cargador.
3. **Corte:** el día 1 de un mes. El mes anterior se cierra en Woffu (la plantilla confirma sus jornadas) y se exporta entero.
4. **Mes en paralelo** fichando en los dos sitios; cada día se comparan los totales por persona.
5. **Carga** de saldos y ausencias futuras a la fecha del corte; cada persona revisa sus saldos y los confirma (como el cierre mensual).
6. **Exportación final** de los 4 años, comprobación de recuentos (días, fichajes y personas) y archivo con huella en la app y en la copia externa.
7. **Comunicación a la plantilla:** documento de implantación nuevo y texto informativo actualizado, con lectura registrada.
8. **Baja de Woffu** cuando todo lo anterior esté hecho y comprobado.

---

## 14. Preguntas para el propietario

Pocas y concretas. Las de derecho laboral (convenio, horas extra dentro de la flexibilidad, plazos de supresión) son para la asesoría: `WOFFU-INVESTIGACION.md` §F.

| # | Pregunta | Opciones | Recomendada |
|---|---|---|---|
| **P1** | ¿Qué plan de Woffu tenéis, cuándo se renueva y cuántas personas fichan (¿también socios o dirección?) | Lite, Pro o Enterprise; fecha de renovación o permanencia | Decirnos el plan y la fecha: con Enterprise usamos la API; si no, los informes. La fecha marca el corte (§13.3). Los socios que no son trabajadores por cuenta ajena no tienen que fichar |
| **P2** | ¿Quién pide a Woffu por escrito lo de la baja y quién descarga las exportaciones? | (a) tú, (b) alguien de administración con acceso de admin en Woffu | **(b)**, con una lista de informes que preparamos nosotros |
| **P3** | ¿Qué pausas se fichan? | (a) solo la comida, (b) todas, también el café, (c) ninguna | **(a)**: la comida no es tiempo de trabajo y, si no se registra, se presume trabajado. Las pausas cortas pactadas como trabajo no hace falta ficharlas |
| **P4** | ¿Cuántos niveles de aprobación para vacaciones y permisos? | (a) uno, el responsable, como hoy, (b) dos, responsable y dirección o RR. HH. | **(a)**, con un segundo nivel activable solo para las vacaciones si lo queréis |
| **P5** | ¿Quién gestiona RR. HH. en la app (`manage-people`)? | (a) solo los admins, (b) una persona concreta sin ser admin, (c) además, la gestoría con un acceso de solo lectura a los informes | **(b)**; (c) solo si la gestoría lo pide |
| **P6** | ¿Se hacen horas extra? | (a) no, salvo autorización previa, (b) sí, y se compensan con descanso, (c) sí, y se pagan | **(a)**: el sistema registra todo exceso y el responsable decide si es hora extra (y su destino) o tiempo dentro de la flexibilidad |
| **P7** | ¿Usáis Woffu para repartir nóminas o documentos? | (a) sí, y hay que mantenerlo, (b) no, o lo hace la gestoría por otra vía | **(b)** en la primera versión; si es (a), va en R6 |

### 14.1 Respuestas del propietario (07/10/2026)
- **P1:** plan **Lite** de Woffu (sin API pública: la migración va con los informes exportados). Fecha de renovación: por confirmar. Plantilla: 9 personas más una colaboradora externa (Amparo).
- **P2:** sin respuesta; queda la recomendada (b): Toni, que lleva RR. HH., pide la baja y descarga las exportaciones con la lista que preparemos.
- **P3:** **(a) solo la comida** («creo que se fichan solo las comidas»).
- **P4:** sin respuesta; queda la recomendada (a): un nivel, el responsable (segundo nivel activable solo para vacaciones, en R3).
- **P5:** **(b) una persona concreta: Toni** (Toni Fernández, que además es admin) con `manage-people`.
- **P6:** **sí hay horas extra y se fichan**: el sistema registra todo exceso y el responsable decide si es hora extra y su destino (compensar o pagar), con el resumen semanal (R2).
- **P7:** **(b)** en la primera versión: «creo que las nóminas no se reparten por Woffu, pero no lo sé». R6 queda fuera hasta confirmarlo.

---

## 15. Riesgos

| Riesgo | Efecto | Cómo lo evitamos |
|---|---|---|
| **El servidor es a la vez desarrollo y producción** (D-028) | Un despliegue roto deja a la plantilla sin poder fichar; el registro legal convive con pruebas | Tests obligatorios de R1 y R2 antes de desplegar; la PWA guarda el fichaje si el servidor no responde (fila siguiente); el módulo se activa solo cuando esté probado; plantearse un entorno de pruebas aparte antes de R1 |
| **Copia externa sin activar** (D-127) | Si se pierde el servidor se pierde un registro que hay que guardar 4 años | **Activar la copia externa antes de R1** (pregunta pendiente del propietario: el destino); comprobar la restauración mensual |
| **Fichar sin conexión** | Si el fichaje se guarda con la hora del móvil, se puede manipular | La PWA guarda el clic y lo envía al volver; queda como **corrección propuesta** con la hora del dispositivo y la del servidor, y la acepta el responsable. Nunca entra como fichaje normal |
| **Hora del servidor** | Un reloj desajustado invalida el registro | Comprobar que el servidor sincroniza con NTP (`timedatectl`, solo lectura) y avisar si se desvía |
| **Alguien con acceso a la base de datos reescribe fichajes** | Pérdida de fiabilidad (L-02) | *Trigger* que impide UPDATE y DELETE, cadena de huellas y ancla diaria fuera del servidor (§11.1) |
| **Real decreto con requisitos distintos al borrador** | Ajustes con plazo corto | Diseño según el borrador; la exportación ITSS aislada en `InspectionExport` para cambiar solo el formato |
| **Mezclar finalidades (RGPD)** | Usar el registro para medir productividad es otra finalidad (L-11) | La comparación con las horas imputadas solo la ve la persona (§3.2.5) hasta que la asesoría diga otra cosa (F-5) |
| **Olvidos de fichaje al principio** | Muchas incidencias y correcciones | Avisos de entrada y salida, «fichar al empezar el temporizador» y el mes en paralelo |
| **Confusión de nombres** | «Bolsa de horas» ya significa la de un cliente | En la interfaz, «Saldo de horas» |
| **Justificantes médicos** | El texto RGPD actual dice «nunca justificantes médicos» | Cambiar el texto con la asesoría antes de R3; acceso solo de la persona y de RR. HH., no del responsable |
| **Perder el histórico de Woffu** | Incumplir los 4 años | Exportar y archivar antes de la baja (§13); respuesta escrita de Woffu (P2) |
| **Saldos mal traspasados** | Reclamaciones de días | Cada persona confirma su saldo inicial |
