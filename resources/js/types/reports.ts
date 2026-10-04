/**
 * Informes (Fase 2): contrato con app/Domain/Reports y app/Http/Controllers/Reports.
 * Horas en minutos enteros; importes como string decimal ("2111.67"), solo con view-financials
 * (null si no); ratios de 0 a 1 (pueden superar 1) o null si no hay base.
 */

export type ReportPeriod = 'semana' | 'mes' | 'trimestre' | 'anio' | 'rango';

/** Parámetros de URL de los filtros (App\Domain\Reports\ReportFilters::toQuery). */
export type ReportQuery = {
    periodo: ReportPeriod;
    fecha?: string;
    desde?: string;
    hasta?: string;
    comparar?: '1';
    persona?: number[];
    departamento?: number[];
    cliente?: number[];
    proyecto?: number[];
    bolsa?: number[];
    tipo?: number[];
    facturable?: 'si' | 'no';
};

/**
 * Un informe con sus filtros (App\Domain\Reports\Delivery\ReportRequest::toArray(), D-139): la
 * prop `report_request` de cada página de informe, para el menú «Exportar ▾» y los diálogos de
 * envío y programación. `kind` es un ReportKind (direction, department, person, client, project,
 * billing, detail, hours, project_hours, hour_bank).
 */
export type ReportRequestData = {
    kind: string;
    route_params: Record<string, number | string>;
    query: Record<string, unknown>;
};

/** BuildsReportScope::filterProps(). */
export type ReportFiltersProps = {
    query: ReportQuery;
    period: ReportPeriod;
    from: string;
    to: string;
    compare: boolean;
    previous: ReportQuery;
    next: ReportQuery;
    comparison: { from: string; to: string } | null;
    can_see_financials: boolean;
};

/** GET /informes/opciones. */
export type ReportOptions = {
    people: { id: number; name: string }[];
    departments: { id: number; name: string; color: string }[];
    clients: { id: number; name: string; is_active: boolean }[];
    projects: {
        id: number;
        name: string;
        client_id: number | null;
        archived: boolean;
    }[];
    hour_banks: {
        id: number;
        name: string;
        project_id: number;
        status: string;
    }[];
    task_types: { id: number; name: string; color: string }[];
};

/** Filtros que puede mostrar la barra (cada dashboard oculta los fijos). */
export type ReportFilterKey =
    | 'persona'
    | 'departamento'
    | 'cliente'
    | 'proyecto'
    | 'bolsa'
    | 'tipo'
    | 'facturable';

export type EstimationSummary = {
    tasks: number;
    estimated_minutes: number;
    actual_minutes: number;
    accuracy: number | null;
    deviation: number | null;
};

/** Metrics::summary(). */
export type MetricsSummary = {
    capacity_minutes: number;
    logged_minutes: number;
    billable_minutes: number;
    in_bank_minutes: number;
    overage_minutes: number;
    occupancy: number | null;
    billability: number | null;
    billable_productivity: number | null;
    estimation: EstimationSummary;
    income: string | null;
    cost: string | null;
    margin: string | null;
    margin_pct: number | null;
};

/** Metrics::series(). */
export type SeriesPoint = {
    /** Fecha AAAA-MM-DD: el día, el lunes de la semana o el día 1 del mes. */
    bucket: string;
    logged_minutes: number;
    billable_minutes: number;
    capacity_minutes: number;
    income: string | null;
};

/** Dimensiones de App\Domain\Reports\Dimension. */
export type ReportDimension =
    | 'persona'
    | 'departamento'
    | 'cliente'
    | 'proyecto'
    | 'bolsa'
    | 'tipo'
    | 'tarea'
    | 'dia'
    | 'semana'
    | 'mes';

/** Metrics::breakdown(). */
export type BreakdownRow = {
    key: string | null;
    name: string;
    color: string | null;
    logged_minutes: number;
    billable_minutes: number;
    in_bank_minutes: number;
    overage_minutes: number;
    income: string | null;
    cost: string | null;
};

/** PivotReport::run(). */
export type PivotResult = {
    rows: { key: string | null; name: string }[];
    columns: { key: string | null; name: string }[];
    /** cells[fila][columna] en minutos; la clave vacía ('') es «sin valor». */
    cells: Record<string, Record<string, number>>;
    row_totals: Record<string, number>;
    column_totals: Record<string, number>;
    total: number;
    truncated: boolean;
};
