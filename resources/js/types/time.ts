/**
 * Props de las páginas del área «time» (Fase 1): contrato con app/Http/Controllers/Time/* y
 * app/Http/Controllers/HomeController.php. Los tipos de entidad están en ./domain.
 */
import type {
    Option,
    Project,
    TimeEntry,
    TimeEntryWarning,
    TimesheetStatus,
    UserSummary,
} from './domain';

/** App\Http\Resources\Time\TimesheetPeriodResource. Una semana sin fila llega con id null. */
export type TimesheetPeriodData = {
    id: number | null;
    user_id: number;
    user?: UserSummary;
    /** "2026-W39". */
    week: string;
    week_start: string;
    week_end: string;
    status: TimesheetStatus;
    submitted_at: string | null;
    reviewed_at: string | null;
    reviewer?: UserSummary | null;
    review_comment: string | null;
    /** Aprobada sin revisor (responsables, admins o sin aprobación obligatoria). */
    auto_approved: boolean;
};

/** App\Http\Resources\Time\LoggableTaskResource: buscador de imputación y filas de la hoja. */
export type LoggableTask = {
    id: number;
    title: string;
    project_id: number;
    project: {
        id: number;
        code: string;
        name: string;
        color: string;
        is_internal: boolean;
    };
    hour_bank?: { id: number; name: string } | null;
    /** Facturable por defecto (nunca en proyectos internos). */
    is_billable: boolean;
    is_milestone: boolean;
    is_completed: boolean;
    is_deleted: boolean;
};

/** GET /horas/tareas. */
export type LoggableTasksResponse = {
    tasks: LoggableTask[];
};

/** GET /horas/opciones. */
export type TimeEntryOptionsResponse = {
    /** Para quién puede imputar; la propia persona primero. */
    people: UserSummary[];
    settings: {
        /** Hoy en Madrid, "YYYY-MM-DD". */
        today: string;
        allow_future: boolean;
        description_required: boolean;
    };
};

/** Flash `time_warnings` de las acciones de imputación (avisos no bloqueantes). */
export type TimeWarningsFlash = TimeEntryWarning[];

export type WeekInfo = {
    iso: string;
    start: string;
    end: string;
    /** Los 7 días, lunes primero. */
    days: string[];
    previous: string;
    next: string;
    current: string;
};

/** Una fila de la hoja semanal: una tarea con sus entradas de cada día (7 celdas). */
export type TimesheetRow = {
    task: LoggableTask;
    cells: TimeEntry[][];
    total: number;
};

export type DayTotals = {
    days: Record<string, number>;
    week: number;
};

/** /horas?semana=2026-W39[&persona=12] (TimesheetController::show). */
export type TimesheetPageProps = {
    week: WeekInfo;
    person: UserSummary;
    is_own: boolean;
    /** Personas cuyas horas puede ver (sin contar a quien mira). */
    people: UserSummary[];
    /** «managed_projects»: un gestor que solo ve las entradas de sus proyectos (D-021). */
    scope: 'full' | 'managed_projects';
    period: TimesheetPeriodData;
    rows: TimesheetRow[];
    totals: DayTotals;
    capacity: DayTotals;
    previous_week_tasks: LoggableTask[];
    can: {
        edit: boolean;
        submit: boolean;
        withdraw: boolean;
        review: boolean;
        reopen: boolean;
    };
    settings: {
        today: string;
        allow_future: boolean;
    };
};

/** Una semana pendiente en /horas/aprobaciones. */
export type PendingWeek = {
    period: TimesheetPeriodData;
    department: string | null;
    days: Record<string, number>;
    total: number;
    capacity: number;
    capacity_days: Record<string, number>;
    billable: number;
    overage: number;
    entries: TimeEntry[];
};

export type ReviewedWeek = {
    period: TimesheetPeriodData;
    total: number;
    can_reopen: boolean;
};

/** /horas/aprobaciones (ApprovalController::index). */
export type ApprovalsPageProps = {
    pending: PendingWeek[];
    history: ReviewedWeek[];
    /** Máximo de semanas pendientes que se listan. */
    limit: number;
};

export type LockSummary = { count: number; minutes: number };

/** App\Http\Resources\Time\TimeEntryLockResource (+ unlocked_by). */
export type TimeLockData = {
    id: number;
    client: { id: number; name: string } | null;
    project: { id: number; code: string; name: string } | null;
    date_from: string;
    date_to: string;
    reference: string | null;
    entries_count: number;
    locked_by?: UserSummary;
    created_at: string | null;
    unlocked_at: string | null;
    unlocked_by: { id: number; name: string } | null;
};

export type LockFilters = {
    client_id: number | null;
    project_id: number | null;
    date_from: string | null;
    date_to: string | null;
    reference: string | null;
};

/** /horas/bloqueo y /horas/bloqueo/vista-previa (TimeLockController). */
export type TimeLocksPageProps = {
    clients: Option[];
    projects: {
        id: number;
        code: string;
        name: string;
        client_id: number | null;
    }[];
    filters: LockFilters;
    preview: {
        summary: {
            lockable: LockSummary;
            pending: { draft: LockSummary; submitted: LockSummary };
            locked: LockSummary;
        };
        entries: TimeEntry[];
        limit: number;
    } | null;
    locks: TimeLockData[];
};

/** Entrada de la pestaña Horas con si quien mira puede editarla. */
export type ProjectTimeEntry = TimeEntry & { can_edit: boolean };

export type ProjectTimeFilters = {
    persona: number | null;
    desde: string | null;
    hasta: string | null;
    bolsa: number | null;
    estado: TimeEntry['status'] | null;
    facturable: 'si' | 'no' | null;
};

/** /proyectos/{project}/horas (ProjectTimeController). */
export type ProjectTimePageProps = {
    project: Project;
    canManage: boolean;
    /** Qué entradas ve: todas, las de su equipo o solo las suyas (D-021). */
    scope: 'all' | 'team' | 'mine';
    entries: {
        data: ProjectTimeEntry[];
        meta: {
            current_page: number;
            last_page: number;
            per_page: number;
            total: number;
            from: number | null;
            to: number | null;
        };
        links: { prev: string | null; next: string | null };
    };
    totals: {
        entries: number;
        minutes: number;
        overage_minutes: number;
        in_bank_minutes: number;
        billable_minutes: number;
    };
    filters: ProjectTimeFilters;
    options: { people: Option[]; banks: Option[] };
};

/** App\Http\Resources\Time\HomeTaskResource. */
export type HomeTask = {
    id: number;
    title: string;
    project_id: number;
    project: { id: number; code: string; name: string; color: string };
    status: { name: string; color: string; is_done: boolean };
    start_date: string | null;
    due_date: string | null;
    is_milestone: boolean;
};

export type UnloggedDay = {
    date: string;
    capacity: number;
    week: string;
};

/** Inicio (HomeController). */
export type HomePageProps = {
    tasks: { overdue: HomeTask[]; today: HomeTask[]; week: HomeTask[] };
    hours: {
        today: number;
        week: number;
        capacity_today: number;
        capacity_week: number;
    };
    week: { iso: string; period: TimesheetPeriodData };
    unlogged_days: UnloggedDay[];
};
