import { useRef, useState } from 'react';
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
 * 3. guardar recarga solo las props del Gantt (`reload`); si falla, la barra vuelve y se avisa;
 * 4. si otra visita de Inertia interrumpe el guardado (otro guardado, los filtros, la escala…), la
 *    tarea deja de estar «guardando» al momento y conserva las fechas nuevas solo hasta que vuelven
 *    a llegar las tareas del servidor (la petición ya había salido: no se sabe si se aplicó; ver
 *    refreshAfterInterruption).
 * Nunca se desplazan sucesoras sin confirmarlo (SPEC §6.1).
 */
export function useGanttEditing({
    tasks,
    reload,
    onRevert,
}: {
    tasks: ReadonlyArray<GanttTask>;
    reload: string[];
    /** La tarea vuelve a sus fechas del servidor (falló, o se interrumpió y no se aplicó). */
    onRevert?: (taskId: number) => void;
}) {
    const [overrides, setOverrides] = useState<ReadonlyMap<number, GanttDates>>(
        () => new Map(),
    );
    const [saving, setSaving] = useState<ReadonlySet<number>>(() => new Set());
    const [conflict, setConflict] = useState<RescheduleConflict | null>(null);
    const [resolving, setResolving] = useState<ConflictChoice | null>(null);
    // Intentos de cada tarea: tras una interrupción, solo se quitan sus fechas optimistas si
    // mientras tanto no se ha vuelto a mover.
    const attempts = useRef(new Map<number, number>());

    const effectiveTasks =
        overrides.size === 0
            ? tasks
            : tasks.map((task) => {
                  const dates = overrides.get(task.id);

                  return dates ? { ...task, ...dates } : task;
              });

    const stopSaving = (taskId: number) => {
        setSaving((previous) => {
            if (!previous.has(taskId)) {
                return previous;
            }

            const next = new Set(previous);
            next.delete(taskId);

            return next;
        });
    };

    const dropOverride = (taskId: number) => {
        setOverrides((previous) => {
            if (!previous.has(taskId)) {
                return previous;
            }

            const next = new Map(previous);
            next.delete(taskId);

            return next;
        });
    };

    const release = (taskId: number) => {
        dropOverride(taskId);
        stopSaving(taskId);
    };

    const fail = (taskId: number, message: string) => {
        release(taskId);
        onRevert?.(taskId);
        toast.error(message);
    };

    /**
     * Guarda las fechas. Pase lo que pase (bien, mal o interrumpido por otra visita), la tarea deja
     * de estar «guardando»: nunca se queda bloqueada con el indicador de carga.
     */
    const save = (
        task: GanttTask,
        dates: GanttDates,
        shift: boolean,
        onFinish?: () => void,
    ) => {
        let settled = false;
        const attempt = attempts.current.get(task.id);

        saveReschedule(task.id, dates, shift, reload, {
            onSuccess: () => {
                settled = true;
                release(task.id);
            },
            onFailure: (message) => {
                settled = true;
                fail(task.id, message);
            },
            onCancel: () => {
                // Deja de estar ocupada ya; las fechas nuevas se ven hasta que vuelvan a llegar
                // las del servidor (onRefreshed), para que no salte atrás y adelante.
                settled = true;
                stopSaving(task.id);
            },
            onRefreshed: () => {
                if (attempts.current.get(task.id) === attempt) {
                    dropOverride(task.id);
                    onRevert?.(task.id);
                }
            },
            onFinish: () => {
                if (!settled) {
                    release(task.id);
                }

                onFinish?.();
            },
        });
    };

    const reschedule = async (task: GanttTask, dates: GanttDates) => {
        if (saving.has(task.id) || sameDates(task, dates)) {
            return;
        }

        attempts.current.set(task.id, (attempts.current.get(task.id) ?? 0) + 1);
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
        save(task, dates, choice === 'shift', () => {
            setConflict(null);
            setResolving(null);
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
