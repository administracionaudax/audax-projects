/**
 * Props de las páginas del área «projects» (Fase 1). Los tipos de entidad están en ./domain.
 * Contrato con app/Http/Controllers/Projects y app/Http/Resources/Projects.
 */
import type {
    BillingType,
    Option,
    Project,
    ProjectMember,
    ProjectStatus,
    UserSummary,
} from './domain';
import type { HourBankCard } from './hour-banks';
import type { ProjectMilestones } from './planning';
import type { TemplateOption } from './templates';

/** App\Http\Resources\Projects\Paginated: una página de resultados. */
export type ProjectsPaginated<T> = {
    data: T[];
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

/** Filtros del listado (App\Domain\Projects\ProjectFilters), tal como van en la URL. */
export type ProjectListFilters = {
    cliente: number | null;
    /** '' = sin archivados; un estado; o 'todos' (también los archivados). */
    estado: '' | ProjectStatus | 'todos';
    tipo: BillingType | null;
    /** Gestor principal (owner, D-032). */
    responsable: number | null;
    /** Departamento implicado (D-037). */
    departamento: number | null;
    buscar: string;
    /** Solo los proyectos de los que soy miembro. */
    mios: boolean;
};

/** Fila del listado: ProjectListResource. */
export type ProjectListItem = Project & {
    /** Consumo agregado de las bolsas abiertas; null si no es un proyecto de bolsas. */
    hour_banks: {
        open_count: number;
        total_minutes: number;
        consumed_minutes: number;
        overage_minutes: number;
    } | null;
};

export type ProjectsIndexProps = {
    projects: ProjectsPaginated<ProjectListItem>;
    filters: ProjectListFilters;
    options: {
        clients: Option[];
        owners: Option[];
        departments: Option[];
    };
};

export type ProjectClientOption = Option & { is_active: boolean };

/** Persona interna activa para los selectores de gestor y miembros. */
export type ProjectPersonOption = Option & { department: string | null };

export type ProjectCreateProps = {
    clients: ProjectClientOption[];
    people: ProjectPersonOption[];
    defaults: {
        color: string;
        owner_user_id: number;
        status: ProjectStatus;
        billing_type: BillingType;
    };
    /** «Desde plantilla» (Fase 4, D-058): plantillas activas. */
    templates?: TemplateOption[];
    /** Para la primera bolsa de un proyecto de bolsas creado desde plantilla. */
    departments?: Option[];
    overageDefault?: 'allow' | 'block';
};

/** App\Domain\Projects\ProjectSummary. */
export type ProjectSummaryFigures = {
    estimated_minutes: number;
    logged_minutes: number;
    budget_minutes: number | null;
    open_tasks: number;
    total_tasks: number;
};

/** App\Domain\Projects\ProjectActivityFeed: quién, qué y cuándo. */
export type ProjectActivityItem = {
    id: number;
    actor: { id: number; name: string } | null;
    text: string;
    url: string | null;
    /** Instante ISO en UTC. */
    created_at: string | null;
};

export type ProjectShowProps = {
    project: Project;
    canManage: boolean;
    summary: ProjectSummaryFigures;
    managers: UserSummary[];
    membersCount: number;
    /** Bolsas abiertas (solo en proyectos de bolsas). */
    hourBanks: HourBankCard[];
    activity: ProjectActivityItem[];
    /** Próximos hitos (D-062): vencidos y los 5 siguientes. */
    milestones: ProjectMilestones;
};

export type ProjectSettingsProps = {
    project: Project;
    canManage: boolean;
    members: ProjectMember[];
    clients: ProjectClientOption[];
    people: ProjectPersonOption[];
    hasHourBanks: boolean;
    /**
     * Tareas sin bolsa (0 si ya es de bolsas): si pasa a «bolsa de horas», hay que crear su primera
     * bolsa en el mismo paso y van a ella.
     */
    tasksWithoutBank: number;
    /** Para la primera bolsa. */
    departments: Option[];
    overageDefault: 'allow' | 'block';
    can: {
        manageMembers: boolean;
        archive: boolean;
        /** Gestores cuyas alertas puede editar quien mira (D-023). */
        editAlertsOf: number[];
    };
};
