import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { runInNewContext } from 'node:vm';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Avisos del navegador en el service worker de la PWA (public/sw.js, D-072): se ejecuta el
 * fichero real en un ámbito de worker simulado y se disparan sus manejadores.
 */

type Handler = (event: Record<string, unknown>) => void;

const ORIGIN = 'https://projects.audaxstudio.com';
const SOURCE = readFileSync(join(process.cwd(), 'public/sw.js'), 'utf8');

function worker() {
    const handlers = new Map<string, Handler>();
    const showNotification = vi.fn(async () => undefined);
    const openWindow = vi.fn(async () => null);
    const matchAll = vi.fn(async (): Promise<unknown[]> => []);
    const subscribe = vi.fn();
    const fetchMock = vi.fn(async () => new Response(null, { status: 201 }));

    const self = {
        addEventListener: (type: string, handler: Handler) =>
            handlers.set(type, handler),
        registration: { showNotification, pushManager: { subscribe } },
        clients: { matchAll, openWindow, claim: vi.fn() },
        location: { origin: ORIGIN },
        skipWaiting: vi.fn(),
    };

    runInNewContext(SOURCE, {
        self,
        caches: { open: vi.fn(), keys: vi.fn(async () => []), match: vi.fn() },
        fetch: fetchMock,
        URL,
        Request,
        Response,
        console,
    });

    /** Dispara un evento y espera a lo que el manejador deja en waitUntil. */
    const dispatch = async (type: string, event: Record<string, unknown>) => {
        const pending: Array<Promise<unknown>> = [];
        handlers.get(type)?.({
            ...event,
            waitUntil: (promise: Promise<unknown>) => pending.push(promise),
        });
        await Promise.all(pending);
    };

    return {
        handlers,
        showNotification,
        openWindow,
        matchAll,
        subscribe,
        fetchMock,
        dispatch,
    };
}

const pushEvent = (payload: unknown) => ({
    data: {
        json: () => payload,
        text: () => JSON.stringify(payload),
    },
});

