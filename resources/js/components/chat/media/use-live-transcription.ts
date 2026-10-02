import { useEffect, useState } from 'react';
import type {
    AudioMessageData,
    ChatAudio,
    ChatTranscription,
    TranscriptionUpdate,
} from '@/components/chat/media/types';
import {
    acquireChannel,
    releaseChannel,
} from '@/hooks/use-realtime-connection';
import { realtimeEnabled } from '@/lib/realtime';
import { transcriptions as transcriptionsRoute } from '@/routes/chat/media';

/**
 * Transcripción que se actualiza sola (SPEC §12: «Transcribiendo…» → texto):
 * - con tiempo real, al llegar el evento AUDIO_TRANSCRIBED_EVENT por el canal de la conversación
 *   (lo emite C2 a partir de App\Events\Chat\AudioTranscribed) se pide el estado a la ruta de C3
 *   (el evento solo lleva ids: {conversation_id, message_id, transcription_id, status}; el texto de
 *   un audio largo no cabe en un aviso y la ruta comprueba los permisos) y, por si se pierde, una
 *   consulta cada minuto;
 * - sin tiempo real (prop `realtime` null), una consulta ligera cada 15 s: UNA petición para todos
 *   los audios pendientes a la vista (GET /chat/transcripciones?mensajes=…), que se salta si la
 *   pestaña no está visible.
 */

/** Nombre del evento en Echo (broadcastAs 'audio.transcribed'). */
export const AUDIO_TRANSCRIBED_EVENT = '.audio.transcribed';

export const POLL_MS = 15_000;

export const POLL_MS_WITH_REALTIME = 60_000;

/** null: la consulta ya no devuelve ese mensaje (borrado, oculto o ya no se ve): se deja de vigilar. */
type Listener = (update: TranscriptionUpdate | null) => void;

const listeners = new Map<number, Set<Listener>>();
let timer: ReturnType<typeof setTimeout> | null = null;
let interval = POLL_MS;

/** Pide el estado de esos mensajes (y URLs nuevas de sus audios). */
export async function fetchTranscriptionUpdates(
    ids: number[],
): Promise<TranscriptionUpdate[]> {
    if (ids.length === 0) {
        return [];
    }

    const response = await fetch(
        transcriptionsRoute.url({ query: { mensajes: ids.join(',') } }),
        {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        },
    );

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    const data = (await response.json()) as {
        messages?: TranscriptionUpdate[];
    };

    return Array.isArray(data.messages) ? data.messages : [];
}

function notify(id: number, update: TranscriptionUpdate | null): void {
    for (const listener of listeners.get(id) ?? []) {
        listener(update);
    }
}

async function poll(): Promise<void> {
    timer = null;
    const ids = [...listeners.keys()].slice(0, 50);

    if (ids.length > 0 && document.visibilityState !== 'hidden') {
        try {
            const updates = await fetchTranscriptionUpdates(ids);
            const found = new Map(updates.map((update) => [update.id, update]));

            for (const id of ids) {
                notify(id, found.get(id) ?? null);
            }
        } catch {
            // Sin conexión o error puntual: se vuelve a intentar en la siguiente vuelta.
        }
    }

    schedule();
}

function schedule(): void {
    if (timer === null && listeners.size > 0) {
        timer = setTimeout(() => void poll(), interval);
    }
}

function onVisible(): void {
    if (document.visibilityState === 'visible' && listeners.size > 0) {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }

        void poll();
    }
}

/**
 * Vigila la transcripción de un mensaje; devuelve la función para dejar de vigilarla. Todas las
 * vigilancias comparten una sola consulta periódica.
 */
