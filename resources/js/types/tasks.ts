/**
 * Props de las páginas del área «tasks» (Fase 1): pestañas Tareas y Archivos del proyecto y
 * Mis tareas. Contrato con app/Http/Controllers/Tasks y app/Http/Resources/Tasks.
 * Los tipos de entidad están en ./domain.
 */
import type {
    HourBankStatus,
    Project,
    Task,
    TaskPriority,
    TaskStatus,
    TaskType,
    TimeEntry,
    UserSummary,
} from './domain';

export type TaskView = 'list' | 'kanban';

export type TaskGroupBy = 'status' | 'assignee' | 'bank' | 'type' | 'none';

/** Filtros de la pestaña Tareas (en la URL: responsable, bolsa, tipo, prioridad, estado, mias, completadas, agrupar). */
export type TaskFilters = {
    /** Id de la persona, 'none' (sin responsable) o null (todas). */
    assignee: number | 'none' | null;
    bank: number | null;
    type: number | null;
    priority: TaskPriority | null;
    status: number | null;
    mine: boolean;
    /** Mostrar las completadas (ocultas por defecto). */
    completed: boolean;
    group: TaskGroupBy;
};

/** TaskListItemResource: fila de la lista y tarjeta del kanban. */
export type TaskListItem = Task & {
    assignee?: UserSummary | null;
    logged_minutes?: number;
    /** Solo en las tareas raíz. */
    subtasks?: TaskListItem[];
    estimate_from_subtasks: boolean;
    effective_estimated_minutes: number | null;
};

/** TaskBankOptionResource (sin datos económicos). */
export type TaskBankOption = {
    id: number;
    name: string;
    status: HourBankStatus;
    /** Activa o agotada: admite tareas y horas. */
    is_open: boolean;
    department_id: number | null;
    department?: { id: number; name: string; color: string } | null;
    consumed_pct: number;
};

/** Persona interna activa que se puede asignar (los miembros del proyecto, primero). */
export type TaskAssignee = UserSummary & { is_member: boolean };

/** AttachmentResource. Las URLs son firmadas y caducan en 1 h. */
export type TaskAttachment = {
    id: number;
    original_name: string;
    mime: string;
    size: number;
    /** Imagen rasterizada: se ve en el navegador y tiene miniatura. */
    is_image: boolean;
    url: string;
    thumbnail_url: string | null;
    uploader?: UserSummary | null;
    created_at: string | null;
    /** Pestaña Archivos: la tarea a la que pertenece (también si está en un comentario). */
    task?: { id: number; title: string; project_id: number } | null;
    in_comment: boolean;
    can_delete: boolean;
    /** Pestaña Archivos: adjunto del chat del proyecto, con su enlace al mensaje (D-118). */
    message?: {
        conversation_id: number;
        message_id: number;
        deleted: boolean;
    } | null;
};

export type TaskCommentReaction = {
    emoji: string;
    count: number;
    /** ¿La ha puesto quien mira? */
    reacted: boolean;
    users: string[];
};

/** Comentario del panel (HTML ya saneado en el servidor). */
export type TaskCommentItem = {
    id: number;
    body: string;
    author: UserSummary | null;
    created_at: string | null;
    edited_at: string | null;
    can_update: boolean;
    can_delete: boolean;
    reactions: TaskCommentReaction[];
    attachments: TaskAttachment[];
};

export type TaskActivityField =
    | 'title'
    | 'status_id'
    | 'assignee_user_id'
    | 'priority'
    | 'hour_bank_id'
    | 'task_type_id'
    | 'start_date'
    | 'due_date'
    | 'estimated_minutes'
    | 'is_billable'
    | 'is_milestone'
    | 'project_id'
    | 'description';

export type TaskActivityItem = {
    id: number;
    /** created, updated, deleted o restored. */
    event: string;
    causer: string | null;
    created_at: string | null;
    /** Valores ya formateados en español (nombres, fechas dd/mm/aaaa, h:mm). */
    changes: {
        field: TaskActivityField;
        from: string | null;
        to: string | null;
    }[];
};

