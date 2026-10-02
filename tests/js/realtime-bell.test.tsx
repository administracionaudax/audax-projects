// @vitest-environment jsdom
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createFakeEcho, jsonResponse } from './realtime-fake-echo';
import type { FakeEcho } from './realtime-fake-echo';

const mocks = vi.hoisted(() => ({
    realtime: true,
    echo: null as FakeEcho | null,
    page: {
        props: {
            auth: { user: { id: 7, name: 'Luis' } },
            notifications: { unread: 2 },
        },
    },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => mocks.page,
    router: { post: vi.fn() },
    Link: ({ children }: { children: React.ReactNode }) => (
        <a href="/notificaciones">{children}</a>
    ),
}));
vi.mock('@laravel/echo-react', () => ({ echo: () => mocks.echo }));
vi.mock('@/lib/realtime', () => ({
    realtimeEnabled: () => mocks.realtime,
    configureRealtime: () => undefined,
}));

import {
    NotificationBell,
    POLL_MS,
} from '@/components/notifications/notification-bell';
import { resetRealtimeConnectionForTests } from '@/hooks/use-realtime-connection';

const notification = (id: string, title: string) => ({
    id,
    data: {
        kind: 'chat.direct',
        title,
        body: 'Hola',
        url: '/tiempo-real/conversaciones/3/abrir?mensaje=9',
        icon: 'message-square',
    },
    read_at: null,
    created_at: '2026-09-27T10:00:00Z',
});

describe('NotificationBell en tiempo real', () => {
    let fetchMock: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        mocks.realtime = true;
        mocks.echo = createFakeEcho();
        fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async () =>
            jsonResponse({
                unread: 2,
                notifications: [
                    notification('a', 'Te han asignado «Maquetar»'),
                ],
            }),
        );
    });

    afterEach(() => {
        resetRealtimeConnectionForTests();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('escucha el canal personal y no consulta cada 60 segundos', async () => {
        render(<NotificationBell />);

        expect(mocks.echo?.channel('private-App.Models.User.7')).toBeTruthy();

        await act(async () => {
            vi.advanceTimersByTime(POLL_MS * 3);
        });

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('una notificación nueva sube el recuento y aparece arriba de la lista abierta', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<NotificationBell />);
        await user.click(
            screen.getByRole('button', { name: /Notificaciones/ }),
        );
        expect(
            await screen.findByText('Te han asignado «Maquetar»'),
        ).toBeTruthy();

        await act(async () => {
            mocks.echo
                ?.channel('private-App.Models.User.7')
                ?.emit('.notification.created', {
                    notification: notification('b', 'Ana te ha escrito'),
                });
        });

        expect(
            screen.getByRole('button', { name: 'Notificaciones: 3 sin leer' }),
        ).toBeTruthy();
        const titles = screen
            .getAllByText(/Ana te ha escrito|Te han asignado/)
            .map((element) => element.textContent);
        expect(titles[0]).toContain('Ana te ha escrito');
    });

    it('si la conexión se corta vuelve a consultar, y al reconectar se pone al día', async () => {
        render(<NotificationBell />);

        await act(async () => {
            mocks.echo?.setStatus('disconnected');
        });
        await act(async () => {
            vi.advanceTimersByTime(POLL_MS);
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);

        await act(async () => {
            mocks.echo?.setStatus('connected');
        });
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('sin tiempo real sigue consultando cada 60 segundos', async () => {
        mocks.realtime = false;
        render(<NotificationBell />);

        await act(async () => {
            vi.advanceTimersByTime(POLL_MS);
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(mocks.echo?.channels.size).toBe(0);
    });
});
