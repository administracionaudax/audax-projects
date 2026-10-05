import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    acquireChannel,
    onRealtimeReconnect,
    releaseChannel,
    useRealtimeStatus,
} from '@/hooks/use-realtime-connection';
import { realtimeEnabled } from '@/lib/realtime';
import { status as reportStatus } from '@/routes/weeklies/report';
import type {
    WeeklyGenerationEvent,
    WeeklyJobDetail,
    WeeklyJobState,
    WeeklyReportStatus,
} from '@/types/weeklies';

/** Sin tiempo real, cada cuánto se pregunta por el estado mientras se genera algo. */
export const PROGRESS_POLL_MS = 4_000;

/** Con tiempo real, el sondeo es solo de respaldo (por si se pierde un evento). */
export const PROGRESS_LIVE_POLL_MS = 20_000;

export type WeeklyJob = {
    state: WeeklyJobState | null;
    detail: WeeklyJobDetail | null;
    error: string | null;
};

export function isBusy(state: WeeklyJobState | null): boolean {
    return state === 'queued' || state === 'running';
}

/**
 * El progreso del informe y del audio de una semana (F-072 y F-084, D-190): por Reverb (canal
 * privado `weeklies.{id}`, evento `weekly.progress`) y, como respaldo, preguntando a
 * `weeklies.report.status` mientras haya algo en marcha. Al terminar uno, recarga los datos de la
 * página (el informe, el texto, el audio y el estado del cierre).
 */
export function useWeeklyProgress({
    cycleId,
    report,
    audio,
}: {
    cycleId: number;
    report: WeeklyJob;
    audio: WeeklyJob;
}): { report: WeeklyJob; audio: WeeklyJob; refresh: () => void } {
    const live = useRealtimeStatus() === 'connected';
    const [jobs, setJobs] = useState({ report, audio });
    const key = JSON.stringify({ report, audio });
    const [synced, setSynced] = useState(key);
    const busy = isBusy(jobs.report.state) || isBusy(jobs.audio.state);
    const wasBusy = useRef(busy);

    // Lo que llega de la página (tras recargar) manda sobre lo que se sabía.
    if (synced !== key) {
        setSynced(key);
        setJobs({ report, audio });
    }

    const reload = useCallback(() => {
        router.reload({
            only: ['cycle', 'stale', 'submitted_count', 'progress', 'close'],
        });
    }, []);

    const check = useCallback(async () => {
        try {
            const response = await fetch(reportStatus.url(cycleId), {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                return;
            }

            const data = (await response.json()) as WeeklyReportStatus;
            setJobs({
                report: {
                    state: data.report_state,
                    detail: data.report_progress,
                    error: data.report_error,
                },
                audio: {
                    state: data.audio_state,
                    detail: data.audio_progress,
                    error: data.audio_error,
                },
            });
        } catch {
            // Un fallo de red al sondear no corta la espera.
        }
    }, [cycleId]);

    // El evento en vivo.
    useEffect(() => {
        if (!realtimeEnabled()) {
            return;
        }

        const name = `weeklies.${cycleId}`;
        const channel = acquireChannel(name, 'private');

        if (!channel) {
            return;
        }

        const handler = (event: WeeklyGenerationEvent) => {
            if (event.cycle_id !== cycleId) {
                return;
            }

            setJobs((current) => ({
                ...current,
                [event.kind]: {
                    state: event.state,
                    detail: isBusy(event.state)
                        ? {
                              step: event.step,
                              done: event.done,
                              total: event.total,
                          }
                        : null,
                    error: event.error,
                },
            }));
        };

        channel.listen('.weekly.progress', handler);
        const offReconnect = onRealtimeReconnect(() => void check());

        return () => {
            channel.stopListening('.weekly.progress', handler);
            offReconnect();
            releaseChannel(name, 'private');
        };
    }, [cycleId, check]);

    // El sondeo mientras hay algo en marcha.
    useEffect(() => {
        if (!busy) {
            return;
        }

        const interval = setInterval(
            () => void check(),
            live ? PROGRESS_LIVE_POLL_MS : PROGRESS_POLL_MS,
        );

        return () => clearInterval(interval);
    }, [busy, live, check]);

    // Al terminar (bien o mal), se recargan los datos de la página.
    useEffect(() => {
        if (wasBusy.current && !busy) {
            reload();
        }

        wasBusy.current = busy;
    }, [busy, reload]);

    return { ...jobs, refresh: reload };
}