export type TaskDeleteBlocked =
    | 'has_time'
    | 'subtasks_have_time'
    | 'timer_running';

/** Prop `panel` (App\Http\Resources\Tasks\TaskPanel), con recarga parcial. */
export type TaskPanelData = {
    task: Task & {
        description: string | null;
        assignee?: UserSummary | null;
        logged_minutes?: number;
        creator: UserSummary | null;
        created_at: string | null;
        updated_at: string | null;
    };
    project: {
        id: number;
        code: string;
        name: string;
        uses_hour_banks: boolean;
        is_internal: boolean;
    };
    parent: { id: number; title: string } | null;
    subtasks: TaskListItem[];
    estimate_from_subtasks: boolean;
    effective_estimated_minutes: number | null;
    watchers: UserSummary[];
    is_watching: boolean;
    attachments: TaskAttachment[];
    comments: TaskCommentItem[];
    /** Entradas que quien mira puede ver (D-021), las 50 más recientes. */
    time_entries: TimeEntry[];
    time_visible_minutes: number;
    /** ¿La tarea o sus subtareas tienen horas? (aviso al cambiar de bolsa). */
    has_time: boolean;
    activity: TaskActivityItem[];
    reaction_emojis: string[];
    delete_blocked: TaskDeleteBlocked | null;
    /** Mensaje del chat desde el que se creó (Fase 6), si quien mira ve esa conversación. */
    source_message?: { conversation_id: number; message_id: number } | null;
    can: {
        update: boolean;
        delete: boolean;
        comment: boolean;
        move: boolean;
        log_time: boolean;
    };
};

/** Proyecto al que se puede mover una tarea (prop opcional `moveTargets`). */
export type TaskMoveTarget = {
    id: number;
    code: string;
    name: string;
    uses_hour_banks: boolean;
    banks: TaskBankOption[];
};

/** App\Http\Controllers\Tasks\ProjectTasksController::index. */
export type ProjectTasksPageProps = {
    project: Project;
    canManage: boolean;
    can: { create: boolean; update: boolean };
    view: TaskView;
    filters: TaskFilters;
    tasks: TaskListItem[];
    hiddenCompletedCount: number;
    statuses: TaskStatus[];
    types: TaskType[];
    banks: TaskBankOption[];
    users: TaskAssignee[];
    currentUser: { id: number; department_id: number | null };
    maxAttachmentMb: number;
    panel: TaskPanelData | null;
    moveTargets?: TaskMoveTarget[];
};

export type MyTaskSectionKey =
    | 'overdue'
    | 'today'
    | 'this_week'
    | 'upcoming'
    | 'no_date';

/** MyTaskItemResource. */
export type MyTaskItem = Task & {
    logged_minutes?: number;
    project: { id: number; code: string; name: string; color: string };
    hour_bank: { id: number; name: string } | null;
    parent: { id: number; title: string } | null;
};

/** App\Http\Controllers\Tasks\MyTasksController. */
export type MyTasksPageProps = {
    /** Hoy en Madrid, "YYYY-MM-DD". */
    today: string;
    sections: { key: MyTaskSectionKey; tasks: MyTaskItem[] }[];
    statuses: TaskStatus[];
};

export type ProjectFileCategory =
    | 'image'
    | 'pdf'
    | 'document'
    | 'spreadsheet'
    | 'text'
    | 'archive';

/** App\Http\Controllers\Tasks\ProjectFilesController. */
export type ProjectFilesPageProps = {
    project: Project;
    canManage: boolean;
    files: TaskAttachment[];
    pagination: {
        current_page: number;
        last_page: number;
        total: number;
        prev_url: string | null;
        next_url: string | null;
    };
    filters: { type: ProjectFileCategory | null; task: number | null };
    categories: ProjectFileCategory[];
    tasks: { id: number; name: string }[];
};
