import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    askAssistant,
    fetchAssistantQuestion,
    WeeklyRequestError,
} from '@/components/weeklies/weekly-api';
import {
    acquireChannel,
    onRealtimeReconnect,
    releaseChannel,
    useRealtimeStatus,
} from '@/hooks/use-realtime-connection';
import { t } from '@/lib/i18n';
import { realtimeEnabled } from '@/lib/realtime';
import type {
    AssistantAnsweredEvent,
    AssistantQuestion,
} from '@/types/weeklies';

/** Sin tiempo real, cada cuánto se pregunta por la respuesta. */
export const ASSISTANT_POLL_MS = 2_000;

/** Con tiempo real, el sondeo es solo de respaldo. */
export const ASSISTANT_LIVE_POLL_MS = 10_000;

/** Pasado este tiempo se deja de esperar (la cola `ai` puede estar ocupada con un informe). */
export const ASSISTANT_TIMEOUT_MS = 5 * 60_000;

/** Turnos de la conversación que se mandan con cada pregunta (el servidor usa los 6 últimos). */
export const ASSISTANT_HISTORY = 6;

export type AssistantMessage = {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    /** Una respuesta de error (no se manda como historia). */
    error?: boolean;
    at: string;
};

type Stored = { messages: AssistantMessage[]; pending: string | null };

/** Mientras se manda la pregunta (aún sin id). */
const SENDING = '__sending';

export function storageKey(userId: number | null): string {
    return `audax.assistant.${userId ?? 'anon'}`;
}

function load(key: string): Stored {
    try {
        const raw = window.sessionStorage.getItem(key);
        const parsed = raw ? (JSON.parse(raw) as Partial<Stored>) : null;

        return {
            messages: Array.isArray(parsed?.messages) ? parsed.messages : [],
            pending:
                typeof parsed?.pending === 'string' &&
                parsed.pending !== SENDING
                    ? parsed.pending
                    : null,
        };
    } catch {
        return { messages: [], pending: null };
    }
}

function save(key: string, value: Stored): void {
    try {
        window.sessionStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Sin almacenamiento (modo privado, cuota…): la conversación vive solo en la pestaña.
    }
}

let sequence = 0;

function message(
    role: AssistantMessage['role'],
    content: string,
    error = false,
): AssistantMessage {
    sequence += 1;

    return {
        id: `${Date.now()}-${sequence}`,
        role,
        content,
        error: error || undefined,
        at: new Date().toISOString(),
    };
}

/** Los últimos turnos (sin los errores), para que la IA entienda «¿y la semana pasada?». */
export function historyFor(
    messages: AssistantMessage[],
): { role: 'user' | 'assistant'; content: string }[] {
    return messages
        .filter((item) => !item.error)
        .slice(-ASSISTANT_HISTORY)
        .map((item) => ({ role: item.role, content: item.content }));
}

/**
 * La conversación con el asistente (F-146, D-206): el historial es de la sesión (sessionStorage de la
 * pestaña, como el estado de React de WeeklySync pero sin perderlo al recargar). Cada pregunta se
 * manda al servidor, que la responde en la cola `ai`; la respuesta llega por el evento
 * `assistant.answered` del canal privado de quien pregunta o, sin Reverb, preguntando cada 2 s.
 */
export function useAssistant() {
    const userId = usePage().props.auth?.user?.id ?? null;
    const key = storageKey(userId);
    const live = useRealtimeStatus() === 'connected';
    const [state, setState] = useState<Stored>(() => load(key));
    const mounted = useRef(true);

    useEffect(() => {
        mounted.current = true;

        return () => {
            mounted.current = false;
        };
    }, []);

    useEffect(() => {
        save(key, state);
    }, [key, state]);

    const finish = useCallback((question: AssistantQuestion) => {
        if (!mounted.current) {
            return;
        }

        setState((current) => {
            if (current.pending !== question.id) {
                return current;
            }

            const reply =
                question.state === 'done'
                    ? message(
                          'assistant',
                          question.answer ?? t('assistant.empty_answer'),
                      )
                    : message(
                          'assistant',
                          question.error
                              ? t('assistant.failed_with', {
                                    error: question.error,
                                })
                              : t('assistant.failed'),
                          true,
                      );

            return { messages: [...current.messages, reply], pending: null };
        });
    }, []);

    const check = useCallback(
        async (id: string) => {
            try {
                const question = await fetchAssistantQuestion(id);

                if (question.state === 'done' || question.state === 'failed') {
                    finish(question);
                }
            } catch (caught) {
                // La pregunta ya no existe (caducó): se deja de esperar.
                if (
                    caught instanceof WeeklyRequestError &&
                    caught.status === 404
                ) {
                    finish({ id, state: 'failed', answer: null, error: null });
                }
            }
        },
        [finish],
    );

    // El evento en vivo.
    useEffect(() => {
        const pending = state.pending;

        if (
            pending === null ||
            pending === SENDING ||
            userId === null ||
            !realtimeEnabled()
        ) {
            return;
        }

        const name = `App.Models.User.${userId}`;
        const channel = acquireChannel(name, 'private');

        if (!channel) {
            return;
        }

        const handler = (event: AssistantAnsweredEvent) => {
            if (event.question_id === pending) {
                void check(pending);
            }
        };

        channel.listen('.assistant.answered', handler);
        const offReconnect = onRealtimeReconnect(() => void check(pending));

        return () => {
            channel.stopListening('.assistant.answered', handler);
            offReconnect();
            releaseChannel(name, 'private');
        };
    }, [state.pending, userId, check]);

    // El sondeo (de respaldo con Reverb) y el límite de espera.
    useEffect(() => {
        const pending = state.pending;

        if (pending === null || pending === SENDING) {
            return;
        }

        void check(pending);
        const interval = window.setInterval(
            () => void check(pending),
            live ? ASSISTANT_LIVE_POLL_MS : ASSISTANT_POLL_MS,
        );
        const timeout = window.setTimeout(() => {
            if (!mounted.current) {
                return;
            }

            setState((current) =>
                current.pending === pending
                    ? {
                          messages: [
                              ...current.messages,
                              message(
                                  'assistant',
                                  t('assistant.timeout'),
                                  true,
                              ),
                          ],
                          pending: null,
                      }
                    : current,
            );
        }, ASSISTANT_TIMEOUT_MS);

        return () => {
            window.clearInterval(interval);
            window.clearTimeout(timeout);
        };
    }, [state.pending, live, check]);

    const send = async (text: string) => {
        const question = text.trim();

        if (question === '' || state.pending !== null) {
            return;
        }

        const history = historyFor(state.messages);
        setState((current) => ({
            ...current,
            messages: [...current.messages, message('user', question)],
            pending: SENDING,
        }));

        try {
            const asked = await askAssistant(question, history);

            if (mounted.current) {
                setState((current) => ({ ...current, pending: asked.id }));
            }
        } catch (caught) {
            if (!mounted.current) {
                return;
            }

            const detail =
                caught instanceof WeeklyRequestError
                    ? (caught.userMessage ??
                      (caught.status === 429 ? t('assistant.too_many') : null))
                    : null;

            setState((current) => ({
                messages: [
                    ...current.messages,
                    message(
                        'assistant',
                        detail
                            ? t('assistant.failed_with', { error: detail })
                            : t('assistant.failed'),
                        true,
                    ),
                ],
                pending: null,
            }));
        }
    };

    return {
        messages: state.messages,
        waiting: state.pending !== null,
        send,
        reset: () => setState({ messages: [], pending: null }),
    };
}
