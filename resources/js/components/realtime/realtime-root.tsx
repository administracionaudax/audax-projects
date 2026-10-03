import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { syncPushSubscription } from '@/components/realtime/push';
import { startPresence } from '@/hooks/use-presence';

/**
 * Lo que el tiempo real necesita en toda la app (va una vez en el layout interno):
 * - la presencia de quien tiene la sesión (canal «online» o latidos sin Reverb; un colaborador
 *   externo, siempre con latidos: el canal «online» es de la plantilla, D-134),
 * - la reactivación silenciosa de los avisos del navegador si esta persona los activó aquí.
 * No pinta nada.
 */
export function RealtimeRoot() {
    const user = usePage().props.auth?.user;
    const userId = user?.id ?? null;
    const restricted = user?.is_collaborator ?? false;

    useEffect(() => {
        if (userId === null) {
            return;
        }

        return startPresence({ id: userId, restricted });
    }, [userId, restricted]);

    useEffect(() => {
        if (userId === null) {
            return;
        }

        syncPushSubscription(userId).catch(() => undefined);
    }, [userId]);

    return null;
}
