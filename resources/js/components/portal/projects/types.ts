/**
 * Proyectos en el portal (Fase 5, D-064) y cabecera del portal (D-067). Contrato con
 * App\Domain\Portal\Projects\PortalProjects y PortalShell. Nunca lleva personas, horas por persona,
 * comentarios, adjuntos ni importes.
 */
import type {
    GanttPreferences,
    GanttRange,
    GanttTask,
    GanttTaskStatus,
} from '@/components/gantt/types';
import type { ProjectStatus, TaskStatusCategory } from '@/types';
import type { TaskDependencyItem } from '@/types/schedule';

/** Nombre y logo de la empresa (/admin/identidad). */
export type PortalCompany = {
    name: string;
    logo: { url: string; width: number; height: number } | null;
};

/** Proyecto abierto al portal en la navegación. */
export type PortalNavProject = {
    id: number;
    code: string;
    name: string;
    /** Vista del proyecto (tareas y estados) abierta. */
    view: boolean;
    /** Gantt de solo lectura abierto. */
    gantt: boolean;
};

/** Prop compartida `portal` (solo en las páginas de un usuario del portal). */
export type PortalShellProps = {
    company: PortalCompany;
    projects: PortalNavProject[];
};

export type PortalProjectSummary = {
    id: number;
    code: string;
    name: string;
    status: ProjectStatus;
    start_date: string | null;
    due_date: string | null;
};

export type PortalTaskStatus = {
    id: number;
    name: string;
    color: string;
    category: TaskStatusCategory;
};

/** Tarea en la vista del proyecto del portal: raíz (depth 0) y, detrás, sus subtareas (depth 1). */
export type PortalTask = {
    id: number;
    parent_task_id: number | null;
    depth: 0 | 1;
    title: string;
    status: PortalTaskStatus | null;
    start_date: string | null;
    due_date: string | null;
    is_milestone: boolean;
    is_completed: boolean;
    subtasks_count: number;
    /** Horas visibles (con las de sus subtareas), solo si el proyecto las enseña. */
    minutes?: number;
};

/** /portal/proyectos/{proyecto}. */
export type PortalProjectShowProps = {
    project: PortalProjectSummary;
    tasks: PortalTask[];
    statuses: PortalTaskStatus[];
    showHours: boolean;
    totals: {
        tasks: number;
        done: number;
        open: number;
        milestones: number;
        minutes: number | null;
    };
    /** El Gantt también está abierto. */
    gantt: boolean;
};

export type PortalProjectListItem = PortalProjectSummary & {
    view: boolean;
    gantt: boolean;
    progress: { done: number; total: number } | null;
};

/** /portal/proyectos. */
export type PortalProjectsIndexProps = {
    projects: PortalProjectListItem[];
};

/** /portal/proyectos/{proyecto}/gantt. */
export type PortalProjectGanttProps = {
    project: PortalProjectSummary;
    tasks: GanttTask[];
    dependencies: TaskDependencyItem[];
    statuses: GanttTaskStatus[];
    range: GanttRange;
    preferences: GanttPreferences;
    today: string;
    /** La vista del proyecto también está abierta. */
    view: boolean;
};
