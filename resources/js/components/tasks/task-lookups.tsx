import { createContext, useContext } from 'react';
import type { ReactNode } from 'react';
import type {
    Project,
    ProjectTasksPageProps,
    TaskAssignee,
    TaskBankOption,
    TaskStatus,
    TaskType,
} from '@/types';

/**
 * Datos de referencia de la pestaña Tareas (estados, tipos, bolsas, personas y permisos) para
 * que la lista, el kanban y el panel no tengan que pasárselos de mano en mano.
 */
export type TaskLookups = {
    project: Project;
    usesBanks: boolean;
    statuses: TaskStatus[];
    statusById: Map<number, TaskStatus>;
    types: TaskType[];
    typeById: Map<number, TaskType>;
    banks: TaskBankOption[];
    bankById: Map<number, TaskBankOption>;
    users: TaskAssignee[];
    userById: Map<number, TaskAssignee>;
    currentUser: { id: number; department_id: number | null };
    can: { create: boolean; update: boolean };
    maxAttachmentMb: number;
};

const TaskLookupsContext = createContext<TaskLookups | null>(null);

export function buildTaskLookups(
    props: Pick<
        ProjectTasksPageProps,
        | 'project'
        | 'statuses'
        | 'types'
        | 'banks'
        | 'users'
        | 'currentUser'
        | 'can'
        | 'maxAttachmentMb'
    >,
): TaskLookups {
    return {
        project: props.project,
        usesBanks: props.project.billing_type === 'hour_bank',
        statuses: props.statuses,
        statusById: new Map(
            props.statuses.map((status) => [status.id, status]),
        ),
        types: props.types,
        typeById: new Map(props.types.map((type) => [type.id, type])),
        banks: props.banks,
        bankById: new Map(props.banks.map((bank) => [bank.id, bank])),
        users: props.users,
        userById: new Map(props.users.map((user) => [user.id, user])),
        currentUser: props.currentUser,
        can: props.can,
        maxAttachmentMb: props.maxAttachmentMb,
    };
}

export function TaskLookupsProvider({
    value,
    children,
}: {
    value: TaskLookups;
    children: ReactNode;
}) {
    return (
        <TaskLookupsContext.Provider value={value}>
            {children}
        </TaskLookupsContext.Provider>
    );
}

export function useTaskLookups(): TaskLookups {
    const lookups = useContext(TaskLookupsContext);

    if (!lookups) {
        throw new Error(
            'useTaskLookups debe usarse dentro de TaskLookupsProvider',
        );
    }

    return lookups;
}

/**
 * Bolsa por defecto para una tarea nueva (SPEC §8.3): la primera abierta del departamento del
 * usuario; si no hay, la primera abierta. El servidor ya las envía en ese orden.
 */
export function defaultBankId(
    banks: TaskBankOption[],
    departmentId: number | null,
): number | null {
    const open = banks.filter((bank) => bank.is_open);

    return (
        open.find(
            (bank) =>
                departmentId !== null && bank.department_id === departmentId,
        )?.id ??
        open[0]?.id ??
        null
    );
}

export function isDoneStatus(
    statusById: Map<number, TaskStatus>,
    statusId: number,
): boolean {
    return statusById.get(statusId)?.category === 'done';
}
