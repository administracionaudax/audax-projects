# Diseño de la Previsión (Nivel 2 de las cargas)

Diseño visual de `/prevision`, de la ficha de un proyecto previsto, de la pestaña «Planificación», de «Estimado frente a real» y de «Mi carga». Se basa en `docs/PLAN-CARGAS.md` §5 a §8 y en las respuestas del propietario de §15, que mandan sobre el resto: P4, P5 (sin %), P6 (la carga sale solo de las asignaciones), P7 (bolsas y fees fuera) y P8 (el empleado ve también los posibles). Decisiones: **D-290 a D-299**.

- **Maquetas** (HTML autocontenido, claro y oscuro, 375 px): `docs/diseno-prevision/index.html` y `01` a `07`.
- **Capturas:** `docs/diseno-prevision/capturas/` (escritorio a 1440 px y móvil a 375 px, en claro y oscuro).
- **Fuentes:** `docs/diseno-prevision/fuente/`. `node docs/diseno-prevision/fuente/construir.mjs` genera los HTML y `node docs/diseno-prevision/fuente/capturas.mjs` hace las capturas. Están fuera de lint y formato (`vite.config.ts`).

---

## 1. Recomendación

**Para `/prevision`, la alternativa A, la «matriz de ocupación»** (`01-prevision-matriz.html`):
- la **fila de cada departamento** es una gráfica de columnas apiladas (real, seguro y posible) frente a su capacidad, con el % debajo. Responde a «¿cabemos en noviembre?» sin abrir nada;
- al **desplegar** un departamento salen sus **personas × semanas (o meses)**, cada celda con el semáforo de la Carga (D-052) y una barra fina de capas. Responde a «¿quién se pasa y por qué?»;
- debajo van los **huecos sin persona**, con «Asignar a…», y los previstos abiertos.

**Por qué esta y no otra:**
1. **Escala a Audax:** 30 personas × 13 semanas (o 12 meses) caben en una pantalla sin perder la lectura. Las barras de B obligan a desplazarse mucho y el cronograma de C crece con cada asignación.
2. **Coherencia:** reutiliza el semáforo, los iconos y las celdas de `/carga` (`LoadCell`, `WorkloadLegend`). Quien sabe leer la Carga sabe leer la Previsión.
3. **Dos preguntas en una vista:** el departamento (agregado, con horas) y la persona (en %), con la misma codificación de capas.
4. Lo bueno de B (columnas frente a la capacidad) está dentro de A, en la fila del departamento. Lo bueno de C (quién está en qué) va a donde hace falta: «Mis asignaciones» en Mi carga, la tabla de asignaciones de la ficha y la Planificación.

| | A · Matriz (recomendada) | B · Barras frente a capacidad | C · Cronograma por persona |
|---|---|---|---|
| Pregunta que contesta mejor | ¿Quién y qué departamento se pasa, y cuándo? | ¿Cuánta capacidad libre tiene cada departamento? | ¿En qué proyectos está cada persona y hasta cuándo? |
| 30 personas × 12 meses | Sí (celdas de 64–88 px) | Mal: 30 tiras pequeñas | Mal: alto variable, mucho desplazamiento |
| Ver la carga sumada | Sí (fila de departamento y semáforo) | Sí | Solo con la franja de % |
| Coherencia con `/carga` | Total | Parcial | Baja (se parece al Gantt) |
| Coste de construirla | Medio (reutiliza la matriz de la Carga) | Bajo | Alto si se puede arrastrar |

---

## 2. Qué hacen otras herramientas y qué no copiamos

