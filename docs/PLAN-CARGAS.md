# Plan: dos cargas, «Plan del día» y «Previsión»

_Documento de diseño · 06/10/2026 · sin código · base acordada con el propietario el 06/10_

> **Qué cambia en una frase.** Hoy la carga solo sale de un árbol «proyecto → tareas estimadas → días» (`WorkloadPlanner`, D-051). Ese árbol casi nunca existe por adelantado, así que la carga sale vacía o engañosa. Proponemos **dos cargas que conviven**: una a corto plazo, escrita por cada persona en texto libre (**Plan del día**), y otra a medio plazo, por **asignaciones de horas** sin tareas (**Previsión**), que funciona antes de que exista el proyecto y se casa con él cuando llega. La carga por tareas se queda como una fuente más, no como la única.

---

## Índice
1. Objetivos y no objetivos
2. Lo que ya existe y en qué nos apoyamos
3. Cómo lo resuelven otras herramientas (y qué copiamos)
4. Nivel 1 · Plan del día
5. Nivel 2 · Previsión
6. Reglas de cálculo
7. Modelo de datos
8. Permisos
9. Notificaciones mínimas
10. Integración con lo existente
11. Qué se elimina o se simplifica
12. Entregas propuestas
13. Riesgos
14. Preguntas para el propietario

---

## 1. Objetivos y no objetivos

### Objetivos
| # | Objetivo | Cómo sabremos que se cumple |
|---|---|---|
| O1 | **Sustituir la lista «daily» de ClickUp** por algo igual de rápido (escribir el plan en menos de un minuto) | El 90 % de la plantilla escribe su plan los días con jornada durante 4 semanas seguidas |
| O2 | **Vigilar el trabajo del día**: ver de un vistazo qué pretende hacer cada persona y si lo cumple | El responsable responde «¿qué está haciendo Diseño hoy y qué se quedó ayer sin hacer?» en una pantalla, sin preguntar |
| O3 | **Decidir si se coge un proyecto** con la carga a 2, 3 y 6 meses por persona y departamento | Antes de aceptar un proyecto, su ficha prevista dice cuánto sube la ocupación de cada departamento y en qué meses se pasa del 100 % |
| O4 | **Prever sin tareas ni proyecto real**: proyectos previstos (incluso de clientes que aún no existen), con personas o departamentos y horas repartidas entre fechas | Se crea una previsión en menos de 2 minutos, sin crear cliente, proyecto ni tareas |
| O5 | **Aprender a estimar**: casar la previsión con el proyecto real y comparar horas y plazos | Cada proyecto vinculado enseña estimado frente a real (horas, por persona y departamento, por mes; fechas) |
| O6 | **No romper lo que funciona**: la carga por tareas, el calendario, la Weekly, Inicio y el temporizador siguen igual o mejor | Los tests actuales de Carga, Calendario y Weekly siguen en verde |

### No objetivos (por ahora)
- No es un CRM: el proyecto previsto no lleva embudo comercial, contactos ni presupuestos en PDF.
- No es un planificador automático: la app no reparte personas ni propone a quién asignar (sí avisa de sobrecargas).
- El plan del día **no crea tareas** del sistema ni sustituye a Mis tareas (aunque puede enlazarlas).
- No hay contratos de horas por persona distintos de `WorkSchedule` (ya cubre jornadas parciales e intensivas).
- Nada de importes en la Previsión para quien no tenga `view-financials`.

---

## 2. Lo que ya existe y en qué nos apoyamos

| Pieza | Qué hace hoy | Cómo se usa en este plan |
|---|---|---|
| `App\Domain\Time\Capacity` y `CapacityPlan` | Capacidad por persona y día: `WorkSchedule` vigente − festivos − ausencias aprobadas; sumas por tramos sin recorrer días (PERF-05) | **La misma** capacidad para las dos cargas. Se añade la suma por departamento (personas activas del departamento) |
| `WorkloadPlanner` (D-051) | Reparte el restante de cada tarea abierta (estimación − imputado) entre los días con capacidad de su responsable; bandejas «Sin planificar» y «Sin asignar» | Pasa a ser **una de tres fuentes** del «comprometido» (tareas, asignaciones, bolsas con fecha) |
| `/carga` (`WorkloadBoard`, D-052) | Matriz personas × días o semanas, semáforo, panel de celda, reasignar | Suma las asignaciones; nuevo selector de fuente. El horizonte largo se va a **Previsión** |
| Calendario del equipo (D-144) | Tareas por día y vista «Personas» con la carga del día | Su carga usa el combinado; la vista «Día» de una persona enseña su plan del día |
| Mis tareas (D-143) | Mis tareas por imputación reciente, filtros | Botón «Añadir a mi día» |
| Temporizador (`TimerService`, `active_timers.task_id` obligatorio) | Uno por persona, siempre sobre una **tarea**; al parar, entradas en borrador con `TimeEntryWriter` | Se arranca **desde una línea** del plan; la línea resuelve una tarea (sección 6.1.4) |
| `time_entries` (`task_id` y `project_id` obligatorios) | Toda hora vive en una tarea | No cambia; se añade un enlace opcional a la línea del plan |
| Weekly (D-150, D-157): un apunte por cliente y semana; «Autocompletar» (F-048, `MyWeeklyClients`) | Propone texto por cliente con las tareas imputadas | «Autocompletar» añade **las líneas de mi plan de la semana**, agrupadas por cliente |
| Ausencias (D-049, D-088), festivos (D-050), «Estoy fuera» (D-228) | Restan capacidad (las dos primeras) o eximen de la Weekly (la tercera) | Eximen del recordatorio del plan del día; el tipo de ausencia solo lo ve quien puede (D-088) |
| `HourBankCommitment` | Horas comprometidas de una bolsa = estimación restante de sus tareas abiertas | Para no contar dos veces el saldo de una bolsa que ya tiene tareas |
| `projects.status = planned` | Proyecto real aún no empezado | Se mantiene para proyectos **firmados**; las propuestas dejan de crearse como proyectos reales |
| Inicio (`HomeLayout`, D-138) | Tarjetas ordenables | Nueva tarjeta «Mi día»; «Mi carga» usa el combinado |
| Caché por versión (D-086) | Invalidación tras el commit | La Previsión se cachea igual (asignaciones, previstos, jornadas, ausencias) |

---

## 3. Cómo lo resuelven otras herramientas (y qué copiamos)

Revisados los centros de ayuda públicos de Float, Runn, Forecast.app, Teamwork.com, Resource Guru, Productive.io y Harvest Forecast (06/10/2026). La ayuda de Forecast.app no se pudo abrir directamente (error 403): sus datos salen de los extractos del buscador de esas mismas páginas.

