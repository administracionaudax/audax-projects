/**
 * La Weekly en las fichas de cliente y de persona y la vista «Estado de proyectos» (entrega 10.4,
 * D-194). Contrato JSON con App\Domain\Weeklies\Insights\*, ProjectStatusBoard y los controladores
 * ProjectStatusController, ClientController y TeamController.
 */
import type { ProjectStatus, UserSummary } from './domain';
import type {
    WeeklyClientUpdate,
    WeeklyCycleStatus,
    WeeklyJobState,
    WeeklyPersonStatus,
    WeeklyProjectSnapshot,
    WeeklyStreakSummary,
} from './weeklies';

// --- Tipos de proyecto de WeeklySync (ProjectKindCode) ------------------------------------------

/** Prefijo del código (BH1, FE2…): el tipo de proyecto de WeeklySync. */
export type ProjectKindCode =
    | 'PR'
    | 'EC'
    | 'WE'
    | 'AD'
    | 'AM'
    | 'AT'
    | 'FE'
    | 'BH'
    | 'BR'
    | 'GE';

/** Grupo del tipo (las tres auditorías comparten «Auditoría»). */
export type ProjectKindTag =
    | 'product'
    | 'ecommerce'
    | 'web'
    | 'audit'
    | 'monthly_fee'
    | 'hour_bank'
    | 'branding'
    | 'general';

/** Insignia de un cliente: cuántos proyectos abiertos tiene de un grupo (F-120). */
export type ProjectKindBadge = { tag: ProjectKindTag; count: number };

// --- Estado de proyectos (F-119 a F-121) ---------------------------------------------------------

/** Un proyecto de la cartera, a fecha de hoy (ProjectStatusBoard). */
export type ProjectStatusEntry = WeeklyProjectSnapshot & {
    kind_code: ProjectKindCode;
    project_status: ProjectStatus;
};

export type ProjectStatusClient = {
    client: { id: number; name: string; icon: string | null };
    badges: ProjectKindBadge[];
    projects: ProjectStatusEntry[];
};

/** weeklies/project-status (ProjectStatusController). */
export type ProjectStatusPageProps = {
    clients: ProjectStatusClient[];
    /** "YYYY-MM-DD": hoy en Madrid, la fecha de lo consumido y lo esperado. */
    reference_date: string;
};

// --- Resúmenes con IA (AiSummaries::present) -------------------------------------------------------

export type AiSummaryKind =
    | 'client_summary'
    | 'client_team_activity'
    | 'person_performance'
    | 'person_client_activity';

export type AiSummary = {
    kind: AiSummaryKind;
    state: WeeklyJobState;
    /** En cola o generando desde hace más de 12 minutos: se puede volver a pedir. */
    stuck: boolean;
    /** Markdown (resumen del cliente y desempeño). */
    content: string | null;
    /** id (persona o cliente, como texto) → frase (equipo y actividad por cliente). */
    items: Record<string, string> | null;
    error: string | null;
    generated_at: string | null;
    requested_by: UserSummary | null;
};

// --- Ficha de cliente (F-128 a F-133) ------------------------------------------------------------

export type ClientTab = 'resumen' | 'historial' | 'equipo' | 'satisfaccion';

/** Referencia corta a una semana (ClientInsights::cycleRef). */
export type WeeklyCycleRef = {
    id: number;
    number: string;
    label: string;
    start_date: string;
    end_date: string;
    status: WeeklyCycleStatus;
};

/** Responsable del cliente: quien gestiona más proyectos abiertos (F-128). */
export type ClientOwner = UserSummary & { job_title: string | null };

export type ClientWeeklySummaryTab = {
    tab: 'resumen';
    latest: { cycle: WeeklyCycleRef; update: WeeklyClientUpdate } | null;
    satisfaction: { score: number; trend: number | null };
    ai: AiSummary | null;
};

export type ClientWeeklyEntry = {
    id: number;
    author: UserSummary | null;
    body: string;
    submitted_at: string;
    project: { id: number; code: string | null } | null;
};

export type ClientWeeklyHistoryTab = {
    tab: 'historial';
    weeks: {
        cycle: WeeklyCycleRef;
        update: WeeklyClientUpdate | null;
        entries: ClientWeeklyEntry[];
    }[];
};

export type ClientTeamMember = {
    user: UserSummary;
    job_title: string | null;
    role: 'owner' | 'member';
    projects: { id: number; code: string }[];
    last_report_at: string | null;
    reports: { cycle: WeeklyCycleRef; body: string; submitted_at: string }[];
};

