/**
 * Previsión (docs/PLAN-CARGAS.md, Nivel 2; D-280 a D-289). Contrato con App\Domain\Forecast
 * (LoadCombiner, ForecastImpact, EstimateVsActual, ForecastPresenter) y App\Http\Resources\Forecast.
 *
 * Todas las horas van en **minutos enteros**; las fechas, como `YYYY-MM-DD` locales; los meses,
 * `YYYY-MM`; las semanas ISO, `YYYY-Www`. La ocupación (carga / capacidad) y su semáforo los calcula
 * la interfaz con las capas que se enciendan (lib/forecast.ts).
 */

import type { Project } from './domain';

export type AllocationMode = 'total' | 'per_day' | 'percent' | 'monthly';
export type ForecastConfidence = 'tentative' | 'firm';
export type ForecastStatus = 'open' | 'confirmed' | 'lost' | 'linked';
/** Capas de la carga (D-283): proyectos reales, previstos seguros y previstos posibles. */
export type LoadLayer = 'real' | 'firm' | 'tentative';
export type ForecastGranularity = 'week' | 'month';

// --- Carga (LoadCombiner) -------------------------------------------------------------------

/** Una columna (semana o mes) del periodo. */
export type ForecastBucket = { key: string; from: string; to: string };

export type LoadLayers = { real: number; firm: number; tentative: number };

/** Capacidad y asignado de una columna. Sin capacidad en los días pasados (cuenta desde hoy). */
export type LoadCell = LoadLayers & { capacity: number };

/** De dónde sale la carga: una asignación con sus minutos por columna. */
export type LoadSource = {
    allocation_id: number;
    layer: LoadLayer;
    user_id: number | null;
    /** Solo en los huecos (asignaciones de un departamento sin persona). */
    department_id: number | null;
    mode: AllocationMode;
    project: { id: number; code: string; name: string; color: string } | null;
    forecast: { id: number; name: string; color: string } | null;
    /** Minutos por columna, en el orden de `buckets`. */
    minutes: number[];
};

export type ForecastBoard = {
    period: {
        from: string;
        to: string;
        granularity: ForecastGranularity;
        today: string;
        /** Primer día que cuenta: max(from, hoy). */
        counts_from: string;
    };
    buckets: ForecastBucket[];
    people: {
        id: number;
        name: string;
        department_id: number | null;
        cells: LoadCell[];
    }[];
    /** Capacidad = suma de su plantilla activa; la carga incluye sus huecos (también en `gaps`). `id` null = sin departamento. */
    departments: {
        id: number | null;
        name: string | null;
        color: string | null;
        people: number;
        cells: LoadCell[];
        gaps: LoadLayers[];
    }[];
    totals: LoadCell[];
    sources: LoadSource[];
    /** Asignaciones con el fin pasado y restante (todo va a hoy). */
    overdue: number[];
    /** Asignaciones sin ningún día laborable en su rango (todo al primer día). */
    unscheduled: number[];
};

// --- Asignaciones y previstos ---------------------------------------------------------------

export type Allocation = {
    id: number;
    forecast_project_id: number | null;
    project_id: number | null;
    user: { id: number; name: string; department_id: number | null } | null;
    department: { id: number; name: string; color: string } | null;
    is_gap: boolean;
    mode: AllocationMode;
    /** Total, por día o por mes según el modo; null en `percent`. */
    minutes: number | null;
    percent: number | null;
    start_date: string;
    /** Null solo en `monthly` (hasta el horizonte). */
    end_date: string | null;
    note: string | null;
    copied_from_allocation_id: number | null;
    /** Plan completo (todo el rango). */
    planned_minutes: number;
    /** Plan completo por mes (`YYYY-MM` → minutos). */
    months: Record<string, number>;
    /** Solo en proyectos reales: imputado por esa persona en el proyecto dentro del rango (null en huecos). */
    logged_minutes: number | null;
    /** Solo en proyectos reales: lo que cuenta en la carga de hoy en adelante. */
    remaining_minutes: number | null;
    overdue: boolean;
    unscheduled: boolean;
    can: { update: boolean; assign: boolean };
};

export type ForecastProject = {
    id: number;
    name: string;
    client: { id: number; name: string } | null;
    prospect_name: string | null;
    /** El del cliente o el nombre libre. */
    client_name: string | null;
    color: string;
    description: string | null;
    owner: { id: number; name: string };
    confidence: ForecastConfidence;
    status: ForecastStatus;
    lost_reason: string | null;
    lost_at: string | null;
    start_date: string | null;
    end_date: string | null;
    estimated_minutes: number | null;
    /** Solo con view-financials; si no, null. Decimal en texto. */
    estimated_amount: string | null;
    /** Plan completo de sus asignaciones. */
    allocated_minutes: number | null;
    project: { id: number; code: string; name: string } | null;
    linked_at: string | null;
    /** Abierto o confirmado con inicio pasado: «actualiza las fechas». */
    starts_in_past: boolean;
    can: {
        update: boolean;
        confirm: boolean;
        lose: boolean;
        reopen: boolean;
        delete: boolean;
        link: boolean;
        create_project: boolean;
        unlink: boolean;
    };
};

