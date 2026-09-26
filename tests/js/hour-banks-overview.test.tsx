// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import HourBanksIndex from '@/pages/hour-banks/index';
import type { Abilities, HourBanksIndexProps } from '@/types';

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    get: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ url: '/', props: inertia.props }),
    router: { get: inertia.get, on: () => () => {} },
    Link: ({
        href,
        children,
        preserveScroll: _preserveScroll,
        ...rest
    }: {
        href: string | { url: string };
        children?: ReactNode;
        preserveScroll?: boolean;
        [key: string]: unknown;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
}));

const can = (overrides: Partial<Abilities> = {}): Abilities => ({
    viewHourBanks: true,
    viewAdmin: false,
    viewFinancials: false,
    createClients: true,
    createProjects: true,
    approveTime: true,
    lockTime: false,
    manageUsers: false,
    manageSettings: false,
    ...overrides,
});

beforeEach(() => {
    inertia.props = { auth: { user: null, can: can() }, errors: {} };
    inertia.get.mockReset();
});

describe('vista global de bolsas', () => {
    const props: HourBanksIndexProps = {
        banks: {
            data: [],
            meta: {
                current_page: 1,
                last_page: 1,
                per_page: 25,
                total: 0,
                from: null,
                to: null,
            },
            links: { prev: null, next: null },
        },
        filters: {
            cliente: null,
            departamento: null,
            estado: '',
            proximas: false,
        },
        stats: { open: 4, exhausted: 1, near: 2 },
        threshold: 75,
        scope: 'managed',
        options: {
            clients: [{ id: 1, name: 'Acme' }],
            departments: [{ id: 2, name: 'Diseño' }],
        },
    };

    it('explica el alcance del gestor y resume las bolsas', () => {
        const { container } = render(<HourBanksIndex {...props} />);

        expect(container.querySelectorAll('h1')).toHaveLength(1);
        expect(container.textContent).toContain(
            'Las bolsas abiertas de los proyectos que gestionas',
        );
        expect(container.textContent).toContain('Al 75\u00a0% o más');
        expect(screen.getByText('No hay bolsas abiertas')).toBeTruthy();
    });

    it('«próximas a agotarse» se aplica con la URL', async () => {
        const user = userEvent.setup();
        render(<HourBanksIndex {...props} />);

        await user.click(
            screen.getByRole('switch', { name: /próximas a agotarse/ }),
        );

        expect(inertia.get).toHaveBeenCalledWith(
            '/bolsas?proximas=1',
            undefined,
            expect.objectContaining({ replace: true }),
        );
    });
});
