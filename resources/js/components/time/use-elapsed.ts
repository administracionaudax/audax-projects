import { useEffect, useState } from 'react';

/** Segundos transcurridos desde un instante ISO (UTC), actualizados cada segundo. */
export function useElapsedSeconds(
    startedAt: string | null | undefined,
): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!startedAt) {
            return;
        }

        const id = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(id);
    }, [startedAt]);

    return elapsedSeconds(startedAt, now);
}

export function elapsedSeconds(
    startedAt: string | null | undefined,
    now: number = Date.now(),
): number {
    if (!startedAt) {
        return 0;
    }

    const started = Date.parse(startedAt);

    return Number.isNaN(started)
        ? 0
        : Math.max(Math.floor((now - started) / 1000), 0);
}

/** 3725 → "1:02:05" (h:mm:ss, las horas sin ceros a la izquierda). */
export function formatElapsed(seconds: number): string {
    const total = Math.max(Math.floor(seconds), 0);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const rest = total % 60;

    return `${hours}:${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
}
