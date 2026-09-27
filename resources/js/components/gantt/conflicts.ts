/**
 * Conflictos de las dependencias fin-inicio (D-057), la misma regla que
 * App\Domain\Schedule\ScheduleConflicts: una sucesora está en conflicto si empieza (o, sin inicio,
 * vence) el mismo día o antes de que acabe (entrega) su predecesora. Sin esas fechas, no hay
 * conflicto. Las dependencias son una ayuda: el Gantt solo lo avisa.
 */
import type { GanttDates, GanttTask } from '@/components/gantt/types';
import type { TaskDependencyItem } from '@/types/schedule';

export function isConflict(
    predecessor: GanttDates,
    successor: GanttDates,
): boolean {
    const end = predecessor.due_date;
    const begin = successor.start_date ?? successor.due_date;

    return end !== null && begin !== null && begin <= end;
}

/** Ids de las dependencias en conflicto con las fechas actuales (incluidas las que se mueven). */
export function conflictingDependencies(
    dependencies: ReadonlyArray<TaskDependencyItem>,
    tasksById: ReadonlyMap<number, GanttTask>,
): Set<number> {
    const conflicts = new Set<number>();

    for (const dependency of dependencies) {
        const predecessor = tasksById.get(dependency.predecessor_task_id);
        const successor = tasksById.get(dependency.successor_task_id);

        if (predecessor && successor && isConflict(predecessor, successor)) {
            conflicts.add(dependency.id);
        }
    }

    return conflicts;
}
