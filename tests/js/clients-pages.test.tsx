// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HourBankMeter } from '@/components/charts/hour-bank-meter';
import { hourBankAlerts, hourBankLevel } from '@/components/charts/thresholds';
import { TooltipProvider } from '@/components/ui/tooltip';
import ClientsIndex from '@/pages/clients/index';
import ClientShow from '@/pages/clients/show';
import type {
    Abilities,
    ClientHourBank,
    ClientListItem,
    ClientShowProps,
    ClientsIndexProps,
} from '@/types';

const page = vi.hoisted(() => ({
    url: '/clientes',
    props: {} as Record<string, unknown>,
}));

const inertia = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
        usePage: () => page,
        router: {
            get: inertia.get,
            post: inertia.post,
            on: () => () => {},
        },
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

const EMPLOYEE: Abilities = {
    viewHourBanks: false,
    viewAdmin: false,
    viewFinancials: false,
    createClients: false,
    createProjects: false,
    approveTime: false,
    lockTime: false,
    manageUsers: false,
    manageSettings: false,
};

function withAbilities(can: Partial<Abilities>, thresholds = [75, 90, 100]) {
    page.props = {
        auth: { user: null, can: { ...EMPLOYEE, ...can } },
        config: {
            hour_bank_thresholds: thresholds,
            timer_warning_hours: 10,
            timer_rounding_minutes: 1,
        },
    };
}

beforeEach(() => {
    withAbilities({});
    inertia.get.mockReset();
    inertia.post.mockReset();
});

const row = (overrides: Partial<ClientListItem> = {}): ClientListItem => ({
    id: 1,
    name: 'Hoteles Mediterráneo',
    tax_id: 'B12345678',
    contact_name: 'Ana',
    contact_email: 'ana@hoteles.es',
    phone: null,
    notes: null,
    is_active: true,
    active_projects_count: 2,
    month_minutes: 150,
    last_report_at: null,
    satisfaction_trend: null,
    kind_badges: [],
    ...overrides,
});

const indexProps = (
    data: ClientListItem[],
    filters: Partial<ClientsIndexProps['filters']> = {},
): ClientsIndexProps => ({
    clients: {
        data,
        links: { first: null, last: null, prev: null, next: null },
        meta: {
            current_page: 1,
            from: 1,
            last_page: 1,
            path: '/clientes',
            per_page: 25,
            to: data.length,
            total: data.length,
        },
    },
    filters: {
        q: '',
        estado: 'activos',
        orden: 'nombre',
        dir: 'asc',
        tipo: '',
        persona: '',
        mios: '',
        ...filters,
    },
    people: [],
    weekly: false,
});

