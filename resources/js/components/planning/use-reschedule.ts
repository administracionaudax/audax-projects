import { useState } from 'react';
import { toast } from 'sonner';
import {
    fetchReschedulePreview,
    RescheduleError,
    saveReschedule,
} from '@/components/planning/reschedule-requests';
import { t } from '@/lib/i18n';
import type { ShiftProposal } from '@/types/schedule';

export type RescheduleDates = { start_date: string | null; due_date: string };

export type RescheduleTarget = { id: number; title: string };

/** Movimiento en curso: la tarea, sus fechas nuevas y, si las hay, las sucesoras en conflicto. */
export type PendingReschedule = {
    task: RescheduleTarget;
    dates: RescheduleDates;
    proposals: ShiftProposal[];
};

/**
 * Flujo de reprogramar con propuesta (D-057), común al arrastre, al teclado y a «Asignar fecha»:
 * 1. pide la propuesta; 2. si no hay sucesoras en conflicto, guarda; 3. si las hay, deja el
 * movimiento en `pending` para que el diálogo pregunte: «Mover también las sucesoras»,
 * «Solo esta tarea» o «Cancelar». Nunca desplaza sucesoras sin confirmación.
 * `moving` son las fechas que se enseñan mientras tanto (se quitan al terminar o cancelar).
 */
export function useReschedule() {
    const [moving, setMoving] = useState<{
        taskId: number;
        dates: RescheduleDates;
    } | null>(null);
    const [pending, setPending] = useState<PendingReschedule | null>(null);
    const [saving, setSaving] = useState(false);

    const save = (
        task: RescheduleTarget,
        dates: RescheduleDates,
        shiftSuccessors: boolean,
    ) => {
        setSaving(true);
        saveReschedule(
            task.id,
            { ...dates, shift_successors: shiftSuccessors },
            {
                onFinish: () => {
                    setSaving(false);
                    setPending(null);
                    setMoving(null);
                },
            },
        );
    };

    const request = async (task: RescheduleTarget, dates: RescheduleDates) => {
        setMoving({ taskId: task.id, dates });

        try {
            const proposals = await fetchReschedulePreview(task.id, dates);

            if (proposals.length === 0) {
                save(task, dates, false);
            } else {
                setPending({ task, dates, proposals });
            }
        } catch (error) {
            setMoving(null);
            toast.error(
                error instanceof RescheduleError
                    ? error.message
                    : t('task_errors.generic'),
            );
        }
    };

    return {
        moving,
        pending,
        saving,
        request,
        /** Confirma el movimiento: con las sucesoras (true) o solo esta tarea (false). */
        confirm: (shiftSuccessors: boolean) => {
            if (pending) {
                save(pending.task, pending.dates, shiftSuccessors);
            }
        },
        cancel: () => {
            if (!saving) {
                setPending(null);
                setMoving(null);
            }
        },
    };
}
