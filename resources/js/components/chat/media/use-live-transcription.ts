import { echo, echoIsConfigured } from '@laravel/echo-react';
import { useEffect, useState } from 'react';
import type {
    AudioMessageData,
    ChatAudio,
    ChatTranscription,
    TranscriptionUpdate,
} from '@/components/chat/media/types';
import { realtimeEnabled } from '@/lib/realtime';
import { transcriptions as transcriptionsRoute } from '@/routes/chat/media';

/**
 * Transcripción que se actualiza sola (SPEC §12: «Transcribiendo…» → texto):
 * - con tiempo real, al llegar el evento AUDIO_TRANSCRIBED_EVENT por el canal de la conversación
 *   (lo emite C2 a partir de App\Events\Chat\AudioTranscribed) y, por si se pierde, una consulta
 *   cada minuto;
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
 * Carga útil del evento de tiempo real: {message_id, transcription, audio?}. Se admiten también
 * {transcription: {message_id, …}} por si el emisor la anida.
 */
export function transcriptionFromEvent(
    payload: unknown,
    messageId: number,
): { transcription: ChatTranscription; audio: ChatAudio | null } | null {
    if (payload === null || typeof payload !== 'object') {
        return null;
    }

    const data = payload as {
        message_id?: unknown;
        transcription?: unknown;
        audio?: unknown;
    };
    const transcription =
        data.transcription !== null && typeof data.transcription === 'object'
            ? (data.transcription as ChatTranscription & {
                  message_id?: unknown;
              })
            : null;
    const id = Number(data.message_id ?? transcription?.message_id);

    if (transcription === null || id !== messageId) {
        return null;
    }

    return {
        transcription,
        audio:
            data.audio !== null && typeof data.audio === 'object'
                ? (data.audio as ChatAudio)
                : null,
    };
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
        if (!pending || !realtime || !echoIsConfigured()) {
            return;
        }

        const channel = echo().private(
            `conversation.${message.conversation_id}`,
        );
        const onTranscribed = (payload: unknown) => {
            const update = transcriptionFromEvent(payload, message.id);

            if (update) {
                setState((current) => ({
                    audio: update.audio ?? current.audio,
                    transcription: update.transcription,
                    gone: false,
                }));
            }
        };

        channel.listen(AUDIO_TRANSCRIBED_EVENT, onTranscribed);

        return () => {
            channel.stopListening(AUDIO_TRANSCRIBED_EVENT, onTranscribed);
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