### 3.1 Lo que hacen
| Tema | Qué hacen | Fuentes |
|---|---|---|
| **Asignación** | Todas admiten **horas al día, % de capacidad u horas totales**, y reparten el total a partes iguales entre los días laborables. Harvest Forecast añade horas **a la semana o al mes**. Teamwork recalcula el total al cambiar las horas al día, y al revés | [Float](https://support.float.com/en/articles/4188692-allocate-time) · [Runn](https://help.runn.io/en/articles/3386862-managing-assignments) · [Forecast](https://support.forecast.app/hc/en-us/articles/4906277647889-Creating-Allocations) · [Teamwork](https://support.teamwork.com/projects/schedule/allocate-and-manage-resources) · [Resource Guru](https://help.resourceguruapp.com/en/articles/1955394) · [Harvest Forecast](https://help.getharvest.com/forecast/schedule/plan/adding-time-assignments/) |
| **Capacidad** | Jornada (Runn: contratos con fechas) − festivos − ausencias. En Float, Runn y Forecast las **ausencias de día completo** reducen solas las asignaciones; en Runn y Forecast las **parciales no** | [Float](https://support.float.com/en/articles/4385599-people-report) · [Runn: ausencias](https://help.runn.io/en/articles/1659069-time-off-overview) · [Runn: contratos](https://help.runn.io/en/articles/1625881-contracts) |
| **Tentativo / confirmado** | Float: proyecto en borrador, tentativo o confirmado (el tentativo se pinta con contorno y **no admite imputar horas**). Runn y Productive: interruptor **«incluir tentativo»** en la utilización y las gráficas. Forecast: asignación provisional o firme. Resource Guru: lo tentativo no ocupa disponibilidad y tiene además **lista de espera** (trabajo confirmado sin hueco). Teamwork: al confirmar se conservan las asignaciones | [Float: estados](https://support.float.com/en/articles/12042863-project-statuses-and-stages) · [Runn](https://help.runn.io/en/articles/3780190-tentative-projects) · [Forecast](https://support.forecast.app/hc/en-us/articles/36512262486545-Soft-vs-Hard-Project-Allocations) · [Resource Guru](https://resourceguruapp.com/blog/product-updates/tentative-bookings) · [Productive](https://help.productive.io/en/articles/8582323-tentative-bookings) · [Teamwork](https://support.teamwork.com/projects/schedule/tentative-resources) |
| **Probabilidad** | Forecast: «Win chance» por proyecto y una opción para **ponderar** la utilización con ella. Productive: probabilidad por etapa de la oportunidad. Runn no tiene un campo propio (se monta con un campo personalizado) | [Forecast](https://support.forecast.app/hc/en-us/articles/11951561590161-About-Capacity-Planning) · [Productive](https://help.productive.io/en/articles/2179570-winning-a-deal) · [Runn](https://help.runn.io/en/articles/4887764-project-overview-report) |
| **Huecos sin persona (placeholders)** | En todas son **demanda, no capacidad**: un rol o departamento sin persona. Se pasan a una persona con «Buscar persona» o «Asignar a…» y luego «Transferir». Float borra lo imputado al cambiar el marcador por la persona | [Float](https://support.float.com/en/articles/2059673-placeholders) · [Runn](https://help.runn.io/en/articles/4177851-placeholders) · [Runn: buscar persona](https://help.runn.io/en/articles/12105825-find-a-person-to-fulfill-the-placeholder) · [Forecast](https://support.forecast.app/hc/en-us/articles/34568600936721-Staffing-Placeholder-Allocations) · [Teamwork](https://support.teamwork.com/projects/schedule/placeholders) · [Resource Guru](https://resourceguruapp.com/blog/product-updates/resource-placeholders) · [Productive](https://help.productive.io/en/articles/4168381-placeholders) |
| **Real frente a plan** | Float, Runn y Resource Guru **proponen lo planificado en la hoja de horas** (se confirma o se corrige). Runn enseña la desviación en %. Harvest Forecast: mapa de calor real/plan, en rojo si pasa del 100 % o si un periodo pasado está a 0 | [Float](https://support.float.com/en/articles/3616990-time-tracking) · [Runn](https://help.runn.io/en/articles/10008668-filling-missing-actual-hours-quickly) · [Resource Guru](https://resourceguruapp.com/features/timesheets) · [Harvest Forecast](https://help.getharvest.com/forecast/schedule/faqs/weekly-and-monthly-actuals-faq/) |
| **Del previsto al real** | Productive: al ganar la oportunidad se crea un presupuesto que **se lleva las reservas futuras**. Teamwork: al confirmar se conservan asignaciones y presupuesto | [Productive](https://help.productive.io/en/articles/2179570-winning-a-deal) · [Teamwork](https://support.teamwork.com/projects/schedule/tentative-resources) |
| **El día** | Ninguna tiene un «plan del día» con líneas y check. Lo más cercano: el **estado del día** de Float (casa, oficina, viaje) y «My work» de Teamwork (atrasadas, hoy, mañana, esta semana) | [Float](https://support.float.com/en/articles/3567342-statuses) · [Teamwork](https://support.teamwork.com/projects/my-work/use-my-tasks-table-view) |
| **Gráfica de capacidad** | Runn: capacidad efectiva frente a confirmado y tentativo, con el exceso en rojo | [Runn](https://help.runn.io/en/articles/11517226-capacity-dashboard) |

### 3.2 Qué copiamos
1. **Cuatro modos de asignación**: total, horas al día, % y **horas al mes** (de Harvest Forecast; encaja con los fees). Reparto a partes iguales entre días laborables, con nuestra `Capacity` (6.2).
2. **Huecos por departamento** que son demanda y no capacidad, con **«Asignar a…»** que conserva lo hecho (no borramos horas, a diferencia de Float).
3. **Seguro/posible + % opcional** y un interruptor **«Ponderar por probabilidad»** (Forecast), además de capas que se encienden y apagan (Runn, Productive).
4. **El previsto no admite horas** (Float, Runn): la ejecución va siempre en el proyecto real.
5. **Al confirmar y vincular, las asignaciones pasan al real** (Productive, Teamwork), y el previsto queda como línea base.
6. **Proponer lo planificado al imputar**: desde una línea con tarea y horas previstas, «Imputar 2:00» en un clic (Float, Resource Guru, Runn). Va en C3.
7. **Plan frente a imputado con desviación en %** (Runn) y periodos pasados sin imputar en rojo (Harvest Forecast), en la pestaña Planificación.

### 3.3 Qué no copiamos (por ahora)
- La **lista de espera** de Resource Guru: con «posible» y los huecos basta para una agencia de este tamaño.
- **Habilidades y búsqueda de persona por perfil** (Runn): «Asignar a…» filtra por departamento y enseña la ocupación de cada candidato; habilidades sería un catálogo nuevo.
- El **estado de ubicación del día** (Float): se puede escribir en la nota del día; si se pide, un campo en `day_plans` en una v2.
- Las **ausencias parciales que no restan** (Runn, Forecast): en Audax `Capacity` ya resta `partial_minutes`; se mantiene por coherencia con la Carga y los informes.

---

## 4. Nivel 1 · Plan del día

### 4.1 Concepto
Cada persona escribe por la mañana **líneas de texto** con lo que va a hacer ese día. Una línea es una intención, no una tarea:

- **Texto** (obligatorio, hasta 200 caracteres): «Creatividades campaña otoño», «JS del configurador».
- **Cliente o proyecto** (opcional, con buscador; elegir un proyecto fija su cliente). Sin nada = «General / Interno», como la Weekly (F-044).
- **Tarea** (opcional): si la línea ya corresponde a una tarea de Audax.
- **Horas previstas** (opcional, con el parser de duración de siempre: `1:30`, `1.5`, `90m`).
- **Estado**: pendiente → **hecha** (check) · **no hecha** (con motivo opcional) · **pasada a otro día**.

El día se puede planificar con antelación (mañana o cualquier día de esta semana y la siguiente), pero el foco es **hoy**.

### 4.2 Flujos por rol

**Empleado (y cualquier persona interna; los colaboradores externos no, como la Weekly, D-147)**
1. Entra por la mañana. Inicio le enseña la tarjeta «Mi día» o va a **Mi día** (`/dia`).
2. Si ayer dejó líneas pendientes, arriba sale «Tienes 3 pendientes del lunes»: **Pasar todas a hoy** · elegir una a una · **Marcar como no hechas**.
3. Escribe sus líneas: texto + Intro crea la siguiente (como una lista). `@` o `#` abren el selector de cliente/proyecto; `~1:30` pone horas; o con los selectores de la fila. «Desde mis tareas» añade tareas de Mis tareas (que vencen hoy o en las que imputó ayer).
4. Al empezar una línea pulsa ▶: arranca el temporizador (sección 6.1.4). Al pararlo, la app pregunta «¿Das por hecha la línea?».
5. Marca el check al acabar. Puede reordenar arrastrando.
6. Al final del día (o a la mañana siguiente), lo que queda pendiente se pasa o se marca como no hecho.
7. El viernes, en «Mi weekly», **Autocompletar** trae sus líneas de la semana por cliente.

**Responsable de departamento**
1. Su propio día, como el empleado.
2. **Equipo hoy** (`/dia/equipo`): una fila por persona de su departamento (y las demás, según la respuesta a la pregunta P1) con su plan, el progreso, las horas previstas frente a la jornada y qué tiene en marcha el temporizador.
3. Ve en rojo quién **no ha escrito el plan** a la hora límite (ajuste, por defecto 10:00) y quién arrastra líneas varios días («arrastrada ×3»).
4. **Semana**: personas × días (lunes a viernes) con «hechas / planificadas» por celda; clic para ver las líneas.
5. Puede dejar un **comentario** en una línea de su equipo (no editarla).

**Admin**
- Lo mismo que el responsable, para toda la agencia.
- En `/admin/ajustes`: activar el módulo (`day_plan` en los módulos activos de D-151), hora límite y recordatorio, días hacia atrás editables.

### 4.3 Bocetos

**Mi día (`/dia`)**
```
┌─ Mi día ─────────────────────────────────────────────── martes 7 oct ‹ Hoy › ─┐
│  Jornada 8:00 · Previsto 6:30 · Imputado 2:10            [ Mi día | Equipo | Semana ]
│                                                                                 │
│  ⚠ Tienes 2 pendientes del lunes   [Pasar a hoy]  [Elegir…]  [Marcar no hechas] │
│                                                                                 │
│  ☐ ⠿ Creatividades campaña otoño        ACME · Campaña Q4      2:00   ▶  ⋯     │
│  ☑ ⠿ Revisar textos landing             Bodegas Ruiz · Web     1:00   1:05 ✓  ⋯ │
│  ☐ ⠿ JS del configurador  ↻ ×2          Kiwi · Configurador    3:00   ■ 0:42  ⋯ │
│        └ tarea: «Configurador · paso 3»                         (en marcha)     │
│  ☐ ⠿ Reunión interna de producción      General / Interno      0:30   ▶  ⋯     │
│  ＋ Escribe qué vas a hacer…   (@cliente  #proyecto  ~1:30)                     │
│                                                                                 │
│  [＋ Desde mis tareas]                       Hechas 1 de 4 · Previsto 6:30/8:00 │
└─────────────────────────────────────────────────────────────────────────────────┘
  ↻ ×2 = viene arrastrada de 2 días   ■ 0:42 = temporizador en marcha en esta línea
  ⋯ = editar, pasar a otro día, marcar no hecha (motivo), vincular horas, borrar
```

**Equipo hoy (`/dia/equipo`), vista del responsable**
```
┌─ Equipo hoy ─────────────── martes 7 oct ‹ › ── Departamento [Diseño ▾] [Buscar] ─┐
│ Persona            Plan        Hechas   Previsto/Jornada   Ahora                     │
│ ───────────────────────────────────────────────────────────────────────────────── │
│ ▾ Ana López        09:12       2 / 5    7:00 / 8:00  ▮▮▮▮▮▮▮▯  ■ Creatividades ACME │
│     ☑ Banners Black Friday · ACME                      1:30                         │
│     ☑ Ajustes logo · Kiwi                              0:45                         │
│     ☐ Creatividades campaña otoño · ACME   ↻ ×1        2:00                         │
│     ☐ Moodboard · Bodegas Ruiz                         2:00                         │
│     ☐ Reunión producción · Interno                     0:45                         │
│ ▸ Luis Martín      09:40       0 / 3    5:00 / 8:00                                 │
│ ▸ Marta Gil        —  Sin plan (pasada la hora límite)                     [Avisar] │
│ ▸ Pedro Pérez      Ausente                                                           │
│ ▸ Sara Ruiz        08:55       4 / 4    8:30 / 6:00 (jornada intensiva) ▲ 142 %      │
│ ───────────────────────────────────────────────────────────────────────────────── │
│ Diseño: 4 de 5 con plan · 6 de 17 hechas · 3 arrastradas · 1 sin plan               │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

**Semana del equipo (`/dia/semana`)**
```
┌─ Semana 41 · 6–10 oct ────────────────────────────── [Diseño ▾] ‹ Esta semana › ─┐
│ Persona        Lun         Mar         Mié        Jue        Vie      Semana       │
│ Ana López      5/5 ✓       2/5 …       —          —          —        7/10 · 70 %  │
│ Luis Martín    3/4 (1 ↻)   0/3 …       —          —          —        3/7          │
│ Marta Gil      Sin plan    Sin plan    —          —          —        ⚠ 0 días     │
│ Pedro Pérez    Ausente     Ausente     Ausente    Ausente    Ausente  —            │
│ Sara Ruiz      4/4 ✓       4/4 ✓       2 previst. —          —        8/8          │
│ Clic en una celda → sus líneas del día · «2 previst.» = ya planificado a futuro     │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

**Tarjeta «Mi día» en Inicio**
```
┌─ Mi día ─────────────────────── 1 de 4 hechas ─┐
│ ☐ Creatividades campaña otoño   ACME   ▶       │
│ ☐ JS del configurador ↻ ×2      Kiwi   ■ 0:42  │
│ ☑ Revisar textos landing        B. Ruiz        │
│ ＋ Añadir línea…                 Abrir Mi día → │
└────────────────────────────────────────────────┘
```

### 4.4 Comportamientos concretos
- **Hora de publicación del plan:** la primera línea del día fija `published_at`. Las líneas creadas después de la hora límite se marcan «añadida a las 12:40» en la vista del equipo (honestidad sin bloquear).
- **Editar el pasado:** la persona puede cambiar el estado de las líneas de hoy y del último día con jornada; las anteriores son de solo lectura (ajuste `day_plan_editable_days`, por defecto 1). Así el plan no se reescribe a posteriori.
- **Arrastrar:** pasar una línea a otro día crea una línea nueva (con `carried_from_id`) y deja la original como «pasada». El contador «↻ ×N» sale de la cadena. Nunca es automático (pregunta P3).
- **Check y tarea:** si la línea tiene tarea, al marcarla hecha la app pregunta «¿Marcar también la tarea como hecha?» (con `TaskPolicy::update`). Nunca al revés en silencio.
- **Días sin jornada** (festivo, ausencia aprobada de día completo, fin de semana, «Estoy fuera»): no cuentan como «sin plan» y no hay recordatorio.

---

## 5. Nivel 2 · Previsión

### 5.1 Concepto
- **Asignación**: _quién_ (una persona **o** un departamento sin persona, «hueco») × _cuánto_ (horas totales, horas al día, % de dedicación o horas al mes) × _cuándo_ (desde–hasta). Sin tareas.
- **Proyecto previsto**: un contenedor ligero de asignaciones para algo que aún no es un proyecto real. Puede no tener cliente («Cliente nuevo: Hotel Mar Azul»). Lleva **seguridad**: _segura_ (firmado o casi) o _posible_, con una **probabilidad** opcional (%).
- **Vincular**: cuando el proyecto real existe (o se crea desde el previsto), el previsto queda **como estimación congelada** y el real pasa a llevar el plan vivo. La ejecución (tareas, horas) **nunca** se hace en el previsto.
- **Las asignaciones también valen en proyectos reales** sin tareas (un fee de 20 h/mes de Marketing, un desarrollo de 120 h repartidas en noviembre).

### 5.2 Flujos por rol

**Admin o responsable (quien tiene `manage-forecast`)**
1. **«¿Cogemos el proyecto del Hotel Mar Azul?»** → Previsión › **Nuevo proyecto previsto**: nombre, cliente (existente o nombre libre), fechas, seguridad «Posible · 60 %», horas totales estimadas (opcional).
2. Añade asignaciones: «Diseño (sin persona) · 80 h · 3 nov–28 nov», «Luis Martín · 50 % · 10 nov–19 dic», «Desarrollo (sin persona) · 120 h · dic».
3. La ficha enseña al momento el **impacto**: «Con este proyecto, Diseño llega al 118 % en noviembre y Desarrollo al 96 % en diciembre». Comparar «sin / con este proyecto».
4. Decide: **Confirmar** (pasa a «segura»), **Marcar como perdido** (motivo; deja de contar) o lo deja como posible.
5. Cuando se firma: **Crear proyecto real** (rellena cliente —o lo crea si hace falta y se tiene permiso, D-022—, nombre, fechas, `budget_minutes`, miembros = personas asignadas) o **Vincular con un proyecto existente**. Elige si **copiar las asignaciones** al proyecto real como plan de trabajo (por defecto, sí).
6. Más adelante, en el real o en el previsto: **Estimado frente a real**.
7. Sustituye un hueco por una persona: «Asignar a… Ana López» (conserva fechas y horas; avisa si Ana se pasa del 100 %).

**Gestor de proyecto (de un proyecto real)**
- En la pestaña nueva **Planificación** de su proyecto: crea y edita asignaciones de personas del proyecto (o de un departamento) sin necesidad de tareas, y ve plan frente a imputado por persona y semana.
- No ve la Previsión de la agencia (salvo que sea responsable o admin).

**Empleado**
- No ve la Previsión ni los proyectos previstos (es información comercial).
- En **Inicio › Mi carga** y en `/carga` ve su carga combinada: tareas + asignaciones de proyectos reales + (según P8) asignaciones de previstos seguros, con el nombre del proyecto previsto.
- Recibe un aviso cuando le asignan horas (sección 9).

### 5.3 Bocetos

**Previsión del equipo (`/prevision`), por departamento**
```
┌─ Previsión ──────────────────────────────────────────────────────────────────────────┐
│ Horizonte [3 meses ▾]  Por [Semanas | Meses]  Departamento [Todos ▾]                  │
│ Incluir: [✓] Comprometido  [✓] Previsto seguro  [✓] Previsto posible  [ ] Ponderar %  │
│                                                                                        │
│ Diseño · capacidad 640 h/mes                                                           │
│ h  ┤                                    ▓ = posible   ▒ = previsto seguro             │
│ 800┤                 ▓▓                 █ = comprometido (asignaciones+tareas+bolsas)  │
│ 640┤ ─ ─ ─ ─ ─ ─ ─ ─ ▓▓ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ capacidad (jornadas−festivos−ausencias)  │
│    ┤      ▒▒         ▒▒        ▓▓                                                      │
│ 400┤ ██   ▒▒         ▒▒        ▒▒                                                      │
│    ┤ ██   ██         ██        ▒▒                                                      │
│   0┼─────────────────────────────────────                                              │
│      oct          nov          dic                                                     │
│      92 %         118 % ▲      71 %        ← ocupación con lo que está marcado arriba  │
│                                                                                        │
│ Departamento    Capacidad   Comprometido   Seguro   Posible   Libre      oct  nov  dic │
│ Diseño           1.880 h       1.150 h      310 h    290 h    130 h      92  118   71 │
│ Desarrollo       2.240 h       1.620 h      200 h    120 h    300 h      88   96   79 │
│ Marketing        1.120 h         640 h       80 h      0 h    400 h      61   70   54 │
│ Huecos sin persona: Diseño 80 h (nov) · Desarrollo 120 h (dic)       [Ver huecos →]   │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

**Previsión por persona (misma página, «Por personas»)**
```
┌─ Previsión · Diseño · por personas · semanas ────────────────────────────────────────┐
│ Persona        S41   S42   S43   S44   S45   S46   S47   S48   … S01                  │
│ Ana López      95    102   88    120▲  130▲  110   —vac  —vac    70                   │
│ Luis Martín    60    60    75    75    90    90    90    85      40                   │
│ Sara Ruiz(6h)  100   100   100   100   100   100   100   100     0  (sin asignar)     │
│ Hueco Diseño    —     —     —    20h   20h   20h   20h    —      —                    │
│ ─────────────────────────────────────────────────────────────────────────────────── │
│ Clic en una celda → qué la forma: «Campaña Q4 (asignación) 16 h · ACME (tareas) 6 h · │
│ Hotel Mar Azul (posible 60 %) 10 h · Vacaciones 1 día»                                │
└──────────────────────────────────────────────────────────────────────────────────────┘
   % de ocupación con el semáforo de D-052 (azul < 70, verde 70–100, ámbar 100–120, rojo > 120),
   siempre con texto e icono; «—vac» = sin capacidad (el motivo solo a quien puede verlo, D-088).
```

**Ficha de proyecto previsto (`/prevision/proyectos/{id}`)**
```
┌─ Hotel Mar Azul · Web y branding ─────────────────── Posible · 60 % ─ [Confirmar] [⋯] ─┐
│ Cliente: «Hotel Mar Azul» (aún no existe)     Responsable: Chele                         │
│ Fechas: 3 nov → 19 dic       Estimación total: 250 h      Importe estimado: 18.000 €*  │
│                                                                                          │
│ Asignaciones                                                     [＋ Asignación]        │
│ Quién                 Cómo           Desde    Hasta    Total    nov    dic              │
│ Diseño (hueco)        80 h total     3 nov    28 nov    80 h    80     —   [Asignar a…] │
│ Luis Martín           50 %           10 nov   19 dic    96 h    48    48                │
│ Desarrollo (hueco)    120 h total    1 dic    19 dic   120 h     —   120   [Asignar a…] │
│                                                       ───────                            │
│                                         Asignado 296 h · Estimación 250 h ⚠ +46 h       │
│                                                                                          │
│ Impacto si se coge                       sin él    con él                                │
│   Diseño       nov                         98 %   → 110 % ▲                               │
│   Desarrollo   dic                         79 %   →  96 %                                 │
│   Luis Martín  nov                         75 %   → 125 % ▲▲                              │
│                                                                                          │
│ [Crear proyecto real]  [Vincular con proyecto existente]  [Marcar como perdido]          │
│ Historial: creado 06/10 · probabilidad 40 → 60 % el 12/10                               │
└──────────────────────────────────────────────────────────────────────────────────────────┘
  * importes solo con view-financials.
```

**Lista de proyectos previstos (`/prevision/proyectos`)**
```
│ Proyecto                      Cliente           Seguridad      Fechas          Horas  Estado     │
│ Hotel Mar Azul · Web          (nuevo) Mar Azul  Posible 60 %   3 nov–19 dic    296 h  Previsto   │
│ ACME · Campaña primavera      ACME              Segura         ene–mar         180 h  Previsto   │
│ Kiwi · App fase 2             Kiwi              Segura         sep–nov         420 h  Vinculado → KIWI-APP2 │
│ Bodegas Ruiz · Rebranding     Bodegas Ruiz      —              —               —      Perdido (precio) │
```

**Pestaña «Planificación» de un proyecto real (`/proyectos/{id}/planificacion`)**
```
┌─ KIWI-APP2 · Planificación ───────────────── Origen: previsto «Kiwi · App fase 2» ─┐
│ Fuente de la carga: [Automática ▾] (asignaciones si hay; si no, tareas)             │
│ Quién             Cómo        Desde    Hasta    Plan    Imputado   Restante         │
│ Ana López         20 h/sem    1 sep    30 nov   260 h   180 h      80 h             │
│ Luis Martín       160 h       1 oct    15 nov   160 h    40 h     120 h  ⚠ tareas 150 h │
│ Desarrollo (hueco) 0:00       —        —         —        —         —               │
│ Plan frente a imputado por semana: ▁▂▃▅▆▇█  (líneas: plan / barras: imputado)        │
└──────────────────────────────────────────────────────────────────────────────────────┘
```

**Estimado frente a real (en la ficha prevista vinculada y en el resumen del proyecto real)**
```
┌─ Estimado frente a real · Kiwi · App fase 2 ─────────────── vinculado a KIWI-APP2 ─┐
│                      Estimado     Real       Desviación                            │
│ Horas                 420 h      486 h       +66 h  (+16 %) ▲                        │
│ Inicio               1 sep      8 sep       +5 días laborables                      │
│ Fin                  30 nov     (en curso; previsión al ritmo actual: 19 dic)       │
│                                                                                     │
│ Por departamento     Estimado   Real    Desv.      Por persona   Estim.  Real  Desv.│
│ Diseño                 160 h    150 h   −6 %        Ana López    260 h  280 h  +8 % │
│ Desarrollo             260 h    336 h   +29 % ▲     Luis Martín  160 h  206 h +29 % │
│                                                                                     │
│ Curva acumulada  h ┤                    ╭── real                                    │
│                    ┤              ╭────╯ ┄┄┄ estimado                              │
│                    ┤      ╭┄┄┄┄┄┄╯                                                  │
│                    ┼──sep────oct────nov────dic                                      │
│ [Exportar CSV/PDF]                                                                  │
└─────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 6. Reglas de cálculo

### 6.1 Plan del día

1. **Previsto del día** = suma de `planned_minutes` de las líneas del día que no están «pasadas a otro día». Se compara con `Capacity::onDate` (la misma jornada de siempre).
2. **Imputado de una línea** = suma de `time_entries.minutes` con `day_plan_item_id` = la línea (todas, sea cual sea el estado, como el consumo de bolsas, SPEC §8.4). **Imputado del día** = el total de la persona ese día (todas sus entradas), para no esconder lo que se imputó fuera del plan.
3. **Cumplimiento de un día** = líneas hechas / (líneas del día − líneas borradas). Las pasadas a otro día y las no hechas cuentan como no cumplidas ese día. La métrica es para la persona y su responsable (P1); no hay ranking.
4. **Temporizador desde una línea.** `active_timers.task_id` y `time_entries.task_id` son obligatorios y deben seguir así (bolsas, informes, `TimeEntryWriter`). Por eso, al pulsar ▶:
   - si la línea tiene tarea → se usa esa tarea;
   - si solo tiene proyecto → diálogo corto «¿En qué tarea?» con mis tareas de ese proyecto primero (imputadas recientemente, D-143) y la opción **«Crear la tarea «<texto de la línea>» en el proyecto»** (TaskWriter, con la bolsa por defecto del departamento si el proyecto es de bolsas). La tarea elegida se guarda en la línea para la próxima vez;
   - si no tiene nada → mismo diálogo con los proyectos donde imputo, incluido «Interno – Agencia» (D-033).
   - El temporizador guarda `day_plan_item_id`; al pararlo, `TimerService` lo copia a las entradas (también a las dos si cruza medianoche).
   - **Imputar a mano** desde la línea abre el diálogo de horas de siempre (D-172) con la tarea y la línea rellenas.
   - **«Imputar lo previsto»**: si la línea tiene tarea y horas previstas y está hecha sin horas, un clic imputa esas horas (borrador, con `TimeEntryWriter` y todas sus reglas). Lo mismo en bloque al final del día: «Imputar lo previsto de 3 líneas hechas sin horas».
   - **Vincular horas ya imputadas**: desde ⋯, elegir entradas mías de ese día sin línea.
5. **Arrastrar**: ver 4.4. «↻ ×N» = longitud de la cadena `carried_from_id`.
6. **Sin plan**: un día con capacidad > 0, sin «Estoy fuera» y sin líneas a la hora límite.

### 6.2 Asignaciones: cómo se reparten

Todas trabajan por **día** y luego se agregan por semana o mes. «Día laborable de X» = día con capacidad > 0 en `Capacity` (para una persona) o con capacidad > 0 de algún miembro activo (para un departamento).

| Modo | Se escribe | Reparto |
|---|---|---|
| `total` | 80 h entre dos fechas | Como `WorkloadPlanner` (D-051): a partes iguales entre los días laborables del rango; los minutos que sobran a los primeros días; sin días laborables, todo al primero (y se avisa) |
| `per_day` | 4 h al día | 4 h cada día laborable del rango. Si la jornada de ese día es menor, se pone igualmente (y la celda sale en ámbar o rojo) |
| `percent` | 50 % | 50 % de la capacidad de ese día de la persona → las ausencias y los festivos la bajan solos. Para un departamento: % de la jornada por defecto (`default_work_minutes`), es decir «0,5 personas» |
| `monthly` | 20 h al mes, sin fin | Cada mes natural del rango, 20 h repartidas como `total` entre sus días laborables (meses partidos, a prorrata de días laborables). `end_date` puede ser nulo: se extiende hasta el horizonte. Pensado para **fees** (FE) y servicios recurrentes |

- **Restante en proyectos reales** (solo modo `total` con persona): lo que cuenta hacia delante es `max(total − imputado por esa persona en ese proyecto dentro del rango, 0)`, repartido desde `max(hoy, desde)` hasta `hasta`; si ya pasó la fecha de fin y queda restante, se lleva a hoy y se marca «vencida», como una tarea (D-051). Los demás modos son de plan fijo: cuenta lo que cae de hoy en adelante.
- **Proyectos previstos:** siempre plan fijo (no hay horas reales). La parte del rango que ya pasó no cuenta y la ficha avisa «Empieza en el pasado: actualiza las fechas».
- **Más allá de un año**: misma regla que D-051 (jornada semanal vigente al final del año, sin festivos ni ausencias).
- **Hueco (departamento sin persona)**: carga del departamento, no de nadie. En la vista por personas sale como fila «Hueco <departamento>». Al asignarlo a una persona, la asignación cambia de `department_id` a `user_id` y conserva lo demás.

### 6.3 Capacidad
- **Persona**: `Capacity` tal cual (jornada vigente − festivos − ausencias **aprobadas**). Las ausencias **solicitadas** pueden pintarse rayadas como aviso, pero no restan (igual que hoy).
- **Departamento**: suma de la capacidad de sus personas **activas e internas** (sin colaboradores externos, D-134) en cada día. Altas y bajas futuras: con `is_active` no se sabe la fecha de baja; v1 usa el estado de hoy (riesgo R6).
- **Ocupación** = carga / capacidad, con el semáforo de D-052 y el redondeo de la revisión global (el nivel sale del porcentaje que se enseña).

### 6.4 Qué cuenta como «comprometido» (proyectos reales)
Por persona y día, se suman tres fuentes, **sin contar dos veces**:

1. **Asignaciones de proyectos reales** no archivados ni completados (y no en pausa: un proyecto «en pausa» se pinta aparte, rayado, y no suma).
2. **Carga por tareas** (`WorkloadPlanner`), **pero solo para los pares persona × proyecto sin asignación ese día**. Regla «la asignación manda»: si Luis tiene una asignación en KIWI-APP2 del 1 oct al 15 nov, sus tareas de KIWI-APP2 en ese rango no suman; si sus tareas restantes en el rango superan la asignación, la celda y la Planificación avisan «Las tareas superan la asignación en 30 h» (pregunta P6). Fuera del rango de la asignación, las tareas cuentan como hoy.
   - La **fuente de la carga** de un proyecto (`load_source`) puede forzarse: `auto` (por defecto: lo de arriba), `tasks` (ignora sus asignaciones) o `allocations` (ignora sus tareas).
   - Los «huecos» de departamento de un proyecto real absorben su bandeja «Sin asignar»: si Desarrollo tiene un hueco de 120 h en el proyecto, sus tareas sin responsable de Desarrollo no se suman encima.
3. **Saldo de bolsas activas con fecha de fin** sin cubrir: `max(restante de la bolsa − HourBankCommitment − asignaciones del proyecto en ese rango, 0)`, repartido como `total` entre hoy y `end_date`, como **hueco del departamento de la bolsa** (o «Sin departamento»). Es una **capa propia** («Saldo de bolsas») que se puede ocultar, porque una bolsa no siempre se consume entera (pregunta P7). Las bolsas sin fecha de fin no se reparten: salen en una lista «Bolsas sin fecha: X h» con el botón **Crear asignación** (por ejemplo `monthly`).

### 6.5 Previsto, seguridad y probabilidad
- **Seguro** (`confidence = confirmed`): cuenta al 100 % en la capa «Previsto seguro».
- **Posible** (`confidence = tentative`): capa «Previsto posible». Con **«Ponderar por probabilidad»** activado, cada minuto cuenta × probabilidad (60 % → 0,6); sin probabilidad, se usa la del ajuste `forecast_default_probability` (50 %). Desactivado, cuenta entero (escenario pesimista: «¿y si salen todos?»).
- **Perdido** o **vinculado**: no cuenta nunca en la previsión (el vinculado ya está en el real).
- La ocupación que se pinta es siempre la de las capas marcadas; por defecto: comprometido + seguro + posible sin ponderar (la pregunta incómoda primero).

### 6.6 Vincular y convertir
1. **Crear proyecto real desde el previsto**: abre el alta de proyecto de siempre (con `ProjectPolicy::create`, D-022) rellenada: cliente (si no existe y se tiene permiso, primero «Crear cliente «Hotel Mar Azul»»), nombre, fechas, `budget_minutes` = estimación total (o la suma de asignaciones), miembros = personas asignadas. Al guardar, se vincula.
2. **Vincular con uno existente**: buscador de proyectos no archivados del mismo cliente (o de cualquiera si el previsto no tiene cliente). Un proyecto real solo puede tener **un** previsto (único).
3. **Copiar las asignaciones** (casilla, por defecto marcada): se crean asignaciones del proyecto real idénticas (mismas personas o huecos, modos, fechas), con `copied_from_allocation_id`. Las del previsto quedan **congeladas** como línea base (solo lectura).
4. **Congelar**: al vincular se guarda una foto (`baseline`: total estimado, por persona, por departamento, por mes, fechas) en el propio previsto, para que la comparación no cambie si luego alguien toca algo.
5. **Desvincular** (admin): vuelve a «previsto», borra el vínculo, no borra las asignaciones copiadas del real. Auditado.

### 6.7 Estimado frente a real
- **Real** = `time_entries` del proyecto vinculado, con el mismo criterio de estados que la tabla «Estimado frente a real» del proyecto de la Fase 2 (D-081, D-084), para que las dos cifras nunca difieran.
- **Por persona**: su `user_id`; **por departamento**: el departamento de la persona **hoy** (v1; anotar en la ayuda).
- **Por mes**: estimado = reparto de la línea base por mes; real = minutos por mes.
- **Fechas**: inicio real = primera entrada; fin real = la fecha de paso a «completado» (registro de actividad) o, si sigue en curso, una proyección «al ritmo de las últimas 4 semanas».
- **Desviación** = (real − estimado) / estimado. Por debajo de medio punto, «Igual que lo estimado» (como D-079).
- **Informe «Precisión de previsiones»** (en Informes, para admin y responsables): previstos vinculados y cerrados con su desviación de horas y de fechas; media por departamento y por responsable. Es lo que hace aprender a estimar.

---

## 7. Modelo de datos

> Nombres en inglés, textos en `lang/ui/day-plan.json` y `lang/ui/forecast.json` (+ `lang/es/day_plan.php` y `lang/es/forecast.php`). Minutos enteros; importes `decimal`; fechas locales `date`; instantes UTC. Auditoría (spatie) en todas las tablas nuevas.

### 7.1 Plan del día
```
day_plans                                   -- cabecera: una por persona y día
  id
  user_id            FK users  restrictOnDelete
  date               date
  published_at       timestamp null          -- primera línea del día
  note               text null               -- «Hoy tengo médico a las 12», bloqueos
  timestamps
  UNIQUE (user_id, date)

day_plan_items                              -- las líneas
  id
  day_plan_id        FK day_plans  cascadeOnDelete
  user_id            FK users                -- desnormalizado (consultas del equipo)
  date               date                    -- desnormalizado
  position           smallint
  text               varchar(200)
  client_id          FK clients  null  nullOnDelete
  project_id         FK projects null  nullOnDelete
  task_id            FK tasks    null  nullOnDelete
  planned_minutes    smallint unsigned null
  status             varchar(12)  -- pending | done | not_done | carried
  status_changed_at  timestamp null
  not_done_reason    varchar(200) null
  carried_from_id    FK day_plan_items null nullOnDelete
  origin             varchar(12)  -- manual | task | carried
  created_by         FK users null           -- por si un día lo propone el responsable
  softDeletes, timestamps
  INDEX (date, user_id), INDEX (user_id, status, date), INDEX (task_id)

day_plan_comments                           -- comentario del responsable en una línea
  id, day_plan_item_id FK cascade, user_id FK, body text, timestamps

-- cambios en tablas existentes
active_timers.day_plan_item_id   FK day_plan_items null nullOnDelete
time_entries.day_plan_item_id    FK day_plan_items null nullOnDelete  + INDEX
```
- La línea no exige cliente/proyecto/tarea. Si tiene proyecto, `client_id` = el del proyecto (se rellena al guardar).
- `time_entries.day_plan_item_id` es solo un enlace: `TimeEntryWriter` lo acepta en `TimeEntryData` y no cambia ninguna regla de bolsas ni de aprobación.

### 7.2 Previsión
```
forecast_projects                           -- proyecto previsto
  id
  name               varchar(160)
  client_id          FK clients null restrictOnDelete
  prospect_name      varchar(160) null      -- cliente que aún no existe (si client_id es nulo)
  color              char(7)
  description        text null
  owner_user_id      FK users
  confidence         varchar(12)  -- tentative | confirmed
  probability        smallint null          -- 0..100; solo con tentative
  status             varchar(12)  -- open | linked | lost
  lost_reason        varchar(200) null
  start_date, end_date   date null
  estimated_minutes  int unsigned null      -- estimación global (opcional)
  estimated_amount   decimal(12,2) null     -- solo view-financials
  project_id         FK projects null UNIQUE nullOnDelete   -- vínculo con el real
  linked_at          timestamp null, linked_by FK users null
  baseline           jsonb null             -- foto al vincular (6.6.4)
  softDeletes, timestamps
  INDEX (status, confidence), INDEX (start_date, end_date)

allocations                                 -- asignaciones (previstas o de proyectos reales)
  id
  forecast_project_id FK forecast_projects null cascadeOnDelete
  project_id          FK projects null cascadeOnDelete
  user_id             FK users null restrictOnDelete
  department_id       FK departments null restrictOnDelete
  mode                varchar(10)  -- total | per_day | percent | monthly
  minutes             int unsigned null     -- total, por día o por mes según el modo
  percent             smallint null         -- 1..200, modo percent
  start_date          date
  end_date            date null             -- nulo solo en monthly
  task_type_id        FK task_types null    -- «qué tipo de trabajo» (opcional, informativo)
  note                varchar(200) null
  copied_from_allocation_id FK allocations null nullOnDelete
  created_by          FK users
  softDeletes, timestamps
  CHECK ((forecast_project_id IS NULL) <> (project_id IS NULL))     -- de uno u otro
  CHECK ((user_id IS NULL) <> (department_id IS NULL))               -- persona o hueco
  CHECK (end_date IS NULL OR end_date >= start_date)
  INDEX (user_id, start_date, end_date), INDEX (department_id, start_date, end_date)
  INDEX (project_id), INDEX (forecast_project_id)

-- cambios en tablas existentes
projects.load_source   varchar(12) default 'auto'   -- auto | tasks | allocations
```

### 7.3 Relaciones
```
users 1─n day_plans 1─n day_plan_items n─1 clients / projects / tasks
day_plan_items 1─n time_entries (enlace opcional)   active_timers n─1 day_plan_items
forecast_projects n─1 clients (opcional) · 1─1 projects (opcional, al vincular)
forecast_projects 1─n allocations n─1 users | departments
projects 1─n allocations
capacidad ← work_schedules + holidays + absences (Capacity, sin cambios)
```

### 7.4 Ajustes nuevos (`settings`)
`day_plan_deadline` (10:00), `day_plan_reminder_enabled` (sí), `day_plan_editable_days` (1), `forecast_default_probability` (50), `forecast_default_horizon_months` (3), y el módulo `day_plan` (y `forecast`) en `modules` (D-151).

### 7.5 Servicios de dominio (nombres orientativos)
- `App\Domain\DayPlan\DayPlanWriter` (crear, ordenar, check, pasar, vincular horas; único punto de escritura, como `TimeEntryWriter`).
- `App\Domain\DayPlan\DayPlanBoard` (Mi día, Equipo, Semana; con `WorkloadScope`-like para el alcance).
- `App\Domain\Forecast\AllocationPlanner` (reparto por día de las asignaciones, por tramos de `CapacityPlan`).
- `App\Domain\Forecast\LoadCombiner` (6.4: tareas + asignaciones + bolsas, sin dobles; lo usan `/carga`, Calendario, Inicio y Previsión).
- `App\Domain\Forecast\ForecastBoard` (capas, horizonte, agregación semana/mes, departamento/persona).
- `App\Domain\Forecast\ForecastLinker` (crear real, vincular, copiar asignaciones, foto, desvincular).
- `App\Domain\Forecast\EstimateVsActual` (6.7).
- Casos compartidos PHP/TS en `tests/fixtures/allocations.json` (reparto por modos, festivos, ausencias, meses partidos).

---

## 8. Permisos

| Acción | Admin | Responsable | Gestor (de su proyecto) | Empleado | Colaborador |
|---|---|---|---|---|---|
| Escribir **mi** plan del día | ✓ | ✓ | ✓ | ✓ | ✗ (D-134, como la Weekly) |
| Ver el plan de otros (textos y checks) | todos | su departamento (o todos, P1) | — | según P1 | ✗ |
| Ver métricas del plan de otros (cumplimiento, previsto/jornada, temporizador) | todos | su departamento | ✗ | ✗ (D-021) | ✗ |
| Comentar una línea de otro | ✓ | su departamento | ✗ | ✗ | ✗ |
| Editar la línea de otro | ✗ (nadie) | ✗ | ✗ | ✗ | ✗ |
| Ver **Previsión** (`/prevision`) | ✓ | ✓ (todas las personas en lectura; P4) | ✗ | ✗ | ✗ |
| Crear/editar **proyectos previstos** y sus asignaciones | ✓ | ✓ (`manage-forecast`) | ✗ | ✗ | ✗ |
| Ver importes estimados | `view-financials` | si lo tiene | ✗ | ✗ | ✗ |
| Asignaciones de **proyectos reales** | ✓ | personas de su departamento y huecos de su departamento | su proyecto | ✗ | ✗ |
| Vincular / crear proyecto real desde previsto | ✓ | ✓ si puede crear el proyecto (D-022) | ✗ | ✗ | ✗ |
| Desvincular | ✓ | ✗ | ✗ | ✗ | ✗ |
| Ver **mis** asignaciones en Mi carga | ✓ | ✓ | ✓ | ✓ (P8) | ✗ |

- Permiso nuevo **`manage-forecast`** (por defecto: admin y responsables, como `manage-weeklies`, D-147). Gates: `use-day-plan`, `view-team-day-plan`, `view-forecast`, `manage-forecast`, `manage-allocations` (por proyecto, en `ProjectPolicy`).
- **Ausencias**: en Plan del día y Previsión, «Ausente / Sin capacidad» a todos; el tipo solo con `canSeeAbsencesOf` (D-088).
- Policies `DayPlanItemPolicy`, `ForecastProjectPolicy`, `AllocationPolicy`, con tests de cada fila de la tabla.

---

## 9. Notificaciones mínimas
Todas por el catálogo y las preferencias de D-073/D-122 (se pueden apagar), y respetando ausencias, festivos y «Estoy fuera».

| Aviso | A quién | Cuándo | Canal por defecto |
|---|---|---|---|
| «Aún no has escrito tu plan de hoy» | la persona | a la hora límite (10:00), solo días con jornada y sin plan | app + navegador |
| «Te han asignado 40 h en «Campaña Q4» (3–28 nov)» | la persona asignada | al crear o cambiar una asignación suya de un proyecto real o de un previsto **seguro** | app (+ resumen diario) |
| «El proyecto previsto «Hotel Mar Azul» empieza en 7 días y sigue sin vincular» | responsable del previsto | 7 días antes de `start_date` | app |
| «Diseño supera el 110 % en las próximas 8 semanas» | responsables de ese departamento y admins | en el **resumen semanal de los lunes** (D-047), no como aviso suelto | email (el de siempre) |
| Comentario en una línea | la persona | al comentar | app |

Nada más: ni aviso por cada check ni por cada línea arrastrada.

---

## 10. Integración con lo existente

| Pieza | Cambio |
|---|---|
| **Carga (`/carga`)** | La matriz usa `LoadCombiner`. Selector «Fuente»: Todo (por defecto) · Solo tareas · Solo asignaciones. El panel de una celda lista tareas **y** asignaciones (con su modo). El horizonte «Próximos 3 meses» enlaza a Previsión. Las bandejas «Sin planificar»/«Sin asignar» excluyen los proyectos en `load_source = allocations` y las tareas cubiertas por un hueco |
| **Calendario del equipo (D-144)** | La carga de la vista «Personas» usa el combinado. En la vista **Día** de una persona, un desplegable «Plan del día» con sus líneas (con los permisos de 8) |
| **Inicio** | Tarjeta nueva **«Mi día»** (en `HomeLayout::CARDS`, oculta a colaboradores). «Mi carga» usa el combinado y separa «tareas» y «asignaciones» en la ayuda |
| **Weekly (D-157, F-048)** | «Autocompletar» añade por cliente las líneas de la semana: `- Creatividades campaña otoño (hecho, 2:10)`. Las líneas sin cliente van a «General / Interno». No cambia nada del ciclo, el informe ni las exenciones |
| **Temporizador** | ▶ en cada línea (6.1.4). La cabecera muestra «en «Creatividades campaña otoño»» si viene de una línea |
| **Mis tareas (D-143)** | Acción «Añadir a mi día» (y a «mañana») en cada fila y en el panel de la tarea |
| **Proyecto real** | Pestaña nueva **Planificación** (asignaciones, plan frente a imputado, fuente de la carga). En el **Resumen**, si viene de un previsto: tarjeta «Estimado frente a real» |
| **Cliente** | En la ficha, los previstos abiertos del cliente (solo con `view-forecast`) |
| **Informes** | «Precisión de previsiones» (6.7). Las exportaciones con totales llevan minutos enteros (D-081) |
| **Búsqueda global** | «Mi día», «Equipo hoy», «Previsión» y los proyectos previstos (solo con `view-forecast`) |
| **Importación de ClickUp** | Opcional: importar la lista «daily» de las últimas semanas como líneas hechas (si se quiere histórico). Los fees (FE) pueden generar una asignación `monthly` del departamento (asistente, no automático) |
| **RGPD (D-075)** | El plan del día y su cumplimiento son datos de desempeño: deben ir en el texto del asesor, en la exportación de datos personales y en la retención |
| **Datos de ejemplo** | `DemoDataSeeder` crea planes de los últimos 30 días, 3 previstos (posible, seguro, vinculado) y asignaciones de fee |

---

## 11. Qué se elimina o se simplifica
- **Se deja de exigir el árbol completo**: un proyecto con asignaciones ya «carga» sin tareas estimadas. La bandeja «Sin planificar» deja de molestar en esos proyectos.
- **El horizonte de 3 meses de `/carga`** pasa a Previsión (que lo hace mejor, por meses y por departamento). `/carga` queda para el corto plazo (semana, semana que viene, 4 semanas).
- **No se crean proyectos reales para propuestas** (hoy la única forma de «prever» era crear un proyecto `planned` con tareas inventadas). Recomendación: revisar los `planned` sin horas y convertirlos en previstos (asistente de una vez, opcional).
- **La lista «daily» de ClickUp** se apaga cuando la adopción llegue al O1.
- **No se toca**: `WorkloadPlanner`, `Capacity`, `TimeEntryWriter`, `HourBankLedger` ni las reglas de bolsas. Solo se añaden consumidores y un enlace opcional en `time_entries`.

---

## 12. Entregas propuestas
Tamaño relativo: **S** (≈ 1–2 días), **M** (≈ 3–5), **L** (≈ 1–2 semanas), **XL** (más). Cada una con Pest, Vitest y Playwright (E2E) y su decisión en `DECISIONES.md`.

| # | Entrega | Incluye | Tamaño | Depende de |
|---|---|---|---|---|
| **C1** | **Mi día** | Tablas `day_plans`, `day_plan_items`; `DayPlanWriter`; `/dia` con crear, ordenar, check, no hecha, pasar a otro día, arrastrar pendientes; tarjeta de Inicio; módulo `day_plan` | M | — |
| **C2** | **Equipo hoy y Semana** | `/dia/equipo` y `/dia/semana`, alcance y permisos, comentarios, «sin plan», recordatorio de la hora límite, ajustes | M | C1 |
| **C3** | **Temporizador e integración** | ▶ desde la línea (diálogo de tarea, crear tarea rápida), `day_plan_item_id` en timers y entradas, vincular horas, «Imputar lo previsto», «Añadir a mi día» en Mis tareas, Autocompletar de la Weekly, vista Día del calendario | M | C1 |
| **P1** | **Asignaciones en proyectos reales** | Tabla `allocations`, `AllocationPlanner` (4 modos, festivos, ausencias, restante, >1 año), `LoadCombiner` (6.4), pestaña Planificación, `/carga` e Inicio con el combinado, `load_source`, fixtures PHP/TS | L | — |
| **P2** | **Proyectos previstos** | `forecast_projects`, lista y ficha, seguridad y probabilidad, huecos y «Asignar a…», impacto «sin / con», permisos `manage-forecast` | L | P1 |
| **P3** | **Vista Previsión** | `/prevision` 2–12 meses, por departamento y por persona, capas, ponderación, celda explicada, saldo de bolsas, caché por versión, rendimiento (30 personas × 12 meses < 300 ms) | L | P1, P2 |
| **P4** | **Vincular y comparar** | Crear real desde previsto, vincular, copiar asignaciones, foto, desvincular, «Estimado frente a real», informe «Precisión de previsiones», exportación | M | P2 |
| **P5** | **Cierre** | Avisos de asignación y de previsto sin vincular, resumen de los lunes, búsqueda, datos de ejemplo, RGPD, revisión adversarial y E2E completos | M | todo |

**Orden recomendado:** C1 → C2 (se puede apagar la daily de ClickUp en unas dos semanas) → P1 → P2 → P3 → C3 → P4 → P5. C3 puede ir en paralelo a P1 si hay dos agentes (no comparten tablas salvo `time_entries`, que solo toca C3).

---

## 13. Riesgos

| # | Riesgo | Mitigación |
|---|---|---|
| R1 | **Adopción del plan del día** («otro sitio más donde escribir») | Escribir debe ser tan rápido como ClickUp: Intro para la siguiente línea, atajos `@#~`, arrastrar pendientes con un clic, tarjeta en Inicio. Recordatorio a la hora límite. Apagar ClickUp al llegar al O1 |
| R2 | **Sensación de vigilancia** y RGPD (datos de desempeño) | Sin ranking ni puntuación; métricas solo para la persona y su responsable; texto del asesor (D-075); retención y exportación de datos personales |
| R3 | **Contar dos veces** (asignación + tareas + bolsa del mismo trabajo) | Regla 6.4 con un solo `LoadCombiner` usado por todas las vistas; avisos de «tareas superan la asignación»; tests con casos de solape |
| R4 | **Previsiones que nadie actualiza** (fechas pasadas, posibles eternos) | Aviso 7 días antes; previstos con inicio pasado marcados en rojo en la lista; filtro «Desactualizados» |
| R5 | **Confusión de conceptos** (línea, tarea, asignación, hueco) | Ayuda contextual en cada pantalla, nombres fijos en la UI, tutorial en el centro de ayuda (D-208) |
| R6 | **Capacidad del departamento** con altas y bajas futuras | v1 con la plantilla activa de hoy; si se pide, «contratación prevista» como hueco con capacidad en una v2 |
| R7 | **Rendimiento** a 6–12 meses de todo el equipo | `CapacityPlan` por tramos, una consulta por fuente, caché por versión (D-086), presupuesto de consultas en tests |
| R8 | **Temporizador sin tarea** | Nunca se relaja `task_id`: la línea siempre resuelve una tarea (6.1.4); si no, no hay temporizador |
| R9 | **Rama y despliegue**: se trabaja sobre `fase-10` con la Weekly a medias | Rama propia (`cargas`) desde `main` tras fusionar la Fase 10, o desde `fase-10` si se quiere ya; migraciones solo aditivas |

---

## 14. Preguntas para el propietario
Máximo 8. Cada una con opciones y la recomendada (★).

**P1 · ¿Quién ve el plan del día de los demás?**
- a) ★ Toda la plantilla ve los textos y los checks de todos (como la lista de ClickUp); las cifras (horas previstas, cumplimiento, temporizador) solo la propia persona, su responsable y los admins.
- b) Solo los responsables (su departamento) y los admins.
- c) Todo para todos, cifras incluidas.

**P2 · ¿Recordatorio por la mañana?**
- a) ★ Sí, a las 10:00 a quien no ha escrito su plan (solo días con jornada), y el responsable lo ve marcado en «Equipo hoy».
- b) Sí, y además un aviso al responsable con la lista de quién falta.
- c) No; cada uno a su ritmo.

**P3 · ¿Qué pasa con lo que se queda sin hacer?**
- a) ★ Al día siguiente se propone «Pasar a hoy» con un clic (todas o una a una); queda la marca «↻ ×N».
- b) Se pasa solo al día siguiente.
- c) Se queda en su día; cada uno lo reescribe.

