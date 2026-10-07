# Woffu → Audax Proyectos: inventario funcional

_06/10/2026 · solo lectura · complementa `WOFFU-INVESTIGACION.md` (lo legal) y alimenta `PLAN-RRHH.md` (la propuesta)._

## Cómo se ha hecho
- **Fuente principal:** los **143 artículos** del centro de ayuda público de Woffu (`woffu.my.site.com/help`, sacados de su mapa del sitio el 06/10/2026), leídos uno a uno. La columna «Fuente» da el *slug* del artículo: la URL es `https://woffu.my.site.com/help/s/article/<slug>?language=es`. Entre paréntesis va la fecha de su última publicación.
- **Otras fuentes:** [woffu.com/en/api](https://woffu.com/en/api/) (API), [Capterra](https://www.capterra.com/p/156162/Woffu/) y la comparativa de la propia Woffu con Sesame ([woffu.com, blog](https://woffu.com/es/blog/noticias/woffu-vs-sesame-hr/)).
- **No se ha visto la cuenta de Audax en Woffu.** No sabemos qué plan tiene contratado ni qué módulos usa: es la primera pregunta para el propietario (P1 de `PLAN-RRHH.md`). Lo marcado **[inferido]** se deduce de la ayuda, no se ha leído literalmente.

## Columnas
- **Para Audax** (unas 30 personas, una oficina en Valencia, teletrabajo y horario flexible):
  - **E**: esencial, porque lo exige la ley o porque la plantilla lo usa a diario,
  - **U**: útil, se hace si cuesta poco,
  - **S**: sobra para 30 personas o no encaja.
- **Destino:**
  - **Existe**: Audax Proyectos ya lo tiene,
  - **Adaptar**: hay una base que completar,
  - **Nueva**: hay que construirlo,
  - **No**: no se hace (con el motivo).
- La **entrega** (R1 a R6) es la de `PLAN-RRHH.md` §12.

## Vocabulario de Woffu (para entender las filas)
| Woffu | Qué es | En Audax |
|---|---|---|
| **Personal / Corporativo** | Las dos mitades del menú: lo mío y lo que gestiono | La barra lateral ya separa por rol |
| **Presencia / Mi presencia** | Fichar, el diario de jornadas y las horas | «Mi jornada» (R1) |
| **Solicitudes** | Pedir cualquier ausencia o presencia, con saldos y calendario | «Ausencias», ampliada |
| **Motivo** (de ausencia o de presencia) | Cada tipo de permiso: vacaciones, médico, teletrabajo, horas extra… | Tipo de ausencia (R3); los de presencia, en R2 |
| **Convenio** | El paquete de motivos, vacaciones anuales, horas anuales y reglas de exceso que se asigna a cada persona | Política de RR. HH. por persona (R2 y R3) |
| **Asignación** | Días u horas dados a una persona para un motivo (vacaciones del año, bolsa de médico…) | Saldo (R3) |
| **Bolsa** | Saldo de horas o días: un motivo de presencia lo genera y uno de ausencia lo gasta | Saldo de horas (R2). **No confundir con las bolsas de horas de clientes** |
| **Horario** (con **periodos**) | Jornada teórica: fija, tolerante o flexible, con pausas y periodos especiales (verano, Navidad) | `work_schedules`, ampliado (R1) |
| **Calendario** | Los festivos de un centro o localidad | Festivos, por calendario (R3) |
| **Centro de trabajo** | Oficina con sus métodos y límites de fichaje | `work_calendars` (R3) |
| **Incidencia** | Jornada que no cuadra: sin fichajes, fichajes impares o fichar durante una ausencia | Incidencias del día (R1) |
| **Validar** | Que el responsable apruebe un fichaje editado | Aceptar una corrección (R1) |
| **Confirmar / desconfirmar** | La persona da por buenas sus jornadas; el responsable las reabre | Cierre mensual (R2) |
| **Categorización de excesos** | Convertir el exceso de jornada en horas extra, complementarias, etc. | Horas extra (R2) |

---

## 1. Fichaje

| ID | Funcionalidad | Qué hace y cómo se usa | Rol | Para Audax | Destino | Fuente |
|---|---|---|---|---|---|---|
| W-001 | Botón **«Entrar» / «Salir»** | En el inicio personal (web y app) se ve el horario del día y el centro asignado, y un botón «Entrar» que pasa a «Salir». «Salir» sirve tanto para una pausa como para terminar | Empleado | **E** | Nueva (R1): botón junto al temporizador | `como-fichar-en-woffu` (10/11/2025) |
| W-002 | Tiempo efectivo en curso | Contador del tiempo trabajado hoy y de la semana, el mes y el año en «Datos de presencia» | Empleado | **E** | Nueva (R1) | `Como-consulto-mis-horas-trabajadas` (05/05/2026) |
| W-003 | **Fichaje por motivos** | Al entrar, pausar o salir se elige un motivo (comida, médico, teletrabajo…). Solo con motivos «autoaceptables» y sin bolsa. Plan Pro | Empleado | **E** (tipos de pausa y modo remoto) | Nueva (R1): tipo de pausa y presencial o a distancia | `fichaje-por-motivos` (24/02/2026); `faq-no-me-aparece-un-fichaje-por-motivo` |
| W-004 | Fichaje web | Desde cualquier navegador | Empleado | **E** | Nueva (R1) | `los-metodos-de-fichaje-en-woffu` (15/09/2026) |
| W-005 | App iOS y Android | Fichar, ver horario, solicitudes, calendario, documentos, anuncios y validar (responsables) | Todos | **E** (como PWA) | Adaptar (R1): la PWA de Audax con avisos push | `un-mundo-de-posibilidades-a-traves-de-la-app-de-woffu` (21/07/2026) |
| W-006 | Extensión de Chrome | Fichar desde un botón del navegador | Empleado | U | No: el botón está en la cabecera de la app, siempre visible | `plugin-para-fichar` (17/01/2023) |
| W-007 | Fichaje desde Slack | Entrar, salir, pausar y ver ausencias desde Slack. Se contrata aparte | Empleado | S | No: Audax tiene su propio chat | `nueva-forma-de-fichar-en-woffu-a-traves-de-la-app-de-slack` (13/07/2026) |
| W-008 | Fichaje desde Teams | Igual, desde Microsoft Teams | Empleado | S | No | `nueva-forma-de-fichar-en-woffu-a-traves-de-la-app-de-teams` (13/07/2026) |
| W-009 | Fichaje por WhatsApp | Mensajes «entrar» y «salir» al número de Woffu | Empleado | S | No (y saca datos a un tercero) | `app-woffu-para-fichaje-desde-whatsapp` (14/07/2026) |
| W-010 | Portal QR | La persona escanea con la app un QR mostrado en una pantalla del centro | Empleado / Admin | S | No | `fichaje-con-codigo-qr` (15/07/2026) |
| W-011 | Portal con PIN (kiosco) | Tablet u ordenador compartido; cada persona teclea su PIN | Empleado / Admin | S | No: todos tienen ordenador | `sistema-de-fichaje-digital-por-codigo` (15/07/2026) |
| W-012 | Dispositivos físicos (biometría, RFID) | Terminales en los accesos. Solo Enterprise | Admin | **S** | **No** (la AEPD desaconseja la biometría, L-13) | `Integrar-dispositivos-de-control-de-acceso-y-fichaje` (07/05/2026) |
| W-013 | Métodos por persona | Se elige qué métodos puede usar cada persona, departamento o centro | Admin | S | No: un único método (web/PWA) | `los-metodos-de-fichaje-en-woffu` |
| W-014 | **Restricción por IP** | Por centro de trabajo, solo se ficha desde IP autorizadas | Admin | U | Nueva opcional (R6), apagada por defecto: choca con el teletrabajo | `limitacion-en-los-fichajes` (04/06/2026) |
| W-015 | Restricción por GPS (geovalla) | Por centro, solo se ficha dentro de un radio | Admin | S | No (L-12) | `limitacion-en-los-fichajes` |
| W-016 | **Geolocalización** del fichaje | Guarda la ubicación de cada fichaje si la persona da permiso; activable en Configuración → Personalizar → Otros → Presencia | Admin | **S** | **No** (L-12: hay que informar y justificar; no aporta a una oficina con teletrabajo) | `geolocalizacion-gestiona-los-fichajes-de-tus-empleados-as` (03/06/2026) |
| W-017 | IP y método de cada fichaje | El informe de fichajes guarda la IP, las coordenadas y el método (web, app, biométrico, API, QR o código) | Admin | U | Nueva (R1): origen (`source`) e IP reducida, solo para seguridad | `tipos-de-informes` (28/10/2025) |
| W-018 | **Fichaje olvidado: editar la jornada** | La persona añade a mano la hora que olvidó; queda **«Pendiente de validar»** y el día en negativo hasta que el responsable lo valida | Empleado | **E** | Nueva (R1): corrección con doble conformidad | `como-fichar-en-woffu`; `informe-de-presencia-para-consultar-las-jornadas-pendientes-de-validar` (05/11/2025) |
| W-019 | Rol sin permiso para editar fichajes | Un rol personalizado puede impedir que la persona edite sus fichajes (solo consulta) | Admin | U | Adaptar: en Audax nadie edita; todos proponen correcciones | `desactiva-los-permisos-de-modificacion-de-fichajes` (20/03/2026) |
| W-020 | Fichaje real frente a editado | En el detalle del día se ve el fichaje original y el editado, con la ubicación si la hay, y el historial de cambios de horario del día | Responsable / Admin | **E** | Nueva (R1): historial visible en el diario | `como-gestionar-el-fichaje-de-mi-equipo` (25/09/2026) |
| W-021 | A qué día pertenece un fichaje | En «Edición de fichajes → Detalles» se ve el origen de la entrada o la salida inicial | Admin | U | Nueva (R1): regla del día de inicio de la jornada | `faq-a-que-dia-corresponde-un-fichaje` (16/07/2026) |
| W-022 | Quién validó un fichaje | Detalle del día o columnas «Validado» y «Validado por» del informe de presencia diaria | Admin | **E** | Nueva (R1) | `faq-quien-ha-validado-un-fichaje` (04/07/2025) |
| W-023 | **Quién está trabajando** | Estados en tiempo real: disponibles (han fichado), no han fichado, no disponibles (fuera de horario) y ausentes. En el inicio corporativo y en Equipo | Responsable / Admin | U | Adaptar (R4): «Equipo hoy» (D-255) ya da un estado por persona | `consulta-quien-ha-fichado-y-esta-activo-en-la-empresa` (14/04/2026); `faq-como-ver-quien-esta-trabajando…` |
| W-024 | Informe de seguridad | Quién está dentro del centro según los fichajes (evacuación) | Admin | S | No | `tipos-de-informes` |
| W-025 | Informe de visitas | Personas externas que acceden por dispositivo | Admin | S | No | `tipos-de-informes` |
| W-026 | Importador de fichajes | CSV (o FTP en Enterprise) para crear o editar fichajes en masa | Admin | U (para migrar) | Nueva solo para la migración (R5), nunca como forma de fichar | `importador-de-fichajes` (17/09/2026) |

## 2. Horarios, calendarios y jornada

| ID | Funcionalidad | Qué hace y cómo se usa | Rol | Para Audax | Destino | Fuente |
|---|---|---|---|---|---|---|
| W-027 | **Horario fijo** | Franjas de entrada y salida, días laborables y pausas | Admin | **E** | Adaptar (R1): `work_schedules` gana horario y pausa | `configurar-un-horario-laboral-en-woffu-nueva-version` (05/03/2026) |
| W-028 | **Horario tolerante** | Margen de entrada (p. ej., de 8 a 10) y salida según la entrada. Woffu insiste en que es lo que la mayoría llama «flexible» | Admin | **E** (es el caso de Audax) | Adaptar (R1): `start_time_from` y `start_time_to` | `horarios-flexibles-como-configurarlos` (28/10/2025) |
| W-029 | **Horario flexible** | Solo horas diarias y descanso diario, sin franjas; no aplican las reglas que dependen de una hora de referencia | Admin | U | Existe (minutos por día) | ídem |
| W-030 | **Periodos** dentro del horario | Fechas del año con otra jornada: **intensiva de verano**, vísperas de Navidad… | Admin | **E** (el convenio de publicidad: 35 h en julio y agosto) | Nueva (R1): `schedule_profiles` | `configurar-un-horario-laboral…`; `como-se-resta-medio-dia-de-vacaciones-en-una-jornada-especial-de-trabajo` (30/09/2026) |
| W-031 | Horarios rotativos (varias semanas) | Ciclos de N semanas | Admin | S | No | `configurar-un-horario-laboral…` |
| W-032 | Tolerancias | Minutos de margen en la entrada y la salida antes de marcar retraso o salida anticipada | Admin | U | Nueva opcional (R2) | ídem |
| W-033 | Pausas previstas y autoasignadas | Pausas no retribuidas que restan de la jornada; descanso mínimo; asignar la pausa sola si la persona no la ficha | Admin | U | Nueva (R1): `expected_pause_minutes`. **Sin autoasignar**: la pausa se registra o se pregunta | `indica-el-tiempo-de-los-descansos-en-los-horarios` (11/07/2025); `las-horas-extras-como-gestionaralas` |
| W-034 | **Media jornada** en días especiales | En días de jornada reducida (24 y 31 de diciembre, verano), unas vacaciones descuentan 0,5 días | Admin | U | Nueva (R3) | `como-se-resta-medio-dia-de-vacaciones…` |
| W-035 | **Planificar** horarios | Cambiar el horario de personas o equipos en unas fechas (Corporativo → Presencia → Corporativa o desde el calendario) | Admin | U | Existe: versiones de `work_schedules` (D-036) | `Planificar-los-horarios` (18/09/2026) |
| W-036 | Importador de planificaciones | CSV de horarios por persona y fecha | Admin | S | No | `el-planificador-de-horarios-a-traves-del-Importador-masivo` (20/11/2024) |
| W-037 | Turnos y cuadrante | Calendario de turnos. Solo Enterprise | Admin | S | No | `gestiona-los-turnos-de-trabajo` (05/05/2026) |
| W-038 | **Calendarios de festivos** por centro | Festivos nacionales y autonómicos automáticos por código postal; locales automáticos solo de Madrid y Barcelona; el resto, a mano. Eventos de empresa y días bloqueados para vacaciones | Admin | **E** | Adaptar (R3): calendario «Valencia» con los locales; la importación nacional no basta (L-23) | `crea-y-modifica-los-calendarios` (07/08/2026); `configura-los-festivos-de-una-localidad` (20/07/2026) |
| W-039 | Días bloqueados | Fechas en las que no se pueden pedir vacaciones | Admin | U | Nueva (R3) | ídem |
| W-040 | Trabajar en festivo | Fichar o no en festivo, contar o no el exceso, compensar 1×1 o con factor, en días u horas, con bolsa | Admin | U | Nueva (R2): como hora extra con su destino | `trabajar-en-festivo-opciones-de-configuracion` (14/10/2025); `compensa-con-dias-de-vacaciones-los-dias-trabajados-en-festivo` (18/07/2023) |
| W-041 | Horas anuales del convenio | Horas anuales por convenio o contrato para comparar lo teórico con lo fichado en vista semanal, mensual y anual | Admin | U | Nueva (R2): cómputo anual del convenio | `como-gestionar-las-horas-registradas-de-mis-equipos` (14/07/2026) |
| W-042 | **Presencia comparada** | Horas teóricas frente a reales del equipo | Responsable / Admin | **E** | Nueva (R2) | `como-gestionar-el-fichaje-de-mi-equipo` |
| W-043 | Reducción de jornada a 37,5 h | Guía de cómo aplicarla en Woffu | Admin | — | Ya está en `work_schedules` si llega (no está aprobada, G.4) | `nueva-ley-reduccion-de-la-jornada-laboral` (06/11/2024) |
| W-044 | Control solo con horario (sin fichaje) | Modo en el que se asigna un horario y la persona solo confirma cada mes | Admin | **S** | **No**: no es un registro de inicio y fin reales (L-01) | `control-de-presencia-y-control-horario` (28/10/2025) |
| W-045 | Activar Presencia y Fichaje | Interruptores de módulo en Configuración → Otros | Admin | **E** | Nueva: módulo `people` apagado por defecto | ídem |

## 3. Horas extra y bolsas

| ID | Funcionalidad | Qué hace y cómo se usa | Rol | Para Audax | Destino | Fuente |
|---|---|---|---|---|---|---|
| W-046 | **Diferencia** | Trabajado − (horario ± motivos). En rojo si es negativa; se compensa entre días | Todos | **E** | Nueva (R1) | `las-horas-extras-como-gestionaralas` (17/11/2025) |
| W-047 | **Categorización de excesos** | Motor que convierte el exceso en un motivo de presencia (extra, complementaria, a compensar…) hasta un límite | Admin | **E** (si hay horas extra) | Nueva (R2): horas extra con destino | `la-categorizacion-de-horas-en-woffu` (08/07/2025); `el-nuevo-motor-de-categorizacion-de-horas` (08/06/2023) |
| W-048 | Categorización avanzada | Motores distintos por tipo de día (laborable, festivo, descanso) y con ausencias; varios tipos de hora | Admin | S | No | `las-opciones-avanzadas-de-la-categorizacion…` (08/06/2023) |
| W-049 | Por qué no se categoriza un exceso | Horario incompleto, fichajes que faltan, límites, solicitudes que mandan sobre el motor | Admin | U | Nueva (R2): explicación en pantalla | `faq-el-exceso-de-jornada-puede-no-categorizarse…` (04/07/2025) |
| W-050 | Limitar el exceso de jornada | No contar lo trabajado pasados X minutos extra o después de una hora | Admin | **S** | **No**: dejar de contar tiempo realmente trabajado es arriesgado (STS 372/2026). El exceso se registra y se decide qué es | `Limitar-el-exceso-de-Jornada`; `configurar-un-horario-laboral…` |
| W-051 | Horas extra por solicitud | La persona pide un motivo de presencia «horas extra» con franja, el responsable lo aprueba y suma a una bolsa | Empleado / Responsable | U | Nueva (R2): aceptar o rechazar el destino de las horas extra | `las-horas-extras-como-gestionaralas`; `hacer-solicitud-por-franja-horaria` (16/05/2024) |
| W-052 | **Bolsas de horas o días** | Un motivo de presencia genera saldo y uno de ausencia lo gasta (horas extra, guardias, médico…) | Admin | U | Nueva (R2): saldo de horas | `bolsas-de-horas-como-crearlas-y-gestionarlas` (07/09/2026) |
| W-053 | Compensable, pagable o de libre disposición | Separa lo que se compensa con descanso de lo que se paga | Admin | **E** (art. 35 ET) | Nueva (R2) | [woffu.com](https://woffu.com/es/software-politica-horaria) |

## 4. Ausencias y vacaciones

| ID | Funcionalidad | Qué hace y cómo se usa | Rol | Para Audax | Destino | Fuente |
|---|---|---|---|---|---|---|
| W-054 | **Solicitudes** | Pantalla con motivos y saldos, solicitudes pendientes, próximos eventos y «Mi calendario». «Solicitar» abre un panel lateral con los permisos disponibles | Empleado | **E** | Existe (D-049); Adaptar (R3) con saldos | `como-hacer-solicitudes` (07/05/2026) |
| W-055 | Por días o por horas, con franja | Una solicitud por horas pide el número de horas y la franja (de 10 a 12) | Empleado | **E** | Adaptar (R3): hoy `partial_minutes` sin franja | `hacer-solicitud-por-franja-horaria` |
| W-056 | Días laborables o naturales | Cada motivo cuenta en días laborables o naturales; los naturales empiezan el primer día que se debía trabajar | Admin | **E** (permisos del ET) | Nueva (R3): unidad del tipo | `dias-naturales` (16/03/2026) |
| W-057 | **Motivos configurables** por convenio | Nombre, código, descripción, contabilización (días u horas), retribuido, justificante, aprobación… | Admin | **E** | Nueva (R3): catálogo de tipos | `Personaliza-los-motivos-de-Presencia-y-Ausencia` (13/03/2026) |
| W-058 | Tipos (familias) de motivos | Agrupar motivos para filtrar e informar | Admin | U | Existe: las 5 categorías de `AbsenceType` | `tipos-de-motivos-en-el-convenio` (28/10/2025) |
| W-059 | Límites de un motivo | Veces por periodo, cantidad máxima, antelación… | Admin | U | Nueva (R3): preaviso y máximo | `limitar-los-motivos-de-ausencia-y-presencia` (18/07/2025) |
| W-060 | **Asignación anual de vacaciones** | El convenio dice los días u horas del año; al cerrar el ejercicio se asignan solos. Tiempo parcial, según su porcentaje | Admin | **E** | Nueva (R3) | `preparate-para-el-cambio-de-ejercicio` (20/11/2025); `como-asignar-y-configurar-días-horas-vacaciones` (04/08/2026) |
| W-061 | Inicio, fin y caducidad de la asignación | Cada asignación tiene fecha de inicio y de fin; al pasar el fin, lo pendiente caduca | Admin | **E** | Nueva (R3) | `como-asignar-y-configurar-días-horas-vacaciones` |
| W-062 | **Arrastre** al año siguiente con fecha límite | Días no disfrutados que pasan al año siguiente hasta una fecha (normalmente el primer trimestre) | Admin | **E** | Nueva (R3) | `configura-las-fechas-limites-de-disfrute-de-las-vacaciones` (17/06/2024) |
| W-063 | Vacaciones por antigüedad | Días extra por tramos de años en la empresa | Admin | U | Nueva opcional (R3) | `vacaciones-a-asignar-por-antiguedad-de-los-empleados` (04/07/2025) |
| W-064 | Vacaciones proporcionales por contrato | Con contrato temporal (fecha de fin) calcula los días proporcionales | Admin | **E** (L-17) | Nueva (R3): devengo proporcional | `gestion-y-ajuste-de-dias-de-vacaciones-por-contrato` (05/05/2026) |
| W-065 | ERE o ERTE | Reflejar días sin actividad que no devengan vacaciones | Admin | S | No (se registra como ausencia si llega) | `erte-como-configurar` (28/12/2023) |
| W-066 | **Asignaciones y solicitudes masivas** | Dar días u horas o registrar un permiso a un grupo (departamento, centro, toda la plantilla) | Admin | U | Nueva (R3): ajuste de saldo en bloque | `asigna-de-forma-masiva-vacaciones-y-dias-u-horas-a-compensar` (11/06/2026) |
| W-067 | **Dos niveles de aprobación** | Responsable de 1.er nivel y de 2.º nivel por persona; el segundo recibe el aviso cuando aprueba el primero | Responsable | U | Nueva (R3): segundo nivel por tipo (P4) | `asigna-dos-responsables-a-un-mismo-empleado` (07/05/2026) |
| W-068 | **Panel de solicitudes pendientes** | Lista de pendientes y procesadas con persona, departamento, tipo y fechas; aprobar o rechazar | Responsable / Admin | **E** | Existe (`/ausencias/equipo`) | `el-nuevo-panel-de-gestion-de-las-solicitudes-pendientes` (08/07/2025) |
| W-069 | **Cancelar** una solicitud | El empleado cancela las pendientes o aceptadas de fechas futuras; si ya está aprobada, pide la cancelación y el responsable la acepta o la rechaza | Empleado / Responsable | **E** | Adaptar (R3): hoy cancela sola si no ha empezado; añadir «pedir cancelación» | `cancelar-las-solicitudes` (17/07/2026) |
| W-070 | Modificar una solicitud | El responsable o el admin edita fechas o cantidad de una solicitud ya hecha | Responsable / Admin | U | Existe (D-049, `update`) | `modificacion-de-las-solicitudes` (10/06/2024) |
| W-071 | **Justificantes** adjuntos | La solicitud admite adjunto; informe de **justificantes pendientes** | Empleado / Admin | **E** (L-20: «previo aviso y justificación») | Nueva (R3) | `Informe-de-Justificantes-pendientes` (17/09/2026) |
| W-072 | **Reglas de cobertura mínima** | Limitan las vacaciones para que quede un mínimo de personas por departamento, centro, cargo o habilidad | Admin | U | Nueva opcional (R4): aviso, no bloqueo | `reglas-que-hacer-con-esta-funcionalidad` (03/08/2026) |
| W-073 | Habilidades y atributos | Etiquetas por persona para reglas y filtros | Admin | S | No | `Agrupa-a-los-empleados-en-funcion-de-sus-habilidades` (07/05/2026) |
| W-074 | Teletrabajo y viaje como motivo de presencia | Se solicitan (por día u hora) y se aprueban | Empleado | **E** (Ley 10/2021) | Nueva (R1): modo del tramo al fichar; acuerdo de teletrabajo en el perfil | `como-configurar-el-teletrabajo` (03/07/2025) |
| W-075 | Importador de solicitudes | CSV para crear, actualizar o borrar solicitudes, aprobadas o pendientes | Admin | U (migración) | Nueva solo para la migración (R5) | `el-importador-de-solicitudes` (14/09/2026) |

## 5. Calendario y equipo

| ID | Funcionalidad | Qué hace y cómo se usa | Rol | Para Audax | Destino | Fuente |
|---|---|---|---|---|---|---|
| W-076 | **Mi calendario** | Festivos, eventos y días bloqueados, solicitudes (ausencia y presencia) y descansos, con leyenda | Empleado | **E** | Adaptar (R3) | `Visualiza-tu-Calendario` (07/05/2026) |
| W-077 | **Calendario del equipo** | Ausencias, presencias, horarios y turnos por día, semana o mes, con filtros y planificación | Todos (según permiso) | **E** | Existe (calendario de ausencias del equipo) | `el-nuevo-calendario-de-Woffu-mas-dinamico-mas-funcional` (28/07/2026) |
| W-078 | Compartir permisos con el equipo | Que todos vean las ausencias de su departamento o de la empresa para planificar | Admin | **E** | Existe (D-088: se ve «no disponible», el tipo solo quien debe) | `Compartir-el-calendario-de-permisos…` (05/06/2024) |
| W-079 | **Suscripción iCal** | URL del calendario de ausencias para Google, Outlook o iOS | Responsable | U | Nueva (R4): iCal firmado, sin el tipo de ausencia | `Exporta-el-calendario-de-Woffu-en-el-tuyo-de-uso-diario` (30/09/2026) |
| W-080 | Organigrama | Vista en árbol de sedes y departamentos | Admin | S | No | `vista-en-organigrama-de-sedes-y-departamentos` (13/05/2025) |

## 6. Validación, confirmación y trazabilidad

| ID | Funcionalidad | Qué hace y cómo se usa | Rol | Para Audax | Destino | Fuente |
|---|---|---|---|---|---|---|
| W-081 | **Validar ediciones de fichajes** | En Corporativo → Presencia → «Validar», cada día editado sale «Pendiente de validar»; validación una a una o masiva | Responsable / Admin | **E** | Nueva (R1) | `valida-la-modificacion-de-los-fichajes` (29/05/2025); `como-gestionar-el-fichaje-de-mi-equipo` |
| W-082 | Validar desde la app | Lo mismo desde el móvil | Responsable | U | Nueva (R1, la PWA) | `validar-las-ediciones-de-fichajes-desde-la-app` (28/09/2026) |
| W-083 | **Incidencias** | Sin fichajes, fichajes impares (falta la salida) o fichar durante una ausencia aprobada | Todos | **E** | Nueva (R1), con más casos legales (12 h, 6 h, 9 h) | `Informe-de-incidencias` (21/08/2023) |
| W-084 | **Confirmar jornadas** | En «Mi presencia → Jornadas por confirmar» se seleccionan días (máximo 30 cada vez) y se confirman; correo a principios de mes. La propia Woffu lo presenta como **«firma digital con valor legal»** | Empleado | **E** | Nueva (R2): cierre mensual con confirmación o disconformidad | `confirmar-horarios` (04/08/2026); `Cumplimiento-del-registro-horario…` (06/03/2026) |
| W-085 | **Desconfirmar** | Una jornada confirmada ya no se edita; el responsable o un admin la reabre «planificando» de nuevo el periodo | Responsable / Admin | **E** | Nueva (R2): desconfirmar con motivo, en el historial | `confirmar-horarios` |
| W-086 | Quién validó una solicitud | Informe de actividad, columnas «Actualizado» y «Actualizado por» | Admin | **E** | Existe (auditoría y `approved_by`) | `faq-quien-ha-validado-una-solicitud` (04/07/2025) |
| W-087 | **Conservación 4 años** | Al suspender a alguien su información se guarda al menos 4 años y se puede reactivar | Admin | **E** | Adaptar (R2): retención de 48 meses y nunca borrar al desactivar | `consulta-la-informacion-de-un-usuario-eliminado` (18/09/2024); `eliminar-a-un-empleado-a-sin-perder-su-historico` (03/07/2025) |
| W-088 | **Guía de inspección** | Woffu recomienda: (1) subir y publicar el **protocolo de registro de jornada** con confirmación de lectura, (2) validar las incidencias con regularidad, (3) que la plantilla confirme sus jornadas del día 1 al 3, (4) descargar los informes oficiales y (5) acceso inmediato en Excel o PDF | Admin | **E** | Nueva (R2): es el guion de cumplimiento del módulo | `Cumplimiento-del-registro-horario-ante-inspecciones-Administrador` (06/03/2026) |

## 7. Informes y exportaciones

| ID | Funcionalidad | Qué hace y cómo se usa | Rol | Para Audax | Destino | Fuente |
|---|---|---|---|---|---|---|
| W-089 | **Registro mensual de la jornada** | El informe para la Inspección: horas ordinarias y extra del mes con el detalle de horarios y fichajes con hora y minuto | Admin | **E** | Nueva (R2): exportación ITSS | `tipos-de-informes`; `Cumplimiento-del-registro-horario…` |
| W-090 | **Anexo comunicado de horas** | Horas por categoría y mes (extra, complementarias, fuerza mayor). Complementa al anterior | Admin | **E** | Nueva (R2) | ídem |
| W-091 | **Presencia diaria** | El más completo: entrada, salida y pausas, previsto frente a trabajado, incidencias, horas categorizadas, si está confirmada, método de fichaje, validado y validado por | Admin | **E** | Nueva (R2) | `Informe-de-presencia-diario` (21/07/2026) |
| W-092 | Presencia mensual | Totales por persona y mes, planificado frente a trabajado | Admin | **E** | Nueva (R2) | `tipos-de-informes` |
| W-093 | Fichajes | Cada registro con hora, IP, coordenadas y método | Admin | **E** | Nueva (R2) | ídem |
| W-094 | Incidencias | Jornadas con incidencia | Responsable / Admin | **E** | Nueva (R1) | `Informe-de-incidencias` |
| W-095 | Jornadas pendientes de validar | Lista de ediciones sin validar | Responsable / Admin | **E** | Nueva (R1) | `informe-de-presencia-para-consultar-las-jornadas-pendientes-de-validar` |
| W-096 | **Saldos** | Saldos de vacaciones y bolsas por persona | Admin | **E** | Nueva (R3) | `informe-de-saldos` (26/06/2023) |
| W-097 | **Actividad** | Todas las solicitudes y asignaciones de cualquier fecha (también años anteriores) | Admin | **E** | Adaptar (R3) | `informe-de-actividad` (15/05/2026) |
| W-098 | Usuarios | Datos personales, profesionales, contratos, responsables, horario, métodos de fichaje y estado | Admin | U | Existe en parte (`/admin/usuarios`) | `informe-de-usuario` (22/06/2023) |
| W-099 | Personalizar, guardar y compartir informes | Columnas, filtros, orden y agrupación; «Mis informes», «Compartidos conmigo» y favoritos | Admin | U | Adaptar: los filtros de la URL de los informes de Audax | `los-informes-de-woffu` (25/06/2026) |
| W-100 | Exportar a **Excel, PDF y CSV** | Desde cualquier informe; el fichero sale en la campana | Admin | **E** | Existe la infraestructura (Gotenberg y openspout) | ídem |
| W-101 | Exportaciones programadas | Envío automático de un informe por email o a FTP/SFTP | Admin | U | Existe (envíos programados, Fase 9) | ídem |
| W-102 | Informes por responsable | Permisos «Lector» y «Editor de informes» limitados a su equipo | Responsable | U | Adaptar: los informes ya respetan el ámbito del responsable | `informes-personalizados-para-responsables` (16/10/2025) |
| W-103 | Inicio corporativo | Absentismo, quién ha fichado, pendientes de validar y solicitudes de un vistazo | Responsable / Admin | U | Nueva (R4): tarjeta de Inicio | `nuevo-panel-de-inicio-corporativo` (17/02/2025); `bienvenido-a-woffu-edicion-manager` (14/07/2026) |

## 8. Documentos, firma y comunicación

| ID | Funcionalidad | Qué hace y cómo se usa | Rol | Para Audax | Destino | Fuente |
|---|---|---|---|---|---|---|
| W-104 | **Gestor documental** | «Mis documentos», «Corporativos» y «Empleados»: subir, previsualizar, compartir, etiquetar y pedir firma | Todos | U | Nueva parcial (R4): solo documentos de RR. HH. con versión y lectura registrada | `documentos-que-funcionalidades-nos-ofrece` (27/10/2025); `visualizo-los-documentos-compartidos` (21/07/2026) |
| W-105 | **Nóminas** | Envío por email a una dirección de Woffu que las reparte en el espacio de cada persona; la persona las descarga | Admin / Empleado | U | Nueva opcional (R6, P7) | `enviar-la-nomina-en-woffu` (03/07/2025); `consulta-tus-nominas` (08/05/2026) |
| W-106 | Firma de documentos | Firma en paralelo o secuencial de PDF o DOC. Se contrata aparte | Admin / Empleado | S | No: la gestoría ya firma contratos; el acuse de lectura basta para las políticas | `la-firma-digital-de-documentos-en-woffu` (05/09/2024) |
| W-107 | Asistente de documentos (IA) | Responde preguntas sobre los documentos subidos | Todos | S | No (ya hay un asistente con sus reglas, D-225) | `el-chatbot-de-documentos-nuevo-asistente-de-woffu` (07/09/2026) |
| W-108 | **Anuncios** con confirmación de lectura | Comunicados a personas o grupos; tarjeta de pendientes en el inicio; confirmación de lectura | Admin / Responsable | **E** (para el protocolo, W-088) | Adaptar (R2): documentos de RR. HH. con lectura registrada (patrón de `PrivacyNotice`); el resto, el chat | `tablon-de-anuncios` (16/07/2026); `como-recibir-comunicados-en-woffu` (07/05/2026) |
| W-109 | Canal de denuncias | Ley 2/2023. Servicio aparte | Admin | S | No (no obligatorio por debajo de 50) | `Canal-de-denuncias` (13/07/2026) |

## 9. Notificaciones

| ID | Funcionalidad | Qué hace y cómo se usa | Rol | Para Audax | Destino | Fuente |
|---|---|---|---|---|---|---|
| W-110 | **Recordatorio de inicio de jornada** | Unos minutos antes de la hora prevista si no ha fichado | Empleado | **E** | Nueva (R1) | `notificaciones-personaliza-los-avisos` (10/07/2026) |
| W-111 | **Salida no registrada** | Unos minutos después de la hora prevista de salida | Empleado | **E** | Nueva (R1) | ídem |
| W-112 | Jornada no finalizada | Al día siguiente, si no hay fichajes o falta la entrada o la salida | Empleado | **E** | Nueva (R1) | ídem |
| W-113 | Edición de jornada (al responsable) y validada (a la persona) | Avisos del flujo de corrección | Ambos | **E** | Nueva (R1) | ídem |
| W-114 | Presencia pendiente de confirmar | Recordatorio mensual | Empleado | **E** | Nueva (R2) | ídem |
| W-115 | Cambios de horario y de límites | A quien le cambian el horario o las tolerancias | Empleado | U | Nueva (R1) | ídem |
| W-116 | Solicitudes: nueva a corto o largo plazo (15 días), pendiente, aceptada, rechazada, modificada, cancelada, pedir cancelación | Avisos del flujo de solicitudes | Ambos | **E** | Existe en parte (D-049); Adaptar (R3) | ídem |
| W-117 | Configuración de avisos | Se activan o desactivan **para toda la empresa**, no por persona | Admin | — | Audax ya es mejor: preferencias por persona y obligatorios con candado (D-122) | ídem |

## 10. Personas, roles y estructura

| ID | Funcionalidad | Qué hace y cómo se usa | Rol | Para Audax | Destino | Fuente |
|---|---|---|---|---|---|---|
| W-118 | Roles por defecto | Administrador, administrador de centro, responsable y usuario | Admin | **E** | Existe (admin, responsable de departamento y empleado) | `creacion-de-roles-y-asignacion-de-permisos` (12/03/2026) |
| W-119 | Roles personalizados con permisos por módulo | Por ejemplo, un admin sin acceso a documentos o un perfil «gestoría» que solo consulta | Admin | U | Nueva (R2): permiso `manage-people` y, opcional, un perfil de solo lectura para la gestoría | `Detalle-de-los-permisos-en-los-Roles` (13/04/2026); `establecer-el-rol-de-tu-empresa-por-defecto` (07/08/2026) |
| W-120 | Administrador de centro | Admin limitado a un centro | Admin | S | No | `asigna-el-rol-de-administrador-de-centro-de-trabajo` (21/07/2026) |
| W-121 | Departamentos y centros | Departamento = equipo; centro = oficina con sus límites de fichaje | Admin | **E** | Existe (departamentos); centro = calendario (R3) | `como-crear-departamentos-y-centros-de-trabajo` (20/07/2026); `estructura-woffu-una-buena-clasificacion…` (19/03/2026) |
| W-122 | Sedes (varias empresas) y multiusuario | Sociedades en una misma cuenta. Enterprise | Admin | S | No (SPEC §18: sin multiempresa) | `Sedes-como-tener-diferentes-empresas-en-una-misma-cuenta` (21/05/2026); `faq-multiusuario-como-crearlo-en-woffu` (08/07/2025) |
| W-123 | **Alta de persona** | Nombre, email, cargo, departamento, rol, centro, horario y convenio; correo de bienvenida de un solo uso (72 h) | Admin | **E** | Existe (alta e invitación); Adaptar (R1) con horario y calendario | `como-anadir-un-empleado` (20/07/2026); `como-recuperar-la-contrasena-de-un-empleado-a` (21/10/2024) |
| W-124 | Datos laborales del perfil | DNI, NSS («muy recomendable para la Inspección»), fecha de incorporación y de fin, campos personalizados | Admin | **E** | Nueva (R1): `employment_profiles` | `videos-explicativos-para-un-onboarding-exitoso` (20/07/2026); `como-anado-datos-en-el-perfil-de-los-empleados-as` (13/07/2026) |
| W-125 | **Multicontratos** | Historial de contratos con fechas, que activan o desactivan a la persona | Admin | U | Nueva (R1): alta y baja en `employment_profiles` | `multicontratos-en-woffu` (21/10/2024) |
| W-126 | Asignar calendario, horario y convenio | Individual o masivo | Admin | **E** | Adaptar (R1 y R3) | `como-asignar-un-calendario-horario-o-convenio-al-usuario` (04/08/2026) |
| W-127 | Edición masiva | Cambiar responsable, departamento, horario… a muchos | Admin | U | Nueva (R4) | `editar-masivamente-los-datos-de-los-empleados-as` (03/07/2025) |
| W-128 | Suspender y restaurar | Baja sin perder el histórico y reactivación | Admin | **E** | Existe (`UserDeactivator`) | `como-recuperar-a-un-empleado-dado-de-baja` (02/02/2024) |
| W-129 | Mis datos personales | La persona ve y edita sus datos, si el rol lo permite | Empleado | U | Existe (perfil) | `consulta-y-modifica-tus-datos-personales` (07/05/2026); `posibilidad-de-modificar-los-datos-personales-a-los-usuarios` (10/07/2026) |
| W-130 | Importador de usuarios (CSV) | Alta masiva con responsables, centro, horario… | Admin | S | No (30 personas) | `importacion-masiva-csv` (14/09/2026); `excel-de-importacion-masiva` (07/05/2026) |
| W-131 | Candidaturas temporales (bolsa de trabajo) | Enterprise | Admin | S | No | `la-bolsa-de-trabajo-de-empresa-en-woffu` (14/10/2025) |

## 11. Portal, configuración e integraciones

| ID | Funcionalidad | Qué hace y cómo se usa | Rol | Para Audax | Destino | Fuente |
|---|---|---|---|---|---|---|
| W-132 | **Inicio personal** | Tarjetas de Presencia (fichar), Solicitudes, Perfil (saldos) y Calendario, más anuncios | Empleado | **E** | Adaptar (R1): tarjeta «Mi jornada» en Inicio | `nueva-pantalla-de-Inicio-en-los-perfiles-personales` (28/02/2023); `bienvenido-a-woffu-edicion-empleado` (20/07/2026) |
| W-133 | Menú de inicio configurable | Enlaces del inicio personal y página preferida del menú corporativo | Admin | S | Existe (paneles de Inicio reordenables, D-138) | `configuracion-del-menu-de-inicio-personal` (17/07/2025); `inicio-corporativo-Escoge-tu-pagina-preferida` (13/05/2025) |
| W-134 | Idiomas | 7 idiomas | Todos | S | No (español, SPEC) | `Cambiar-el-idioma-en-Woffu` (07/05/2026) |
| W-135 | Logo y colores | Enterprise | Admin | S | Existe (tema de Audax) | `Como-añadir-el-logo-de-tu-empresa-en-woffu` (18/07/2025) |
| W-136 | Seguridad | ISO 27001, 27018 y 27701 | — | — | Audax: servidor propio, doble factor, auditoría y copias (D-076) | `la-seguridad-de-los-datos-en-woffu` (27/06/2023) |
| W-137 | **API pública** | REST con JSON y claves de API; referencia en Swagger (`app.woffu.com/swagger/ui/index`, con sesión iniciada). **Solo en el plan Enterprise** | Admin | — | Para la migración (R5), si Audax la tiene | [woffu.com/en/api](https://woffu.com/en/api/); `los-planes-de-woffu` (15/04/2025) |
| W-138 | Conectores (Biostar, Sage, Fundanet…) | Integraciones con nóminas y otros sistemas. Enterprise | Admin | S | No: CSV para la gestoría (R6) | `Integración-Woffu---Fundanet-a-través-de-API` (27/01/2026) |
| W-139 | Facturación y regularización | Pago por usuario, incluidos los inactivos; solo dejan de cobrarse los suspendidos | Admin | — | Ahorro al darlo de baja | `Guía-de-Regularización-cómo-entender-tu-factura` (26/06/2026); `donde-consulto-mis-facturas` (07/07/2026) |
| W-140 | Woffu Bay, Academy, peticiones de mejora | Descuentos, formación y sugerencias | — | S | No (las sugerencias ya existen, Fase 10) | `woffu-bay…`, `woffu-academy` (17/09/2026), `envia-cualquier-comentario-o-consulta-a-Woffu` (12/05/2026) |

## 12. Planes de Woffu (abril de 2025)

Del artículo `los-planes-de-woffu` (15/04/2025):

| Plan | Para quién | Precio | Lo que importa para Audax |
|---|---|---|---|
| **Lite** | Menos de 25 personas, un centro, política sencilla | 1,5 € por usuario y mes | Fichaje web y app, vacaciones y ausencias, categorización de horas extra, IP y GPS, informes básicos (usuarios, actividad, presencia diaria, fichajes y saldos). 1 calendario, 4 horarios, 1 convenio y 1 centro |
| **Pro** | Flexibilidad y automatización | A medida | Roles personalizados, reglas, fichaje por motivos, informes avanzados, acciones masivas, importadores |
| **Enterprise** | Necesidades complejas | A medida | Turnos, **API pública**, conectores (Sage, Biostar), biometría, logo y colores |

Con unas 30 personas, Audax estaría en **Pro** o en **Lite** si se quedó por debajo de 25 al contratarlo **[inferido]**. En ninguno de los dos hay API: la migración tendría que hacerse con los informes (§13).

---

## 13. Sacar los datos de Woffu (para la migración)

| Qué | Cómo se saca | Qué contiene | Fuente |
|---|---|---|---|
| **Fichajes de los últimos 4 años** | Informe **Fichajes** con el rango de fechas, exportado a CSV o Excel | Cada fichaje con hora, IP, coordenadas y método | `tipos-de-informes`; `los-informes-de-woffu` |
| **Jornadas con correcciones y validaciones** | Informe **Presencia diaria** con las columnas «Validado», «Validado por», «Confirmada», horas por contrato y categorizadas | El diario de cada día y quién validó | `Informe-de-presencia-diario`; `faq-quien-ha-validado-un-fichaje` |
| **El registro oficial** | **Registro mensual de la jornada** y **Anexo comunicado de horas**, en PDF, mes a mes | Lo que se enseñaría a la ITSS | `Cumplimiento-del-registro-horario…` |
| **Ausencias e historial de solicitudes** | Informe **Actividad**, ampliando la fecha de inicio a hace 4 años | Cada solicitud y asignación, con «Actualizado por» | `informe-de-actividad` |
| **Saldos** | Informe **Saldos** el día del corte | Vacaciones y bolsas pendientes por persona | `informe-de-saldos` |
| **Personas** | Informe **Usuarios** | Datos, contratos, responsables y horarios | `informe-de-usuario` |
| **Documentos** | Gestor documental (descarga por persona) | Lo que se haya subido | `documentos-que-funcionalidades-nos-ofrece` |
| **API** | Solo si el plan es Enterprise. La documentación (Swagger) pide iniciar sesión | — | [woffu.com/en/api](https://woffu.com/en/api/) |

**Antes de pedir la baja:** Woffu guarda los datos de una persona suspendida 4 años, pero **no hemos encontrado qué pasa con los datos de la cuenta al darse de baja la empresa**. Hay que preguntarlo a Woffu por escrito y **exportar todo antes** (P2 de `PLAN-RRHH.md`).
