import { useMemo, useSyncExternalStore } from 'react';
import {
    acquireChannel,
    releaseChannel,
} from '@/hooks/use-realtime-connection';
import type { RealtimePresenceChannel } from '@/hooks/use-realtime-connection';
import { realtimeRequest } from '@/hooks/use-realtime-http';
import { realtimeEnabled } from '@/lib/realtime';
import { presence as presenceRoute } from '@/routes/realtime';

/**
 * Presencia de la plantilla (SPEC §12): en línea, ausente (5 minutos sin actividad) o desconectado.
 *
 * - Con tiempo real: canal presence «online» de Echo (quién está conectado) y whisper «status»
 *   para avisar de «ausente» y de la vuelta. La actividad se comparte entre pestañas del mismo
 *   navegador (localStorage), así todas dicen lo mismo.
 * - Sin tiempo real: un latido por minuto a POST /tiempo-real/presencia, que devuelve el estado de
 *   los demás.
 *
 * La arranca <RealtimeRoot /> (en el layout de la app) una sola vez; usePresence() la lee.
 */

export type PresenceStatus = 'online' | 'away' | 'offline';

export const AWAY_AFTER_MS = 5 * 60_000;
export const PRESENCE_POLL_MS = 60_000;
const CHECK_MS = 30_000;
const ACTIVITY_WRITE_MS = 15_000;
export const PRESENCE_ACTIVITY_KEY = 'audax.presence.activity';
const CHANNEL = 'online';

type Member = { id: number; name?: string; avatar?: string | null };

type PresenceState = {
    statuses: Record<number, 'online' | 'away'>;
    /** true con el canal de Reverb; false con los latidos periódicos. */
    live: boolean;
    started: boolean;
};

const INITIAL: PresenceState = { statuses: {}, live: false, started: false };

let state: PresenceState = INITIAL;
let myId: number | null = null;
let myStatus: 'online' | 'away' = 'online';
let lastActivity = Date.now();
let runs = 0;
let teardown: (() => void) | null = null;
let channel: RealtimePresenceChannel | null = null;

const listeners = new Set<() => void>();

function emit(next: PresenceState): void {
    state = next;
    listeners.forEach((listener) => listener());
}

function setStatuses(
    statuses: Record<number, 'online' | 'away'>,
    live: boolean,
): void {
    emit({ statuses, live, started: true });
}

function sharedActivity(): number {
    try {
        const value = Number(localStorage.getItem(PRESENCE_ACTIVITY_KEY));

        return Number.isFinite(value) ? value : 0;
    } catch {
        return 0;
    }
}

function computeStatus(now = Date.now()): 'online' | 'away' {
    const latest = Math.max(lastActivity, sharedActivity());

    return now - latest >= AWAY_AFTER_MS ? 'away' : 'online';
}

function whisperStatus(): void {
    if (channel && myId !== null) {
        channel.whisper('status', { user_id: myId, status: myStatus });
    }
}

async function beat(): Promise<void> {
    try {
        const data = await realtimeRequest<{
            users: Record<string, 'online' | 'away'>;
        }>(presenceRoute.url(), { method: 'POST', body: { status: myStatus } });

        if (data) {
            const statuses: Record<number, 'online' | 'away'> = {};

            for (const [id, status] of Object.entries(data.users)) {
                statuses[Number(id)] = status;
            }

            if (myId !== null) {
                statuses[myId] = myStatus;
            }

            setStatuses(statuses, false);
        }
    } catch {
        // Sin respuesta: se reintenta en el siguiente latido.
    }
}

/** Revisa si hay que pasar a «ausente» o volver a «en línea» y lo comunica. */
function evaluate(): void {
    const next = computeStatus();

    if (next === myStatus) {
        return;
    }

    myStatus = next;

    if (myId !== null && state.started) {
        setStatuses({ ...state.statuses, [myId]: myStatus }, state.live);
    }

    if (channel) {
        whisperStatus();
    } else {
        void beat();
    }
}

function recordActivity(): void {
    const now = Date.now();

    if (now - lastActivity < ACTIVITY_WRITE_MS && myStatus === 'online') {
        return;
    }

    lastActivity = now;

    try {
        localStorage.setItem(PRESENCE_ACTIVITY_KEY, String(now));
    } catch {
        // Sin almacenamiento: cada pestaña lleva la suya.
    }

    evaluate();
}

