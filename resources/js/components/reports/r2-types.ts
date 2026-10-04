/**
 * Props de los informes de R2 (Fase 2): cliente, proyecto y exportación para facturar.
 * Contrato con App\Http\Controllers\Reports\{ClientReportController, ProjectReportController} y
 * Exports\BillingReportController. Horas en minutos enteros; importes como string decimal
 * ("1221.67") y null sin view-financials.
 */
import type {
    BillingType,
    BreakdownRow,
    HourBankStatus,
    MetricsSummary,
    ProjectStatus,
    ReportFiltersProps,
    ReportRequestData,
    TaskStatusCategory,
} from '@/types';

/** Bolsa de un cliente con su consumo total (lo ve cualquier interno) y el del periodo. */
export type R2ClientBank = {
    id: number;
    project: { id: number; code: string; name: string };
    name: string;
    status: HourBankStatus;
    start_date: string;
    end_date: string | null;
    total_minutes: number;
    consumed_minutes: number;
    overage_minutes: number;
    in_bank_minutes: number;
    remaining_minutes: number;
    committed_minutes: number;
    period_in_bank_minutes: number;
    period_overage_minutes: number;
};

/** Horas por proyecto y cubo de tiempo (mes o semana, con todos los cubos del periodo). */
export type R2Timeline = {
    bucket: 'mes' | 'semana';
    /** Primer día de cada mes o lunes de cada semana (AAAA-MM-DD). */
    buckets: string[];
    /** Proyectos de más a menos horas. */
    series: { key: string; name: string; total: number }[];
    /** cells[proyecto][cubo] en minutos. */
    cells: Record<string, Record<string, number>>;
};

/**
 * Fila del resumen por proyecto del cliente: in_bank_minutes es lo que va dentro de una bolsa de
 * verdad (BankUsage); en los proyectos sin horas en bolsas, has_bank es false y no aplica.
 */
export type R2ClientProjectRow = BreakdownRow & { has_bank: boolean };

export type R2ClientReportProps = {
    /** El informe con sus filtros, para el menú «Exportar ▾» (Fase 9, D-139). */
    report_request: ReportRequestData;
    client: { id: number; name: string; is_active: boolean };
    filters: ReportFiltersProps;
    scope: {
        /** Gestor: solo los proyectos del cliente que gestiona. */
        projects_only: boolean;
        /** No admin: solo las horas que puede ver (su equipo y sus proyectos, D-021). */
        team_only: boolean;
    };
    summary: MetricsSummary;
    comparison: MetricsSummary | null;
    /**
     * Dentro de las bolsas en el periodo (summary.in_bank_minutes también cuenta las horas sin
     * bolsa) y si hay horas en alguna bolsa.
     */
    banked: { has_bank: boolean; in_bank_minutes: number };
    projects: R2ClientProjectRow[];
    timeline: R2Timeline;
    banks: R2ClientBank[];
    history: R2ClientBank[][];
};

export type R2TaskType = { id: number; name: string; color: string } | null;

/** EstimateComparison::forProject(): tareas principales y sus subtareas (depth 1) detrás. */
export type R2EstimateTask = {
    id: number;
    parent_id: number | null;
    depth: 0 | 1;
    title: string;
    type: R2TaskType;
    status: { name: string; color: string; category: TaskStatusCategory };
    completed: boolean;
    /** La estimación es la suma de las subtareas (SPEC §6). */
    derived: boolean;
    estimated_minutes: number | null;
    actual_minutes: number;
};

export type R2Estimates = {
    tasks: R2EstimateTask[];
    by_type: {
        type: R2TaskType;
        estimated_minutes: number;
        actual_minutes: number;
    }[];
    totals: {
        estimated_minutes: number;
        actual_minutes: number;
        /** Horas de tareas que ya no están en el proyecto (movidas). */
        other_minutes: number;
        tasks: number;
        estimated_tasks: number;
        over_tasks: number;
    };
};

export type R2Week = {
    /** Lunes (AAAA-MM-DD). */
    week: string;
    logged_minutes: number;
    billable_minutes: number;
    in_bank_minutes: number;
    overage_minutes: number;
    income: string | null;
};

export type R2TaskStatusSummary = {
    by_status: {
        id: number;
        name: string;
        color: string;
        category: TaskStatusCategory;
        count: number;
    }[];
    by_category: Record<TaskStatusCategory, number>;
    overdue: number;
    total: number;
};

export type R2Milestone = {
    id: number;
    title: string;
    due_date: string | null;
    completed: boolean;
    overdue: boolean;
};

export type R2ProjectReportProps = {
    /** El informe con sus filtros, para el menú «Exportar ▾» (Fase 9, D-139). */
    report_request: ReportRequestData;
    project: {
        id: number;
        code: string;
        name: string;
        color: string;
        billing_type: BillingType;
        status: ProjectStatus;
        budget_minutes: number | null;
        client: { id: number; name: string } | null;
    };
    filters: ReportFiltersProps;
    /** Un responsable que no gestiona el proyecto ve las horas de su equipo (D-021). */
    scope: { team_only: boolean };
    summary: MetricsSummary;
    comparison: MetricsSummary | null;
    byPerson: BreakdownRow[];
    byType: BreakdownRow[];
    weekly: R2Week[];
    estimates: R2Estimates;
    tasks: R2TaskStatusSummary;
    milestones: R2Milestone[];
};

/** Cómo se valora un grupo de la exportación para facturar (BillingReport::PRICING_*). */
export type R2BillingPricing =
    | 'bank_price'
    | 'hourly'
    | 'person_rates'
    | 'fixed_price'
    | 'internal';

export type R2BillingRow = {
    project: {
        id: number;
        code: string;
        name: string;
        billing_type: BillingType;
    };
    bank: { id: number; name: string; status: HourBankStatus } | null;
    logged_minutes: number;
    in_bank_minutes: number;
    overage_minutes: number;
    billable_minutes: number;
    non_billable_minutes: number;
    /** Borradores y enviadas: aún pueden cambiar. */
    pending_minutes: number;
    pricing: R2BillingPricing | null;
    rate: string | null;
    price_amount: string | null;
    income: string | null;
};

export type R2BillingSummary = {
    rows: R2BillingRow[];
    totals: {
        /** Entradas del periodo (una fila cada una en la exportación). */
        entries: number;
        logged_minutes: number;
        in_bank_minutes: number;
        overage_minutes: number;
        billable_minutes: number;
        non_billable_minutes: number;
        pending_minutes: number;
        income: string | null;
    };
};

export type R2BillingProps = {
    filters: ReportFiltersProps;
    /** El informe con sus filtros (null sin cliente elegido), para el menú «Exportar ▾». */
    report_request: ReportRequestData | null;
    client: { id: number; name: string; is_active: boolean } | null;
    clients: { id: number; name: string; is_active: boolean }[];
    summary: R2BillingSummary | null;
    scope: { team_only: boolean };
    /** Entradas que caben en la exportación (D-045: hasta 20.000 filas, sin la de totales). */
    export_limit: number;
    /** viewReport: el informe del cliente (ClientPolicy::viewReport). */
    can: { viewReport: boolean };
};
