/**
 * Área «planning» (Fase 4, agente G2): calendario de tareas (D-061), dependencias en el panel de
 * la tarea y próximos hitos (D-062). Contrato con App\Domain\Planning\* y
 * App\Http\Controllers\Planning\*. Las fechas de tarea son "YYYY-MM-DD" locales, sin zona.
 */
import type { TaskPriority, UserSummary } from './domain';

export type CalendarMode = 'month' | 'week';

/** Tarea en el calendario (App\Domain\Planning\TaskCalendar::item), sin datos económicos. */
export type CalendarTask = {
    id: number;
    title: string;
    parent_task_id: number | null;
    /** Título de la tarea padre, si es una subtarea. */
    parent_title: string | null;
    status_id: number;
    priority: TaskPriority;
    assignee: UserSummary | null;
    start_date: string | null;
    due_date: string | null;
    is_milestone: boolean;
    is_completed: boolean;
};

/** Prop `calendar` de la pestaña Tareas con ?vista=calendario (null en las otras vistas). */
export type TaskCalendarData = {
    mode: CalendarMode;
    /** Mes "2026-10" o lunes de la semana "2026-10-05". */
    period: string;
    /** Primer y último día visibles (de lunes a domingo). */
    from: string;
    to: string;
    /** Hoy en Madrid. */
    today: string;
    /** Vencen en el periodo o lo cruzan con su franja (inicio → entrega). */
    tasks: CalendarTask[];
    /** Sin fecha de entrega (las más recientes). */
    undated: CalendarTask[];
    undated_total: number;
};

/** Tarea enlazada en la sección «Dependencias» del panel (App\Domain\Planning\TaskDependencyList). */
export type LinkedTask = {
    dependency_id: number;
    /** Fechas incompatibles (D-057): la sucesora empieza el día en que acaba la predecesora o antes. */
    conflict: boolean;
    task: {
        id: number;
        title: string;
        parent_task_id: number | null;
        status_id: number;
        start_date: string | null;
        due_date: string | null;
        is_milestone: boolean;
        is_completed: boolean;
    };
};

/** `panel.dependencies`: de qué tareas depende (predecesoras) y a cuáles bloquea (sucesoras). */
export type TaskPanelDependencies = {
    predecessors: LinkedTask[];
    successors: LinkedTask[];
};

/** GET /tareas/{task}/dependencias/candidatas → {tasks: DependencyCandidate[]}. */
export type DependencyCandidate = {
    id: number;
    title: string;
    parent_title: string | null;
    start_date: string | null;
    due_date: string | null;
    is_milestone: boolean;
    is_completed: boolean;
};

/** Hito de las listas de próximos hitos. `days`: días hasta la entrega (negativo si vencido). */
export type MilestoneItem = {
    id: number;
    project_id: number;
    title: string;
    due_date: string;
    is_overdue: boolean;
    days: number;
};

/** Prop `milestones` del resumen del proyecto (UpcomingMilestones::forProject). */
export type ProjectMilestones = {
    /** Los más atrasados primero (como mucho 10). */
    overdue: MilestoneItem[];
    overdue_total: number;
    /** Los 5 siguientes sin completar, por entrega. */
    upcoming: MilestoneItem[];
    undated_count: number;
    today: string;
};

/**
 * Prop `milestones` de Inicio (UpcomingMilestones::forUser): como mucho 8, por entrega, con los
 * vencidos (como mucho 3 si los próximos llenan la tarjeta) delante de los de los próximos 30 días.
 */
export type HomeMilestone = MilestoneItem & {
    project: { id: number; code: string; name: string; color: string };
};
