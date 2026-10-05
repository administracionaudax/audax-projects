import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import {
    acquireChannel,
    onRealtimeReconnect,
    releaseChannel,
} from '@/hooks/use-realtime-connection';
import { realtimeEnabled } from '@/lib/realtime';

/** Espera para juntar varios cambios seguidos en una sola recarga (WeeklySync: 180 ms). */
export const HELP_LIVE_DEBOUNCE_MS = 250;

export type HelpChangedEvent = {
    scope: 'help' | 'suggestions';
    post_id: number | null;
};

/**
 * Tiempo real del centro de ayuda y las sugerencias (F-170): con el evento `help.changed` del canal
 * privado `help` (o al recuperar la conexión), recarga solo las props de la pestaña abierta, sin
 * perder lo que se está escribiendo. Sin Reverb, la página se pone al día al volver a ella (D-184).
 */
export function useHelpLive(scope: 'help' | 'suggestions', only: string[]) {
    const onlyRef = useRef(only);

    useEffect(() => {
        onlyRef.current = only;
    });

    useEffect(() => {
        if (!realtimeEnabled()) {
            return;
        }

        const channel = acquireChannel('help', 'private');

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
                router.reload({ only: onlyRef.current });
            }, HELP_LIVE_DEBOUNCE_MS);
        };
        const handler = (event: HelpChangedEvent) => {
            if (event.scope === scope) {
                reload();
            }
        };

        channel.listen('.help.changed', handler);
        const offReconnect = onRealtimeReconnect(reload);

        return () => {
            if (timer !== null) {
                clearTimeout(timer);
            }

            channel.stopListening('.help.changed', handler);
            offReconnect();
            releaseChannel('help', 'private');
        };
    }, [scope]);
}
