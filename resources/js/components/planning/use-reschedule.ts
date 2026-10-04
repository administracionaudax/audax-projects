import { useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    fetchReschedulePreview,
    RescheduleError,
    saveReschedule,
} from '@/components/planning/reschedule-requests';
import { t } from '@/lib/i18n';
import type { ShiftProposal } from '@/types/schedule';

/** Fechas nuevas. Sin entrega solo en una tarea que solo tiene inicio (calendario del equipo). */
export type RescheduleDates = {
    start_date: string | null;
    due_date: string | null;
};

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
 *
 * Un solo movimiento a la vez: mientras hay uno en curso (propuesta pedida, diálogo abierto o
 * guardando), `request` no hace nada y devuelve false, así que un segundo arrastre no puede
 * cerrar ni sustituir el diálogo del primero. `busy` sirve para desactivar el arrastre y el
 * teclado; `isBusy()` lo dice al instante (dos soltados seguidos verían el estado anterior).
 */
export function useReschedule() {
    const [moving, setMoving] = useState<{
        taskId: number;
        dates: RescheduleDates;
    } | null>(null);
    const [pending, setPending] = useState<PendingReschedule | null>(null);
    const [saving, setSaving] = useState(false);
    const busyRef = useRef(false);

    const finish = () => {
        busyRef.current = false;
        setSaving(false);
        setPending(null);
        setMoving(null);
    };

    const save = (
        task: RescheduleTarget,
        dates: RescheduleDates,
        shiftSuccessors: boolean,
    ) => {
        setSaving(true);
        saveReschedule(
            task.id,
            { ...dates, shift_successors: shiftSuccessors },
            { onFinish: finish },
        );
    };

    const request = async (
        task: RescheduleTarget,
        dates: RescheduleDates,
    ): Promise<boolean> => {
        if (busyRef.current) {
            return false;
        }

        busyRef.current = true;
        setMoving({ taskId: task.id, dates });

        try {
            const proposals = await fetchReschedulePreview(task.id, dates);

            if (proposals.length === 0) {
                save(task, dates, false);
            } else {
                setPending({ task, dates, proposals });
            }
        } catch (error) {
            finish();
            toast.error(
                error instanceof RescheduleError
                    ? error.message
                    : t('task_errors.generic'),
            );
        }

        return true;
    };

    return {
        moving,
        pending,
        saving,
        /** Hay un movimiento en curso: no se admite otro hasta que termine. */
        busy: moving !== null || pending !== null || saving,
        isBusy: () => busyRef.current,
        request,
        /** Confirma el movimiento: con las sucesoras (true) o solo esta tarea (false). */
        confirm: (shiftSuccessors: boolean) => {
            if (pending && !saving) {
                save(pending.task, pending.dates, shiftSuccessors);
            }
        },
        cancel: () => {
            if (!saving) {
                finish();
            }
        },
    };
}
