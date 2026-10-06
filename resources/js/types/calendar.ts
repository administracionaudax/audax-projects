/**
 * Calendario del equipo (/calendario, D-144). Contrato con App\Http\Controllers\Calendar\
 * TeamCalendarController y App\Domain\Calendar\*. Fechas de tarea "YYYY-MM-DD" locales, sin zona.
 */
import type { DayPlanLine } from './day-plan';
import type { TaskPriority, TaskStatus, TaskType, UserSummary } from './domain';
import type {
    ProjectTasksPageProps,
    TaskPanelData,
    TaskMoveTarget,
} from './tasks';

export type TeamCalendarView = 'month' | 'week' | 'day';

/** App\Domain\Calendar\CalendarFilters::toArray(). */
export type TeamCalendarFilters = {
    view: TeamCalendarView;
    /** Filas por persona (solo semana y día). */
    people: boolean;
    /** Día de referencia. */
    date: string;
    persons: number[];
    department: number | null;
    projects: number[];
    clients: number[];
    /** Incluir las hechas. */
    done: boolean;
    priority: TaskPriority | null;
    types: number[];
    milestones: boolean;
    unassigned: boolean;
    mine: boolean;
    q: string | null;
};

/** Tarea del calendario (sin horas ni datos económicos). */
export type TeamCalendarTask = {
    id: number;
    title: string;
    project_id: number;
    parent_task_id: number | null;
    parent_title: string | null;
    status_id: number;
    priority: TaskPriority;
    task_type_id: number | null;
    assignee_id: number | null;
    start_date: string | null;
    due_date: string | null;
    is_milestone: boolean;
    is_completed: boolean;
    estimated_minutes: number | null;
};

export type TeamCalendarProject = {
    id: number;
    code: string;
    name: string;
    color: string;
    /** TaskPolicy::update: puede mover sus tareas. */
    can_update: boolean;
};

/** Lo de una persona un día (vista «Personas»). */
export type TeamCalendarDay = {
    /** Minutos de capacidad (null si quien mira no ve su carga). */
    capacity: number | null;
    /** Minutos planificados (null: no la ve, o el día ya pasó). */
    load: number | null;
    /** Ausencia aprobada; el tipo, solo si quien mira puede verlo. */
    absence: { partial: boolean; label: string | null } | null;
    holiday: string | null;
};

export type TeamCalendarRow = {
    /** null: la fila «Sin asignar». */
    person: {
        id: number;
        name: string;
        avatar: string | null;
        department: { id: number; name: string } | null;
    } | null;
    show_load: boolean;
    days: Record<string, TeamCalendarDay>;
};

/** Prop `calendar`. */
export type TeamCalendarData = {
    view: TeamCalendarView;
    date: string;
    /** Primer y último día visibles. */
    from: string;
    to: string;
    today: string;
    people_view: boolean;
    tasks: TeamCalendarTask[];
    projects: TeamCalendarProject[];
    assignees: UserSummary[];
    /** Hay más de `limit` tareas: solo llegan las primeras. */
    truncated: boolean;
    total: number;
    limit: number;
    rows: TeamCalendarRow[];
    /** Plan del día (D-254): en la vista Día por personas, las líneas de cada una; null sin el módulo. */
    day_plans?: Record<number, DayPlanLine[]> | null;
};

export type TeamCalendarOptions = {
    people: {
        id: number;
        name: string;
        avatar: string | null;
        department_id: number | null;
    }[];
    departments: { id: number; name: string }[];
    projects: {
        id: number;
        code: string;
        name: string;
        color: string;
        client_id: number | null;
    }[];
    clients: { id: number; name: string }[];
    types: TaskType[];
};

/** Proyecto donde se puede crear una tarea (prop opcional `creatable`). */
export type TeamCalendarCreatable = TaskMoveTarget;

/** Lo que el panel de la tarea necesita de su proyecto (TaskPanelContext::lookups). */
export type TeamCalendarPanelLookups = Pick<
    ProjectTasksPageProps,
    | 'project'
    | 'canManage'
    | 'can'
    | 'statuses'
    | 'types'
    | 'banks'
    | 'users'
    | 'currentUser'
    | 'maxAttachmentMb'
>;

export type TeamCalendarPageProps = {
    filters: TeamCalendarFilters;
    calendar: TeamCalendarData;
    statuses: TaskStatus[];
    options: TeamCalendarOptions;
    currentUser: { id: number; department_id: number | null };
    panel: TaskPanelData | null;
    panelLookups: TeamCalendarPanelLookups | null;
    creatable?: TeamCalendarCreatable[];
    moveTargets?: TaskMoveTarget[];
};
