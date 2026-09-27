// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { NotificationBell } from '@/components/notifications/notification-bell';
import Notifications from '@/pages/notifications/index';
import type { NotificationsPageProps } from '@/types';

/*
| Enlace «Preferencias» a /ajustes/notificaciones (D-073) desde el desplegable de la campana y
| desde /notificaciones. Su nombre accesible completa el visible (WCAG 2.5.3).
*/

const page = vi.hoisted(() => ({
    url: '/notificaciones',
    props: { notifications: { unread: 0 } } as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
        usePage: () => page,
        router: { post: vi.fn(), get: vi.fn(), on: () => () => {} },
        Link: ({
            href,
            children,
            preserveScroll: _preserveScroll,
            prefetch: _prefetch,
            ...rest
        }: {
            href: string | { url: string };
            children?: ReactNode;
            preserveScroll?: boolean;
            prefetch?: boolean;
            [key: string]: unknown;
        }) => (
            <a href={typeof href === 'string' ? href : href.url} {...rest}>
                {children}
            </a>
        ),
    };
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('enlaces a las preferencias de notificación', () => {
    it('el desplegable de la campana lleva a las preferencias', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ unread: 0, notifications: [] }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }),
        );
        const user = userEvent.setup();

        render(<NotificationBell />);
        await user.click(
            screen.getByRole('button', { name: 'Notificaciones' }),
        );

        const link = await screen.findByRole('link', {
            name: 'Preferencias de notificación',
        });
        expect(link.getAttribute('href')).toBe('/ajustes/notificaciones');
        expect(link.textContent).toBe('Preferencias');
        expect(
            screen
                .getByRole('link', { name: 'Ver todas' })
                .getAttribute('href'),
        ).toBe('/notificaciones');
    });

    it('/notificaciones enlaza a las preferencias, también sin avisos', () => {
        const props: NotificationsPageProps = {
            items: {
                data: [],
                links: { first: null, last: null, prev: null, next: null },
                meta: {
                    current_page: 1,
                    last_page: 1,
                    per_page: 30,
                    total: 0,
                    from: null,
                    to: null,
                },
            },
            filter: 'all',
            unread: 0,
        };

        render(<Notifications {...props} />);

        const link = screen.getByRole('link', {
            name: 'Preferencias de notificación',
        });
        expect(link.getAttribute('href')).toBe('/ajustes/notificaciones');
        expect(link.textContent).toContain('Preferencias');
    });
});
