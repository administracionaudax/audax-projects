// @vitest-environment jsdom
import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    createFakeEcho,
    jsonBody,
    jsonResponse,
    urlOf,
} from './realtime-fake-echo';
import type { FakeEcho } from './realtime-fake-echo';

const mocks = vi.hoisted(() => ({
    realtime: false,
    echo: null as FakeEcho | null,
    page: { props: { auth: { user: { id: 1, name: 'Ana' } } } },
}));

vi.mock('@inertiajs/react', () => ({ usePage: () => mocks.page }));
vi.mock('@laravel/echo-react', () => ({ echo: () => mocks.echo }));
vi.mock('@/lib/realtime', () => ({
    realtimeEnabled: () => mocks.realtime,
    configureRealtime: () => undefined,
}));

import {
    PresenceDot,
    PresenceLabel,
    UserAvatar,
} from '@/components/realtime/presence-indicator';
import { RealtimeRoot } from '@/components/realtime/realtime-root';
import {
    AWAY_AFTER_MS,
    PRESENCE_POLL_MS,
    resetPresenceForTests,
} from '@/hooks/use-presence';
import { resetRealtimeConnectionForTests } from '@/hooks/use-realtime-connection';

function People() {
    return (
        <ul>
            {[1, 2, 3].map((id) => (
                <li key={id} data-testid={`persona-${id}`}>
                    <PresenceLabel userId={id} />
                </li>
            ))}
        </ul>
    );
}

const status = (id: number) =>
    screen.getByTestId(`persona-${id}`).textContent ?? '';

describe('presencia', () => {
    let fetchMock: ReturnType<typeof vi.spyOn>;
    let users: Record<string, string>;

    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        mocks.realtime = false;
        mocks.echo = createFakeEcho();
        users = { '1': 'online', '2': 'away' };
        fetchMock = vi
            .spyOn(globalThis, 'fetch')
            .mockImplementation(async () => jsonResponse({ users }));
    });

    afterEach(() => {
        resetPresenceForTests();
        resetRealtimeConnectionForTests();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('sin RealtimeRoot todos salen como desconectados', () => {
        render(<People />);

        expect(status(1)).toBe('Desconectado');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('sin tiempo real manda un latido por minuto y pinta el estado de los demás', async () => {
        render(
            <>
                <RealtimeRoot />
                <People />
            </>,
        );

        await act(async () => {
            await Promise.resolve();
        });
        expect(status(1)).toBe('En línea');
        expect(status(2)).toBe('Ausente');
        expect(status(3)).toBe('Desconectado');

        const beats = () =>
            fetchMock.mock.calls.filter(([url]) =>
                urlOf(url).startsWith('/tiempo-real/presencia'),
            );
        expect(beats()).toHaveLength(1);
        expect(jsonBody(beats()[0][1])).toEqual({
            status: 'online',
        });

        users = { '1': 'online', '3': 'online' };
        await act(async () => {
            vi.advanceTimersByTime(PRESENCE_POLL_MS);
        });
        expect(beats()).toHaveLength(2);
        expect(status(2)).toBe('Desconectado');
        expect(status(3)).toBe('En línea');
    });

    it('con tiempo real usa el canal de presencia: quién está, quién entra, quién sale y quién está ausente', async () => {
        mocks.realtime = true;
        render(
            <>
                <RealtimeRoot />
                <People />
            </>,
        );
        const channel = mocks.echo?.presence('online');
        expect(channel).toBeTruthy();

        await act(async () => {
            channel?.hereCallback?.([
                { id: 1, name: 'Ana', avatar: null },
                { id: 2, name: 'Luis', avatar: null },
            ]);
        });
        expect(status(2)).toBe('En línea');

        await act(async () => {
            channel?.whisperFrom('status', { user_id: 2, status: 'away' });
            channel?.joiningCallback?.({ id: 3, name: 'Eva', avatar: null });
        });
        expect(status(2)).toBe('Ausente');
        expect(status(3)).toBe('En línea');

        await act(async () => {
            channel?.leavingCallback?.({ id: 3, name: 'Eva', avatar: null });
        });
        expect(status(3)).toBe('Desconectado');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('ignora los whispers de quien no está en el canal según el servidor o se hace pasar por otro (D-120)', async () => {
        mocks.realtime = true;
        render(
            <>
                <RealtimeRoot />
                <People />
            </>,
        );
        const channel = mocks.echo?.presence('online');

        await act(async () => {
            channel?.hereCallback?.([
                { id: 1, name: 'Ana', avatar: null },
                { id: 2, name: 'Luis', avatar: null },
            ]);
        });

        await act(async () => {
            // Eva (3) no está conectada según el servidor: su «en línea» no cuenta.
            channel?.whisperFrom('status', { user_id: 3, status: 'online' });
            // Alguien (Reverb dice que es el 3) dice ser Luis: tampoco.
            channel?.whisperFrom(
                'status',
                { user_id: 2, status: 'away' },
                { user_id: '3' },
            );
        });
        expect(status(3)).toBe('Desconectado');
        expect(status(2)).toBe('En línea');

        // Luis de verdad (Reverb firma su user_id).
        await act(async () => {
            channel?.whisperFrom(
                'status',
                { user_id: 2, status: 'away' },
                { user_id: '2' },
            );
        });
        expect(status(2)).toBe('Ausente');
    });

    it('tras 5 minutos sin actividad pasa a ausente, lo avisa y vuelve con la actividad', async () => {
        mocks.realtime = true;
        render(
            <>
                <RealtimeRoot />
                <People />
            </>,
        );
        const channel = mocks.echo?.presence('online');
        await act(async () => {
            channel?.hereCallback?.([{ id: 1, name: 'Ana', avatar: null }]);
        });

        await act(async () => {
            vi.advanceTimersByTime(AWAY_AFTER_MS + 30_000);
        });
        expect(status(1)).toBe('Ausente');
        expect(channel?.whispers.at(-1)).toEqual({
            event: 'status',
            data: { user_id: 1, status: 'away' },
        });

        // Quien entra después se entera de que sigues ausente.
        await act(async () => {
            channel?.joiningCallback?.({ id: 2, name: 'Luis', avatar: null });
        });
        expect(channel?.whispers).toHaveLength(2);

        await act(async () => {
            window.dispatchEvent(new Event('keydown'));
        });
        expect(status(1)).toBe('En línea');
        expect(channel?.whispers.at(-1)).toEqual({
            event: 'status',
            data: { user_id: 1, status: 'online' },
        });
    });

    it('el punto del avatar lleva forma y texto, no solo color', async () => {
        mocks.realtime = true;
        render(
            <>
                <RealtimeRoot />
                <UserAvatar user={{ id: 2, name: 'Luis Pérez' }} showPresence />
                <PresenceDot userId={3} />
            </>,
        );
        await act(async () => {
            mocks.echo
                ?.presence('online')
                ?.hereCallback?.([{ id: 2, name: 'Luis Pérez', avatar: null }]);
        });

        const online = screen.getByRole('img', { name: 'En línea' });
        const offline = screen.getByRole('img', { name: 'Desconectado' });
        expect(online.getAttribute('data-status')).toBe('online');
        expect(offline.getAttribute('data-status')).toBe('offline');
        expect(screen.getByText('LP')).toBeTruthy();
    });
});
