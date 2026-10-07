import type { QuickAddDefaults } from '@/components/tasks/quick-add-task';
import type { TaskLookups } from '@/components/tasks/task-lookups';
import { t } from '@/lib/i18n';
import type { TaskGroupBy, TaskListItem } from '@/types';

export type TaskGroup = {
    key: string;
    label: string;
    /** Color del punto del grupo (estado, tipo o departamento de la bolsa). */
    color?: string;
    tasks: TaskListItem[];
    /** Valores con los que la creación rápida del grupo crea la tarea. */
    defaults: QuickAddDefaults;
    /**
     * Plegado mientras la persona no lo despliegue: el estado «done», el que más crece (D-320), y
     * los estados sin tareas salvo el de por defecto (D-328).
     */
    collapsedByDefault?: boolean;
};

function byPosition(a: TaskListItem, b: TaskListItem): number {
    return a.position - b.position || a.id - b.id;
}

/**
 * Agrupa las tareas raíz de la lista (SPEC §6: por estado, responsable, bolsa o tipo).
 * - Por estado: todas las columnas en su orden (las «done» solo si se muestran las completadas),
 *   aunque estén vacías, para poder crear tareas en ellas.
 * - Por responsable, bolsa o tipo: solo los grupos con tareas, y «sin …» al final.
 * - Sin agrupar: un solo grupo, por estado y posición.
 */
export function groupTasks(
    tasks: TaskListItem[],
    groupBy: TaskGroupBy,
    lookups: TaskLookups,
    showCompleted: boolean,
): TaskGroup[] {
    const statusOrder = new Map(
        lookups.statuses.map((status, index) => [status.id, index]),
    );

    if (groupBy === 'status') {
        return lookups.statuses
            .filter((status) => showCompleted || status.category !== 'done')
            .map((status) => {
                const statusTasks = tasks
                    .filter((task) => task.status_id === status.id)
                    .sort(byPosition);

                return {
                    key: `status-${status.id}`,
                    label: status.name,
                    color: status.color,
                    tasks: statusTasks,
                    defaults: { status_id: status.id },
                    // El estado por defecto queda abierto aunque esté vacío: siempre hay un alta
                    // rápida a la vista.
                    collapsedByDefault:
                        status.category === 'done' ||
                        (statusTasks.length === 0 && !status.is_default),
                };
            });
    }

    const sorted = [...tasks].sort(
        (a, b) =>
            (statusOrder.get(a.status_id) ?? 0) -
                (statusOrder.get(b.status_id) ?? 0) || byPosition(a, b),
    );

    if (groupBy === 'none') {
        return [
            {
                key: 'all',
                label: t('task_list.all_tasks'),
                tasks: sorted,
                defaults: {},
            },
        ];
    }

    const groups = new Map<string, TaskGroup>();
    const none: TaskGroup = {
        key: 'none',
        label: '',
        tasks: [],
        defaults: {},
    };

    for (const task of sorted) {
        let group: TaskGroup | null = null;

        if (groupBy === 'assignee' && task.assignee_user_id !== null) {
            const user =
                lookups.userById.get(task.assignee_user_id) ?? task.assignee;
            group = groups.get(`assignee-${task.assignee_user_id}`) ?? {
                key: `assignee-${task.assignee_user_id}`,
                label: user?.name ?? t('task_fields.unknown_person'),
                tasks: [],
                defaults: { assignee_user_id: task.assignee_user_id },
            };
        } else if (groupBy === 'bank' && task.hour_bank_id !== null) {
            const bank = lookups.bankById.get(task.hour_bank_id);
            group = groups.get(`bank-${task.hour_bank_id}`) ?? {
                key: `bank-${task.hour_bank_id}`,
                label: bank?.name ?? t('task_fields.unknown_bank'),
                color: bank?.department?.color,
                tasks: [],
                defaults:
                    bank?.is_open === true
                        ? { hour_bank_id: task.hour_bank_id }
                        : {},
            };
        } else if (groupBy === 'type' && task.task_type_id !== null) {
            const type = lookups.typeById.get(task.task_type_id);
            group = groups.get(`type-${task.task_type_id}`) ?? {
                key: `type-${task.task_type_id}`,
                label: type?.name ?? t('task_fields.unknown_type'),
                color: type?.color,
                tasks: [],
                defaults:
                    type?.is_active === true
                        ? { task_type_id: task.task_type_id }
                        : {},
            };
        }

        if (group === null) {
            none.tasks.push(task);
            continue;
        }

        group.tasks.push(task);
        groups.set(group.key, group);
    }

    none.label =
        groupBy === 'assignee'
            ? t('task_fields.no_assignee')
            : groupBy === 'bank'
              ? t('task_fields.no_bank')
              : t('task_fields.no_type');

    const result = [...groups.values()].sort((a, b) =>
        a.label.localeCompare(b.label, 'es'),
    );

    if (none.tasks.length > 0 || result.length === 0) {
        result.push(none);
    }

    return result;
}
