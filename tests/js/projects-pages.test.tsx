// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { formatPercent } from '@/lib/format';
import HourBankShow from '@/pages/projects/hour-bank';
import ProjectSettings from '@/pages/projects/settings';
import ProjectShow from '@/pages/projects/show';
import type {
    Abilities,
    HourBankShowProps,
    Project,
    ProjectMember,
    ProjectSettingsProps,
    ProjectShowProps,
} from '@/types';

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    patch: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    setLayoutProps: () => {},
    usePage: () => ({ url: '/proyectos/1', props: inertia.props }),
    router: {
        patch: inertia.patch,
        put: inertia.put,
        post: inertia.post,
        delete: inertia.delete,
        get: vi.fn(),
        on: () => () => {},
    },
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
    createClients: false,
    createProjects: false,
    approveTime: false,
    lockTime: false,
    manageUsers: false,
    manageSettings: false,
    ...overrides,
});

const project: Project = {
    id: 1,
    code: 'ACME-WEB',
    name: 'Web corporativa',
    color: '#0171FF',
    description: 'Rediseño completo',
    client: { id: 3, name: 'Acme' },
    client_id: 3,
    billing_type: 'hour_bank',
    status: 'active',
    start_date: '2026-09-01',
    due_date: null,
    budget_minutes: 600,
    owner: {
        id: 7,
        name: 'Laura Gómez',
        avatar: null,
        department_id: null,
        is_active: true,
    },
    owner_user_id: 7,
    is_internal: false,
};

function member(overrides: Partial<ProjectMember>): ProjectMember {
    return {
        id: 7,
        name: 'Laura Gómez',
        avatar: null,
        department_id: null,
        is_active: true,
        is_manager: true,
        is_owner: true,
        alert_preferences: {
            hour_bank_threshold: true,
            hour_bank_overage: true,
        },
        ...overrides,
    };
}

function levels(container: HTMLElement): number[] {
    return [...container.querySelectorAll('h1, h2, h3, h4')].map((h) =>
        Number(h.tagName.slice(1)),
    );
}

function expectOutline(container: HTMLElement) {
    const found = levels(container);

    expect(found.filter((level) => level === 1)).toHaveLength(1);
    expect(found[0]).toBe(1);
    found.slice(1).forEach((level, i) => {
        expect(level - found[i]).toBeLessThanOrEqual(1);
    });
}

beforeEach(() => {
    inertia.props = {
        auth: { user: null, can: can() },
        config: { hour_bank_thresholds: [75, 90, 100] },
        errors: {},
    };
    inertia.patch.mockReset();
    inertia.put.mockReset();
});

describe('resumen del proyecto', () => {
    const props: ProjectShowProps = {
        project,
        canManage: false,
        summary: {
            estimated_minutes: 480,
            logged_minutes: 540,
            budget_minutes: 600,
            open_tasks: 3,
            total_tasks: 5,
        },
        managers: [
            {
                id: 7,
                name: 'Laura Gómez',
                avatar: null,
                department_id: null,
                is_active: true,
            },
        ],
        membersCount: 4,
        hourBanks: [],
        activity: [
            {
                id: 1,
                actor: { id: 7, name: 'Laura Gómez' },
                text: 'creó la tarea «Maquetar»',
                url: '/proyectos/1/tareas?tarea=9',
                created_at: '2026-09-26T08:05:00Z',
            },
        ],
        milestones: {
            overdue: [],
            overdue_total: 0,
            upcoming: [
                {
                    id: 12,
                    project_id: 1,
                    title: 'Entrega al cliente',
                    due_date: '2026-10-16',
                    is_overdue: false,
                    days: 20,
                },
            ],
            undated_count: 0,
            today: '2026-09-26',
        },
    };

    it('un h1 (el proyecto) y secciones en h2; horas, presupuesto y actividad', () => {
        const { container } = render(<ProjectShow {...props} />);

        expectOutline(container);
        expect(container.querySelector('h1')?.textContent).toBe(
            'Web corporativa',
        );
        expect(container.textContent).toContain('9:00 / 8:00');
        expect(container.textContent).toContain(
            '1:00 por encima de lo estimado',
        );
        expect(container.textContent).toContain(
            `9:00 de 10:00 (${formatPercent(0.9, 0)})`,
        );
        expect(
            screen
                .getByRole('link', { name: 'creó la tarea «Maquetar»' })
                .getAttribute('href'),
        ).toBe('/proyectos/1/tareas?tarea=9');
        expect(container.textContent).toContain('26/09/2026 10:05');
        // Próximos hitos (Fase 4, D-062).
        expect(
            screen
                .getByRole('link', { name: 'Entrega al cliente' })
                .getAttribute('href'),
        ).toBe('/proyectos/1/tareas?tarea=12');
        expect(screen.getByText('No hay bolsas abiertas')).toBeTruthy();
    });
});

