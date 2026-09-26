# Plan Fase 2: informes y dashboards

## Contexto
La Fase 1 deja el núcleo en marcha: clientes, proyectos, bolsas, tareas, horas y aprobación, con 12 meses de datos de ejemplo. La Fase 2 construye el segundo dolor principal del SPEC: **saber cómo va la agencia** (SPEC §10). Incluye:
- las métricas de §10 con sus definiciones visibles,
- los filtros globales en la URL,
- los seis dashboards: dirección, cliente, proyecto, departamento, persona e informe detallado,
- la exportación a XLSX y CSV, el PDF de consumo de bolsa para el cliente y la exportación de horas para facturar,
- el resumen semanal de productividad por email a los responsables.

**Aceptación (SPEC §17):**
- las métricas coinciden con cálculos manuales en tests con datos controlados,
- los dashboards cargan en menos de 1 s con los seeders de 12 meses.

Se trabaja en modo autónomo (D-027): el plan se ejecuta sin esperar aprobación.

## Decisiones de la fase (se registran como D-043 y siguientes)

### D-043 · Ingreso estimado **[concreta el SPEC §10]**
- **Qué cuenta:** solo las horas facturables (`is_billable`). Los proyectos internos dan 0.
- **Tarifa de cada entrada:** la instantánea si está aprobada o bloqueada. Si no, la vigente según la prioridad bolsa > proyecto > cliente > persona (la misma de la aprobación, D-034).
- **Bolsas con `price_amount`:** los minutos dentro de la bolsa valen `price_amount × minutos dentro / total de la bolsa`; el exceso se valora a la tarifa de la bolsa (o la siguiente en la prioridad). Una bolsa sin `price_amount` se valora entera a tarifa.
- **Precio cerrado:** el importe se reparte según el avance.
  - La base es el mayor de: el presupuesto de horas, la suma de las estimaciones de las tareas raíz y lo imputado hasta hoy.
  - El ingreso de un periodo es `importe × minutos facturables del periodo / base`, y lo acumulado nunca supera el importe.
- **Por horas sin bolsa:** minutos facturables × tarifa.
- **Coste:** minutos × la instantánea de coste si existe; si no, el coste por hora actual de la persona.
- **Rentabilidad:** ingreso − coste, y el margen en %. Solo con `view-financials`.

### D-044 · Quién ve cada informe **[concreta D-021 para la F2]**
| Informe | Admin | Responsable | Gestor de proyecto | Empleado |
|---|---|---|---|---|
| Dirección | ✅ todo | ✅ limitado a su departamento | ❌ | ❌ |
| Departamento | ✅ | ✅ los suyos | ❌ | ❌ |
| Cliente | ✅ | ✅ | ✅ solo sus proyectos | ❌ |
| Proyecto | ✅ | ✅ | ✅ los suyos | ❌ |
| Persona | ✅ | ✅ su equipo | ❌ | ✅ solo la suya |
| Detallado | ✅ | ✅ con `TimeEntry::visibleTo` | ✅ con `visibleTo` | ✅ solo lo suyo |

- **Datos económicos** (ingreso, coste, margen, tarifas): solo con `view-financials`.
- **Agregados sin datos por persona** (horas por proyecto, bolsa o tipo): se ven en las fichas a las que ya se tiene acceso.

### D-045 · Exportación
- **XLSX y CSV:** con **OpenSpout 5.12** (MIT), en streaming y sin cola hasta 20.000 filas. La exportación respeta los filtros y los permisos del informe.
- **PDF de consumo de bolsa:** con **FPDF 1.9** (MIT). Usa las fuentes estándar del PDF (Helvetica), porque incrustar DM Sans exigiría convertirla con herramientas externas; la marca va en el logotipo (dibujado en vectorial o como PNG) y en los colores. Incluye:
  - logo y nombre de la empresa,
  - cliente, proyecto y bolsa,
  - barra de consumo, dentro y exceso por separado,
  - consumo por mes y listado de entradas aprobadas con fecha, persona, tarea, horas y descripción.
  Pensado para enviárselo al cliente: solo lleva las horas aprobadas o bloqueadas, como hará el portal de la F5.
- **Exportación para facturar:** por cliente y periodo, con el detalle de cada entrada (dentro de bolsa y exceso por separado) y sus tarifas si hay `view-financials`.

