import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import {
    acquireChannel,
    onRealtimeReconnect,
    releaseChannel,
} from '@/hooks/use-realtime-connection';
import { realtimeEnabled } from '@/lib/realtime';

/** Espera para juntar varios cambios seguidos (varios envíos a la vez) en una sola recarga. */
export const WEEKLY_LIVE_DEBOUNCE_MS = 400;

export type WeeklyChangedEvent = {
    cycle_id: number | null;
    reason: 'submission' | 'exemption' | 'cycle' | 'report' | 'satisfaction';
};

/**
 * Tiempo real de la Weekly (10.9b, D-229; WeeklySync escuchaba sus tablas con Supabase Realtime,
 * `ws:App.tsx:922-980`): con `weekly.changed` del canal privado `weeklies` (o al recuperar la
 * conexión), la página vuelve a pedir sus datos, juntando los cambios seguidos. Así el resumen de
 * quien gestiona, la tira del equipo, «Hay nuevos reportes», los reportes originales y el aviso de
 * cierre cambian solos cuando alguien envía. Con `cycleId`, solo los cambios de esa semana (o de
 * ninguna en concreto). Sin Reverb, la página se pone al día al volver a ella (D-184).
 */
export function useWeeklyLive({
    cycleId = null,
    only,
}: {
    cycleId?: number | null;
    /** Props que se recargan; sin ellas, todas. */
    only?: string[];
} = {}) {
    const onlyRef = useRef(only);

    useEffect(() => {
        onlyRef.current = only;
    });

    useEffect(() => {
        if (!realtimeEnabled()) {
            return;
        }

        const channel = acquireChannel('weeklies', 'private');

        if (!channel) {
            return;
        }

        let timer: ReturnType<typeof setTimeout> | null = null;
        const reload = () => {
            if (timer !== null) {
                clearTimeout(timer);
            }

            timer = setTimeout(() => {
                timer = null;
                router.reload(onlyRef.current ? { only: onlyRef.current } : {});
            }, WEEKLY_LIVE_DEBOUNCE_MS);
        };
        const handler = (event: WeeklyChangedEvent) => {
            if (
                cycleId === null ||
                event.cycle_id === null ||
                event.cycle_id === cycleId
            ) {
                reload();
            }
        };

        channel.listen('.weekly.changed', handler);
        const offReconnect = onRealtimeReconnect(reload);

        return () => {
            if (timer !== null) {
                clearTimeout(timer);
            }

            channel.stopListening('.weekly.changed', handler);
            offReconnect();
            releaseChannel('weeklies', 'private');
        };
    }, [cycleId]);
}
