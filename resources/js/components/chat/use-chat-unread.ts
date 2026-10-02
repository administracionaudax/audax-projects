import { usePage } from '@inertiajs/react';
import { useUnreadCounter } from '@/components/chat/realtime-bridge';

/**
 * Total sin leer del chat para la entrada Chat de la navegación (conversaciones no silenciadas):
 * - al pintar la página, el de las props compartidas (HandleInertiaRequests),
 * - en cuanto llega el primer recuento, el contador de C2 (useUnreadCounter), que va en vivo con
 *   tiempo real y, sin él, se consulta cada 30 s con la pestaña visible. El chat lo pone a cero
 *   al leer (markRead) y lo vuelve a pedir al silenciar (refresh).
 */
export function useChatUnreadTotal(): number {
    const shared = usePage().props.chat?.unread ?? 0;
    const counter = useUnreadCounter();

    return counter.ready ? counter.total : shared;
}