### D-046 · Rendimiento
- **Cálculo:** agregados SQL sobre `time_entries` con sus índices (fecha y persona; proyecto y fecha; bolsa y fecha), sin cargar modelos, y la capacidad con `Capacity::forRanges`.
- **Caché:** por combinación de filtros y persona que consulta, con una versión global que se incrementa al escribir entradas, tareas, bolsas o jornadas. Así se invalida al momento sin etiquetas.
- **Presupuesto:**
  - menos de 1 s en el servidor con los datos de 12 meses (medido en el despliegue),
  - un test de presupuesto de consultas por dashboard, como `Phase1PagesPerformanceTest`.

### D-047 · Resumen semanal de productividad
- **Cuándo y a quién:** los lunes a las 08:00 de Madrid, por email (cola `mail`) a cada responsable, sobre su equipo y la semana anterior. Los admins lo reciben de toda la agencia.
- **Contenido:**
  - días sin imputar por persona,
  - ocupación por encima o por debajo de los umbrales,
  - bolsas en riesgo (≥ primer umbral),
  - tareas vencidas.
- **Nuevos ajustes:** `occupancy_low_threshold` (70 %), `occupancy_high_threshold` (110 %) y `weekly_digest_enabled` (sí).

### D-048 · Capacidad en la F2
En la F2 la capacidad sale de `Capacity`, que solo usa `WorkSchedule`. Cuando la F3 añada festivos y ausencias a `Capacity`, todos los informes los tendrán en cuenta sin más cambios.

## Contrato técnico ✅ hecho (rama `fase-2`, con tests de cálculo manual)
- **`App\Domain\Reports\ReportFilters`:** se construye desde la URL con los parámetros en español.
  - `periodo=semana|mes|trimestre|año|rango`, `desde`, `hasta` y `comparar=1`,
  - `persona[]`, `departamento[]`, `cliente[]`, `proyecto[]`, `bolsa[]`, `tipo[]` y `facturable=si|no`.
  - Navegación anterior y siguiente, y el periodo de comparación.
- **`ReportScope`:** aplica D-044 y los filtros a una consulta de `time_entries`, y da las personas visibles para calcular la capacidad.
- **`Metrics`:**
  - las métricas de §10: capacidad, imputadas, facturables, ocupación, facturabilidad, productividad facturable y precisión de estimación con su desviación,
  - ingreso, coste y margen con `RevenueCalculator` (D-043),
  - series por semana y mes, y desgloses por departamento, cliente, proyecto, persona, tipo y bolsa.
- **`PivotReport`:** dos dimensiones cualesquiera (cliente, proyecto, persona, departamento, tipo, bolsa, semana o mes) con subtotales.
- **`ReportCache`:** implementa D-046.
- **Tipos TS y componentes base:**
  - barra de filtros sincronizada con la URL,
  - tarjeta de KPI con tooltip de definición,
  - tablas de «top 10»,
  - variantes de las gráficas existentes: líneas, barras apiladas y mapa de calor.
- **Rutas:**
  - `/informes` (redirige al dashboard de su ámbito),
  - `/informes/direccion`, `/informes/clientes/{client}`, `/informes/proyectos/{project}`, `/informes/departamentos/{department}`, `/informes/personas/{user}` y `/informes/detalle`,
  - exportaciones con `?formato=xlsx|csv`, `/informes/facturacion` y `/proyectos/{p}/bolsas/{b}/pdf`.

## Reparto (tras el contrato, en paralelo)
| Área | Contenido |
|---|---|
| R1 | Dirección, departamento y persona (con mapa de calor y días sin imputar); «Mis indicadores» en Inicio |
| R2 | Cliente y proyecto (estimado frente a real, por persona, tipo y semana, estado de las tareas); PDF de bolsa; exportación para facturar |
| R3 | Informe detallado (tabla dinámica); exportación XLSX y CSV de cualquier informe y de la pestaña Horas del proyecto; resumen semanal por email y sus ajustes |
| Yo | Contrato, integración, E2E, revisión global, despliegue y medición en el servidor |

## Tests
- **Métricas con datos controlados:** para cada fórmula de §10, un escenario pequeño calculado a mano, incluidos los bordes:
  - capacidad 0,
  - sin horas facturables,
  - bolsas con y sin precio y con exceso,
  - precio cerrado por encima del presupuesto,
  - aprobadas frente a borradores (instantáneas),
  - tareas completadas con y sin estimación.
