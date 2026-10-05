import type {
    MySpaceTask,
    MySpaceTaskProject,
    TaskSuggestion,
} from '@/types/weeklies';

/**
 * Piezas puras de la pestaña «Tareas» de «Mi espacio» (F-055 a F-063, TaskView de WeeklySync):
 * filtros, agrupación por cliente, proyectos de un cliente y el borrador de una tarea sugerida.
 */

/** Todas, Pendientes o Completadas (F-056), como el original. */
export type TaskStatusFilter = 'all' | 'todo' | 'done';

/** Mis tareas visibles: las archivadas o las que no lo están (F-057), y el filtro de estado. */
export function filterTasks(
    tasks: MySpaceTask[],
    filter: TaskStatusFilter,
    archived: boolean,
): MySpaceTask[] {
    return tasks.filter(
        (task) =>
            task.archived === archived &&
            (filter === 'all' ||
                (filter === 'done' ? task.completed : !task.completed)),
    );
}

export type TaskGroup = {
    /** `general` para las tareas sin cliente («Tareas generales»). */
    key: string;
    client: MySpaceTask['client'];
    tasks: MySpaceTask[];
};

/**
 * Agrupadas por cliente (F-055) en el orden en que aparecen (las tareas llegan de la más nueva a la
 * más antigua); las de proyectos sin cliente, juntas en «Tareas generales».
 */
export function groupByClient(tasks: MySpaceTask[]): TaskGroup[] {
    const groups = new Map<string, TaskGroup>();

    for (const task of tasks) {
        const key = task.client ? `client-${task.client.id}` : 'general';
        const group = groups.get(key) ?? {
            key,
            client: task.client,
            tasks: [],
        };
        group.tasks.push(task);
        groups.set(key, group);
    }

    return [...groups.values()];
}

/** Clientes del catálogo (por nombre), más «Sin cliente» al final si hay proyectos internos. */
export function catalogClients(
    projects: MySpaceTaskProject[],
): { id: number | null; name: string; icon: string | null }[] {
    const clients = new Map<
        number | null,
        { id: number | null; name: string; icon: string | null }
    >();

    for (const project of projects) {
        const id = project.client?.id ?? null;

        if (!clients.has(id)) {
            clients.set(id, {
                id,
                name: project.client?.name ?? '',
                icon: project.client?.icon ?? null,
            });
        }
    }

    return [...clients.values()].sort((a, b) =>
        a.id === null
            ? 1
            : b.id === null
              ? -1
              : a.name.localeCompare(b.name, 'es'),
    );
}

/** Los proyectos de un cliente (null: los internos, sin cliente). */
export function projectsOfClient(
    projects: MySpaceTaskProject[],
    clientId: number | null,
): MySpaceTaskProject[] {
    return projects.filter(
        (project) => (project.client?.id ?? null) === clientId,
    );
}

/**
 * La bolsa por defecto de un proyecto de bolsas (SPEC §8.3): la primera, que el servidor ya manda
 * con las del departamento de la persona delante.
 */
export function defaultBank(
    project: MySpaceTaskProject | undefined,
): number | null {
    return project?.uses_banks ? (project.banks[0]?.id ?? null) : null;
}

/** Lo que la persona revisa de una tarea sugerida antes de crearla (D-204). */
export type SuggestionDraft = {
    key: string;
    selected: boolean;
    title: string;
    clientId: number | null;
    projectId: number | null;
    bankId: number | null;
    priority: 'low' | 'normal' | 'high' | 'urgent';
    dueDate: string | null;
};

/** El borrador de una propuesta, con el proyecto y la bolsa sugeridos (si aún existen). */
export function suggestionDraft(
    item: TaskSuggestion,
    projects: MySpaceTaskProject[],
): SuggestionDraft {
    const project = projects.find((p) => p.id === item.project_id);
    const bankId =
        project?.uses_banks &&
        item.hour_bank_id !== null &&
        project.banks.some((bank) => bank.id === item.hour_bank_id)
            ? item.hour_bank_id
            : defaultBank(project);

    return {
        key: item.key,
        selected: true,
        title: item.title,
        clientId: item.client_id,
        projectId: project?.id ?? null,
        bankId,
        priority: 'normal',
        dueDate: null,
    };
}

/** Qué le falta a una propuesta para poder crearla: el título, el proyecto o la bolsa. */
export function draftProblems(
    draft: SuggestionDraft,
    projects: MySpaceTaskProject[],
): ('title' | 'project' | 'bank')[] {
    const problems: ('title' | 'project' | 'bank')[] = [];
    const project = projects.find((p) => p.id === draft.projectId);

    if (draft.title.trim() === '') {
        problems.push('title');
    }

    if (!project) {
        problems.push('project');
    } else if (project.uses_banks && draft.bankId === null) {
        problems.push('bank');
    }

    return problems;
}
