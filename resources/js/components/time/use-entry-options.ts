import { useEffect, useState } from 'react';
import { options as optionsRoute } from '@/routes/time';
import type { TimeEntryOptionsResponse } from '@/types';

type Loaded = { key: string; data: TimeEntryOptionsResponse | null };

/**
 * Opciones del diálogo de imputación (GET /horas/opciones?project_id=): para quién puede imputar
 * y los ajustes (hoy en Madrid, fechas futuras, descripción obligatoria). null mientras carga o si
 * falla: el diálogo sigue funcionando y el servidor valida igualmente.
 */
export function useEntryOptions(
    projectId: number | null,
    enabled: boolean,
): TimeEntryOptionsResponse | null {
    const key = String(projectId ?? '');
    const [loaded, setLoaded] = useState<Loaded | null>(null);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const controller = new AbortController();
        const url = optionsRoute.url({
            query: projectId !== null ? { project_id: projectId } : {},
        });

        fetch(url, {
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

                return (await response.json()) as TimeEntryOptionsResponse;
            })
            .then((data) => setLoaded({ key, data }))
            .catch(() => {
                if (!controller.signal.aborted) {
                    setLoaded({ key, data: null });
                }
            });

        return () => controller.abort();
    }, [key, projectId, enabled]);

    // Mientras llegan las de otro proyecto, se mantienen las anteriores (ajustes y personas).
    return loaded?.data ?? null;
}
