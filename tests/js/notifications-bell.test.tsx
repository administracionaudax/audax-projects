// @vitest-environment jsdom
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const page = { props: { notifications: { unread: 3 } } };

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { post: vi.fn() },
    Link: ({
        children,
        href,
    }: {
        children: React.ReactNode;
        href: unknown;
    }) => (
        <a href={typeof href === 'string' ? href : '/notificaciones'}>
            {children}
        </a>
    ),
}));

import {
    NotificationBell,
    POLL_MS,
} from '@/components/notifications/notification-bell';

describe('NotificationBell', () => {
    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('anuncia el recuento de sin leer en su nombre accesible', () => {
        render(<NotificationBell />);

        expect(
            screen.getByRole('button', { name: 'Notificaciones: 3 sin leer' }),
        ).toBeTruthy();
    });

    it('al abrirla carga las recientes y las muestra', async () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    unread: 1,
                    notifications: [
                        {
                            id: 'a',
                            data: {
                                kind: 'task.assigned',
                                title: 'Te han asignado «Maquetar»',
                                body: null,
                                url: '/x',
                                icon: 'user-plus',
                            },
                            read_at: null,
                            created_at: '2026-09-26T10:00:00Z',
                        },
                    ],
                }),
                {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                },
            ),
        );
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

        render(<NotificationBell />);
        await user.click(
            screen.getByRole('button', { name: /Notificaciones/ }),
        );

        expect(
            await screen.findByText('Te han asignado «Maquetar»'),
        ).toBeTruthy();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(
            screen.getByRole('button', { name: 'Notificaciones: 1 sin leer' }),
        ).toBeTruthy();
    });

    it('consulta el recuento cada 60 segundos', async () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ unread: 7, notifications: [] }), {
                status: 200,
            }),
        );

        render(<NotificationBell />);

        await act(async () => {
            vi.advanceTimersByTime(POLL_MS);
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(
            await screen.findByRole('button', {
                name: 'Notificaciones: 7 sin leer',
            }),
        ).toBeTruthy();
    });
});