**P4 · ¿Quién crea y quién ve los proyectos previstos?**
- a) ★ Crean y editan admins y responsables (`manage-forecast`); todos los responsables ven toda la Previsión (para decidir hace falta ver a todos); los empleados no la ven.
- b) Solo los admins.
- c) También los gestores de proyecto.

**P5 · ¿Cómo se expresa la seguridad de un previsto?**
- a) ★ Dos estados (segura / posible) y un % opcional en los posibles, con un interruptor «Ponderar por probabilidad» en las gráficas.
- b) Solo dos estados, sin %.
- c) % obligatorio en todos (embudo ponderado).

**P6 · Si un proyecto real tiene asignaciones y también tareas estimadas, ¿qué manda?**
- a) ★ La asignación, por persona y proyecto, en su rango de fechas; las tareas solo fuera de ese rango; aviso si las tareas la superan.
- b) Se suman las dos.
- c) La mayor de las dos cada día.

**P7 · ¿Cómo entran las bolsas y los fees en la previsión?**
- a) ★ Las bolsas con fecha de fin, como capa «Saldo de bolsas» que se puede ocultar; las bolsas sin fecha y los fees, solo si se les crea una asignación (con un botón, p. ej. «20 h al mes de Marketing»).
- b) Todas las bolsas con saldo, automáticamente (repartidas a 3 meses si no tienen fecha).
- c) No entran: solo asignaciones y tareas.