- **Runn:** la gráfica de capacidad frente a carga pinta lo confirmado en azul y lo tentativo en **azul claro**, con un interruptor para incluir lo tentativo; los huecos (*placeholders*) tienen carga pero no capacidad. **Copiamos** las capas que se encienden y se apagan y que el hueco sea demanda. **No copiamos** distinguir lo tentativo solo por la claridad del color: no aguanta el daltonismo ni la impresión.
- **Float:** mapa de calor de la asignación por persona y vista de capacidad por disciplina y mes, en azul hasta el 100 % y en rojo claro u oscuro por encima del 100 % y del 150 %. Lo tentativo se distingue en el cronograma. **Copiamos** la vista por grupo y mes con desplegable a persona. **No copiamos** pintar en rojo la celda entera: aquí el exceso lleva icono, texto y el tinte del semáforo de siempre.
- **Productive:** las reservas tentativas van con **rayas grises** y un interruptor «Incluir tentativo» en los indicadores de capacidad. **Copiamos** la trama como señal de «puede no salir». La gris no: perdería que es un previsto.
- **Resource Guru:** lo tentativo va en blanco con el contorno del color del proyecto y **no cuenta** en el mapa de calor; las horas extra, en rojo en la barra de disponibilidad. **No copiamos** que lo tentativo no cuente: la pregunta incómoda primero (PLAN-CARGAS §6.5).
- **Teamdeck:** lo tentativo es una etiqueta de texto. No basta: el ojo no lo ve en una matriz.
- **En general no copiamos:** el color por proyecto en las vistas de carga (con 17 proyectos haría falta un arcoíris, D-012), la probabilidad en % (P5) ni las bolsas o los fees como capa (P7).