describe('listado de clientes', () => {
    it('muestra proyectos activos, horas del mes en h:mm y estado con texto', () => {
        render(
            <ClientsIndex
                {...indexProps([
                    row(),
                    row({
                        id: 2,
                        name: 'Antiguo SA',
                        is_active: false,
                        month_minutes: 0,
                    }),
                ])}
            />,
        );

        const rows = within(
            screen.getByRole('table', { name: 'Clientes' }),
        ).getAllByRole('row');
        expect(
            within(rows[1])
                .getByRole('link', { name: 'Hoteles Mediterráneo' })
                .getAttribute('href'),
        ).toBe('/clientes/1');
        expect(within(rows[1]).getByText('2:30')).toBeTruthy();
        expect(within(rows[1]).getByText('Activo')).toBeTruthy();
        expect(within(rows[2]).getByText('Desactivado')).toBeTruthy();
    });

    it('«Nuevo cliente» solo para quien puede crearlos', () => {
        const { unmount } = render(<ClientsIndex {...indexProps([row()])} />);
        expect(
            screen.queryByRole('button', { name: 'Nuevo cliente' }),
        ).toBeNull();
        unmount();

        withAbilities({ createClients: true });
        render(<ClientsIndex {...indexProps([row()])} />);
        expect(
            screen.getByRole('button', { name: 'Nuevo cliente' }),
        ).toBeTruthy();
    });

    it('el diálogo pide la tarifa solo con acceso a los datos económicos', async () => {
        withAbilities({ createClients: true, viewFinancials: false });
        const { unmount } = render(<ClientsIndex {...indexProps([row()])} />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Nuevo cliente' }),
        );
        let dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByLabelText(/^Nombre/)).toBeTruthy();
        expect(within(dialog).queryByLabelText(/Tarifa por hora/)).toBeNull();
        unmount();

        withAbilities({ createClients: true, viewFinancials: true });
        render(<ClientsIndex {...indexProps([row()])} />);
        await userEvent.click(
            screen.getByRole('button', { name: 'Nuevo cliente' }),
        );
        dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByLabelText(/Tarifa por hora/)).toBeTruthy();
    });

    it('buscar espera a que dejes de escribir y filtra por estado al momento', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<ClientsIndex {...indexProps([row()])} />);

        await user.type(screen.getByLabelText('Buscar'), 'hot');
        expect(inertia.get).not.toHaveBeenCalled();
        vi.advanceTimersByTime(350);
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/clientes',
            { q: 'hot' },
            expect.anything(),
        );

        await user.selectOptions(screen.getByLabelText('Estado'), 'todos');
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/clientes',
            { q: 'hot', estado: 'todos' },
            expect.anything(),
        );
        vi.useRealTimers();
    });

    it('con la Weekly: icono, insignias, último reporte y satisfacción con tendencia; ordena y filtra (F-123 y F-124)', async () => {
        const user = userEvent.setup();
        render(
            <TooltipProvider>
                <ClientsIndex
                    {...indexProps([
                        row({
                            icon: '🍷',
                            satisfaction_score: 64,
                            satisfaction_trend: 4,
                            last_report_at: '2026-10-02T15:00:00+00:00',
                            kind_badges: [
                                { tag: 'web', count: 1 },
                                { tag: 'hour_bank', count: 2 },
                            ],
                        }),
                    ])}
                    people={[{ id: 7, name: 'Raúl' }]}
                    weekly
                />
            </TooltipProvider>,
        );

        const rows = within(
            screen.getByRole('table', { name: 'Clientes' }),
        ).getAllByRole('row');
        expect(within(rows[0]).getByText('Último reporte')).toBeTruthy();
        expect(within(rows[1]).getByText('02/10/2026')).toBeTruthy();
        expect(within(rows[1]).getByText('64 %')).toBeTruthy();
        expect(
            within(rows[1]).getByText(
                'Sube 4 puntos frente al cierre anterior',
            ),
        ).toBeTruthy();
        expect(within(rows[1]).getByText('2 Bolsas de horas')).toBeTruthy();
        expect(within(rows[0]).queryByText('Contacto')).toBeNull();

        await user.click(
            screen.getByRole('button', { name: 'Ordenar por Satisfacción' }),
        );
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/clientes',
            { orden: 'satisfaccion', dir: 'desc' },
            expect.anything(),
        );

        await user.selectOptions(
            screen.getByLabelText('Tipo de proyecto'),
            'hour_bank',
        );
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/clientes',
            expect.objectContaining({ tipo: 'hour_bank' }),
            expect.anything(),
        );

        await user.click(screen.getByLabelText('Mis proyectos'));
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/clientes',
            expect.objectContaining({ mios: '1' }),
            expect.anything(),
        );
    });

    it('sin clientes, un estado vacío', () => {
        render(<ClientsIndex {...indexProps([])} />);

        expect(screen.getByText('Aún no hay clientes')).toBeTruthy();
    });
});

const bank = (overrides: Partial<ClientHourBank> = {}): ClientHourBank => ({
    id: 5,
    project_id: 3,
    name: 'Bolsa Q4',
    department: { id: 1, name: 'Diseño', color: '#0171FF' },
    department_id: 1,
    total_minutes: 600,
    consumed_minutes: 480,
    overage_minutes: 0,
    remaining_minutes: 120,
    in_bank_minutes: 480,
    consumed_pct: 80,
    status: 'active',
    overage_policy: 'inherit',
    effective_overage_policy: 'allow',
    start_date: '2026-09-01',
    end_date: null,
    renewed_from_id: null,
    invoice_reference: null,
    notes: null,
    closed_at: null,
    closed_remaining_minutes: null,
    project: { id: 3, code: 'HOT-WEB', name: 'Web', color: '#0171FF' },
    committed_minutes: 180,
    ...overrides,
});

const showProps = (
    overrides: Partial<ClientShowProps> = {},
): ClientShowProps => ({
    client: {
        id: 1,
        name: 'Hoteles Mediterráneo',
        tax_id: 'B12345678',
        contact_name: 'Ana',
        contact_email: 'ana@hoteles.es',
        phone: '600 000 000',
        notes: 'Factura trimestral.',
        is_active: true,
    },
    projects: [
        {
            id: 3,
            code: 'HOT-WEB',
            name: 'Web',
            color: '#0171FF',
            description: null,
            client_id: 1,
            billing_type: 'hour_bank',
            status: 'active',
            start_date: null,
            due_date: null,
            budget_minutes: null,
            owner: {
                id: 2,
                name: 'Raúl',
                avatar: null,
                department_id: 1,
                is_active: true,
            },
            owner_user_id: 2,
            is_internal: false,
        },
    ],
    hourBanks: [bank()],
    hourBankHistory: [
        bank({
            id: 4,
            name: 'Bolsa Q3',
            status: 'renewed',
            consumed_minutes: 1260,
            total_minutes: 1200,
            overage_minutes: 60,
            end_date: '2026-08-31',
        }),
    ],
    hours: {
        month_minutes: 210,
        year_minutes: 1470,
        month_start: '2026-09-01',
        year: 2026,
    },
    can: { update: false },
    ...overrides,
});