**P8 · ¿Qué ve el empleado de la previsión en «Mi carga»?**
- a) ★ Sus asignaciones de proyectos reales y de previstos **seguros** (con el nombre del previsto); nunca los posibles.
- b) Todo, también los posibles.
- c) Solo proyectos reales.

## 15. Respuestas del propietario (06/10/2026)
- **P1, quién ve el plan del día:** a) Toda la plantilla ve los textos y los checks de todos; las cifras, solo la persona, su responsable y los admins.
- **P2, recordatorio:** sí, a las **8:30** (no a las 10:00), a quien aún no ha escrito su plan, solo en días laborables suyos. El responsable lo ve marcado en «Equipo hoy».
- **P3, pendientes:** a) «Pasar a hoy» con un clic, todas o una a una, con la marca «↻ ×N».
- **P4, quién crea y ve los previstos:** a) Crean y editan los admins y los responsables (`manage-forecast`); todos los responsables ven toda la previsión; los empleados no ven la previsión global.
- **P5, seguridad:** b) **solo segura o posible, sin %.**
- **P6, qué manda en la carga:** las horas estimadas de las tareas **no se tienen en cuenta** en la previsión. La carga de un proyecto real sale de él, de las horas asignadas a sus personas, y el proyecto estimado va aparte como línea base para comparar lo estimado con lo real. Pendiente de confirmar la interpretación con el propietario.
- **P7, bolsas y fees:** c) **no entran:** solo asignaciones.
- **P8, «Mi carga» del empleado:** b) **todo**, también los previstos posibles.

