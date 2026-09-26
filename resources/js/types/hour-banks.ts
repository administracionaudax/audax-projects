/**
 * Props de las páginas del área «hour-banks» (Fase 1). Los tipos de entidad están en ./domain.
 * Contrato con app/Http/Controllers/HourBanks y app/Http/Resources/HourBanks.
 */
import type {
    HourBank,
    HourBankStatus,
    Option,
    Project,
    TaskStatusCategory,
    TaskType,
    TimeEntry,
    UserSummary,
} from './domain';
import type { ProjectsPaginated } from './projects';

/**
 * Departamento del selector de una bolsa. `deleted`: eliminado, pero es el de la bolsa y se
 * puede conservar al editarla o renovarla.
 */
export type HourBankDepartmentOption = Option & { deleted?: boolean };

/**
 * Qué botones ofrecer en una bolsa (la autorización real la hace el servidor). `renew`, solo si
 * está agotada o próxima a agotarse (D-035).
 */
export type HourBankAbilities = {
    update: boolean;
    renew: boolean;
    close: boolean;
    reopen: boolean;
    delete: boolean;
};

/** HourBankCardResource. */
export type HourBankCard = HourBank & {
    /** Estimación restante de sus tareas abiertas (App\Domain\HourBanks\HourBankCommitment). */
    committed_minutes: number;
    /** Tareas abiertas de cualquier nivel, también hitos: las que se mueven al renovar. */
    open_tasks_count: number;
    project?: {
        id: number;
        code: string;
        name: string;
        color: string;
        client: { id: number; name: string } | null;
    };
    renewed_from?: { id: number; name: string; status: HourBankStatus } | null;
    renewal?: { id: number; name: string; status: HourBankStatus } | null;
    closed_by?: { id: number; name: string } | null;
    can?: HourBankAbilities;
};

/** Eslabón del histórico de renovaciones (de la más antigua a la más reciente). */
export type HourBankChainItem = {
    id: number;
    project_id: number;
    name: string;
    status: HourBankStatus;
    start_date: string;
    end_date: string | null;
};

export type ProjectHourBanksProps = {
    project: Project;
    canManage: boolean;
    banks: (HourBankCard & { can: HourBankAbilities })[];
    /** Bolsas cerradas o renovadas que no se muestran sin el filtro. */
    hiddenCount: number;
    history: HourBankChainItem[][];
    filters: { todas: boolean };
    departments: HourBankDepartmentOption[];
    /** Política efectiva de las bolsas con «Según el ajuste general». */
    overageDefault: 'allow' | 'block';
    can: { create: boolean };
};

/** Semana ISO del detalle (App\Domain\HourBanks\HourBankBreakdown::weekly). */
export type HourBankWeek = {
    /** "2026-W39". */
    week: string;
    /** Lunes "YYYY-MM-DD". */
    week_start: string;
    in_bank_minutes: number;
    overage_minutes: number;
};

export type HourBankPersonRow = {
    user: UserSummary;
    minutes: number;
    overage_minutes: number;
};

export type HourBankTypeRow = {
    /** null = tareas sin tipo. */
    type: TaskType | null;
    minutes: number;
    overage_minutes: number;
};

/** Tarea de la bolsa con su estimación, lo imputado en la bolsa y lo comprometido. */
export type HourBankTaskRow = {
    id: number;
    title: string;
    parent_task_id: number | null;
    /** 0 = primer nivel; 1 = subtarea. */
    depth: number;
    is_completed: boolean;
    is_milestone: boolean;
    status: { name: string; color: string; category: TaskStatusCategory };
    assignee: UserSummary | null;
    estimated_minutes: number | null;
    logged_minutes: number;
    /** null en un padre cuya estimación sale de sus subtareas (cuentan ellas). */
    committed_minutes: number | null;
};

export type HourBankShowProps = {
    project: Project;
    canManage: boolean;
    bank: HourBankCard & { can: HourBankAbilities };
    weekly: HourBankWeek[];
    /** Solo para gestores, responsables y admins (HourBankPolicy::viewBreakdown). */
    byPerson: HourBankPersonRow[] | null;
    byType: HourBankTypeRow[];
    /** Entradas que puede ver quien mira (D-021). */
    entries: ProjectsPaginated<TimeEntry>;
    tasks: HourBankTaskRow[];
    departments: HourBankDepartmentOption[];
    /** Política efectiva de las bolsas con «Según el ajuste general». */
    overageDefault: 'allow' | 'block';
};

/** Filtros de la vista global (/bolsas), tal como van en la URL. */
export type HourBankOverviewFilters = {
    cliente: number | null;
    departamento: number | null;
    /** '' = abiertas (activas y agotadas); un estado; o 'todas'. */
    estado: '' | HourBankStatus | 'todas';
    /** Solo las que están en el primer umbral o por encima. */
    proximas: boolean;
};

export type HourBanksIndexProps = {
    banks: ProjectsPaginated<HourBankCard>;
    filters: HourBankOverviewFilters;
    stats: { open: number; exhausted: number; near: number };
    /** Primer umbral de alerta (%), el de «próxima a agotarse». */
    threshold: number;
    /** 'managed' = solo las bolsas de mis proyectos (gestor, D-035). */
    scope: 'all' | 'managed';
    /** Histórico de renovaciones del cliente filtrado (SPEC §8.8); null sin filtro de cliente. */
    history: {
        chains: HourBankChainItem[][];
        projects: HourBankHistoryProject[];
    } | null;
    options: { clients: Option[]; departments: Option[] };
};

/** Proyecto de una cadena del histórico de un cliente. */
export type HourBankHistoryProject = {
    id: number;
    code: string;
    name: string;
    color: string;
};
