import { useEffect, useState } from 'react';
import { tasks as tasksRoute } from '@/routes/time';
import type { LoggableTask, LoggableTasksResponse } from '@/types';

export const TASK_SEARCH_DEBOUNCE_MS = 200;

type Status = 'idle' | 'loading' | 'success' | 'error';

type Completed = {
    key: string;
    status: 'success' | 'error';
    tasks: LoggableTask[];
};

async function fetchTasks(
    query: string,
    userId: number | undefined,
    signal: AbortSignal,
    projectId?: number,
): Promise<LoggableTask[]> {
    const params: Record<string, string | number> = {};

    if (projectId !== undefined) {
        params.project_id = projectId;
    }

    if (query !== '') {
        params.q = query;
    }

    if (userId !== undefined) {
        params.user_id = userId;
    }

    const response = await fetch(tasksRoute.url({ query: params }), {
        method: 'GET',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        signal,
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    const data = (await response.json()) as Partial<LoggableTasksResponse>;

    return Array.isArray(data.tasks) ? data.tasks : [];
}

/**
 * Tareas donde se puede imputar (GET /horas/tareas?q=&user_id=): con debounce, cancelando la
 * petición anterior y solo mientras el buscador está abierto. Sin texto, las sugeridas.
 */
export function useLoggableTasks(
    rawQuery: string,
    options: { userId?: number; enabled: boolean; projectId?: number },
): { status: Status; tasks: LoggableTask[] } {
    const query = rawQuery.trim();
    const key = `${options.userId ?? ''}|${options.projectId ?? ''}|${query}`;
    const [completed, setCompleted] = useState<Completed | null>(null);

    useEffect(() => {
        if (!options.enabled) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetchTasks(
                query,
                options.userId,
                controller.signal,
                options.projectId,
            )
                .then((tasks) =>
                    setCompleted({ key, status: 'success', tasks }),
                )
                .catch(() => {
                    if (!controller.signal.aborted) {
                        setCompleted({ key, status: 'error', tasks: [] });
                    }
                });
        }, TASK_SEARCH_DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [key, query, options.userId, options.enabled, options.projectId]);

    if (!options.enabled) {
        return { status: 'idle', tasks: [] };
    }

    if (!completed || completed.key !== key) {
        return { status: 'loading', tasks: completed?.tasks ?? [] };
    }

    return { status: completed.status, tasks: completed.tasks };
}

/** Agrupa por proyecto conservando el orden en que llegan. */
export function groupByProject(
    tasks: LoggableTask[],
): { project: LoggableTask['project']; tasks: LoggableTask[] }[] {
    const groups = new Map<
        number,
        { project: LoggableTask['project']; tasks: LoggableTask[] }
    >();

    for (const task of tasks) {
        const group = groups.get(task.project.id) ?? {
            project: task.project,
            tasks: [],
        };
        group.tasks.push(task);
        groups.set(task.project.id, group);
    }

    return [...groups.values()];
}
