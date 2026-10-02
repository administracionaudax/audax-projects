import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    acquireChannel,
    releaseChannel,
} from '@/hooks/use-realtime-connection';
import type { RealtimeChannel } from '@/hooks/use-realtime-connection';
import { realtimeEnabled } from '@/lib/realtime';

/**
 * «Escribiendo…» (SPEC §12): por whisper de Echo entre navegadores, sin pasar por el servidor.
 * Se avisa como mucho cada 3 s mientras se escribe y el indicador se apaga solo a los 6 s sin
 * noticias (o al enviar: stopTyping). Sin tiempo real no hace nada.
 */

export const TYPING_THROTTLE_MS = 3_000;
export const TYPING_TIMEOUT_MS = 6_000;

export type TypingUser = { id: number; name: string };

type TypingWhisper = {
    user_id?: unknown;
    name?: unknown;
    typing?: unknown;
};

export type Typing = {
    /** Quién está escribiendo ahora (sin ti), en orden de llegada. */
    typers: TypingUser[];
    /** Llámalo en cada pulsación del editor: ya se encarga de no repetir. */
    notifyTyping: () => void;
    /** Al enviar o vaciar el editor. */
    stopTyping: () => void;
    live: boolean;
};

export function useTyping(conversationId: number | null | undefined): Typing {
    const me = usePage().props.auth?.user ?? null;
    const myId = me?.id ?? null;
    const myName = me?.name ?? '';
    const [state, setState] = useState<{
        conversationId: number | null;
        typers: TypingUser[];
    }>({ conversationId: null, typers: [] });
    const channelRef = useRef<RealtimeChannel | null>(null);
    const lastSent = useRef(0);
    const live = realtimeEnabled();

    useEffect(() => {
        if (conversationId === null || conversationId === undefined || !live) {
            return;
        }

        const name = `conversation.${conversationId}`;
        const channel = acquireChannel(name, 'private');
        const timers = new Map<number, ReturnType<typeof setTimeout>>();

        const remove = (userId: number) => {
            const timer = timers.get(userId);

            if (timer !== undefined) {
                clearTimeout(timer);
                timers.delete(userId);
            }

            setState((current) =>
                current.conversationId === conversationId
                    ? {
                          conversationId,
                          typers: current.typers.filter(
                              (typer) => typer.id !== userId,
                          ),
                      }
                    : current,
            );
        };

        const onWhisper = (data: TypingWhisper) => {
            const userId = Number(data.user_id);

            if (!Number.isInteger(userId) || userId <= 0 || userId === myId) {
                return;
            }

            if (data.typing !== true) {
                remove(userId);

                return;
            }

            const typerName =
                typeof data.name === 'string' ? data.name.slice(0, 80) : '';
            const previous = timers.get(userId);

            if (previous !== undefined) {
                clearTimeout(previous);
            }

            timers.set(
                userId,
                setTimeout(() => remove(userId), TYPING_TIMEOUT_MS),
            );
            setState((current) => {
                const typers =
                    current.conversationId === conversationId
                        ? current.typers
                        : [];

                return typers.some((typer) => typer.id === userId)
                    ? { conversationId, typers }
                    : {
                          conversationId,
                          typers: [...typers, { id: userId, name: typerName }],
                      };
            });
        };

        channelRef.current = channel;
        lastSent.current = 0;
        channel?.listenForWhisper('typing', onWhisper);

        return () => {
            channel?.stopListeningForWhisper('typing', onWhisper);
            timers.forEach((timer) => clearTimeout(timer));
            timers.clear();
            channelRef.current = null;

            if (channel) {
                releaseChannel(name, 'private');
            }
        };
    }, [conversationId, live, myId]);

    const notifyTyping = useCallback(() => {
        const channel = channelRef.current;
        const now = Date.now();

        if (
            !channel ||
            myId === null ||
            now - lastSent.current < TYPING_THROTTLE_MS
        ) {
            return;
        }

        lastSent.current = now;
        channel.whisper('typing', {
            user_id: myId,
            name: myName,
            typing: true,
        });
    }, [myId, myName]);

    const stopTyping = useCallback(() => {
        const channel = channelRef.current;

        if (!channel || myId === null || lastSent.current === 0) {
            return;
        }

        lastSent.current = 0;
        channel.whisper('typing', {
            user_id: myId,
            name: myName,
            typing: false,
        });
    }, [myId, myName]);

    return {
        typers: state.conversationId === conversationId ? state.typers : [],
        notifyTyping,
        stopTyping,
        live,
    };
}
