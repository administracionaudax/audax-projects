/**
 * Tipos del Gantt (Fase 4, D-060). Contrato con App\Domain\Gantt\GanttData, GanttPortfolio y los
 * controladores App\Http\Controllers\Gantt\*. Fechas de tarea "YYYY-MM-DD", sin zona horaria.
 */
import type {
    Option,
    Project,
    ProjectStatus,
    TaskBankOption,
    TaskStatusCategory,
} from '@/types';
import type { TaskDependencyItem } from '@/types/schedule';

export type GanttScale = 'day' | 'week' | 'month';

export type GanttColorMode = 'status' | 'assignee';

export type GanttPreferences = {
    scale: GanttScale;
    color: GanttColorMode;
};

export type GanttTaskStatus = {
    id: number;
    name: string;
    color: string;
    category: TaskStatusCategory;
};

export type GanttAssignee = {
    id: number;
    name: string;
    avatar: string | null;
};

/** Una tarea del Gantt (GanttData::tasks). */
export type GanttTask = {
    id: number;
    project_id: number;
    parent_task_id: number | null;
    title: string;
    start_date: string | null;
    due_date: string | null;
    is_milestone: boolean;
    is_completed: boolean;
    status: GanttTaskStatus | null;
    assignee: GanttAssignee | null;
    /** Estimación efectiva: con subtareas estimadas, su suma (SPEC §6). */
    estimated_minutes: number | null;
    /** Horas imputadas (con las de sus subtareas, si las tiene). */
    logged_minutes: number;
    subtasks_count: number;
    can: { update: boolean };
};

/** Fechas de una tarea (inicio y entrega, las dos opcionales). */
export type GanttDates = {
    start_date: string | null;
    due_date: string | null;
};

/** Rango de fechas, los dos extremos incluidos. */
export type GanttRange = {
    start: string;
    end: string;
};

/** Proyecto del Gantt multiproyecto (GanttPortfolio::build). */
export type GanttProject = {
    id: number;
    code: string;
    name: string;
    color: string;
    status: ProjectStatus;
    uses_hour_banks: boolean;
    client: { id: number; name: string } | null;
    owner: { id: number; name: string };
    start_date: string | null;
    due_date: string | null;
    can: { update: boolean };
};

export type GanttLimit = {
    exceeded: 'projects' | 'tasks' | null;
    projects: number;
    tasks: number;
    max_projects: number;
    max_tasks: number;
};

/** Filtros de /gantt (en la URL, en español). */
export type GanttFilters = {
    cliente: number | null;
    departamento: number | null;
    responsable: number | null;
    /** Estado del proyecto, «sin-archivar» o «todos». Por defecto, «active». */
    estado: ProjectStatus | 'sin-archivar' | 'todos';
};

/** App\Http\Controllers\Gantt\ProjectGanttController. */
export type ProjectGanttPageProps = {
    project: Project;
    canManage: boolean;
    can: { create: boolean; update: boolean };
    preferences: GanttPreferences;
    today: string;
    tasks: GanttTask[];
    dependencies: TaskDependencyItem[];
    range: GanttRange;
    statuses: GanttTaskStatus[];
    /** Bolsas abiertas para crear tareas (solo en proyectos de bolsas y si puede crear). */
    banks: TaskBankOption[];
    currentUser: { id: number; department_id: number | null };
};

/** App\Http\Controllers\Gantt\GanttController. */
export type GanttIndexPageProps = {
    filters: GanttFilters;
    options: { clients: Option[]; owners: Option[]; departments: Option[] };
    preferences: GanttPreferences;
    today: string;
    statuses: GanttTaskStatus[];
    limit: GanttLimit;
    projects: GanttProject[];
    tasks: GanttTask[];
    dependencies: TaskDependencyItem[];
    range: GanttRange;
};
