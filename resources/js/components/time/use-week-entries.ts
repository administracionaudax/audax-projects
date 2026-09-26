import { useEffect, useState } from 'react';
import { entries as entriesRoute } from '@/routes/time/approvals';
import type { PendingWeekEntriesResponse, TimeEntry } from '@/types';

type Result = { entries: TimeEntry[] } | { error: true } | null;

export type WeekEntries = {
    status: 'idle' | 'loading' | 'ready' | 'error';
    entries: TimeEntry[];
    /** Vuelve a pedirlas tras un error. */
    retry: () => void;
};

/**
 * Entradas de una semana pendiente de aprobar (GET /horas/aprobaciones/{period}/entradas). Se
 * piden la primera vez que se despliega el detalle y se guardan mientras la tarjeta siga en la
 * página: la carga inicial de /horas/aprobaciones solo lleva los totales (PERF-01). Si se pliega
 * mientras cargan, la petición se cancela y se repite al volver a desplegarlo.
 */
export function useWeekEntries(
    periodId: number | null,
    enabled: boolean,
): WeekEntries {
    const [result, setResult] = useState<Result>(null);
    const needed = enabled && periodId !== null && result === null;

    useEffect(() => {
        if (!needed || periodId === null) {
            return;
        }

        const controller = new AbortController();

        fetch(entriesRoute.url(periodId), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                return (await response.json()) as PendingWeekEntriesResponse;
            })
            .then((data) => setResult({ entries: data.entries }))
            .catch(() => {
                if (!controller.signal.aborted) {
                    setResult({ error: true });
                }
            });

        return () => controller.abort();
    }, [needed, periodId]);

    const status =
        result === null
            ? needed
                ? 'loading'
                : 'idle'
            : 'error' in result
              ? 'error'
              : 'ready';

    return {
        status,
        entries: result !== null && 'entries' in result ? result.entries : [],
        retry: () => setResult(null),
    };
}
