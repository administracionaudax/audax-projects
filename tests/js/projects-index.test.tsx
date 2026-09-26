// @vitest-environment jsdom
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ProjectsIndex from '@/pages/projects/index';
import type {
    Abilities,
    ProjectListFilters,
    ProjectListItem,
    ProjectsIndexProps,
} from '@/types';

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    get: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ url: '/proyectos', props: inertia.props }),
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
    viewHourBanks: false,
    viewAdmin: false,
    viewFinancials: false,
    createClients: false,
    createProjects: false,
    approveTime: false,
    lockTime: false,
    manageUsers: false,
    manageSettings: false,
    ...overrides,
});

const filters: ProjectListFilters = {
    cliente: null,
    estado: '',
    tipo: null,
    responsable: null,
    departamento: null,
    buscar: '',
    mios: false,
};

function project(overrides: Partial<ProjectListItem> = {}): ProjectListItem {
    return {
        id: 1,
        code: 'ACME-WEB',
        name: 'Web corporativa',
        color: '#0171FF',
        description: null,
        client: { id: 3, name: 'Acme' },
        client_id: 3,
        billing_type: 'hour_bank',
        status: 'active',
        start_date: '2026-09-01',
        due_date: '2026-12-31',
        budget_minutes: null,
        owner: {
            id: 7,
            name: 'Laura Gómez',
            avatar: null,
            department_id: null,
            is_active: true,
        },
        owner_user_id: 7,
        is_internal: false,
        hour_banks: {
            open_count: 2,
            total_minutes: 600,
            consumed_minutes: 660,
            overage_minutes: 60,
        },
        ...overrides,
    };
}

function props(
    items: ProjectListItem[],
    overrides: Partial<ProjectsIndexProps> = {},
): ProjectsIndexProps {
    return {
        projects: {
            data: items,
            meta: {
                current_page: 1,
                last_page: 1,
                per_page: 25,
                total: items.length,
                from: items.length ? 1 : null,
                to: items.length || null,
            },
            links: { prev: null, next: null },
        },
        filters,
        options: {
            clients: [{ id: 3, name: 'Acme' }],
            owners: [{ id: 7, name: 'Laura Gómez' }],
            departments: [{ id: 1, name: 'Diseño' }],
        },
        ...overrides,
    };
}

beforeEach(() => {
    inertia.props = { auth: { user: null, can: can() } };
    inertia.get.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('listado de proyectos', () => {
    it('pinta cada proyecto con cliente, estado, tipo, gestor, consumo y exceso', () => {
        render(<ProjectsIndex {...props([project()])} />);

        const table = screen.getByRole('table');
        const row = within(table).getAllByRole('row')[1];

        expect(within(row).getByRole('link').getAttribute('href')).toBe(
            '/proyectos/1',
        );
        expect(row.textContent).toContain('ACME-WEB');
        expect(row.textContent).toContain('Acme');
        expect(row.textContent).toContain('Activo');
        expect(row.textContent).toContain('Bolsas de horas');
        expect(row.textContent).toContain('Laura Gómez');
        expect(row.textContent).toContain(
            '11:00 de 10:00 en 2 bolsas abiertas',
        );
        expect(row.textContent).toContain('+1:00 de exceso');
        expect(
            within(row).getByRole('meter').getAttribute('aria-valuetext'),
        ).toContain('1:00 de exceso');
    });

    it('un proyecto que no es de bolsas dice «No aplica»', () => {
        render(
            <ProjectsIndex
                {...props([
                    project({ billing_type: 'fixed_price', hour_banks: null }),
                ])}
            />,
        );

        expect(screen.getByRole('table').textContent).toContain('No aplica');
        expect(screen.queryByRole('meter')).toBeNull();
    });

    it('el botón «Nuevo proyecto» solo aparece a quien puede crear (D-022)', () => {
        const { unmount } = render(<ProjectsIndex {...props([project()])} />);
        expect(
            screen.queryByRole('link', { name: 'Nuevo proyecto' }),
        ).toBeNull();
        unmount();

        inertia.props = {
            auth: { user: null, can: can({ createProjects: true }) },
        };
        render(<ProjectsIndex {...props([project()])} />);
        expect(
            screen
                .getByRole('link', { name: 'Nuevo proyecto' })
                .getAttribute('href'),
        ).toBe('/proyectos/nuevo');
    });

    it('estado vacío sin proyectos y estado vacío distinto con filtros', () => {
        const { unmount } = render(<ProjectsIndex {...props([])} />);
        expect(screen.getByText('Todavía no hay proyectos')).toBeTruthy();
        expect(screen.queryByRole('search')).toBeNull();
        unmount();

        render(
            <ProjectsIndex
                {...props([], { filters: { ...filters, buscar: 'zzz' } })}
            />,
        );
        expect(
            screen.getByText('Ningún proyecto coincide con los filtros'),
        ).toBeTruthy();
        expect(screen.getByRole('search')).toBeTruthy();
    });

    it('«Solo mis proyectos» navega con el filtro en la URL', async () => {
        const user = userEvent.setup();
        render(<ProjectsIndex {...props([project()])} />);

        await user.click(
            screen.getByRole('switch', { name: 'Solo mis proyectos' }),
        );

        expect(inertia.get).toHaveBeenCalledWith(
            '/proyectos?mios=1',
            undefined,
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('la búsqueda espera a que dejes de escribir', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<ProjectsIndex {...props([project()])} />);

        await user.type(
            screen.getByRole('searchbox', { name: 'Buscar' }),
            'acme',
        );
        expect(inertia.get).not.toHaveBeenCalled();

        await act(async () => {
            vi.advanceTimersByTime(400);
        });

        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(inertia.get.mock.calls[0][0]).toBe('/proyectos?buscar=acme');
    });

    it('con filtros, «Quitar filtros» vuelve al listado por defecto', async () => {
        const user = userEvent.setup();
        render(
            <ProjectsIndex
                {...props([project()], {
                    filters: { ...filters, mios: true, tipo: 'internal' },
                })}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Quitar filtros' }),
        );

        expect(inertia.get.mock.calls[0][0]).toBe('/proyectos');
    });

    it('la paginación enlaza a la página siguiente conservando los filtros', () => {
        render(
            <ProjectsIndex
                {...props([project()], {
                    projects: {
                        data: [project()],
                        meta: {
                            current_page: 1,
                            last_page: 2,
                            per_page: 25,
                            total: 26,
                            from: 1,
                            to: 25,
                        },
                        links: {
                            prev: null,
                            next: '/proyectos?mios=1&pagina=2',
                        },
                    },
                })}
            />,
        );

        const nav = screen.getByRole('navigation', {
            name: 'Páginas de proyectos',
        });
        expect(nav.textContent).toContain('1–25 de 26');
        expect(nav.textContent).toContain('Página 1 de 2');
        expect(
            within(nav)
                .getByRole('link', { name: 'Siguiente' })
                .getAttribute('href'),
        ).toBe('/proyectos?mios=1&pagina=2');
        expect(
            (
                within(nav).getByRole('button', {
                    name: 'Anterior',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });
});