export type PersonOption = {
    id: number;
    name: string;
    department_id: number | null;
};
export type DepartmentOption = { id: number; name: string; color: string };
export type ClientOption = { id: number; name: string };

// --- Impacto y estimado frente a real -------------------------------------------------------

export type ImpactCell = { capacity: number; without: number; with: number };

/** Impacto «sin / con» un previsto, por mes y de hoy en adelante (ForecastImpact). */
export type ForecastImpact = {
    buckets: ForecastBucket[];
    layer: LoadLayer;
    departments: {
        id: number | null;
        name: string | null;
        color: string | null;
        cells: ImpactCell[];
    }[];
    people: {
        id: number;
        name: string;
        department_id: number | null;
        cells: ImpactCell[];
    }[];
};

export type EstimateRow = {
    estimated: number;
    actual: number;
    /** (real − estimado) / estimado en %, con un decimal; null sin estimado. */
    deviation_percent: number | null;
};

/** Estimado (línea base congelada) frente a real (horas imputadas), D-287. */
export type EstimateVsActual = {
    project: { id: number; code: string; name: string; status: string };
    taken_at: string | null;
    totals: EstimateRow;
    dates: {
        estimated_start: string | null;
        estimated_end: string | null;
        actual_start: string | null;
        actual_end: string | null;
        /** En curso: el fin al ritmo de las últimas 4 semanas. */
        projected_end: string | null;
    };
    by_department: ({
        department_id: number | null;
        name: string | null;
    } & EstimateRow)[];
    by_user: ({ user_id: number; name: string } & EstimateRow)[];
    by_month: {
        month: string;
        estimated: number;
        actual: number;
        cumulative_estimated: number;
        cumulative_actual: number;
    }[];
    by_department_month: {
        department_id: number | null;
        months: { month: string; estimated: number; actual: number }[];
    }[];
};

// --- Props de las páginas -------------------------------------------------------------------

/** `forecast/index` (GET /prevision?desde=&meses=&por=semanas|meses&departamento=). */
export type ForecastIndexPageProps = {
    board: ForecastBoard;
    filters: {
        from: string;
        months: number;
        granularity: ForecastGranularity;
        department_id: number | null;
    };
    departments: DepartmentOption[];
    can: { manage: boolean };
};

export type ForecastListFilter = 'active' | 'lost' | 'linked' | 'all';

/** `forecast/projects/index` (GET /prevision/proyectos?estado=). */
export type ForecastProjectsPageProps = {
    projects: ForecastProject[];
    filters: { status: ForecastListFilter };
    /** Diferida. */
    clients?: ClientOption[];
    can: { create: boolean };
};

/** `forecast/projects/show` (GET /prevision/proyectos/{id}). */
export type ForecastProjectPageProps = {
    forecast: ForecastProject;
    allocations: Allocation[];
    /** Meses con plan, en orden (`YYYY-MM`). */
    months: string[];
    totals: {
        allocated_minutes: number;
        estimated_minutes: number | null;
        difference_minutes: number | null;
    };
    /** Diferida (grupo «analysis»); null si el previsto no cuenta (perdido o vinculado). */
    impact?: ForecastImpact | null;
    /** Diferida (grupo «analysis»); null si no está vinculado. */
    estimate?: EstimateVsActual | null;
    /** Diferida (grupo «options»). */
    options?: {
        people: PersonOption[];
        departments: DepartmentOption[];
        clients: ClientOption[];
        link_candidates: {
            id: number;
            code: string;
            name: string;
            client_id: number | null;
        }[];
    };
};

/** `projects/planning` (GET /proyectos/{id}/planificacion). */
export type ProjectPlanningPageProps = {
    project: Project;
    allocations: Allocation[];
    months: string[];
    /** Plan completo e imputado de todo el proyecto por semana (como mucho 52). */
    weeks: (ForecastBucket & { planned: number; logged: number })[];
    totals: {
        planned_minutes: number;
        logged_minutes: number;
        remaining_minutes: number;
    };
    /** El previsto del que viene, si se vinculó. */
    forecast: {
        id: number;
        name: string;
        status: ForecastStatus;
        can_view: boolean;
    } | null;
    /** Diferida (grupo «analysis»). */
    estimate?: EstimateVsActual | null;
    /** Diferida (grupo «options»). */
    options?: { people: PersonOption[]; departments: DepartmentOption[] };
    can: { manage: boolean };
};

/** GET /prevision/mi-carga (JSON, P8): la carga propia por semanas; `departments` va vacío. */
export type MyForecastResponse = ForecastBoard;
