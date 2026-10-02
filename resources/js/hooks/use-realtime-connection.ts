import { echo } from '@laravel/echo-react';
import { useSyncExternalStore } from 'react';
import { realtimeEnabled } from '@/lib/realtime';

/**
 * Base de los hooks de tiempo real (Fase 6, D-068): canales de Echo compartidos entre componentes
 * (una suscripción por canal aunque la usen varios) y el estado de la conexión con Reverb.
 * Sin tiempo real (prop `realtime` null) todo es no-op: acquireChannel() devuelve null.
 */

/** Lo que los hooks usan de un canal de Echo (Pusher/Reverb). */
export interface RealtimeChannel {
    listen(event: string, callback: CallableFunction): unknown;
    stopListening(event: string, callback?: CallableFunction): unknown;
    listenForWhisper(event: string, callback: CallableFunction): unknown;
    stopListeningForWhisper(
        event: string,
        callback?: CallableFunction,
    ): unknown;
    whisper(eventName: string, data: Record<string, unknown>): unknown;
}

export interface RealtimePresenceChannel extends RealtimeChannel {
    here(callback: CallableFunction): unknown;
    joining(callback: CallableFunction): unknown;
    leaving(callback: CallableFunction): unknown;
}

export type ChannelKind = 'private' | 'presence';

/**
 * - unavailable: no hay tiempo real en esta sesión (se usan consultas periódicas),
 * - connecting: conectando o reconectando con Reverb,
 * - connected: en vivo,
 * - disconnected: se ha cortado (los hooks vuelven a consultar hasta que vuelva).
 */
export type RealtimeStatus =
    | 'unavailable'
    | 'connecting'
    | 'connected'
    | 'disconnected';

type Entry = { channel: RealtimeChannel; count: number };

const entries = new Map<string, Entry>();
const statusListeners = new Set<() => void>();
const reconnectListeners = new Set<() => void>();

let status: RealtimeStatus = 'unavailable';
let watching = false;
let wasConnected = false;

function normalize(value: string): RealtimeStatus {
    switch (value) {
        case 'connected':
            return 'connected';
        case 'connecting':
        case 'reconnecting':
            return 'connecting';
        default:
            return 'disconnected';
    }
}

function setStatus(next: RealtimeStatus): void {
    if (next === status) {
        return;
    }

    const previous = status;
    status = next;

    if (next === 'connected') {
        // Solo tras un corte: la primera conexión no es una reconexión.
        if (wasConnected && previous !== 'connected') {
            reconnectListeners.forEach((listener) => listener());
        }

        wasConnected = true;
    }

    statusListeners.forEach((listener) => listener());
}

function watchConnection(): void {
    if (watching || !realtimeEnabled()) {
        return;
    }

    try {
        const instance = echo<'reverb'>();
        watching = true;
        setStatus(normalize(instance.connectionStatus()));
        instance.connector.onConnectionChange((next) =>
            setStatus(normalize(next)),
        );
    } catch {
        watching = false;
    }
}

export function acquireChannel(
    name: string,
    kind: 'presence',
): RealtimePresenceChannel | null;
export function acquireChannel(
    name: string,
    kind: 'private',
): RealtimeChannel | null;
/**
 * Se suscribe al canal (o reutiliza la suscripción) y cuenta un uso más. Cada acquire lleva su
 * releaseChannel() al desmontar.
 */
export function acquireChannel(
    name: string,
    kind: ChannelKind,
): RealtimeChannel | RealtimePresenceChannel | null {
    if (!realtimeEnabled()) {
        return null;
    }

    const key = `${kind}-${name}`;
    let entry = entries.get(key);

    if (!entry) {
        try {
            const instance = echo<'reverb'>();
            const channel =
                kind === 'presence'
                    ? instance.join(name)
                    : instance.private(name);
            entry = { channel, count: 0 };
            entries.set(key, entry);
        } catch {
            return null;
        }

        watchConnection();
    }

    entry.count += 1;

    return entry.channel;
}

/** Suelta un uso; con el último, deja el canal. */
export function releaseChannel(name: string, kind: ChannelKind): void {
    const key = `${kind}-${name}`;
    const entry = entries.get(key);

    if (!entry) {
        return;
    }

    entry.count -= 1;

    if (entry.count > 0) {
        return;
    }

    entries.delete(key);

    try {
        echo().leaveChannel(key);
    } catch {
        // Echo ya no está: no hay nada que dejar.
    }
}

export function realtimeStatus(): RealtimeStatus {
    if (!realtimeEnabled()) {
        return 'unavailable';
    }

    return status === 'unavailable' ? 'connecting' : status;
}

function subscribeStatus(listener: () => void): () => void {
    statusListeners.add(listener);
    watchConnection();

    return () => {
        statusListeners.delete(listener);
    };
}

/** Estado de la conexión, para decidir entre escuchar el canal o consultar cada poco. */
export function useRealtimeStatus(): RealtimeStatus {
    return useSyncExternalStore(
        subscribeStatus,
        realtimeStatus,
        () => 'unavailable',
    );
}

/** Avisa cuando la conexión vuelve tras un corte (para recargar lo que se haya perdido). */
export function onRealtimeReconnect(listener: () => void): () => void {
    reconnectListeners.add(listener);

    return () => {
        reconnectListeners.delete(listener);
    };
}

/** Solo para los tests: vuelve al estado inicial. */
export function resetRealtimeConnectionForTests(): void {
    entries.clear();
    statusListeners.clear();
    reconnectListeners.clear();
    status = 'unavailable';
    watching = false;
    wasConnected = false;
}
