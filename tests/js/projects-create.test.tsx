// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ProjectCreate from '@/pages/projects/create';
import type { Abilities, ProjectCreateProps } from '@/types';

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

describe('alta de proyecto', () => {
    const props: ProjectCreateProps = {
        clients: [{ id: 3, name: 'Acme Corporación', is_active: true }],
        people: [
            { id: 7, name: 'Laura Gómez', department: 'Diseño' },
            { id: 8, name: 'Marc Puig', department: 'Desarrollo' },
        ],
        defaults: {
            color: '#179FA5',
            owner_user_id: 7,
            status: 'active',
            billing_type: 'internal',
        },
    };

    it('sugiere el código con el nombre mientras no se edite a mano', async () => {
        const user = userEvent.setup();
        render(<ProjectCreate {...props} />);

        const code = screen.getByLabelText('Código') as HTMLInputElement;

        await user.type(screen.getByLabelText('Nombre'), 'Formación interna');
        expect(code.value).toBe('FORMACIO');

        await user.clear(code);
        await user.type(code, 'form 26');
        expect(code.value).toBe('FORM-26');

        await user.type(screen.getByLabelText('Nombre'), ' 2026');
        expect(code.value).toBe('FORM-26');
    });

    it('un proyecto interno no pide cliente; el gestor principal ya cuenta como miembro', () => {
        render(<ProjectCreate {...props} />);

        expect(
            screen.getByText('Un proyecto interno no tiene cliente.'),
        ).toBeTruthy();

        const owner = screen.getByRole('checkbox', { name: /Laura Gómez/ });
        expect(owner.getAttribute('data-state')).toBe('checked');
        expect((owner as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByText('Personas elegidas: 0')).toBeTruthy();
    });

    it('la paleta de colores se elige con nombre, no solo con color', () => {
        render(<ProjectCreate {...props} />);

        expect(
            screen
                .getByRole('radio', { name: 'Turquesa' })
                .getAttribute('data-state'),
        ).toBe('checked');
        expect(screen.getByRole('radio', { name: 'Azul' })).toBeTruthy();
    });

    it('sin view-financials no hay campos económicos', () => {
        render(<ProjectCreate {...props} />);

        expect(screen.queryByText('Datos económicos')).toBeNull();
    });
});
