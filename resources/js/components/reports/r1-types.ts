/**
 * Props de las páginas de R1 (índice, dirección, departamento, persona e indicadores de Inicio).
 * Contrato con app/Http/Controllers/Reports/{ReportIndex,DirectionReport,DepartmentReport,
 * PersonReport}Controller y HomeController::indicators. Horas en minutos; importes como string
 * decimal y solo con view-financials (null si no); ratios de 0 a 1 o null sin base.
 */
import type {
    BreakdownRow,
    EstimationSummary,
    MetricsSummary,
    ReportFiltersProps,
    SeriesPoint,
} from '@/types';

/**
 * Metrics::summary() (SPEC §10) con la capacidad transcurrida hasta ayer como dato informativo
 * (Metrics::elapsedCapacity): en un periodo cerrado es igual a capacity_minutes. La ocupación y la
 * productividad facturable no se miden contra ella, sino contra la capacidad del periodo.
 */
export type R1Summary = MetricsSummary & { capacity_to_date_minutes: number };

/**
 * Metrics::breakdown() con el margen (ingreso − coste) de cada fila. `linkable` (solo en los
 * repartos que enlazan a un dashboard) es false si el departamento, cliente o proyecto está
 * borrado: sus horas cuentan, pero su dashboard ya no existe.
 */
export type R1BreakdownRow = BreakdownRow & {
    margin: string | null;
    linkable?: boolean;
};

/** El resto de filas sumadas («Otros»). */
export type R1OthersRow = {
    count: number;
    logged_minutes: number;
    billable_minutes: number;
    in_bank_minutes: number;
    overage_minutes: number;
    income: string | null;
    cost: string | null;
    margin: string | null;
};

export type R1TopRows = {
    rows: R1BreakdownRow[];
    others: R1OthersRow | null;
};

/** App\Domain\Reports\HourBanksAtRisk. */
export type R1AtRiskBank = {
    id: number;
    name: string;
    status: 'active' | 'exhausted';
    project: { id: number; code: string; name: string; color: string };
    client: string | null;
    total_minutes: number;
    consumed_minutes: number;
    overage_minutes: number;
    committed_minutes: number;
    ratio: number;
};

export type R1AtRisk = {
    count: number;
    /** Primer umbral configurado, en %. */
    threshold: number;
    banks: R1AtRiskBank[];
};

/** App\Domain\Reports\OverdueTasks. */
export type R1OverdueTask = {
    id: number;
    title: string;
    project_id: number;
    project: { code: string; name: string; color: string };
    assignee: string | null;
    due_date: string;
    days_overdue: number;
    is_milestone: boolean;
};

export type R1Overdue = {
    count: number;
    tasks: R1OverdueTask[];
};

type Dashboard = {
    filters: ReportFiltersProps;
    summary: R1Summary;
    /** Resumen del periodo de comparación (solo con comparar=1). */
    comparison: MetricsSummary | null;
    /**
     * true si el periodo sigue en curso y la comparación es de los mismos días del periodo
     * anterior (Metrics::summaryFirstDays; el tramo, en filters.comparison).
     */
    comparison_partial: boolean;
};

/** GET /informes. `clients` y `projects` son null si quien mira no tiene esos informes. */
export type ReportIndexProps = {
    me: { id: number; name: string };
    direction: boolean;
    /** Exportación para facturar (R2): admins y quien tenga view-financials. */
    billing: boolean;
    departments: { id: number; name: string; color: string }[];
    clients: { id: number; name: string; is_active: boolean }[] | null;
    projects:
        | {
              id: number;
              code: string;
              name: string;
              color: string;
              client: string | null;
              archived: boolean;
          }[]
        | null;
    people: {
        id: number;
        name: string;
        department: string | null;
        is_active: boolean;
    }[];
};

/** GET /informes/direccion. */
export type DirectionReportProps = Dashboard & {
    /** Departamentos a los que se limita un responsable (null para un admin). */
    limited_to: string[] | null;
    series: { bucket: 'semana' | 'mes'; points: SeriesPoint[] };
    departments: R1BreakdownRow[];
    clients: R1TopRows;
    projects: R1TopRows;
    at_risk: R1AtRisk;
    overdue: R1Overdue;
};

/**
 * Fila de la tabla de miembros del departamento. La ocupación y la productividad facturable, contra
 * la capacidad del periodo (SPEC §10); capacity_to_date_minutes (hasta ayer) es informativa.
 */
export type R1Member = {
    id: number;
    name: string;
    is_active: boolean;
    capacity_minutes: number;
    capacity_to_date_minutes: number;
    logged_minutes: number;
    billable_minutes: number;
    occupancy: number | null;
    billability: number | null;
    billable_productivity: number | null;
    income: string | null;
    cost: string | null;
    margin: string | null;
};

/** Umbrales de ocupación en % (ajustes occupancy_low_threshold y occupancy_high_threshold). */
export type R1OccupancyThresholds = { low: number; high: number };

/** GET /informes/departamentos/{department}. */
export type DepartmentReportProps = Dashboard & {
    department: { id: number; name: string; color: string };
    members: R1Member[];
    clients: R1TopRows;
    occupancy_thresholds: R1OccupancyThresholds;
};

export type R1UnloggedDay = {
    date: string;
    capacity_minutes: number;
    /** Semana ISO ("2026-W39") para abrir la hoja semanal. */
    week: string;
};

/** GET /informes/personas/{user}. */
export type PersonReportProps = Dashboard & {
    person: {
        id: number;
        name: string;
        is_active: boolean;
        department: { id: number; name: string; can_view: boolean } | null;
    };
    is_self: boolean;
    clients: R1TopRows;
    projects: R1TopRows;
    types: R1TopRows;
    days: { date: string; minutes: number }[];
    unlogged: R1UnloggedDay[];
};

/**
 * HomeController::indicators (tarjeta «Mis indicadores» de Inicio). La ocupación, contra la
 * capacidad del mes (SPEC §10); capacity_to_date_minutes (hasta ayer) es informativa.
 */
export type MyIndicators = {
    from: string;
    to: string;
    capacity_minutes: number;
    capacity_to_date_minutes: number;
    logged_minutes: number;
    billable_minutes: number;
    occupancy: number | null;
    billability: number | null;
    estimation: EstimationSummary;
    clients: { key: string | null; name: string; logged_minutes: number }[];
    projects: {
        key: string | null;
        name: string;
        color: string | null;
        logged_minutes: number;
    }[];
};