describe('service worker: avisos del navegador', () => {
    let sw: ReturnType<typeof worker>;

    beforeEach(() => {
        sw = worker();
    });

    it('registra los manejadores de push, notificationclick y pushsubscriptionchange', () => {
        expect([...sw.handlers.keys()]).toEqual(
            expect.arrayContaining([
                'install',
                'activate',
                'fetch',
                'push',
                'notificationclick',
                'pushsubscriptionchange',
            ]),
        );
    });

    it('pinta el aviso con su título, texto, etiqueta de la conversación y enlace', async () => {
        await sw.dispatch(
            'push',
            pushEvent({
                title: 'Ana te ha escrito',
                body: 'Hola, ¿tienes un minuto?',
                url: '/tiempo-real/conversaciones/5/abrir?mensaje=9',
                tag: 'conversation-5',
            }),
        );

        expect(sw.showNotification).toHaveBeenCalledWith(
            'Ana te ha escrito',
            expect.objectContaining({
                body: 'Hola, ¿tienes un minuto?',
                tag: 'conversation-5',
                renotify: true,
                lang: 'es',
                data: { url: '/tiempo-real/conversaciones/5/abrir?mensaje=9' },
            }),
        );
    });

    it('nunca lleva fuera de la app y aguanta avisos vacíos o raros', async () => {
        for (const url of [
            'https://evil.example/x',
            '//evil.example/x',
            '/\\evil',
            null,
        ]) {
            await sw.dispatch('push', pushEvent({ title: 'T', url }));
        }
        await sw.dispatch('push', {});
        await sw.dispatch('push', {
            data: {
                json: () => {
                    throw new Error('no es JSON');
                },
                text: () => 'texto suelto',
            },
        });

        const calls = sw.showNotification.mock.calls as unknown as Array<
            [string, { data: { url: string }; body: string; tag?: string }]
        >;
        expect(
            calls.slice(0, 4).map(([, options]) => options.data.url),
        ).toEqual(['/chat', '/chat', '/chat', '/chat']);
        expect(calls[4][0]).toBe('Audax Proyectos');
        expect(calls[4][1].tag).toBeUndefined();
        expect(calls[5][1].body).toBe('texto suelto');
    });

    it('al pulsar el aviso, abre la conversación en una pestaña de la app si la hay', async () => {
        const navigate = vi.fn(async () => ({}));
        const client = {
            url: `${ORIGIN}/proyectos`,
            focus: vi.fn(async () => client),
            navigate,
        };
        sw.matchAll.mockResolvedValue([
            {
                url: 'https://otra-web.example/',
                focus: vi.fn(),
                navigate: vi.fn(),
            },
            client,
        ]);
        const close = vi.fn();

        await sw.dispatch('notificationclick', {
            notification: {
                close,
                data: { url: '/tiempo-real/conversaciones/5/abrir?mensaje=9' },
            },
        });

        expect(close).toHaveBeenCalled();
        expect(client.focus).toHaveBeenCalled();
        expect(navigate).toHaveBeenCalledWith(
            `${ORIGIN}/tiempo-real/conversaciones/5/abrir?mensaje=9`,
        );
        expect(sw.openWindow).not.toHaveBeenCalled();
    });

    it('si no hay ninguna pestaña de la app (o no se puede usar), abre una nueva', async () => {
        await sw.dispatch('notificationclick', {
            notification: { close: vi.fn(), data: { url: '/chat' } },
        });
        expect(sw.openWindow).toHaveBeenCalledWith(`${ORIGIN}/chat`);

        sw.matchAll.mockResolvedValue([
            {
                url: `${ORIGIN}/`,
                focus: vi.fn(async () => {
                    throw new TypeError('sin control');
                }),
                navigate: vi.fn(),
            },
        ]);
        await sw.dispatch('notificationclick', {
            notification: { close: vi.fn(), data: {} },
        });
        expect(sw.openWindow).toHaveBeenLastCalledWith(`${ORIGIN}/chat`);
    });

    it('si el navegador renueva la suscripción, registra la nueva y quita la vieja', async () => {
        const next = {
            toJSON: () => ({
                endpoint: 'https://fcm.googleapis.com/fcm/send/nueva',
                keys: { p256dh: 'BPUB', auth: 'AUTH' },
            }),
        };

        await sw.dispatch('pushsubscriptionchange', {
            oldSubscription: {
                endpoint: 'https://fcm.googleapis.com/fcm/send/vieja',
                options: { userVisibleOnly: true },
            },
            newSubscription: next,
        });

        const calls = sw.fetchMock.mock.calls as unknown as Array<
            [string, { method: string; body: string; credentials: string }]
        >;
        expect(calls.map(([url, init]) => [url, init.method])).toEqual([
            ['/avisos-navegador/suscripciones', 'POST'],
            ['/avisos-navegador/suscripciones', 'DELETE'],
        ]);
        expect(JSON.parse(calls[0][1].body)).toEqual({
            endpoint: 'https://fcm.googleapis.com/fcm/send/nueva',
            keys: { p256dh: 'BPUB', auth: 'AUTH' },
            content_encoding: 'aes128gcm',
        });
        expect(calls[0][1].credentials).toBe('same-origin');
        expect(JSON.parse(calls[1][1].body)).toEqual({
            endpoint: 'https://fcm.googleapis.com/fcm/send/vieja',
        });
    });

    it('sin la suscripción nueva, se vuelve a suscribir con las mismas opciones', async () => {
        sw.subscribe.mockResolvedValue({
            toJSON: () => ({
                endpoint: 'https://fcm.googleapis.com/fcm/send/otra',
                keys: { p256dh: 'B', auth: 'A' },
            }),
        });

        await sw.dispatch('pushsubscriptionchange', {
            oldSubscription: {
                endpoint: 'https://fcm.googleapis.com/fcm/send/vieja',
                options: { userVisibleOnly: true, applicationServerKey: 'k' },
            },
        });

        expect(sw.subscribe).toHaveBeenCalledWith({
            userVisibleOnly: true,
            applicationServerKey: 'k',
        });
        expect(sw.fetchMock).toHaveBeenCalledTimes(2);
    });
});
