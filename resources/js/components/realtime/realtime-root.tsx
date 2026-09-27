import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { syncPushSubscription } from '@/components/realtime/push';
import { startPresence } from '@/hooks/use-presence';

/**
 * Lo que el tiempo real necesita en toda la app (va una vez en el layout interno):
 * - la presencia de quien tiene la sesión (canal «online» o latidos sin Reverb),
 * - la reactivación silenciosa de los avisos del navegador si esta persona los activó aquí.
 * No pinta nada.
 */
export function RealtimeRoot() {
    const userId = usePage().props.auth?.user?.id ?? null;

    useEffect(() => {
        if (userId === null) {
            return;
        }

        return startPresence({ id: userId });
    }, [userId]);

    useEffect(() => {
        if (userId === null) {
            return;
        }

        syncPushSubscription(userId).catch(() => undefined);
    }, [userId]);

    return null;
}