export type ClientWeeklyTeamTab = {
    tab: 'equipo';
    owner_id: number | null;
    members: ClientTeamMember[];
    ai: AiSummary | null;
    /** Mis proyectos abiertos en el cliente, solo para enlazarlos (D-221). */
    my_projects: {
        id: number;
        code: string;
        name: string;
    }[];
    /** «Unirme a este cliente» de la Weekly (F-133, D-221): una suscripción, no una membresía. */
    subscription: { subscribed: boolean; can_join: boolean };
};

export type ClientSatisfactionPointRow = {
    cycle_id: number;
    number: string;
    label: string;
    end_date: string;
    score: number;
    delta: number;
    reasoning: string | null;
};

export type ClientWeeklySatisfactionTab = {
    tab: 'satisfaccion';
    score: number;
    points: ClientSatisfactionPointRow[];
    deltas: {
        weekly: number | null;
        monthly: number | null;
        quarterly: number | null;
    };
};

export type ClientWeeklyData =
    | ClientWeeklySummaryTab
    | ClientWeeklyHistoryTab
    | ClientWeeklyTeamTab
    | ClientWeeklySatisfactionTab;

// --- Equipo (F-134 a F-145) ----------------------------------------------------------------------

/** Ausencia de hoy; el tipo solo para quien puede saberlo (D-088). */
export type PersonAbsenceToday = {
    type: 'vacation' | 'sick' | 'leave' | 'training' | 'other' | null;
    until: string;
};

export type WeeklyRole = 'admin' | 'department_manager' | 'employee';

export type TeamMemberRow = {
    user: UserSummary;
    email: string;
    job_title: string | null;
    department: { id: number; name: string } | null;
    role: WeeklyRole | null;
    /** Estado en la semana activa; null sin semana activa. */
    report_status: WeeklyPersonStatus | null;
    submitted_at: string | null;
    absence: PersonAbsenceToday | null;
    client_ids: number[];
};

/** team/index (TeamController::index). */
export type TeamIndexPageProps = {
    members: TeamMemberRow[];
    cycle: WeeklyCycleRef | null;
    departments: { id: number; name: string }[];
    clients: { id: number; name: string; icon: string | null }[];
    roles: WeeklyRole[];
    /** remind: «Recordar» a quien tiene pendiente la semana activa (10.5, F-110). */
    can: { manageUsers: boolean; remind: boolean };
};

export type SubmissionDay = 'friday' | 'saturday' | 'sunday' | 'other';

/** Hábitos de envío (PersonInsights::habits, F-142). */
export type SubmissionHabits = {
    total: number;
    /** "HH:MM" en Madrid. */
    average_time: string;
    time_of_day: 'morning' | 'afternoon' | 'evening';
    most_common_day: SubmissionDay;
    distribution: Record<SubmissionDay, number>;
};

export type PersonClient = {
    id: number;
    name: string;
    icon: string | null;
    badges: ProjectKindBadge[];
    projects: { id: number; code: string; name: string }[];
};

export type PersonWeekEntry = {
    id: number;
    client: { id: number; name: string; icon: string | null } | null;
    project: { id: number; code: string } | null;
    body: string;
};

export type PersonWeek = {
    cycle: WeeklyCycleRef;
    status: Extract<WeeklyPersonStatus, 'submitted' | 'submitted_late'>;
    submitted_at: string;
    entries: PersonWeekEntry[];
};

/** team/show (TeamController::show). */
export type TeamShowPageProps = {
    person: UserSummary & {
        email: string;
        job_title: string | null;
        department: { id: number; name: string } | null;
        role: WeeklyRole | null;
    };
    absence: PersonAbsenceToday | null;
    habits: SubmissionHabits | null;
    clients: { owned: PersonClient[]; member: PersonClient[] };
    last_reports: {
        client: { id: number; name: string; icon: string | null } | null;
        cycle: WeeklyCycleRef;
        submitted_at: string;
        body: string;
    }[];
    weeks: PersonWeek[];
    cycle: { id: number; label: string; number: string } | null;
    /** Estado de su weekly en la semana activa. */
    status: WeeklyPersonStatus | null;
    streak: WeeklyStreakSummary;
    /** Solo para el admin y sus responsables (D-147). */
    ai: {
        performance: AiSummary | null;
        client_activity: AiSummary | null;
    } | null;
    can: { viewAi: boolean; manageUser: boolean; remind: boolean };
};
