/**
 * Echo de mentira para los tests de tiempo real (realtime-*.test.tsx): canales privados y de
 * presencia con listen/whisper como los de pusher-js, y el estado de la conexión. Permite
 * emitir eventos del servidor (emit), whispers de otros (whisperFrom) y cortes (setStatus).
 */
type Callback = (payload: never) => void;

export class FakeChannel {
    readonly listeners = new Map<string, Set<Callback>>();
    readonly whispers: Array<{ event: string; data: Record<string, unknown> }> =
        [];

    constructor(readonly name: string) {}

    listen(event: string, callback: Callback): this {
        const set = this.listeners.get(event) ?? new Set<Callback>();
        set.add(callback);
        this.listeners.set(event, set);

        return this;
    }

    stopListening(event: string, callback?: Callback): this {
        if (callback) {
            this.listeners.get(event)?.delete(callback);
        } else {
            this.listeners.delete(event);
        }

        return this;
    }

    listenForWhisper(event: string, callback: Callback): this {
        return this.listen(`.client-${event}`, callback);
    }

    stopListeningForWhisper(event: string, callback?: Callback): this {
        return this.stopListening(`.client-${event}`, callback);
    }

    whisper(event: string, data: Record<string, unknown>): this {
        this.whispers.push({ event, data });

        return this;
    }

    /** Evento del servidor (p. ej. '.message.posted'), con los metadatos de pusher-js si los hay. */
    emit(event: string, payload: unknown, metadata?: unknown): void {
        this.listeners
            .get(event)
            ?.forEach((callback) =>
                (callback as (value: unknown, meta?: unknown) => void)(
                    payload,
                    metadata,
                ),
            );
    }

    /**
     * Whisper de otro navegador (p. ej. 'typing'). En los canales presence, Reverb añade quién lo
     * envía de verdad: `{ user_id }` en los metadatos.
     */
    whisperFrom(event: string, payload: unknown, metadata?: unknown): void {
        this.emit(`.client-${event}`, payload, metadata);
    }

    listenerCount(event: string): number {
        return this.listeners.get(event)?.size ?? 0;
    }
}

export class FakePresenceChannel extends FakeChannel {
    hereCallback: ((members: unknown[]) => void) | null = null;
    joiningCallback: ((member: unknown) => void) | null = null;
    leavingCallback: ((member: unknown) => void) | null = null;

    here(callback: (members: unknown[]) => void): this {
        this.hereCallback = callback;

        return this;
    }

    joining(callback: (member: unknown) => void): this {
        this.joiningCallback = callback;

        return this;
    }

    leaving(callback: (member: unknown) => void): this {
        this.leavingCallback = callback;

        return this;
    }
}

export type FakeEcho = ReturnType<typeof createFakeEcho>;

export function createFakeEcho(initialStatus = 'connected') {
    const channels = new Map<string, FakeChannel>();
    const statusListeners = new Set<(status: string) => void>();
    let status = initialStatus;
    const left: string[] = [];

    return {
        channels,
        left,
        private(name: string): FakeChannel {
            const key = `private-${name}`;
            const channel = channels.get(key) ?? new FakeChannel(key);
            channels.set(key, channel);

            return channel;
        },
        join(name: string): FakePresenceChannel {
            const key = `presence-${name}`;
            const existing = channels.get(key);

            if (existing instanceof FakePresenceChannel) {
                return existing;
            }

            const channel = new FakePresenceChannel(key);
            channels.set(key, channel);

            return channel;
        },
        leaveChannel(name: string): void {
            left.push(name);
            channels.delete(name);
        },
        connectionStatus(): string {
            return status;
        },
        connector: {
            onConnectionChange(callback: (status: string) => void) {
                statusListeners.add(callback);

                return () => statusListeners.delete(callback);
            },
        },
        setStatus(next: string): void {
            status = next;
            statusListeners.forEach((callback) => callback(next));
        },
        channel(name: string): FakeChannel | undefined {
            return channels.get(name);
        },
        presence(name: string): FakePresenceChannel | undefined {
            const channel = channels.get(`presence-${name}`);

            return channel instanceof FakePresenceChannel ? channel : undefined;
        },
    };
}

/** Respuesta JSON para los fetch simulados. */
export function jsonResponse(data: unknown, status = 200): Response {
    return new Response(status === 204 ? null : JSON.stringify(data), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

/** URL de una llamada a fetch (string, URL o Request). */
export function urlOf(input: RequestInfo | URL): string {
    if (typeof input === 'string') {
        return input;
    }

    return input instanceof URL ? input.href : input.url;
}

/** Cuerpo JSON de una llamada a fetch. */
export function jsonBody(init: RequestInit | undefined): unknown {
    return typeof init?.body === 'string' ? JSON.parse(init.body) : undefined;
}