describe('ficha de cliente', () => {
    it('resume las horas del mes y del año y lista proyectos, bolsas e histórico', () => {
        const { container } = render(<ClientShow {...showProps()} />);

        expect(container.querySelectorAll('h1')).toHaveLength(1);
        expect(screen.getByText('Horas de septiembre')).toBeTruthy();
        expect(screen.getByText('3:30')).toBeTruthy();
        expect(screen.getByText('24:30')).toBeTruthy();
        expect(
            screen.getByRole('link', { name: 'Web' }).getAttribute('href'),
        ).toBe('/proyectos/3');
        expect(
            screen.getByRole('meter', { name: 'Consumo de Bolsa Q4' }),
        ).toBeTruthy();
        expect(
            screen.getByRole('link', { name: 'Bolsa Q4' }).getAttribute('href'),
        ).toBe('/proyectos/3/bolsas/5');

        const history = screen.getByRole('table', {
            name: 'Bolsas renovadas y cerradas',
        });
        expect(within(history).getByText('+1:00')).toBeTruthy();
        expect(within(history).getByText('Renovada')).toBeTruthy();
        // Acceso al portal (Fase 5): prop diferida; mientras no llega, su estado de carga.
        expect(screen.getByText('Cargando el acceso al portal…')).toBeTruthy();
    });

    it('sus listas de definición son válidas: cada grupo dt/dd en un único div (UX-01, axe definition-list y dlitem)', () => {
        const { container } = render(<ClientShow {...showProps()} />);
        const lists = [...container.querySelectorAll('dl')];
        expect(lists.length).toBeGreaterThanOrEqual(2);

        for (const list of lists) {
            for (const group of list.children) {
                expect(['DIV', 'DT', 'DD']).toContain(group.tagName);

                if (group.tagName !== 'DIV') {
                    continue;
                }

                // Dentro del grupo solo dt, dd o adornos ocultos a los lectores de pantalla.
                for (const child of group.children) {
                    const decorative =
                        child.getAttribute('aria-hidden') === 'true';
                    expect(
                        decorative || ['DT', 'DD'].includes(child.tagName),
                        `${child.tagName} dentro de un grupo del <dl>`,
                    ).toBe(true);
                }
            }
        }
    });

    it('sin permiso no hay tarifa ni botones de edición', () => {
        render(<ClientShow {...showProps()} />);

        expect(screen.queryByText('Tarifa por hora (€)')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Editar' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Desactivar' })).toBeNull();
    });

    it('con permiso: tarifa, editar y desactivar con confirmación', async () => {
        withAbilities({ viewFinancials: true, createClients: true });
        render(
            <ClientShow
                {...showProps({
                    can: { update: true },
                    client: {
                        ...showProps().client,
                        default_hourly_rate: '55.00',
                    },
                })}
            />,
        );

        expect(screen.getByText(/55,00/)).toBeTruthy();
        await userEvent.click(
            screen.getByRole('button', { name: 'Desactivar' }),
        );
        const dialog = await screen.findByRole('dialog');
        await userEvent.click(
            within(dialog).getByRole('button', { name: 'Desactivar' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/clientes/1/desactivar',
            {},
            expect.anything(),
        );
    });

    it('la barra de consumo sigue los umbrales configurados', () => {
        withAbilities({}, [85, 100]);
        render(<ClientShow {...showProps()} />);

        // 80 % con el primer umbral en el 85 %: aún en margen (verde).
        expect(screen.getByText(/En margen/)).toBeTruthy();
    });

    it('sin proyectos ni bolsas, estados vacíos', () => {
        render(
            <ClientShow
                {...showProps({
                    projects: [],
                    hourBanks: [],
                    hourBankHistory: [],
                })}
            />,
        );

        expect(
            screen.getByText('Este cliente aún no tiene proyectos'),
        ).toBeTruthy();
        expect(screen.getByText('No tiene bolsas activas')).toBeTruthy();
        expect(
            screen.getByText('Aún no hay bolsas renovadas ni cerradas.'),
        ).toBeTruthy();
    });
});

describe('umbrales de la barra de consumo', () => {
    it('convierte los % configurados en proporciones ordenadas con el 100 %', () => {
        expect(hourBankAlerts([90, 75, 100])).toEqual([0.75, 0.9, 1]);
        expect(hourBankAlerts([50])).toEqual([0.5, 1]);
        expect(hourBankAlerts([100, 150])).toEqual([1]);
        expect(hourBankAlerts([])).toEqual([0.75, 0.9, 1]);
        expect(hourBankAlerts(undefined)).toEqual([0.75, 0.9, 1]);
    });

    it('el ámbar empieza en el primer umbral configurado', () => {
        expect(hourBankLevel(480, 600)).toBe('warning');
        expect(hourBankLevel(480, 600, 0.85)).toBe('ok');
        expect(hourBankLevel(600, 600, 0.85)).toBe('exhausted');
    });

    it('el medidor pinta una marca por umbral', () => {
        const { container } = render(
            <HourBankMeter
                name="Bolsa"
                consumed={100}
                total={600}
                thresholds={[50, 80, 100]}
            />,
        );

        expect(
            container.querySelectorAll(
                '[role="meter"] > span[aria-hidden="true"]',
            ),
        ).toHaveLength(3);
    });
});