Fuentes: [Runn, capacidad](https://help.runn.io/en/articles/11517226-capacity-dashboard) · [Runn, gráficas](https://help.runn.io/en/articles/3780211-how-to-use-charts) · [Float, capacidad](https://support.float.com/en/articles/13847946-capacity-planning-and-resource-scheduling) · [Float, tentativo](https://support.float.com/en/articles/28963-tentative-projects-phases-and-allocations) · [Productive, tentativo](https://help.productive.io/en/articles/8582323-tentative-bookings) · [Productive, indicadores](https://help.productive.io/en/articles/4575430-scheduling-heatmaps) · [Resource Guru, mapa de calor](https://help.resourceguruapp.com/en/articles/3381954) · [Resource Guru, horas extra](https://help.resourceguruapp.com/en/articles/1955778) · [Teamdeck](https://teamdeck.io/features/resource-scheduling/).

---

## 3. Sistema visual común (D-291)

### 3.1 Qué es cada canal
| Qué | Canal | Valor |
|---|---|---|
| **Real** (asignado en proyectos reales) | color | `--chart-1` (azul), relleno sólido |
| **Previsto seguro** | color | `--chart-3` (violeta), relleno sólido |
| **Previsto posible** | color **+ trama** | `--chart-3` en rayas de 2 px a 45° sobre `--posible-bg` (violeta al 16 % en claro y al 22 % en oscuro) |
| **Imputado** (horas reales registradas) | color | `--chart-2` (turquesa) |
| **Capacidad** | línea | 2 px en tinta (`--foreground`), escalonada: baja en festivos y ausencias |
| **Hueco sin persona** | forma | borde discontinuo (como «sin responsable» en el Gantt, D-060) e icono de persona punteada |
| **Ocupación** (carga / capacidad) | estado | semáforo de D-052: tinte de la celda + icono + % en texto |
| **Orden de apilado** | posición | real abajo, seguro encima y posible arriba: lo más seguro sostiene lo menos seguro |
| **Hoy** | línea | 2 px `--brand` |

- **Color = de qué tipo es la hora; la trama = que puede no salir.** Sin la trama, seguro y posible tendrían el mismo violeta: la trama es lo que los separa, y también en daltonismo, en escala de grises y al imprimir.
- **No hay colores nuevos:** las capas usan `--chart-1..3` en su orden fijo (D-012) y los estados son los de siempre.
- **La trama va siempre puesta**, aunque la skill de visualización dice que la textura es opcional. Aquí no decora: es un dato («posible»), sigue la convención del sector (Productive) y es el único canal que distingue seguro de posible sin depender del color. Solo hay una dirección (45°) y una sola capa la usa.
- **No usamos color por proyecto:** la identidad del proyecto va en el texto (tooltip, tablas, cronograma).

### 3.2 Paleta validada (`scripts/validate_palette.js` de la skill `dataviz`)
Superficie de las gráficas: la tarjeta (`--card`), `#ffffff` en claro y `#121c30` en oscuro.

| Tema | Real · Previsto · Imputado | Banda L | Croma | Daltonismo (peor par) | Visión normal (peor par) | Contraste con la tarjeta |
|---|---|---|---|---|---|---|
| Claro | `#0171ff` · `#5e2dad` · `#179fa5` | PASA | PASA | **15,6** previsto↔real (deutan), PASA | **19,7**, PASA | 4,37 · 8,55 · 3,21: PASA |
| Oscuro | `#0868e0` · `#9d74f8` · `#0d9298` | PASA | PASA | **7,6** previsto↔real (protan), AVISO (franja 6–8) | **17,0**, PASA | 3,29 · 5,09 · 4,52: PASA |

Pasa en las dos listas de pares (adyacentes y todos).
- **El aviso del tema oscuro es legal porque hay codificación secundaria:** separación de 2 px entre segmentos, orden fijo de apilado, leyenda siempre visible, trama en lo posible, el nombre en el tooltip y la vista de tabla. Si se quisiera quitar el aviso, habría que tocar los tokens oscuros de D-012 para todas las gráficas: no se propone.
- El gris de contexto de la ficha del previsto («sin este proyecto») es `--muted-foreground` al 38 % sobre la tarjeta. Es de énfasis, no una serie.

### 3.3 Marcas y medidas
- **Columnas:** 20–28 px de ancho como mucho, separación de 2 px en el color de la superficie entre segmentos, **sin esquinas redondeadas** (estilo plano, D-137; en `chart-config.ts`, `BAR_RADIUS = 4` contradice D-137 y conviene pasarlo a 0 en todas las gráficas).
- **Líneas:** 2 px. La **previsión** va con raya discontinua (5/4): es lo único discontinuo, porque significa «proyección».
- **Rejilla:** líneas finas de 1 px en `--border`, siempre continuas.
- **Barra de capas en las celdas:** 6 px de alto. La pista va del 0 al 150 % de la capacidad, con una marca de 2 × 12 px en el 100 %. Por encima del 150 %, un triángulo «▸» al final (el valor exacto, en el texto y el tooltip).
- **Texto:** nunca lleva el color de la serie. Las cifras van en tinta, a 500, con cifras tabulares en tablas y ejes; las grandes de las tarjetas, a 600. Horas redondeadas a la hora («24 h», «1.240 h») y espacio duro antes de «h» y de «%» (D-298).

### 3.4 Semáforo y sobrecarga (D-292)
- Los mismos umbrales, iconos, tintes y textos de D-052 (`thresholds.ts`): «Holgada» por debajo del 70 %, «Equilibrada» hasta el 100 %, «Alta» hasta el 120 % y «Sobrecarga» por encima, siempre con icono y texto.
- **La sobrecarga no recolorea las barras:** la columna atraviesa la línea de capacidad, y el % de debajo va en tinta, a 500 y con el icono «Alta» o «Sobrecarga». Los % normales van en tinta secundaria.
- **Festivos:** un icono de calendario en la **cabecera de la columna**, porque afectan a todos. La **ausencia parcial** de una persona va como una **muesca** en la esquina de su celda (no le quita sitio a la cifra), y su tipo solo lo ve quien puede (D-088). Una semana entera ausente sale como celda gris con «Ausencia».

---

## 4. Pantallas

### 4.1 `/prevision` · A · Matriz de ocupación (recomendada)
**Maqueta:** `01-prevision-matriz.html`. **Capturas:** `01-matriz-*`, `01-matriz-tooltip-*` y `01-matriz-12-meses-*`.

- **Cabecera:** «Previsión del equipo», con los botones «Proyectos previstos (5)» y «+ Nuevo proyecto previsto» (solo con `manage-forecast`).
- **Filtros, en una sola fila arriba:**
  - Horizonte: 2, 3, 6 o 12 meses (3 por defecto, `forecast_default_horizon_months`);
  - Agrupar por: semanas o meses (semanas hasta 3 meses; al pasar a 6 o 12 se cambia solo a meses, pero se puede volver a semanas);
  - Departamento, buscar persona;
  - **Capas**: tres casillas con su muestra (Real, Previsto seguro y Previsto posible), todas marcadas por defecto. Sirven a la vez de leyenda y de filtro: al desmarcar una, las cifras y los % se recalculan sin ella.
  - No hay «Ponderar %» (P5). Todo va en la URL, como en `/carga`.
- **Cifras del horizonte**, cuatro tarjetas: ocupación del equipo (con «solo real: X %»), horas libres, semanas en sobrecarga (con quién) y horas en huecos sin persona (por departamento).
- **Leyenda** encima de la matriz: las capas, la capacidad, el hueco y el semáforo con sus tramos (en móvil, sin los tramos).
- **Matriz:**
  - la primera columna es fija al desplazar;
  - en semanas, la cabecera tiene dos filas (mes, y semana con su lunes), con la semana actual subrayada en `--brand`;
  - **fila de departamento** (78 px): columnas apiladas en horas frente a la capacidad escalonada, con el % debajo de cada columna. Su escala es la del propio departamento (se compara con su capacidad, no con otros). Al pulsar el nombre se despliega o se pliega (`aria-expanded`). Por defecto, en escritorio están desplegados los departamentos con alguna semana «Alta» o «Sobrecarga» y plegados los demás; en móvil, todos plegados;
  - **fila de persona**: avatar, nombre y jornada semanal; cada celda (64 × 40 px en semanas, 88 en meses) lleva el tinte del semáforo, el icono y el % arriba, y la barra de capas abajo;
  - **fila «Sin persona»** por departamento, solo si hay huecos en el horizonte: celdas con borde discontinuo, las horas y una tira con las capas que lo forman. No tienen % porque un hueco no tiene capacidad.
- **Tooltip** de cualquier celda (al pasar el ratón o con el foco), según `ChartTooltipCard`:
  - el título (persona o departamento y periodo);
  - el **% grande** con el icono y el nivel;
  - una fila por proyecto, ordenadas de real a seguro y a posible y, dentro de cada capa, de más a menos horas: «24 h · Kiwi · App fase 2», «20 h · Hotel Mar Azul · Web y branding · posible». Las capas desmarcadas salen atenuadas;
  - el pie: «52 h asignadas de 40 h de capacidad · Se pasa en 12 h», los festivos y las ausencias;
  - en un departamento, como mucho 7 proyectos y «y N proyectos más».
- **Al hacer clic o pulsar Intro:** el panel lateral de la celda, como en `/carga` (`?celda=persona:periodo`), con el mismo desglose y las acciones «Editar asignación» y «Asignar a…» (en los huecos), según los permisos.
- **Debajo:**
  - **Huecos sin persona**: el departamento, el proyecto con su seguridad, cuánto y cuándo, y «Asignar a…». Este botón abre un buscador con la ocupación de cada candidato en esas fechas, como `PersonLoadPicker`, y avisa si alguien se pasa del 100 %;
  - **Proyectos previstos abiertos**.
- **En móvil (375 px):** los filtros pasan a varias filas, las tarjetas van de dos en dos y la matriz se desplaza en horizontal dentro de su caja, con la columna de nombres de 132 px fija.

### 4.2 `/prevision` · B · Barras frente a capacidad (alternativa)
**Maqueta:** `02-prevision-barras.html`. Una tarjeta por departamento:
- una gráfica de columnas apiladas en horas con su eje, la capacidad escalonada con su etiqueta, el % de cada periodo y el **pico** en la cabecera;
- «Ver personas» abre una **tira por persona en % de su jornada** (la línea del 100 % es plana, así que se comparan entre sí), con su pico a la derecha.

Es buena para un resumen de dirección a 6–12 meses, pero lee mal a las personas. Si se quiere, puede ser un modo «Resumen» dentro de la misma página.

### 4.3 `/prevision` · C · Cronograma por persona (alternativa)
**Maqueta:** `03-prevision-cronograma.html`.
- Por persona, una barra por asignación en carriles («Kiwi · App fase 2 · 24 h/sem»), con la franja de % de cada semana encima.
- Los colores son las capas: el real y el seguro en un tinte claro con un filete de 4 px; el posible con trama clara, para que el texto se lea.
- Permitiría **arrastrar** para mover las fechas y **estirar** los extremos, como en el Gantt.

No se recomienda como vista principal: crece con cada asignación y no suma. La forma se reutiliza en «Mis asignaciones» (Mi carga) y en la tabla de asignaciones de la ficha.

### 4.4 Ficha de un proyecto previsto · impacto «sin / con» (D-295)
**Maqueta:** `04-ficha-previsto.html`.
- **Cabecera:**
  - etiquetas «Posible» (con la trama), «Cliente nuevo» y el estado;
  - el título;
  - las acciones «Pasar a seguro», «Crear proyecto real» y «⋯» (vincular con un proyecto existente, marcar como perdido).
- **Datos:** cliente, responsable, fechas, estimación total e importe (este último solo con `view-financials`).
- **Impacto si se coge:**
  - Arriba, **una frase con el peor caso**, generada: «Si se coge, Diseño pasa del 91 % al 101 % la semana 47 ⚠ Alta, y Luis Martín llega al 130 % ⓘ Sobrecarga». Es lo que el propietario necesita para decidir.
  - Debajo, una **rejilla de semanas del proyecto**: filas por **departamento** afectado y por **persona con nombre**. Cada celda es una columna con forma de **énfasis**: «sin este proyecto», en el gris de contexto, más lo que añade, con la trama del previsto, frente a la línea de capacidad. Al pie, «91 → ⚠ 101 %», a 500 y con el icono si el resultado es «Alta» o «Sobrecarga».
  - «Sin» incluye todo lo que está marcado (real, seguro y los demás posibles), y la descripción lo dice.
  - Si el previsto es seguro, lo que añade va en violeta liso en lugar de la trama.
- **Asignaciones:**
  - una tabla con quién (persona, o hueco con avatar punteado), cómo (80 h en total, 50 % de su jornada, 6 h/sem), fechas, total y un **minicronograma** de los meses del proyecto (la barra con la capa del previsto; la de un hueco, además, con borde discontinuo);
  - «Asignar a…» en los huecos;
  - el pie: «Asignado frente a la estimación (320 h): 350 h · ⚠ 30 h más de lo estimado».
- **Al lado:** la seguridad (Segura o Posible, sin %) y el historial.

### 4.5 Pestaña «Planificación» de un proyecto real (D-296)
**Maqueta:** `05-planificacion-proyecto.html`.
- Un aviso de origen: «Viene del previsto «Kiwi · App fase 2»», con un enlace a «Estimado frente a real».
- **Cifras:** plan total, imputado (y su % del plan), **desviación hasta hoy** (imputado frente al plan hasta hoy) y restante, con su ritmo semanal.
- **Plan frente a imputado por semana**, con un solo eje en horas:
  - el plan es una **línea escalonada** azul (las asignaciones; baja en los festivos);
  - lo imputado son **columnas turquesa**; la semana en curso, al 45 % de opacidad;
  - la línea de hoy;
  - si alguien no imputó nada en una semana pasada con plan, se marca debajo con ⓘ y su nombre (de Harvest Forecast).
- **Asignaciones por persona:**
  - quién, cómo y el tramo de fechas (minibarra con hoy);
  - plan, plan hasta hoy e imputado;
  - una **barra de bala**: lo imputado en turquesa frente a la marca azul del plan hasta hoy;
  - la **desviación** con flecha (↗, ↘ o =) y el aviso en ámbar por encima de ±10 %.
- La desviación no usa el semáforo de carga: «Holgada» no tendría sentido aquí.
- Sin selector de «fuente de la carga»: por P6 solo cuentan las asignaciones.

### 4.6 Estimado frente a real (D-297)
**Maqueta:** `06-estimado-frente-a-real.html`, en la ficha del previsto vinculado y en el resumen del proyecto real.
- **Cifras:**
  - estimado (la línea base congelada);
  - real hasta hoy (y su % de lo estimado);
  - **previsión al cerrar**, que es el real más lo que **queda asignado** en el proyecto real (P6: la carga sale de las asignaciones), con la desviación;
  - fechas: el inicio y el fin, estimados y reales o previstos.
- **Horas acumuladas:**
  - estimado en violeta (el color del previsto), real en turquesa (el de lo imputado) y la previsión en turquesa discontinuo hasta el fin previsto;
  - la línea de hoy;
  - **etiquetas directas** al final de cada serie, colocadas para que no choquen, además de la leyenda;
  - un tooltip por semana con los tres acumulados.
- **Por departamento:** una barra de bala. Lo real es turquesa sólido, la previsión es su prolongación con borde discontinuo y la marca violeta es lo estimado. A la derecha, «285 h de 230 h» y la desviación.
- **Por mes:** columnas emparejadas, el estimado (violeta) junto al real (turquesa), con la previsión del mes encima, discontinua. Un solo eje.
- **Por persona:** una tabla con el total.

### 4.7 Mi carga del empleado (D-299)
**Maqueta:** `07-mi-carga.html`.
- **Tarjeta de Inicio:**
  - «Esta semana» y «La semana que viene» con la `LoadCell` de siempre;
  - debajo, las **próximas 12 semanas en % de su jornada**: columnas apiladas con la trama de lo posible, la línea del 100 % y solo el pico etiquetado («ⓘ 130 %»);
  - la lista **«Lo que viene»**, con las asignaciones que empiezan después de hoy y su seguridad. Los posibles se dicen en texto: «Previsto posible: puede no salir · 50 % de tu jornada» (P8).
- **`/carga` del empleado:**
  - cifras: esta semana, el mes que viene (con «64 h son de un posible»), la semana más cargada y las horas libres en 3 meses;
  - **por semana (26)**: columnas apiladas en horas frente a su jornada escalonada, con el % debajo de una de cada dos semanas y siempre que es «Alta» o «Sobrecarga»;
  - **«Mis asignaciones»**: un cronograma de una persona (la forma C).
- El empleado nunca ve la previsión de los demás (P4). Ve los nombres de los previstos que le tocan.

---

## 5. Estados

Las maquetas enseñan el estado con datos. Los demás quedan especificados aquí.

| Estado | Qué se ve |
|---|---|
| **Cargando** (primera vez) | Esqueleto de la matriz: la fila de departamento a 78 px y 6 filas de celdas a 40 px (`Skeleton`). Las tarjetas de cifras con el valor en esqueleto |
| **Recargando** (al cambiar un filtro) | Se queda lo que había al 60 % de opacidad, con «Cargando…» y `Spinner` junto al título, como `/carga`. Sin saltos ni esqueleto |
| **Vacío: sin asignaciones** | `EmptyState` grande (con el degradado de marca, D-137): «Aún no hay nada asignado en estos meses», con «Nuevo proyecto previsto» y «Cómo funcionan las asignaciones» (ayuda, D-208) |
| **Vacío por filtros** | «Ninguna persona coincide con los filtros», con «Quitar filtros» (como `/carga`) |
| **Todas las capas desmarcadas** | La matriz se queda con las capacidades y «Marca al menos una capa para ver la carga» en la leyenda |
| **Sin capacidad** (festivo o ausencia de la semana entera) | Celda gris con su icono y «Ausencia» o «Festivo». Si alguien tiene horas asignadas en un día sin capacidad, el icono de «Carga en un día sin capacidad» (`WorkloadLegend`) |
| **Sin permiso** (empleado o gestor en `/prevision`) | Un 403 con el texto «La previsión es información comercial; la ven los responsables y los administradores» y un enlace a «Mi carga». En el menú, «Previsión» solo aparece con `view-forecast` |
| **Ficha sin asignaciones** | El impacto con «Añade asignaciones para ver el impacto» y el botón «+ Asignación» |
| **Previsto con inicio pasado** | Un aviso arriba: «Empieza en el pasado: actualiza las fechas» (PLAN-CARGAS §6.2) |
| **Estimado frente a real sin vincular** | No se enseña la sección. En la ficha, «Vincúlalo a un proyecto real para comparar» |
| **Importes sin `view-financials`** | El campo no sale (no «—») |

---

## 6. Interacción y accesibilidad
- **Tooltip:** completa y nunca es la única vía. Todo lo que dice está también en el panel de la celda y en **«Ver como tabla»** (`ChartFrame`), que pone la matriz como una tabla de persona × periodo con horas, capacidad, % y nivel, además de una fila por proyecto si se despliega. Sale igual con el foco que con el ratón y Escape lo cierra.
- **Zonas de clic** mayores que las marcas: la celda entera; en las filas de departamento y en las gráficas, todo el ancho del periodo y todo el alto.
- **Teclado:** la matriz es una rejilla con una sola parada de tabulación, como `WorkloadMatrix`:
  - las flechas se mueven entre celdas (de departamento y de persona), Inicio y Fin van al principio y al final de la fila, y Re Pág y Av Pág saltan 5 filas;
  - Intro abre el panel y Espacio despliega un departamento desde su cabecera.
- **Nombre accesible** de cada celda: «Luis Martín, semana 46 (9 nov – 13 nov): 130 %, Sobrecarga, 52 h de 40 h», más «capacidad reducida por ausencia» si toca. En los huecos: «Sin persona · Diseño, semana 47: 21 h sin persona».
- **No solo color:**
  - el nivel lleva siempre icono y texto, y lo posible lleva trama;
  - el hueco tiene borde discontinuo y la previsión, línea discontinua;
  - la desviación lleva flecha y signo, y la ausencia parcial, la muesca.
- **Contraste:** los textos usan los tokens AA del tema (verificados en `tests/js/theme-contrast.test.ts`) y las marcas pasan 3:1 contra la tarjeta (§3.2).
- **Movimiento reducido:** sin transiciones. **Impresión** y `forced-colors`: la trama se mantiene (`forced-color-adjust: none` en la muestra).
- **Textos:** todos por `t()` en `lang/ui/forecast.json` (área nueva), con tuteo.

---

## 7. Especificaciones para React

### 7.1 Se reutiliza tal cual
- `components/charts/thresholds.ts`: `loadPercent`, `loadLevel` y `LOAD_LEVELS` (el nivel sale del % redondeado que se enseña).
- `components/charts/load-cell.tsx`: en «Mi carga» (esta semana y la que viene).
- `components/charts/chart-frame.tsx` (`ChartFrame` y `ChartTable`), `chart-tooltip.tsx` (`ChartTooltipCard`) y `chart-legend.tsx` (`ChartLegend`).
- `components/workload/workload-legend.tsx`, ampliado con las capas.
- `components/workload/workload-cell-panel.tsx` como modelo del panel.
- `components/workload/person-load-picker.tsx` para «Asignar a…».
- `components/empty-state.tsx`, `ui/skeleton` y `ui/spinner`.

### 7.2 Cambios pequeños en lo que existe
- `chart-config.ts`:
  - añadir `LAYER_COLORS = { real: 'var(--chart-1)', confirmed: 'var(--chart-3)', tentative: 'var(--chart-3)', logged: 'var(--chart-2)' }`;
  - añadir `CAPACITY_STROKE = 'var(--foreground)'`;
  - poner `BAR_RADIUS = 0` (D-137).
- `app.css`:
  - `--posible-bg: color-mix(in oklab, var(--chart-3) 16%, var(--card))` (22 % en `.dark`);
  - la utilidad `bg-hatch-tentative` con `repeating-linear-gradient(135deg, var(--chart-3) 0 2px, var(--posible-bg) 2px 5px)`.
- `ChartTooltipCard`: admitir `pattern?: 'hatch'` en `TooltipRow`, para que la clave de lo posible lleve trama.
- `ChartLegend`: admitir la forma `'hatch'` y `'dashed'`.

### 7.3 Componentes nuevos (`resources/js/components/forecast/`)
| Componente | Props | Notas |
|---|---|---|
| `HatchDefs` | — | El `<pattern id="forecast-hatch">` de SVG (rayas de 2 px a 45° de `--chart-3` sobre `--posible-bg`), una vez por página |
| `LayerToggles` | `value: Record<Layer, boolean>`, `onChange` | Tres casillas con muestra (leyenda y filtro a la vez). `Layer = 'real' \| 'confirmed' \| 'tentative'` |
| `StackedCapacityColumns` | `periods: ForecastPeriod[]`, `cells: LayerMinutes[]`, `layers`, `height`, `columnWidth`, `showPercent?`, `yMax?`, `onCellFocus?` | SVG: columnas apiladas en el orden real → seguro → posible, con 2 px de separación, la capacidad escalonada, el % debajo con icono si es «Alta» o «Sobrecarga» y zonas de clic por periodo. La usan la fila de departamento, B, Mi carga y la tarjeta de Inicio (con `normalize="percent"`) |
| `LayerBar` | `minutes: LayerMinutes`, `capacity: number`, `layers` | La barra de 6 px de la celda (0–150 %, con la marca del 100 % y «▸» si se pasa) |
| `ForecastCell` | `cell: ForecastCellData`, `layers`, `active`, `onOpen` | Tinte, icono y % (como `LoadCell`), más `LayerBar` y la muesca de ausencia parcial. El nombre accesible sale de `forecastCellLabel()` |
| `GapCell` | `minutes: LayerMinutes`, `layers` | Borde discontinuo, las horas y una tira de las capas |
| `ForecastMatrix` | `periods`, `groups: ForecastDeptGroup[]`, `layers`, `expanded`, `onToggle`, `openKey`, `onOpen`, `loading` | La rejilla WAI-ARIA, copiando la navegación de `WorkloadMatrix`. La primera columna es fija |
| `ForecastCellTooltip` | `title`, `load`, `capacity`, `items: {project, kind, minutes}[]`, `footer?` | Sobre `ChartTooltipCard`: el % grande y el nivel, con las filas ordenadas por capa y por horas |
| `ImpactGrid` | `periods`, `rows: {label, sub, cells: {capacity, without, added}[]}[]`, `kind` | La rejilla «sin / con» de la ficha; la columna de énfasis va con el gris de contexto |
| `ImpactSentence` | `worstDept`, `worstPerson` | La frase con el peor caso |
| `AllocationsTable` | `allocations`, `range`, `canEdit`, `onAssign` | Con el minicronograma por fila (en las fichas de previsto y real) |
| `PlanVsLoggedChart` | `weeks`, `plan: number[]`, `logged: (number\|null)[]`, `todayIndex`, `missing: Record<number, string[]>` | La línea escalonada del plan, las columnas de imputado y la marca de semana sin horas. Va dentro de `ChartFrame` |
| `BulletBar` | `actual`, `target`, `projected?`, `max` | La barra de bala (Planificación y Estimado frente a real) |
| `DeviationBadge` | `percent` | La flecha, el signo y el aviso por encima de ±10 %; «Igual que lo previsto» por debajo de medio punto (§6.7 del plan) |
| `CumulativeChart` | `days`, `estimated`, `actual`, `projected`, `today` | Las tres líneas con sus etiquetas directas al final y un tooltip por semana |
| `MyForecastCard` | `weeks: MyForecastWeek[]`, `upcoming: UpcomingAllocation[]` | La tarjeta de Inicio. Prop diferida, con su esqueleto |

### 7.4 Datos que necesita la vista (para la rama `prevision-datos`)
Todo en **minutos enteros**, por periodo:
- **por persona:** `{ capacity, real, confirmed, tentative, absentDays, holidays, absenceType? }`, con `absenceType` solo si `canSeeAbsencesOf`;
- **por hueco de departamento:** `{ real, confirmed, tentative }`;
- **por departamento:** `{ capacity, real, confirmed, tentative, gapMinutes }`;
- **detalle de una celda, bajo demanda** (`?celda=`): `{ project: {id, name, kind, url}, minutes }[]`;
- **cifras del horizonte**, ya calculadas.

El % y el nivel los calcula el cliente con `loadPercent` y `loadLevel` sobre las capas marcadas, para que encender o apagar una capa no tenga que volver al servidor.

---

## 8. Dudas abiertas para el propietario
1. **¿A, o A con un modo «Resumen» que sea la B?** La B se lee mejor en una reunión de dirección a 12 meses. Cuesta poco si se hace con el mismo componente de columnas.
2. **«Mi carga» y las tareas.** Por P6, la previsión no cuenta las tareas, pero la `/carga` de corto plazo (esta semana y la que viene) se calcula hoy con las tareas (D-051). ¿La tarjeta de Inicio debe pasar a asignaciones, como en la maqueta, o enseñar las dos («Tareas: 70 % · Asignado: 60 %»)? La maqueta propone solo asignaciones, por coherencia.
3. **¿Qué departamentos se despliegan por defecto?** La propuesta es desplegar los que tengan alguna semana «Alta» o «Sobrecarga» en el horizonte. La alternativa es recordar lo que dejó abierto cada cual.
4. **¿Se puede arrastrar para cambiar fechas en la Previsión?** Solo tiene sentido en la forma C (cronograma). En A, se edita desde el panel de la celda.
5. **Columnas sin esquinas redondeadas** también en las gráficas que ya existen (pasar `BAR_RADIUS` a 0, por D-137). ¿Se cambia en todas a la vez?

---

## 9. Respuestas del propietario (06/10) e implementación
- **§8.1:** solo la vista A; ni modo «Resumen» (B) ni arrastre de fechas (C).
- **§8.2:** «Mi carga» cuenta solo las asignaciones (D-305).
- **§8.3:** el equipo es pequeño (9 personas y una colaboradora externa): todos los departamentos desplegados y lo plegado se recuerda en el navegador (D-302); los colaboradores, en su grupo (D-300).
- **§8.5:** columnas sin esquinas en todas las gráficas (D-307).
- Pantallas hechas en la rama `prevision-pantallas` (D-300 a D-309).
