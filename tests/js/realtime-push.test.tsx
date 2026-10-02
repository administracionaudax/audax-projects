// @vitest-environment jsdom
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { webcrypto } from 'node:crypto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { jsonBody, jsonResponse, urlOf } from './realtime-fake-echo';

const mocks = vi.hoisted(() => ({
    page: { props: { auth: { user: { id: 7, name: 'Luis' } } } },
    toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock('@inertiajs/react', () => ({ usePage: () => mocks.page }));
vi.mock('sonner', () => ({ toast: mocks.toast }));

import {
    base64UrlToBytes,
    endpointHash,
    PUSH_OWNER_KEY,
    syncPushSubscription,
} from '@/components/realtime/push';
import { PushNotificationsToggle } from '@/components/realtime/push-toggle';

const KEY_BYTES = new Uint8Array(65).map((_, index) =>
    index === 0 ? 4 : index,
);
const PUBLIC_KEY = btoa(String.fromCharCode(...KEY_BYTES))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc';

type FakeSubscription = {
    endpoint: string;
    options: { applicationServerKey: ArrayBuffer | null };
    toJSON: () => { endpoint: string; keys: Record<string, string> };
    unsubscribe: ReturnType<typeof vi.fn>;
};

function fakeSubscription(key: Uint8Array = KEY_BYTES): FakeSubscription {
    return {
        endpoint: ENDPOINT,
        options: { applicationServerKey: key.slice().buffer },
        toJSON: () => ({
            endpoint: ENDPOINT,
            keys: { p256dh: 'BPUB', auth: 'AUTH' },
        }),
        unsubscribe: vi.fn(async () => true),
    };
}

describe('Avisos del navegador (Web Push)', () => {
    let current: FakeSubscription | null;
    let permission: NotificationPermission;
    let serverSubscriptions: string[];
    let enabled: boolean;
    let fetchMock: ReturnType<typeof vi.spyOn>;
    const pushManager = {
        getSubscription: vi.fn(async () => current),
        subscribe: vi.fn(async () => {
            current = fakeSubscription();

            return current;
        }),
    };
    const requestPermission = vi.fn(async () => {
        permission = 'granted';

        return permission;
    });

    const define = (target: object, name: string, value: unknown) =>
        Object.defineProperty(target, name, { configurable: true, value });

    beforeEach(() => {
        current = null;
        permission = 'default';
        serverSubscriptions = [];
        enabled = true;
        requestPermission.mockClear();
        pushManager.subscribe.mockClear();
        mocks.toast.success.mockClear();

        define(window, 'isSecureContext', true);
        define(
            window,
            'PushManager',
            class {
                static supportedContentEncodings = ['aes128gcm', 'aesgcm'];
            },
        );
        define(
            globalThis,
            'PushManager',
            (window as unknown as { PushManager: unknown }).PushManager,
        );
        define(window, 'Notification', {
            get permission() {
                return permission;
            },
            requestPermission,
        });
        define(
            globalThis,
            'Notification',
            (window as unknown as { Notification: unknown }).Notification,
        );
        define(navigator, 'serviceWorker', {
            getRegistration: vi.fn(async () => ({ pushManager })),
            ready: Promise.resolve({ pushManager }),
        });
        if (!globalThis.crypto?.subtle) {
            define(globalThis, 'crypto', webcrypto);
        }

        fetchMock = vi
            .spyOn(globalThis, 'fetch')
            .mockImplementation(async (input, init) => {
                const url = urlOf(input);
                const method = init?.method ?? 'GET';

                if (url === '/avisos-navegador' && method === 'GET') {
                    return jsonResponse({
                        enabled,
                        public_key: enabled ? PUBLIC_KEY : null,
                        subscriptions: serverSubscriptions,
                    });
                }

                if (
                    url === '/avisos-navegador/suscripciones' &&
                    method === 'POST'
                ) {
                    serverSubscriptions = [await endpointHash(ENDPOINT)];

                    return jsonResponse({ subscribed: true }, 201);
                }

                if (
                    url === '/avisos-navegador/suscripciones' &&
                    method === 'DELETE'
                ) {
                    serverSubscriptions = [];

                    return jsonResponse(null, 204);
                }

                return jsonResponse({}, 404);
            });
    });

    afterEach(() => {
        vi.restoreAllMocks();
        for (const name of ['isSecureContext', 'PushManager', 'Notification']) {
            Reflect.deleteProperty(window, name);
        }
        Reflect.deleteProperty(globalThis, 'PushManager');
        Reflect.deleteProperty(globalThis, 'Notification');
        Reflect.deleteProperty(navigator, 'serviceWorker');
    });

    it('en un navegador sin Web Push lo dice y no deja activarlos', () => {
        Reflect.deleteProperty(window, 'PushManager');
        render(<PushNotificationsToggle />);

        expect(
            screen
                .getByRole('switch', {
                    name: 'Activar avisos en este navegador',
                })
                .hasAttribute('disabled'),
        ).toBe(true);
        expect(
            screen.getByText(/Este navegador no admite avisos/),
        ).toBeTruthy();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('sin VAPID en el servidor, no están disponibles', async () => {
        enabled = false;
        render(<PushNotificationsToggle />);

        expect(
            await screen.findByText(
                'Los avisos del navegador todavía no están disponibles.',
            ),
        ).toBeTruthy();
        expect(screen.getByRole('switch').hasAttribute('disabled')).toBe(true);
    });

    it('activa los avisos: pide permiso, se suscribe con la clave VAPID y lo guarda en el servidor', async () => {
        const user = userEvent.setup();
        render(<PushNotificationsToggle />);
        expect(
            await screen.findByText('Desactivados en este navegador.'),
        ).toBeTruthy();

        await user.click(
            screen.getByRole('switch', {
                name: 'Activar avisos en este navegador',
            }),
        );

        expect(
            await screen.findByText('Activados en este navegador.'),
        ).toBeTruthy();
        expect(requestPermission).toHaveBeenCalledTimes(1);
        const options = pushManager.subscribe.mock
            .calls[0][0] as unknown as PushSubscriptionOptionsInit;
        expect(options.userVisibleOnly).toBe(true);
        expect(Array.from(options.applicationServerKey as Uint8Array)).toEqual(
            Array.from(KEY_BYTES),
        );

        const post = fetchMock.mock.calls.find(
            ([, init]) => (init as RequestInit | undefined)?.method === 'POST',
        );
        expect(jsonBody(post?.[1])).toEqual({
            endpoint: ENDPOINT,
            keys: { p256dh: 'BPUB', auth: 'AUTH' },
            content_encoding: 'aes128gcm',
        });
        expect(localStorage.getItem(PUSH_OWNER_KEY)).toBe('7');
        expect(mocks.toast.success).toHaveBeenCalledWith(
            'Avisos activados en este navegador.',
        );
    });

    it('si se niega el permiso, explica cómo desbloquearlo', async () => {
        requestPermission.mockImplementationOnce(async () => {
            permission = 'denied';

            return permission;
        });
        const user = userEvent.setup();
        render(<PushNotificationsToggle />);
        await screen.findByText('Desactivados en este navegador.');

        await user.click(screen.getByRole('switch'));

        expect(
            await screen.findByText(/Has bloqueado los avisos/),
        ).toBeTruthy();
        expect(pushManager.subscribe).not.toHaveBeenCalled();
    });

    it('ya activados, se pueden desactivar: baja en el servidor y en el navegador', async () => {
        permission = 'granted';
        current = fakeSubscription();
        const subscription = current;
        serverSubscriptions = [await endpointHash(ENDPOINT)];
        localStorage.setItem(PUSH_OWNER_KEY, '7');
        const user = userEvent.setup();
        render(<PushNotificationsToggle />);
        expect(
            await screen.findByText('Activados en este navegador.'),
        ).toBeTruthy();

        await user.click(screen.getByRole('switch'));

        expect(
            await screen.findByText('Desactivados en este navegador.'),
        ).toBeTruthy();
        const deleted = fetchMock.mock.calls.find(
            ([, init]) =>
                (init as RequestInit | undefined)?.method === 'DELETE',
        );
        expect(jsonBody(deleted?.[1])).toEqual({
            endpoint: ENDPOINT,
        });
        expect(subscription.unsubscribe).toHaveBeenCalled();
        expect(localStorage.getItem(PUSH_OWNER_KEY)).toBeNull();
    });

    it('al entrar, los reactiva solo si los activó esta misma persona en este navegador', async () => {
        permission = 'granted';
        current = fakeSubscription();

        localStorage.setItem(PUSH_OWNER_KEY, '8');
        await act(async () => {
            await syncPushSubscription(7);
        });
        expect(fetchMock).not.toHaveBeenCalled();

        localStorage.setItem(PUSH_OWNER_KEY, '7');
        await act(async () => {
            await syncPushSubscription(7);
        });
        expect(
            fetchMock.mock.calls.some(
                ([, init]) =>
                    (init as RequestInit | undefined)?.method === 'POST',
            ),
        ).toBe(true);

        // Ya registrada: no vuelve a enviarla.
        fetchMock.mockClear();
        await act(async () => {
            await syncPushSubscription(7);
        });
        expect(
            fetchMock.mock.calls.some(
                ([, init]) =>
                    (init as RequestInit | undefined)?.method === 'POST',
            ),
        ).toBe(false);
    });

    it('si cambió la clave VAPID del servidor, renueva la suscripción', async () => {
        permission = 'granted';
        const old = fakeSubscription(new Uint8Array(65).fill(9));
        current = old;
        localStorage.setItem(PUSH_OWNER_KEY, '7');

        await act(async () => {
            await syncPushSubscription(7);
        });

        expect(old.unsubscribe).toHaveBeenCalled();
        expect(pushManager.subscribe).toHaveBeenCalledTimes(1);
    });

    it('convierte la clave base64url y calcula el hash del endpoint como el servidor', async () => {
        expect(Array.from(base64UrlToBytes(PUBLIC_KEY))).toEqual(
            Array.from(KEY_BYTES),
        );
        expect(await endpointHash('abc')).toBe(
            'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
        );
    });
});
