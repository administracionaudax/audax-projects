/**
 * Entidades de la Fase 1: contrato con app/Http/Resources/*Resource.php.
 * Horas en minutos enteros; fechas de calendario "YYYY-MM-DD"; instantes ISO en UTC;
 * importes decimales como string ("45.00"), solo presentes con `view-financials`.
 */

export type BillingType =
    | 'hour_bank'
    | 'fixed_price'
    | 'time_and_materials'
    | 'internal'
    | 'monthly_fee';

export type ProjectStatus =
    | 'planned'
    | 'active'
    | 'on_hold'
    | 'completed'
    | 'archived';

export type HourBankStatus = 'active' | 'exhausted' | 'closed' | 'renewed';

export type OveragePolicy = 'inherit' | 'allow' | 'block';

export type TaskPriority = 'low' | 'normal' | 'high' | 'urgent';

export type TaskStatusCategory = 'todo' | 'in_progress' | 'done';

export type TimeEntryStatus = 'draft' | 'submitted' | 'approved' | 'locked';

export type TimesheetStatus =
    | 'open'
    | 'submitted'
    | 'returned'
    | 'approved'
    | 'locked';

/** App\Enums\ProjectAlert: alertas que elige cada gestor (D-023). */
export type ProjectAlert = 'hour_bank_threshold' | 'hour_bank_overage';

/** UserSummaryResource. */
export type UserSummary = {
    id: number;
    name: string;
    avatar: string | null;
    department_id: number | null;
    is_active: boolean;
};

/** DepartmentResource. */
export type Department = {
    id: number;
    name: string;
    color: string;
};

/** ClientResource. */
export type Client = {
    id: number;
    name: string;
    /** Emoji del cliente (Fase 10, F-126). */
    icon?: string | null;
    /** Satisfacción actual 0-100 (Fase 10, F-096). */
    satisfaction_score?: number;
    /** Responsable elegido a mano (D-232); null = se deduce de los proyectos. */
    owner_user_id?: number | null;
    tax_id: string | null;
    contact_name: string | null;
    contact_email: string | null;
    phone: string | null;
    notes: string | null;
    is_active: boolean;
    default_hourly_rate?: string | null;
    projects_count?: number;
};

/** ProjectResource. */
export type Project = {
    id: number;
    code: string;
    name: string;
    color: string;
    description: string | null;
    client?: { id: number; name: string } | null;
    client_id: number | null;
    billing_type: BillingType;
    status: ProjectStatus;
    start_date: string | null;
    due_date: string | null;
    budget_minutes: number | null;
    /** Fee mensual (Fase 12, D-382): horas al mes y, con view-financials, el importe al mes. */
    monthly_minutes?: number | null;
    monthly_fee_amount?: string | null;
    fixed_price_amount?: string | null;
    hourly_rate?: string | null;
    owner?: UserSummary;
    owner_user_id: number;
    is_internal: boolean;
};

/** Miembro de un proyecto (pivote project_members, D-023). */
export type ProjectMember = UserSummary & {
    is_manager: boolean;
    is_owner: boolean;
    alert_preferences: Record<ProjectAlert, boolean>;
};

/** HourBankResource. */
export type HourBank = {
    id: number;
    project_id: number;
    name: string;
    department?: Department | null;
    department_id: number | null;
    total_minutes: number;
    consumed_minutes: number;
    overage_minutes: number;
    remaining_minutes: number;
    in_bank_minutes: number;
    /** Puede superar 100. */
    consumed_pct: number;
    status: HourBankStatus;
    overage_policy: OveragePolicy;
    effective_overage_policy: 'allow' | 'block';
    start_date: string;
    end_date: string | null;
    renewed_from_id: number | null;
    invoice_reference: string | null;
    notes: string | null;
    closed_at: string | null;
    closed_remaining_minutes: number | null;
    hourly_rate?: string | null;
    price_amount?: string | null;
};

/** TaskStatusResource. */
export type TaskStatus = {
    id: number;
    name: string;
    color: string;
    category: TaskStatusCategory;
    position: number;
    is_default: boolean;
};

/** TaskTypeResource. */
export type TaskType = {
    id: number;
    name: string;
    color: string;
    /** Nombre de icono de lucide ("code-xml"). */
    icon: string | null;
    department_id: number | null;
    is_billable_default: boolean;
    is_active: boolean;
    position: number;
};

/** TaskResource. Los campos opcionales solo llegan si el controlador los carga. */
export type Task = {
    id: number;
    project_id: number;
    hour_bank_id: number | null;
    parent_task_id: number | null;
    title: string;
    /** HTML saneado (App\Support\RichText). */
    description?: string | null;
    task_type_id: number | null;
    status_id: number;
    priority: TaskPriority;
    assignee?: UserSummary | null;
    assignee_user_id: number | null;
    start_date: string | null;
    due_date: string | null;
    estimated_minutes: number | null;
    is_billable: boolean;
    is_milestone: boolean;
    is_completed: boolean;
    position: number;
    completed_at: string | null;
    /** null para un colaborador externo: no ve las horas de todos (D-134). */
    logged_minutes?: number | null;
    /**
     * Lo imputado en sus subtareas (D-170), solo en las tareas raíz y si el servidor lo calcula;
     * null para un colaborador externo. El registrado total es logged_minutes + esto.
     */
    subtasks_logged_minutes?: number | null;
    subtasks_count?: number;
    comments_count?: number;
    attachments_count?: number;
};

/** TimeEntryResource. */
export type TimeEntry = {
    id: number;
    user?: UserSummary;
    user_id: number;
    task?: { id: number; title: string };
    task_id: number;
    project?: { id: number; code: string; name: string; color: string };
    project_id: number;
    hour_bank_id: number | null;
    date: string;
    minutes: number;
    overage_minutes: number;
    in_bank_minutes: number;
    started_at: string | null;
    ended_at: string | null;
    description: string | null;
    is_billable: boolean;
    status: TimeEntryStatus;
    approved_at: string | null;
    created_by: number | null;
    logged_on_behalf: boolean;
    hourly_rate_snapshot?: string | null;
    hourly_cost_snapshot?: string | null;
};

/** App\Domain\Time\TimeEntryWarning (avisos no bloqueantes al imputar). */
export type TimeEntryWarning = {
    code:
        | 'task_completed'
        | 'over_capacity'
        | 'overage'
        | 'absence'
        | 'overlap';
    message: string;
};

/** Datos de una notificación del canal database (App\Notifications\AppNotification). */
export type AppNotificationData = {
    kind: string;
    title: string;
    body: string | null;
    url: string | null;
    icon: string | null;
};

export type AppNotification = {
    id: string;
    data: AppNotificationData;
    read_at: string | null;
    created_at: string;
};

/** Opción de un selector (id + etiqueta), p. ej. clientes o proyectos en filtros. */
export type Option = {
    id: number;
    name: string;
};