## C1-C3 (hecho)
Hecho el 07/10/2026 en la rama `plan-del-dia` (D-250 a D-256 en `docs/DECISIONES.md`), con las respuestas de §15:
- **C1 · Mi día:** `day_plans`, `day_plan_items` (con `carry_count`) y `day_plan_comments`; `DayPlanWriter`; `/dia` con escribir (Intro, `@`, `#`, `~`), cerrar, no hecha, pasar a otro día, «Pasar a hoy» las pendientes, ordenar, nota y «Desde mis tareas»; tarjeta «Mi día» en Inicio; módulo `day_plan` (activado por defecto).
- **C2 · Equipo hoy y Semana:** `/dia/equipo` y `/dia/semana`, textos y checks para todos y cifras solo para la persona, su responsable y los admins; comentarios; «Sin plan» y «Recordar»; recordatorio a las **8:30** (hora límite única, `day_plan_deadline`) solo en días con jornada; ajustes en `/admin/ajustes`.
- **C3 · Temporizador e integración:** ▶ desde la línea (tarea propia, elegida o nueva con su texto), `day_plan_item_id` en temporizadores y entradas, «¿Das por hecha la línea?», «Imputar lo previsto», vincular horas, «Añadir a mi día» en Mis tareas, «Autocompletar» de la Weekly y la vista Día del calendario.
- **Además:** búsqueda global, RGPD (exportación, retención de 12 meses y mención en el texto del asesor), auditoría y datos de ejemplo de cuatro semanas.

