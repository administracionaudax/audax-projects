import type { TaskListItem, TaskStatus } from '@/types';

/**
 * Estado del kanban: ids de las tareas de cada columna (estado), en orden. Funciones puras para
 * poder probarlas y para la actualización optimista (se aplica al soltar y se deshace si falla).
 */
export type KanbanColumns = Record<number, number[]>;

export function buildColumns(
    tasks: TaskListItem[],
    statuses: TaskStatus[],
): KanbanColumns {
    const columns: KanbanColumns = {};

    for (const status of statuses) {
        columns[status.id] = tasks
            .filter((task) => task.status_id === status.id)
            .sort((a, b) => a.position - b.position || a.id - b.id)
            .map((task) => task.id);
    }

    return columns;
}

export function findColumn(
    columns: KanbanColumns,
    taskId: number,
): number | null {
    for (const [statusId, ids] of Object.entries(columns)) {
        if (ids.includes(taskId)) {
            return Number(statusId);
        }
    }

    return null;
}

/** Mueve la tarea a la columna $statusId en la posición $index (acotada al tamaño de la columna). */
export function moveTask(
    columns: KanbanColumns,
    taskId: number,
    statusId: number,
    index: number,
): KanbanColumns {
    const next: KanbanColumns = {};

    for (const [key, ids] of Object.entries(columns)) {
        next[Number(key)] = ids.filter((id) => id !== taskId);
    }

    const target = next[statusId] ?? [];
    const at = Math.max(0, Math.min(index, target.length));
    next[statusId] = [...target.slice(0, at), taskId, ...target.slice(at)];

    return next;
}

/**
 * Vecinas para el servidor (PATCH /tareas/{id}/posicion): antes de la siguiente; si es la última,
 * después de la anterior; en una columna vacía, ninguna (al final).
 */
export function neighbours(
    columns: KanbanColumns,
    statusId: number,
    taskId: number,
): { before_id: number | null; after_id: number | null } {
    const ids = columns[statusId] ?? [];
    const index = ids.indexOf(taskId);
    const next = ids[index + 1] ?? null;

    if (next !== null) {
        return { before_id: next, after_id: null };
    }

    return { before_id: null, after_id: ids[index - 1] ?? null };
}

export function sameColumns(a: KanbanColumns, b: KanbanColumns): boolean {
    const keys = new Set([...Object.keys(a), ...Object.keys(b)]);

    for (const key of keys) {
        const left = a[Number(key)] ?? [];
        const right = b[Number(key)] ?? [];

        if (
            left.length !== right.length ||
            left.some((id, index) => id !== right[index])
        ) {
            return false;
        }
    }

    return true;
}
