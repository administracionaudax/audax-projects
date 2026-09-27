import { useState } from 'react';
import { toast } from 'sonner';
import { sameDates } from '@/components/gantt/geometry';
import {
    fetchReschedulePreview,
    GanttRequestError,
    linkTasks,
    saveReschedule,
    unlinkTasks,
} from '@/components/gantt/requests';
import type { GanttDates, GanttTask } from '@/components/gantt/types';
import { t } from '@/lib/i18n';
import type { ShiftProposal, TaskDependencyItem } from '@/types/schedule';

export type RescheduleConflict = {
    task: GanttTask;
    dates: GanttDates;
    proposals: ShiftProposal[];
};

export type ConflictChoice = 'shift' | 'only' | 'cancel';

type LinkCallbacks = {
    onSuccess?: () => void;
    /** Si se da, el error lo enseña quien llama (p. ej. el diálogo) en lugar de un aviso. */
    onFailure?: (message: string) => void;
    onFinish?: () => void;
};

/**
 * Edición desde el Gantt (D-057, D-060), común al Gantt de proyecto y al multiproyecto:
 * 1. al soltar una barra (o confirmar con el teclado) se ve ya en su sitio nuevo (optimista) y
 *    se pide la propuesta al servidor, que no cambia nada;
 * 2. si hay sucesoras en conflicto, `conflict` abre el diálogo con «Mover también las sucesoras»,
 *    «Solo esta tarea» o «Cancelar» (vuelve a su sitio); si no, se guarda directamente;
 * 3. guardar recarga solo las props del Gantt (`reload`); si falla, la barra vuelve y se avisa.
 * Nunca se desplazan sucesoras sin confirmarlo (SPEC §6.1).
 */
export function useGanttEditing({
    tasks,
    reload,
}: {
    tasks: ReadonlyArray<GanttTask>;
    reload: string[];
}) {
    const [overrides, setOverrides] = useState<ReadonlyMap<number, GanttDates>>(
        () => new Map(),
    );
    const [saving, setSaving] = useState<ReadonlySet<number>>(() => new Set());
    const [conflict, setConflict] = useState<RescheduleConflict | null>(null);
    const [resolving, setResolving] = useState<ConflictChoice | null>(null);

    const effectiveTasks =
        overrides.size === 0
            ? tasks
            : tasks.map((task) => {
                  const dates = overrides.get(task.id);

                  return dates ? { ...task, ...dates } : task;
              });

    const release = (taskId: number) => {
        setOverrides((previous) => {
            const next = new Map(previous);
            next.delete(taskId);

            return next;
        });
        setSaving((previous) => {
            const next = new Set(previous);
            next.delete(taskId);

            return next;
        });
    };

    const fail = (taskId: number, message: string) => {
        release(taskId);
        toast.error(message);
    };

    const save = (task: GanttTask, dates: GanttDates, shift: boolean) => {
        saveReschedule(task.id, dates, shift, reload, {
            onSuccess: () => release(task.id),
            onFailure: (message) => fail(task.id, message),
        });
    };

    const reschedule = async (task: GanttTask, dates: GanttDates) => {
        if (saving.has(task.id) || sameDates(task, dates)) {
            return;
        }

        setOverrides((previous) => new Map(previous).set(task.id, dates));
        setSaving((previous) => new Set(previous).add(task.id));

        let proposals: ShiftProposal[];

        try {
            proposals = await fetchReschedulePreview(task.id, dates);
        } catch (error) {
            fail(
                task.id,
                error instanceof GanttRequestError
                    ? error.message
                    : t('gantt.errors.generic'),
            );

            return;
        }

        if (proposals.length > 0) {
            setConflict({ task, dates, proposals });

            return;
        }

        save(task, dates, false);
    };

    const resolveConflict = (choice: ConflictChoice) => {
        if (!conflict || resolving) {
            return;
        }

        const { task, dates } = conflict;

        if (choice === 'cancel') {
            setConflict(null);
            release(task.id);

            return;
        }

        setResolving(choice);
        saveReschedule(task.id, dates, choice === 'shift', reload, {
            onSuccess: () => {
                setConflict(null);
                release(task.id);
            },
            onFailure: (message) => {
                setConflict(null);
                fail(task.id, message);
            },
            onFinish: () => setResolving(null),
        });
    };

    const link = (
        predecessor: GanttTask,
        successor: GanttTask,
        callbacks: LinkCallbacks = {},
    ) => {
        const failure = (message: string) =>
            callbacks.onFailure
                ? callbacks.onFailure(message)
                : toast.error(message);

        if (predecessor.id === successor.id) {
            failure(t('gantt.errors.self'));
            callbacks.onFinish?.();

            return;
        }

        if (predecessor.project_id !== successor.project_id) {
            failure(t('gantt.errors.other_project'));
            callbacks.onFinish?.();

            return;
        }

        linkTasks(
            predecessor.project_id,
            predecessor.id,
            successor.id,
            reload,
            {
                onSuccess: callbacks.onSuccess,
                onFailure: failure,
                onFinish: callbacks.onFinish,
            },
        );
    };

    const unlink = (dependency: TaskDependencyItem) => {
        unlinkTasks(dependency.id, reload, {
            onFailure: (message) => toast.error(message),
        });
    };

    return {
        tasks: effectiveTasks,
        saving,
        conflict,
        resolving,
        reschedule,
        resolveConflict,
        link,
        unlink,
    };
}
