/**
 * Filas del Gantt a partir de las tareas (D-060):
 * - las tareas raíz en el orden de la lista y, debajo, sus subtareas (un nivel, sangradas),
 * - una tarea con subtareas con fechas se pinta como RESUMEN: de la primera a la última fecha de
 *   sus subtareas (y las suyas propias),
 * - las tareas sin ninguna fecha (que tampoco son un resumen) van a la lista «Sin fechas»,
 * - en el Gantt multiproyecto, cada proyecto es un grupo plegable con su barra resumen (de la
 *   primera a la última fecha de sus tareas).
 */
import { taskSpan, unionSpan } from '@/components/gantt/geometry';
import type { Span } from '@/components/gantt/geometry';
import type { GanttProject, GanttTask } from '@/components/gantt/types';

export type GanttTaskRow = {
    kind: 'task';
    key: string;
    task: GanttTask;
    depth: 0 | 1;
    /** Resumen de sus subtareas con fechas (null si es una barra normal). */
    summary: Span | null;
};

export type GanttProjectRow = {
    kind: 'project';
    key: string;
    project: GanttProject;
    span: Span | null;
    collapsed: boolean;
    /** Tareas que se ven en el diagrama. */
    scheduledCount: number;
    /** Tareas sin fechas del proyecto. */
    unscheduledCount: number;
};

export type GanttRow = GanttTaskRow | GanttProjectRow;

export function hasDates(task: GanttTask): boolean {
    return task.start_date !== null || task.due_date !== null;
}

export function spanOfTask(task: GanttTask): Span | null {
    return taskSpan(task, task.is_milestone);
}

/**
 * Filas de las tareas de un proyecto y las tareas sin fechas, en el orden en que llegan (el de la
 * lista). Las subtareas cuya tarea no llega se tratan como raíz.
 */
export function buildTaskRows(tasks: ReadonlyArray<GanttTask>): {
    rows: GanttTaskRow[];
    unscheduled: GanttTask[];
} {
    const ids = new Set(tasks.map((task) => task.id));
    const children = new Map<number, GanttTask[]>();
    const roots: GanttTask[] = [];

    for (const task of tasks) {
        if (task.parent_task_id !== null && ids.has(task.parent_task_id)) {
            const list = children.get(task.parent_task_id) ?? [];
            list.push(task);
            children.set(task.parent_task_id, list);
        } else {
            roots.push(task);
        }
    }

    const rows: GanttTaskRow[] = [];
    const unscheduled: GanttTask[] = [];

    for (const root of roots) {
        const subtasks = children.get(root.id) ?? [];
        const dated = subtasks.filter(hasDates);

        if (dated.length > 0) {
            rows.push({
                kind: 'task',
                key: `t-${root.id}`,
                task: root,
                depth: 0,
                summary: unionSpan([
                    spanOfTask(root),
                    ...dated.map(spanOfTask),
                ]),
            });
        } else if (hasDates(root)) {
            rows.push({
                kind: 'task',
                key: `t-${root.id}`,
                task: root,
                depth: 0,
                summary: null,
            });
        } else {
            unscheduled.push(root);
        }

        for (const subtask of subtasks) {
            if (hasDates(subtask)) {
                rows.push({
                    kind: 'task',
                    key: `t-${subtask.id}`,
                    task: subtask,
                    depth: 1,
                    summary: null,
                });
            } else {
                unscheduled.push(subtask);
            }
        }
    }

    return { rows, unscheduled };
}

/**
 * Filas del Gantt multiproyecto: cada proyecto (en el orden recibido) seguido de sus tareas si no
 * está plegado. Las tareas sin fechas se cuentan por proyecto.
 */
export function buildPortfolioRows(
    projects: ReadonlyArray<GanttProject>,
    tasks: ReadonlyArray<GanttTask>,
    collapsed: ReadonlySet<number>,
): { rows: GanttRow[]; unscheduled: Map<number, GanttTask[]> } {
    const byProject = new Map<number, GanttTask[]>();

    for (const task of tasks) {
        const list = byProject.get(task.project_id) ?? [];
        list.push(task);
        byProject.set(task.project_id, list);
    }

    const rows: GanttRow[] = [];
    const unscheduled = new Map<number, GanttTask[]>();

    for (const project of projects) {
        const built = buildTaskRows(byProject.get(project.id) ?? []);
        const isCollapsed = collapsed.has(project.id);

        unscheduled.set(project.id, built.unscheduled);
        rows.push({
            kind: 'project',
            key: `p-${project.id}`,
            project,
            span: unionSpan(
                built.rows.map((row) => row.summary ?? spanOfTask(row.task)),
            ),
            collapsed: isCollapsed,
            scheduledCount: built.rows.length,
            unscheduledCount: built.unscheduled.length,
        });

        if (!isCollapsed) {
            rows.push(...built.rows);
        }
    }

    return { rows, unscheduled };
}
