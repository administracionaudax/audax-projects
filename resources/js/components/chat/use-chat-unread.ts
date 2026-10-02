import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { chatApi } from '@/components/chat/chat-api';
import { useUnreadCounter } from '@/components/chat/realtime-bridge';

/**
 * Total sin leer del chat para la entrada Chat de la navegación (conversaciones no silenciadas):
 * - llega en las props compartidas en cada página (HandleInertiaRequests),
 * - el chat lo publica al leer, silenciar o recibir mensajes (evento de ventana),
 * - sin tiempo real se consulta cada minuto con la pestaña visible; con él, manda el contador en
 *   vivo de C2 (useUnreadCounter).
 */

export const UNREAD_EVENT = 'audax:chat-unread';

export const UNREAD_POLL_MS = 60_000;

/** El chat avisa del nuevo total (p. ej. tras marcar como leído). */
export function publishUnreadTotal(total: number): void {
    if (typeof window !== 'undefined') {
        window.dispatchEvent(
            new CustomEvent<number>(UNREAD_EVENT, { detail: total }),
        );
    }
}

export function useChatUnreadTotal(): number {
    const shared = usePage().props.chat?.unread ?? 0;
    const [total, setTotal] = useState(shared);
    const [lastShared, setLastShared] = useState(shared);
    const counter = useUnreadCounter();
    const live = counter.ready ? counter.total : null;

    // Cada navegación trae el total actualizado en las props compartidas.
    if (shared !== lastShared) {
        setLastShared(shared);
        setTotal(shared);
    }

    useEffect(() => {
        const onUnread = (event: Event) => {
            const detail = (event as CustomEvent<number>).detail;

            if (typeof detail === 'number') {
                setTotal(detail);
            }
        };

        window.addEventListener(UNREAD_EVENT, onUnread);

        return () => window.removeEventListener(UNREAD_EVENT, onUnread);
    }, []);

    useEffect(() => {
        if (live !== null) {
            return;
        }

        const controller = new AbortController();
        const id = window.setInterval(() => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            chatApi
                .unread(controller.signal)
                .then((data) => setTotal(data.total))
                .catch(() => {
                    // Sin conexión: se queda el último total conocido.
                });
        }, UNREAD_POLL_MS);

        return () => {
            window.clearInterval(id);
            controller.abort();
        };
    }, [live]);

    return live ?? total;
}