- **Permisos:** matriz de D-044 en todas las rutas y exportaciones; datos económicos ocultos sin `view-financials`.
- **Exportaciones:** se leen el XLSX y el CSV generados y se compara su contenido; el PDF se genera y se comprueban su texto y sus totales.
- **Rendimiento:** presupuesto de consultas por dashboard con el DemoDataSeeder, y medición de tiempos en el servidor en el despliegue.
- **E2E:** dirección con filtros en la URL, exportación y PDF de bolsa.

## Estado del contrato (para los agentes)
- **Dominio:** `app/Domain/Reports`.
  - `ReportFilters` y `ReportPeriod`: la URL en español,
  - `ReportScope`: D-044; `entries()` y `people()`,
  - `Metrics`: `summary`, `series`, `breakdown`, `estimation`, `capacityByDate` y `labels`,
  - `RevenueCalculator`: D-043,
  - `PivotReport`,
  - `ReportCache`: D-046; `ReportsServiceProvider` invalida al guardar los modelos,
  - `Dimension`: persona, departamento, cliente, proyecto, bolsa, tipo, tarea, día, semana y mes,
  - `Money`: bcmath.
- **Controladores:**
  - `App\Http\Controllers\Reports\Concerns\BuildsReportScope`: `reportScope($request, $fixed)` y `filterProps($scope)`,
  - `ReportOptionsController`: `GET /informes/opciones`.
- **Rutas:** `routes/app/reports.php`. `/informes` es provisional hasta que R1 haga el índice.
- **Frontend:**
  - tipos en `resources/js/types/reports.ts`,
  - componentes en `resources/js/components/reports/`: `report-filter-bar.tsx` (con `show` para ocultar filtros), `kpi-card.tsx` (con variación) y `multi-select-filter.tsx`,
  - textos comunes en `lang/ui/reports.json` (`reports.*`: periodos, filtros y definiciones de cada métrica).
- **Exportación:** `App\Domain\Reports\Export\TableExporter` (XLSX/CSV en streaming, con `hours()` y `money()` para las celdas) y el componente `components/reports/export-menu.tsx`. Cualquier controlador de informe exporta si recibe `?formato=xlsx|csv`.
- **Librerías:** `openspout/openspout` 5.12 y `setasign/fpdf` 1.9 instaladas.
- **Tests:**
  - `tests/Unit/Reports/ReportFiltersTest.php`,
  - `tests/Feature/Reports/MetricsTest.php`: el escenario calculado a mano sirve de referencia para cualquier cifra nueva,
  - `tests/js/reports-filters.test.tsx`.

## Ficheros de cada agente
| Agente | Ficheros propios |
|---|---|
| R1 | `app/Http/Controllers/Reports/{ReportIndex,Direction,Department,Person}*`; `resources/js/pages/reports/{index,direction,department,person}.tsx`; `components/reports/r1-*`; `lang/ui/reports-r1.json`; `tests/Feature/Reports/R1*`; `tests/js/reports-r1-*`; Inicio: la tarjeta «Mis indicadores» (`HomeController` y `home.tsx`) |
| R2 | `app/Http/Controllers/Reports/{Client,Project}*`; `app/Http/Controllers/Reports/Exports/{HourBankPdf,Billing}*`; `app/Domain/Reports/Pdf/*`; `resources/js/pages/reports/{client,project,billing}.tsx`; `components/reports/r2-*`; `lang/ui/reports-r2.json`; `tests/Feature/Reports/R2*`; y el botón del PDF en el detalle de bolsa (`resources/js/pages/projects/hour-bank.tsx`) |
| R3 | `app/Http/Controllers/Reports/{Detail,HoursExport}*`; `app/Console/Commands/SendWeeklyDigest.php`; `app/Notifications/Reports/*`; `resources/js/pages/reports/detail.tsx`; `components/reports/r3-*`; `lang/ui/reports-r3.json`; `tests/Feature/Reports/R3*`; la exportación de la pestaña Horas del proyecto (quitar el `PhaseBadge 2`); los ajustes nuevos en `Setting::DEFAULTS` y en la página de ajustes |
| Todos | Añaden sus rutas a `routes/app/reports.php` en su propio bloque comentado. Los cambios mínimos de contrato se anotan |
