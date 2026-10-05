import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    fetchDictation,
    uploadDictation,
    WeeklyRequestError,
} from '@/components/weeklies/weekly-api';
import {
    acquireChannel,
    onRealtimeReconnect,
    releaseChannel,
    useRealtimeStatus,
} from '@/hooks/use-realtime-connection';
import { realtimeEnabled } from '@/lib/realtime';
import type { Dictation, DictationUpdatedEvent } from '@/types/weeklies';

/** Sin tiempo real, cada cuánto se pregunta por el dictado. */
export const DICTATION_POLL_MS = 3_000;

/** Con tiempo real, el sondeo es solo de respaldo (por si se pierde el evento). */
export const DICTATION_LIVE_POLL_MS = 15_000;

/** Pasado este tiempo, se deja de esperar (el Job de Whisper tiene su propio límite). */
export const DICTATION_TIMEOUT_MS = 5 * 60_000;

export type DictationPhase = 'idle' | 'uploading' | 'transcribing';

/**
 * - no_speech / too_short: hecho, pero sin texto («no se ha oído nada», F-050 y F-171),
 * - failed: Whisper falló,
 * - upload: no se pudo subir (con el mensaje del servidor si lo hay),
 * - timeout: se ha cansado de esperar.
 */
export type DictationNotice =
    | 'no_speech'
    | 'too_short'
    | 'failed'
    | 'upload'
    | 'timeout';

export type DictationState = {
    phase: DictationPhase;
    notice: DictationNotice | null;
    /** Mensaje del servidor al subir (422), si lo hay. */
    message: string | null;
    send: (file: File, durationMs: number) => Promise<void>;
    dismiss: () => void;
};

/**
 * Dictado de un apunte de «Mi weekly» (F-049, D-158): sube el audio grabado, espera a que el
 * Whisper del servidor lo transcriba («Transcribiendo…») y entrega el texto con `onText`.
 *
 * Se entera de que ha terminado por el evento `dictation.updated` del canal privado de quien dicta
 * (App.Models.User.{id}); sin Reverb (o si se corta), pregunta a `dictations.show` cada 3 s.
 */
export function useDictation({
    cycleId,
    clientId,
    onText,
}: {
    cycleId: number;
    clientId: number | null;
    onText: (text: string) => void;
}): DictationState {
    const userId = usePage().props.auth?.user?.id ?? null;
    const live = useRealtimeStatus() === 'connected';
    const [phase, setPhase] = useState<DictationPhase>('idle');
    const [notice, setNotice] = useState<DictationNotice | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const [pendingId, setPendingId] = useState<number | null>(null);
    const deliver = useRef(onText);
    const mounted = useRef(true);
    const finished = useRef<number | null>(null);

    useEffect(() => {
        deliver.current = onText;
    });

    useEffect(() => {
        mounted.current = true;

        return () => {
            mounted.current = false;
        };
    }, []);

    const finish = useCallback((dictation: Dictation) => {
        if (!mounted.current || finished.current === dictation.id) {
            return;
        }

        finished.current = dictation.id;
        setPendingId(null);
        setPhase('idle');

        if (dictation.status === 'failed') {
            setNotice('failed');

            return;
        }

        const text = (dictation.text ?? '').trim();

        if (text === '') {
            setNotice(
                dictation.warning === 'too_short' ? 'too_short' : 'no_speech',
            );

            return;
        }

        setNotice(null);
        deliver.current(text);
    }, []);

    const check = useCallback(
        async (id: number) => {
            try {
                const dictation = await fetchDictation(id);

                if (
                    dictation.status === 'done' ||
                    dictation.status === 'failed'
                ) {
                    finish(dictation);
                }
            } catch {
                // Un fallo de red al sondear no corta la espera: se vuelve a intentar.
            }
        },
        [finish],
    );

    // El evento en vivo: en cuanto llega, se pide el dictado (el evento no lleva el texto).
    useEffect(() => {
        if (pendingId === null || userId === null || !realtimeEnabled()) {
            return;
        }

        const name = `App.Models.User.${userId}`;
        const channel = acquireChannel(name, 'private');

        if (!channel) {
            return;
        }

        const handler = (event: DictationUpdatedEvent) => {
            if (
                event.dictation_id === pendingId &&
                (event.status === 'done' || event.status === 'failed')
            ) {
                void check(pendingId);
            }
        };

        channel.listen('.dictation.updated', handler);
        const offReconnect = onRealtimeReconnect(() => void check(pendingId));

        return () => {
            channel.stopListening('.dictation.updated', handler);
            offReconnect();
            releaseChannel(name, 'private');
        };
    }, [pendingId, userId, check]);

    // El sondeo de respaldo y el límite de espera.
    useEffect(() => {
        if (pendingId === null) {
            return;
        }

        const interval = setInterval(
            () => void check(pendingId),
            live ? DICTATION_LIVE_POLL_MS : DICTATION_POLL_MS,
        );
        const timeout = setTimeout(() => {
            if (mounted.current) {
                setPendingId(null);
                setPhase('idle');
                setNotice('timeout');
            }
        }, DICTATION_TIMEOUT_MS);

        return () => {
            clearInterval(interval);
            clearTimeout(timeout);
        };
    }, [pendingId, live, check]);

    const send = async (file: File, durationMs: number) => {
        setNotice(null);
        setMessage(null);
        setPhase('uploading');

        try {
            const dictation = await uploadDictation({
                cycleId,
                clientId,
                file,
                durationMs,
            });

            if (!mounted.current) {
                return;
            }

            if (dictation.status === 'done' || dictation.status === 'failed') {
                finish(dictation);

                return;
            }

            setPhase('transcribing');
            setPendingId(dictation.id);
        } catch (caught) {
            if (!mounted.current) {
                return;
            }

            setPhase('idle');
            setNotice('upload');
            setMessage(
                caught instanceof WeeklyRequestError
                    ? caught.userMessage
                    : null,
            );
        }
    };

    return {
        phase,
        notice,
        message,
        send,
        dismiss: () => {
            setNotice(null);
            setMessage(null);
        },
    };
}
