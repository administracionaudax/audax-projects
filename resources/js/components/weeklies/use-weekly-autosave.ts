import { useCallback, useEffect, useRef, useState } from 'react';
import {
    saveWeeklyDraft,
    WeeklyRequestError,
} from '@/components/weeklies/weekly-api';
import type { WeeklyDraftInput, WeeklySubmission } from '@/types/weeklies';

export type AutosaveStatus = 'idle' | 'pending' | 'saving' | 'saved' | 'error';

/** Espera tras la última pulsación antes de guardar (WeeklySync: 700 ms). */
export const AUTOSAVE_DELAY_MS = 700;

export type WeeklyAutosave = {
    status: AutosaveStatus;
    /** Instante ISO del último guardado correcto. */
    savedAt: string | null;
    /** Mensaje del servidor si la regla se rompe (semana cerrada, exento…). */
    error: string | null;
    /** Guarda ya lo pendiente (p. ej. al reintentar). */
    flush: () => Promise<void>;
    /** Olvida lo pendiente (p. ej. antes de enviar, que ya guarda). */
    cancel: () => void;
};

/**
 * Borrador autoguardado de «Mi weekly» (F-051, D-157): cada cambio en los apuntes se guarda con
 * PUT my-weekly.draft cuando se deja de escribir 700 ms, sin recargar la página. Estado visible:
 * «Guardando…», «Guardado» o «No se ha podido guardar» (con reintento).
 *
 * - No guarda la primera vez (lo que llega del servidor ya está guardado) ni si nada cambia.
 * - Si llega otro cambio mientras guarda, lo guarda después: el último gana.
 * - Al salir de la página con cambios sin guardar, los manda con `keepalive` y avisa el navegador.
 */
export function useWeeklyAutosave({
    cycleId,
    draft,
    enabled,
    delay = AUTOSAVE_DELAY_MS,
    initialSavedAt = null,
    save = saveWeeklyDraft,
    onSaved,
}: {
    cycleId: number;
    draft: WeeklyDraftInput;
    enabled: boolean;
    delay?: number;
    initialSavedAt?: string | null;
    save?: typeof saveWeeklyDraft;
    onSaved?: (submission: WeeklySubmission) => void;
}): WeeklyAutosave {
    const serialized = JSON.stringify(draft);
    const [status, setStatus] = useState<AutosaveStatus>('idle');
    const [savedAt, setSavedAt] = useState<string | null>(initialSavedAt);
    const [error, setError] = useState<string | null>(null);
    const lastSaved = useRef(serialized);
    const latest = useRef(serialized);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const inFlight = useRef<Promise<void> | null>(null);
    const mounted = useRef(true);
    const callbacks = useRef({ save, onSaved, cycleId });

    useEffect(() => {
        callbacks.current = { save, onSaved, cycleId };
    });

    useEffect(() => {
        mounted.current = true;

        return () => {
            mounted.current = false;
        };
    }, []);

    const clearTimer = () => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
            timer.current = null;
        }
    };

    const run = useCallback(async (): Promise<void> => {
        clearTimer();

        if (inFlight.current) {
            await inFlight.current;
        }

        const body = latest.current;

        if (body === lastSaved.current) {
            if (mounted.current) {
                setStatus((current) =>
                    current === 'pending' ? 'saved' : current,
                );
            }

            return;
        }

        if (mounted.current) {
            setStatus('saving');
        }

        const attempt = (async () => {
            try {
                const submission = await callbacks.current.save(
                    callbacks.current.cycleId,
                    JSON.parse(body) as WeeklyDraftInput,
                );
                lastSaved.current = body;

                if (mounted.current) {
                    setSavedAt(
                        submission.draft_saved_at ?? new Date().toISOString(),
                    );
                    setError(null);
                    setStatus(latest.current === body ? 'saved' : 'pending');
                }

                callbacks.current.onSaved?.(submission);
            } catch (caught) {
                if (mounted.current) {
                    setStatus('error');
                    setError(
                        caught instanceof WeeklyRequestError
                            ? caught.userMessage
                            : null,
                    );
                }
            }
        })();

        inFlight.current = attempt;
        await attempt;
        inFlight.current = null;

        // Cambios llegados mientras guardaba: se guardan ahora.
        if (mounted.current && latest.current !== lastSaved.current) {
            timer.current = setTimeout(() => void run(), delay);
        }
    }, [delay]);

    useEffect(() => {
        latest.current = serialized;

        if (!enabled || serialized === lastSaved.current) {
            return;
        }

        setStatus('pending');
        clearTimer();
        timer.current = setTimeout(() => void run(), delay);

        return clearTimer;
    }, [serialized, enabled, delay, run]);

    // Al salir con cambios sin guardar: se mandan igualmente y el navegador avisa.
    useEffect(() => {
        if (!enabled) {
            return;
        }

        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            if (latest.current === lastSaved.current) {
                return;
            }

            void callbacks.current
                .save(
                    callbacks.current.cycleId,
                    JSON.parse(latest.current) as WeeklyDraftInput,
                    { keepalive: true },
                )
                .catch(() => undefined);
            event.preventDefault();
        };

        window.addEventListener('beforeunload', onBeforeUnload);

        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, [enabled]);

    // Al desmontar (navegación de Inertia) con cambios pendientes, se guardan en segundo plano.
    useEffect(
        () => () => {
            clearTimer();

            if (enabled && latest.current !== lastSaved.current) {
                void callbacks.current
                    .save(
                        callbacks.current.cycleId,
                        JSON.parse(latest.current) as WeeklyDraftInput,
                        { keepalive: true },
                    )
                    .catch(() => undefined);
            }
        },
        [enabled],
    );

    return {
        status,
        savedAt,
        error,
        flush: run,
        cancel: () => {
            clearTimer();
            lastSaved.current = latest.current;
            setStatus('idle');
        },
    };
}