describe('ajustes del proyecto', () => {
    const settingsProps = (
        overrides: Partial<ProjectSettingsProps> = {},
    ): ProjectSettingsProps => ({
        project,
        canManage: true,
        members: [
            member({}),
            member({ id: 8, name: 'Ana Co', is_owner: false }),
            member({
                id: 9,
                name: 'Beatriz',
                is_owner: false,
                is_manager: false,
            }),
        ],
        clients: [{ id: 3, name: 'Acme', is_active: true }],
        people: [
            { id: 7, name: 'Laura Gómez', department: null },
            { id: 8, name: 'Ana Co', department: 'Diseño' },
            { id: 9, name: 'Beatriz', department: null },
            { id: 10, name: 'Carlos Nuevo', department: 'Desarrollo' },
        ],
        hasHourBanks: true,
        tasksWithoutBank: 0,
        departments: [{ id: 1, name: 'Diseño' }],
        overageDefault: 'allow',
        can: { manageMembers: true, archive: true, editAlertsOf: [7] },
        ...overrides,
    });

    it('un solo h1 y las secciones en h2', () => {
        const { container } = render(<ProjectSettings {...settingsProps()} />);

        expectOutline(container);
    });

    it('al gestor principal no se le puede quitar ni desmarcar; a los demás, sí', async () => {
        const user = userEvent.setup();
        const { container } = render(<ProjectSettings {...settingsProps()} />);

        const rows = [
            ...container.querySelectorAll<HTMLElement>(
                '[data-test="project-member"]',
            ),
        ];
        expect(
            within(rows[0]).queryByRole('button', { name: /Quitar/ }),
        ).toBeNull();
        expect(
            (
                within(rows[0]).getByRole('switch', {
                    name: 'Gestor',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        expect(
            within(rows[1]).getByRole('button', {
                name: 'Quitar a Ana Co del proyecto',
            }),
        ).toBeTruthy();

        await user.click(
            within(rows[2]).getByRole('switch', { name: 'Gestor' }),
        );

        expect(inertia.patch).toHaveBeenCalledWith(
            '/proyectos/1/miembros/9',
            { is_manager: true },
            expect.anything(),
        );
    });

    it('para añadir solo ofrece a quien aún no es miembro', async () => {
        render(<ProjectSettings {...settingsProps()} />);

        expect(screen.getByRole('button', { name: 'Añadir' })).toBeTruthy();
        expect(
            (
                screen.getByRole('button', {
                    name: 'Añadir',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });

    it('alertas: cada gestor solo cambia las suyas (D-023)', async () => {
        const user = userEvent.setup();
        const { container } = render(<ProjectSettings {...settingsProps()} />);

        const managers = [
            ...container.querySelectorAll<HTMLElement>(
                '[data-test="manager-alerts"]',
            ),
        ];
        expect(managers).toHaveLength(2);

        const own = within(managers[0]).getByRole('switch', {
            name: 'Horas en exceso',
        }) as HTMLButtonElement;
        const other = within(managers[1]).getByRole('switch', {
            name: 'Horas en exceso',
        }) as HTMLButtonElement;

        expect(own.disabled).toBe(false);
        expect(other.disabled).toBe(true);
        expect(managers[1].textContent).toContain(
            'solo puede cambiarlas su gestor o administración',
        );

        await user.click(own);

        expect(inertia.put).toHaveBeenCalledWith(
            '/proyectos/1/miembros/7/alertas',
            { alerts: { hour_bank_overage: false } },
            expect.anything(),
        );
    });

    it('avisa de que un proyecto con bolsas no puede dejar de serlo y ofrece archivar', () => {
        render(<ProjectSettings {...settingsProps()} />);

        expect(
            screen.getByRole('button', { name: 'Archivar el proyecto' }),
        ).toBeTruthy();
        expect(screen.queryByText('Proyecto archivado')).toBeNull();
    });

    it('un proyecto archivado se anuncia y se ofrece recuperarlo, sin selector de estado', () => {
        render(
            <ProjectSettings
                {...settingsProps({
                    project: { ...project, status: 'archived' },
                })}
            />,
        );

        expect(screen.getByText('Proyecto archivado')).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Recuperar el proyecto' }),
        ).toBeTruthy();
        expect(screen.queryByRole('combobox', { name: 'Estado' })).toBeNull();
    });

    it('al pasar a bolsas un proyecto con tareas, pide los datos de su primera bolsa', async () => {
        // Radix Select usa la captura del puntero, que jsdom no tiene.
        if (!('hasPointerCapture' in Element.prototype)) {
            Object.assign(Element.prototype, {
                hasPointerCapture: () => false,
                releasePointerCapture: () => {},
            });
        }
        const user = userEvent.setup();

        render(
            <ProjectSettings
                {...settingsProps({
                    project: { ...project, billing_type: 'time_and_materials' },
                    hasHourBanks: false,
                    tasksWithoutBank: 3,
                })}
            />,
        );

        expect(
            screen.queryByRole('region', { name: 'Primera bolsa de horas' }),
        ).toBeNull();

        await user.click(
            screen.getByRole('combobox', { name: 'Tipo de facturación' }),
        );
        await user.click(
            await screen.findByRole('option', { name: 'Bolsas de horas' }),
        );

        const section = screen.getByRole('region', {
            name: 'Primera bolsa de horas',
        });
        expect(section.textContent).toContain(
            'El proyecto ya tiene tareas (3)',
        );
        expect(within(section).getByLabelText('Nombre')).toBeTruthy();
        expect(within(section).getByLabelText('Total de horas')).toBeTruthy();
    });

    it('sin tareas, o si ya es de bolsas, no pide ninguna bolsa', () => {
        render(<ProjectSettings {...settingsProps({ tasksWithoutBank: 0 })} />);

        expect(
            screen.queryByRole('region', { name: 'Primera bolsa de horas' }),
        ).toBeNull();
    });

    it('sin view-financials no hay campos económicos', () => {
        const { unmount } = render(<ProjectSettings {...settingsProps()} />);
        expect(screen.queryByLabelText('Tarifa por hora')).toBeNull();
        unmount();

        inertia.props = {
            ...inertia.props,
            auth: { user: null, can: can({ viewFinancials: true }) },
        };
        render(<ProjectSettings {...settingsProps()} />);
        expect(screen.getByLabelText('Tarifa por hora')).toBeTruthy();
    });
});

describe('detalle de bolsa', () => {
    const detailProps = (
        overrides: Partial<HourBankShowProps> = {},
    ): HourBankShowProps => ({
        project,
        canManage: false,
        bank: {
            id: 5,
            project_id: 1,
            name: 'Bolsa T4',
            department: null,
            department_id: null,
            total_minutes: 600,
            consumed_minutes: 300,
            overage_minutes: 0,
            remaining_minutes: 300,
            in_bank_minutes: 300,
            consumed_pct: 50,
            status: 'active',
            overage_policy: 'block',
            effective_overage_policy: 'block',
            start_date: '2026-10-01',
            end_date: null,
            renewed_from_id: null,
            invoice_reference: null,
            notes: null,
            closed_at: null,
            closed_remaining_minutes: null,
            committed_minutes: 0,
            open_tasks_count: 0,
            renewed_from: null,
            renewal: null,
            closed_by: null,
            can: {
                update: false,
                renew: false,
                close: false,
                reopen: false,
                delete: false,
            },
        },
        weekly: [],
        byPerson: null,
        byType: [],
        entries: {
            data: [],
            meta: {
                current_page: 1,
                last_page: 1,
                per_page: 20,
                total: 0,
                from: null,
                to: null,
            },
            links: { prev: null, next: null },
        },
        tasks: [],
        departments: [],
        overageDefault: 'allow',
        ...overrides,
    });

    it('un empleado ve que solo tiene sus entradas y no el reparto por persona', () => {
        const { container } = render(<HourBankShow {...detailProps()} />);

        expectOutline(container);
        expect(
            screen.getByText('Solo ves tus propias entradas de esta bolsa.'),
        ).toBeTruthy();
        expect(
            screen.queryByRole('heading', { name: 'Por persona' }),
        ).toBeNull();
        expect(
            screen.getByText('No has imputado horas en esta bolsa'),
        ).toBeTruthy();
        expect(
            screen.queryByRole('button', {
                name: /Renovar|Cerrar la bolsa|Editar/,
            }),
        ).toBeNull();
        expect(container.textContent).toContain('No admite exceso');
    });

    it('quien gestiona ve el reparto por persona y las acciones', () => {
        render(
            <HourBankShow
                {...detailProps({
                    canManage: true,
                    byPerson: [
                        {
                            user: {
                                id: 7,
                                name: 'Laura Gómez',
                                avatar: null,
                                department_id: null,
                                is_active: true,
                            },
                            minutes: 300,
                            overage_minutes: 0,
                        },
                    ],
                    bank: {
                        ...detailProps().bank,
                        consumed_minutes: 480,
                        remaining_minutes: 120,
                        in_bank_minutes: 480,
                        consumed_pct: 80,
                        can: {
                            update: true,
                            renew: true,
                            close: true,
                            reopen: false,
                            delete: false,
                        },
                    },
                })}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Por persona' }),
        ).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Renovar' })).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Cerrar la bolsa' }),
        ).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Editar' })).toBeTruthy();
        expect(
            screen.queryByText('Solo ves tus propias entradas de esta bolsa.'),
        ).toBeNull();
    });
});