export function watchTranscription(
    messageId: number,
    listener: Listener,
    everyMs = POLL_MS,
): () => void {
    if (listeners.size === 0 && typeof document !== 'undefined') {
        document.addEventListener('visibilitychange', onVisible);
    }

    interval = Math.min(everyMs, listeners.size === 0 ? everyMs : interval);
    const set = listeners.get(messageId) ?? new Set<Listener>();
    set.add(listener);
    listeners.set(messageId, set);
    schedule();

    return () => {
        set.delete(listener);

        if (set.size === 0) {
            listeners.delete(messageId);
        }

        if (listeners.size === 0) {
            if (timer !== null) {
                clearTimeout(timer);
                timer = null;
            }

            interval = POLL_MS;
            document.removeEventListener('visibilitychange', onVisible);
        }
    };
}

/**
 * ¿El evento de tiempo real (solo ids) es de este mensaje? Carga de C2:
 * {conversation_id, message_id, transcription_id, status}.
 */
export function isTranscriptionEventFor(
    payload: unknown,
    messageId: number,
): boolean {
    if (payload === null || typeof payload !== 'object') {
        return false;
    }

    return (
        Number((payload as { message_id?: unknown }).message_id) === messageId
    );
}

/**
 * Audio y transcripción de un mensaje, al día. `refreshAudio` pide una URL nueva del audio (la
 * firmada caduca a la hora) y la devuelve.
 */
export function useLiveTranscription(message: AudioMessageData): {
    audio: ChatAudio | null;
    transcription: ChatTranscription | null;
    refreshAudio: () => Promise<string | null>;
} {
    const [state, setState] = useState({
        audio: message.audio,
        transcription: message.transcription,
        gone: false,
    });
    const [previous, setPrevious] = useState(message);

    // Si el mensaje llega de nuevo desde fuera (recarga de C1), manda lo que trae.
    if (
        previous.audio !== message.audio ||
        previous.transcription !== message.transcription
    ) {
        setPrevious(message);
        setState({
            audio: message.audio,
            transcription: message.transcription,
            gone: false,
        });
    }

    const pending =
        !state.gone &&
        state.audio !== null &&
        (state.transcription === null || state.transcription.status !== 'done');
    const realtime = realtimeEnabled();

    useEffect(() => {
        if (!pending) {
            return;
        }

        return watchTranscription(
            message.id,
            (update) =>
                setState((current) =>
                    update === null
                        ? { ...current, gone: true }
                        : {
                              audio: update.audio ?? current.audio,
                              transcription: update.transcription,
                              gone: false,
                          },
                ),
            realtime ? POLL_MS_WITH_REALTIME : POLL_MS,
        );
    }, [message.id, pending, realtime]);

    useEffect(() => {
        if (!pending || !realtime) {
            return;
        }

        // El mismo canal que usa la conversación (C2 cuenta las suscripciones y lo deja al final).
        const name = `conversation.${message.conversation_id}`;
        const channel = acquireChannel(name, 'private');

        if (!channel) {
            return;
        }

        let cancelled = false;
        const onTranscribed = (payload: unknown) => {
            if (!isTranscriptionEventFor(payload, message.id)) {
                return;
            }

            fetchTranscriptionUpdates([message.id])
                .then(([update]) => {
                    if (cancelled) {
                        return;
                    }

                    setState((current) =>
                        update === undefined
                            ? { ...current, gone: true }
                            : {
                                  audio: update.audio ?? current.audio,
                                  transcription: update.transcription,
                                  gone: false,
                              },
                    );
                })
                .catch(() => {
                    // La consulta periódica lo recogerá.
                });
        };

        channel.listen(AUDIO_TRANSCRIBED_EVENT, onTranscribed);

        return () => {
            cancelled = true;
            channel.stopListening(AUDIO_TRANSCRIBED_EVENT, onTranscribed);
            releaseChannel(name, 'private');
        };
    }, [message.id, message.conversation_id, pending, realtime]);

    return {
        audio: state.audio,
        transcription: state.transcription,
        refreshAudio: async () => {
            const [update] = await fetchTranscriptionUpdates([message.id]);

            if (!update?.audio) {
                return null;
            }

            setState({
                audio: update.audio,
                transcription: update.transcription,
                gone: false,
            });

            return update.audio.url;
        },
    };
}