function startLive(presenceChannel: RealtimePresenceChannel): () => void {
    channel = presenceChannel;
    const away = new Set<number>();

    presenceChannel.here((members: Member[]) => {
        const statuses: Record<number, 'online' | 'away'> = {};

        for (const member of members) {
            statuses[member.id] = away.has(member.id) ? 'away' : 'online';
        }

        if (myId !== null) {
            statuses[myId] = myStatus;
        }

        setStatuses(statuses, true);

        // Quien acaba de entrar no sabe que estás ausente.
        if (myStatus === 'away') {
            whisperStatus();
        }
    });

    presenceChannel.joining((member: Member) => {
        setStatuses({ ...state.statuses, [member.id]: 'online' }, true);

        if (myStatus === 'away') {
            whisperStatus();
        }
    });

    presenceChannel.leaving((member: Member) => {
        const statuses = { ...state.statuses };
        delete statuses[member.id];
        away.delete(member.id);
        setStatuses(statuses, true);
    });

    const onStatus = (data: { user_id?: unknown; status?: unknown }) => {
        const id = Number(data.user_id);

        if (
            !Number.isInteger(id) ||
            id === myId ||
            (data.status !== 'online' && data.status !== 'away')
        ) {
            return;
        }

        if (data.status === 'away') {
            away.add(id);
        } else {
            away.delete(id);
        }

        setStatuses({ ...state.statuses, [id]: data.status }, true);
    };

    presenceChannel.listenForWhisper('status', onStatus);

    return () => {
        presenceChannel.stopListeningForWhisper('status', onStatus);
        channel = null;
        releaseChannel(CHANNEL, 'presence');
    };
}

function start(user: { id: number }): () => void {
    myId = user.id;
    lastActivity = Date.now();
    myStatus = computeStatus();

    const presenceChannel = realtimeEnabled()
        ? acquireChannel(CHANNEL, 'presence')
        : null;
    let stopChannel: (() => void) | null = null;
    let poll: ReturnType<typeof setInterval> | null = null;

    if (presenceChannel) {
        emit({ statuses: { [user.id]: myStatus }, live: true, started: true });
        stopChannel = startLive(presenceChannel);
    } else {
        void beat();
        poll = setInterval(() => void beat(), PRESENCE_POLL_MS);
    }

    const check = setInterval(evaluate, CHECK_MS);
    const activityEvents = [
        'pointerdown',
        'keydown',
        'pointermove',
        'wheel',
        'touchstart',
        'focus',
    ] as const;
    const onVisibility = () => {
        if (document.visibilityState === 'visible') {
            recordActivity();
        }
    };
    const onStorage = (event: StorageEvent) => {
        if (event.key === PRESENCE_ACTIVITY_KEY) {
            evaluate();
        }
    };

    activityEvents.forEach((name) =>
        window.addEventListener(name, recordActivity, { passive: true }),
    );
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('storage', onStorage);

    return () => {
        stopChannel?.();

        if (poll !== null) {
            clearInterval(poll);
        }

        clearInterval(check);
        activityEvents.forEach((name) =>
            window.removeEventListener(name, recordActivity),
        );
        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('storage', onStorage);
        myId = null;
        emit(INITIAL);
    };
}

/**
 * Arranca la presencia para la persona con sesión (una vez por pestaña: si se llama de nuevo,
 * reutiliza la que ya está en marcha). Devuelve cómo pararla.
 */
export function startPresence(user: { id: number }): () => void {
    runs += 1;

    if (runs === 1) {
        teardown = start(user);
    }

    return () => {
        runs -= 1;

        if (runs <= 0) {
            runs = 0;
            teardown?.();
            teardown = null;
        }
    };
}

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

function getState(): PresenceState {
    return state;
}

export type Presence = {
    /** Estado de una persona: «online», «away» u «offline». */
    statusOf: (userId: number) => PresenceStatus;
    /** Ids de quien está en línea o ausente (conectado). */
    connected: number[];
    /** Con Reverb (canal presence) o con latidos periódicos. */
    live: boolean;
    /** Ya se sabe quién está (antes, todos salen como desconectados). */
    ready: boolean;
};

export function usePresence(): Presence {
    const current = useSyncExternalStore(subscribe, getState, getState);

    return useMemo(
        () => ({
            statusOf: (userId: number): PresenceStatus =>
                current.statuses[userId] ?? 'offline',
            connected: Object.keys(current.statuses).map(Number),
            live: current.live,
            ready: current.started,
        }),
        [current],
    );
}

/** Solo para los tests: vuelve al estado inicial. */
export function resetPresenceForTests(): void {
    teardown?.();
    teardown = null;
    runs = 0;
    channel = null;
    myId = null;
    myStatus = 'online';
    lastActivity = Date.now();
    listeners.clear();
    state = INITIAL;
}
