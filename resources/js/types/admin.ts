/**
 * Props de las páginas del área «admin» (Fase 1). Los tipos de entidad están en ./domain.
 * Contrato con app/Http/Controllers/Admin/* y app/Http/Resources/Admin/*.
 */
import type { Role } from './auth';
import type { Department, TaskStatus, TaskType, UserSummary } from './domain';

/** Paginación de Laravel (ResourceCollection con un paginador). */
export type AdminPaginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        path: string;
        per_page: number;
        to: number | null;
        total: number;
    };
};

/** Rol que se asigna desde /admin/usuarios (el de cliente llega en la Fase 5). */
export type AdminAssignableRole = Exclude<Role, 'client'>;

/** UserRowResource. */
export type AdminUser = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    role: Role | null;
    department_id: number | null;
    department?: Department | null;
    is_active: boolean;
    /** Último acceso correcto (ISO UTC); null si aún no ha entrado (invitación pendiente). */
    last_login_at: string | null;
    two_factor_enabled: boolean;
    hourly_cost?: string | null;
    default_hourly_rate?: string | null;
};

export type AdminUserStatusFilter = 'activos' | 'inactivos' | 'todos';

export type AdminUsersIndexProps = {
    users: AdminPaginated<AdminUser>;
    filters: {
        q: string;
        rol: AdminAssignableRole | null;
        /** Id del departamento o "ninguno". */
        departamento: string | null;
        estado: AdminUserStatusFilter;
    };
    departments: Department[];
    roles: AdminAssignableRole[];
    canGrantAdmin: boolean;
};

/** WorkScheduleResource: una versión de la jornada. */
export type AdminWorkSchedule = {
    id: number;
    valid_from: string;
    valid_to: string | null;
    /** Minutos de lunes a domingo. */
    week: number[];
    weekly_minutes: number;
    is_current: boolean;
    /** Solo la última versión, si aún no ha empezado. */
    is_editable: boolean;
};

export type AdminUserEditProps = {
    user: AdminUser;
    schedules: AdminWorkSchedule[];
    departments: Department[];
    roles: AdminAssignableRole[];
    openTasksCount: number;
    hasActiveTimer: boolean;
    can: {
        manage: boolean;
        grantAdmin: boolean;
        changeRole: boolean;
        deactivate: boolean;
        viewFinancials: boolean;
    };
};

export type AdminDeactivationTask = {
    id: number;
    title: string;
    due_date: string | null;
    project: { id: number; code: string; name: string; color: string };
};

/** Proyecto no archivado del que es gestor principal. */
export type AdminDeactivationProject = {
    id: number;
    code: string;
    name: string;
};

export type AdminUserDeactivateProps = {
    user: { id: number; name: string; email: string };
    /** Motivo por el que no se puede desactivar (último admin, uno mismo…), o null. */
    blocked: string | null;
    tasks: AdminDeactivationTask[];
    timer: {
        task_title: string;
        started_at: string;
        elapsed_minutes: number;
    } | null;
    candidates: UserSummary[];
    managedDepartments: string[];
    ownedProjects: AdminDeactivationProject[];
};

/** DepartmentRowResource. */
export type AdminDepartment = Department & {
    managers: UserSummary[];
    /** Personas activas. */
    users_count: number;
    /** Personas de baja que siguen en él (impiden borrarlo). */
    inactive_users_count: number;
    open_hour_banks_count: number;
    can_delete: boolean;
};

export type AdminDepartmentsProps = {
    departments: AdminDepartment[];
    managerOptions: UserSummary[];
    palette: string[];
};

/** TaskTypeRowResource. */
export type AdminTaskType = TaskType & {
    tasks_count: number;
    in_use: boolean;
};

export type AdminTaskTypesProps = {
    taskTypes: AdminTaskType[];
    departments: Department[];
    palette: string[];
    icons: string[];
};

/** TaskStatusRowResource. */
export type AdminTaskStatus = TaskStatus & {
    tasks_count: number;
};

export type AdminStatusesProps = {
    statuses: AdminTaskStatus[];
    palette: string[];
};

/** Setting::DEFAULTS (App\Http\Controllers\Admin\SettingsController). */
export type AdminSettings = {
    company_name: string;
    require_2fa: boolean;
    timer_rounding_minutes: number;
    timer_warning_hours: number;
    hour_bank_alert_thresholds: number[];
    allow_hour_bank_overage: boolean;
    require_timesheet_approval: boolean;
    allow_future_time_entries: boolean;
    time_entry_description_required: boolean;
    max_attachment_mb: number;
    /** Lunes primero, en minutos. */
    default_work_minutes: number[];
    /** Duración máxima de los audios del chat en segundos (Fase 6; entre 30 y 600). */
    max_audio_seconds: number;
};

export type AdminSettingsProps = {
    settings: AdminSettings;
    roundings: number[];
    /** Límite de subida del servidor (PHP) en MB, o null si no hay. */
    serverUploadLimitMb: number | null;
};
