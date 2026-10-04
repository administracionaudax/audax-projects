// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement, ReactNode } from 'react';
import { cloneElement } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import type {
    Abilities,
    MetricsSummary,
    ReportFiltersProps,
    User,
} from '@/types';

const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    get: vi.fn(),
    listeners: {} as Record<string, () => void>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    setLayoutProps: () => {},
    usePage: () => ({ url: '/', props: inertia.props }),
    router: {
        get: inertia.get,
        reload: vi.fn(),
        on: (event: string, callback: () => void) => {
            inertia.listeners[event] = callback;

            return () => {
                delete inertia.listeners[event];
            };
        },
    },
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string | { url: string };
        children?: ReactNode;
        [key: string]: unknown;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
}));

vi.mock('recharts', async (importOriginal) => {
    const actual = await importOriginal<typeof import('recharts')>();

    return {
        ...actual,
        ResponsiveContainer: ({
            children,
        }: {
            children: ReactElement<{ width?: number; height?: number }>;
        }) => cloneElement(children, { width: 640, height: 280 }),
    };
});

import { act } from 'react';
import { resetReportOptionsCache } from '@/components/reports/report-filter-bar';
import type {
    R2BillingProps,
    R2ClientReportProps,
    R2ProjectReportProps,
} from '@/components/reports/r2-types';
import BillingReport from '@/pages/reports/billing';
import ClientReport from '@/pages/reports/client';
import ProjectReport from '@/pages/reports/project';

// Páginas y gráficas completas: con la máquina cargada (CI) pueden pasar de los 5 s por defecto.
vi.setConfig({ testTimeout: 20_000 });

const norm = (value: string | null | undefined) =>
    (value ?? '').replace(/[  ]/g, ' ');

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

const user = (roles: User['roles']): User =>
    ({
        id: 7,
        name: 'Raúl',
        email: 'raul@example.com',
        avatar: null,
        theme_preference: 'system',
        two_factor_enabled: false,
        roles,
        is_client: false,
    }) as unknown as User;

const filters = (financials: boolean): ReportFiltersProps => ({
    query: { periodo: 'semana', fecha: '2026-09-21', persona: [3] },
    period: 'semana',
    from: '2026-09-21',
    to: '2026-09-27',
    compare: false,
    previous: { periodo: 'semana', fecha: '2026-09-14' },
    next: { periodo: 'semana', fecha: '2026-09-28' },
    comparison: null,
    can_see_financials: financials,
});

const summary = (financials: boolean): MetricsSummary => ({
    capacity_minutes: 0,
    logged_minutes: 940,
    billable_minutes: 910,
    in_bank_minutes: 600,
    overage_minutes: 190,
    occupancy: null,
    billability: 0.9681,
    billable_productivity: null,
    estimation: {
        tasks: 1,
        estimated_minutes: 240,
        actual_minutes: 400,
        accuracy: 0.6,
        deviation: 0.6667,
    },
    income: financials ? '1312.67' : null,
    cost: financials ? '390.00' : null,
    margin: financials ? '922.67' : null,
    margin_pct: financials ? 0.7029 : null,
});

const renderPage = (page: ReactNode) =>
    render(<TooltipProvider>{page}</TooltipProvider>);

beforeEach(() => {
    inertia.get.mockReset();
    inertia.listeners = {};
    inertia.props = {
        auth: { user: user(['admin']), can: can({ viewFinancials: true }) },
        config: { hour_bank_thresholds: [75, 90, 100] },
        errors: {},
    };
    resetReportOptionsCache();
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
        new Response(
            JSON.stringify({
                people: [{ id: 3, name: 'Ana' }],
                departments: [],
                clients: [],
                projects: [],
                hour_banks: [],
                task_types: [],
            }),
            { status: 200 },
        ),
    );
});

const clientProps = (financials: boolean): R2ClientReportProps => ({
    client: { id: 4, name: 'Bodega Ñandú', is_active: true },
    filters: filters(financials),
    report_request: {
        kind: 'client',
        route_params: { client: 4 },
        query: { periodo: 'semana', fecha: '2026-09-21', persona: [3] },
    },
    scope: { projects_only: false, team_only: !financials },
    summary: summary(financials),
    comparison: null,
    banked: { has_bank: true, in_bank_minutes: 600 },
    projects: [
        {
            key: '10',
            name: 'NAN-WEB · Web corporativa',
            color: null,
            logged_minutes: 790,
            billable_minutes: 790,
            in_bank_minutes: 600,
            overage_minutes: 190,
            income: financials ? '1196.67' : null,
            cost: financials ? '330.00' : null,
            has_bank: true,
        },
        {
            key: '11',
            name: 'NAN-CAMP · Campaña otoño',
            color: null,
            logged_minutes: 150,
            billable_minutes: 120,
            in_bank_minutes: 0,
            overage_minutes: 0,
            income: financials ? '116.00' : null,
            cost: financials ? '60.00' : null,
            has_bank: false,
        },
    ],
    timeline: {
        bucket: 'semana',
        buckets: ['2026-09-21'],
        series: [{ key: '10', name: 'NAN-WEB · Web corporativa', total: 790 }],
        cells: { '10': { '2026-09-21': 790 } },
    },
    banks: [
        {
            id: 2,
            project: { id: 10, code: 'NAN-WEB', name: 'Web corporativa' },
            name: 'Bolsa Diseño ñ',
            status: 'exhausted',
            start_date: '2026-09-01',
            end_date: null,
            total_minutes: 600,
            consumed_minutes: 790,
            overage_minutes: 190,
            in_bank_minutes: 600,
            remaining_minutes: 0,
            committed_minutes: 0,
            period_in_bank_minutes: 600,
            period_overage_minutes: 190,
        },
    ],
    history: [],
});

describe('informe de cliente', () => {
    it('con view-financials: KPIs económicos, gráfica, exportaciones con los filtros y PDF de cada bolsa', async () => {
        renderPage(<ClientReport {...clientProps(true)} />);

        expect(screen.getByRole('heading', { level: 1 }).textContent).toContain(
            'Bodega Ñandú',
        );
        const kpis = screen.getByRole('region', {
            name: 'Indicadores del periodo',
        });
        expect(within(kpis).getByText('Ingreso estimado')).toBeTruthy();
        expect(norm(within(kpis).getByText(/1\.312,67/).textContent)).toBe(
            '1.312,67 €',
        );
        expect(within(kpis).getByText('Margen: 70,3 %')).toBeTruthy();
        // Dentro de las bolsas: solo las horas de bolsas (D-078).
        expect(
            within(kpis).getByText('10:00 dentro de las bolsas'),
        ).toBeTruthy();
        // El exceso, en rojo (la tarjeta tiñe su valor).
        expect(
            within(kpis).getByText('+3:10').closest('[data-slot="card"]')
                ?.className,
        ).toContain('text-danger');

        // Filtros: sin el de cliente (fijo por la URL).
        expect(
            await screen.findByRole('combobox', { name: 'Personas: Ana' }),
        ).toBeTruthy();
        expect(screen.queryByRole('combobox', { name: /Clientes/ })).toBeNull();

        // Con view-financials, el PDF se ofrece para el cliente o con importes (uso interno).
        expect(
            screen.getByRole('button', {
                name: 'Descargar el PDF de consumo de Bolsa Diseño ñ',
            }),
        ).toBeTruthy();
        expect(
            screen.getByRole('link', { name: 'Horas para facturar' }),
        ).toHaveProperty(
            'href',
            expect.stringContaining('/informes/facturacion?'),
        );
        expect(
            screen.getByRole('link', { name: 'NAN-WEB · Web corporativa' }),
        ).toHaveProperty(
            'href',
            expect.stringContaining('/informes/proyectos/10?'),
        );
        expect(screen.getByText('Sin renovaciones')).toBeTruthy();

        // Resumen por proyecto: «Dentro de bolsa» no aplica al proyecto sin bolsas; el total, solo bolsas.
        const projectsTable = screen.getByRole('table', {
            name: 'Resumen por proyecto',
        });
        const camp = within(projectsTable)
            .getByRole('rowheader', { name: 'NAN-CAMP · Campaña otoño' })
            .closest('tr');
        expect(camp?.textContent).toContain('—');
        expect(within(camp as HTMLElement).getByText('Sin bolsa')).toBeTruthy();
        const totalRow = within(projectsTable)
            .getByRole('rowheader', { name: 'Total' })
            .closest('tr');
        expect(
            Array.from(totalRow?.querySelectorAll('td') ?? []).map(
                (cell) => cell.textContent,
            ),
        ).toEqual(expect.arrayContaining(['15:40', '15:10', '10:00']));

        // Exportaciones: la tabla y los mismos filtros de la URL.
        const user = userEvent.setup();
        await user.click(
            screen.getByRole('button', {
                name: 'Exportar el resumen por proyecto',
            }),
        );
        expect(
            screen.getByRole('menuitem', { name: 'Excel (.xlsx)' }),
        ).toHaveProperty(
            'href',
            expect.stringContaining(
                '/informes/clientes/4?periodo=semana&fecha=2026-09-21&persona%5B%5D=3&tabla=proyectos&formato=xlsx',
            ),
        );
        await user.keyboard('{Escape}');

        await user.click(
            screen.getByRole('button', {
                name: 'Descargar el PDF de consumo de Bolsa Diseño ñ',
            }),
        );
        expect(
            screen.getByRole('menuitem', { name: /^Para el cliente/ }),
        ).toHaveProperty(
            'href',
            expect.stringMatching(/\/proyectos\/10\/bolsas\/2\/pdf$/),
        );
        expect(
            screen.getByRole('menuitem', { name: /^Con importes/ }),
        ).toHaveProperty(
            'href',
            expect.stringContaining('/proyectos/10/bolsas/2/pdf?importes=1'),
        );
    });

    it('sin view-financials no hay importes, ni enlace para facturar; avisa del alcance', () => {
        inertia.props = {
            ...inertia.props,
            auth: {
                user: user(['department_manager']),
                can: can({ viewFinancials: false }),
            },
        };
        renderPage(<ClientReport {...clientProps(false)} />);

        expect(screen.queryByText('Ingreso estimado')).toBeNull();
        expect(screen.queryByText(/€/)).toBeNull();
        expect(
            screen.queryByRole('link', { name: 'Horas para facturar' }),
        ).toBeNull();
        // Sin view-financials, solo el PDF para el cliente (sin importes).
        expect(
            screen.getByRole('link', {
                name: 'Descargar el PDF de consumo de Bolsa Diseño ñ',
            }),
        ).toHaveProperty(
            'href',
            expect.stringMatching(/\/proyectos\/10\/bolsas\/2\/pdf$/),
        );
        expect(
            screen.getByText(
                'Ves las horas de las personas de tu departamento y todas las de los proyectos que gestionas.',
            ),
        ).toBeTruthy();
    });

    it('sin horas en el periodo lo dice en lugar de pintar la gráfica vacía', () => {
        const props = clientProps(true);
        renderPage(
            <ClientReport
                {...props}
                summary={{ ...props.summary, logged_minutes: 0 }}
                projects={[]}
                banks={[]}
            />,
        );

        expect(
            screen.getAllByText('No hay horas en este periodo'),
        ).toHaveLength(2);
        expect(
            screen.getByText('Este cliente no tiene bolsas en el periodo'),
        ).toBeTruthy();
    });

    it('mientras se actualiza lo anuncia y atenúa el informe; si falla la red, ofrece reintentar', async () => {
        renderPage(<ClientReport {...clientProps(true)} />);

        act(() => inertia.listeners.start?.());
        expect(screen.getByText('Actualizando el informe…')).toBeTruthy();
        expect(document.querySelector('[aria-busy="true"]')).not.toBeNull();

        act(() => inertia.listeners.networkError?.());
        expect(screen.queryByText('Actualizando el informe…')).toBeNull();
        expect(screen.getByRole('alert').textContent).toContain(
            'No se ha podido actualizar el informe',
        );
        expect(screen.getByRole('button', { name: 'Reintentar' })).toBeTruthy();
    });
});

const projectProps = (financials: boolean): R2ProjectReportProps => ({
    project: {
        id: 10,
        code: 'NAN-WEB',
        name: 'Web corporativa',
        color: '#0171FF',
        billing_type: 'hour_bank',
        status: 'active',
        budget_minutes: null,
        client: { id: 4, name: 'Bodega Ñandú' },
    },
    filters: filters(financials),
    report_request: {
        kind: 'project',
        route_params: { project: 10 },
        query: { periodo: 'semana', fecha: '2026-09-21', persona: [3] },
    },
    scope: { team_only: false },
    summary: summary(financials),
    comparison: financials ? { ...summary(true), logged_minutes: 470 } : null,
    byPerson: [
        {
            key: '3',
            name: 'Ana',
            color: null,
            logged_minutes: 390,
            billable_minutes: 390,
            in_bank_minutes: 200,
            overage_minutes: 190,
            income: financials ? '555.00' : null,
            cost: financials ? '130.00' : null,
        },
    ],
    byType: [],
    weekly: [
        {
            week: '2026-09-21',
            logged_minutes: 790,
            billable_minutes: 790,
            in_bank_minutes: 600,
            overage_minutes: 190,
            income: null,
        },
    ],
    estimates: {
        tasks: [],
        by_type: [],
        totals: {
            estimated_minutes: 0,
            actual_minutes: 0,
            other_minutes: 0,
            tasks: 0,
            estimated_tasks: 0,
            over_tasks: 0,
        },
    },
    tasks: {
        by_status: [],
        by_category: { todo: 0, in_progress: 0, done: 0 },
        overdue: 0,
        total: 0,
    },
    milestones: [],
});

describe('informe de proyecto', () => {
    it('KPIs con precisión de estimación y la variación frente al periodo anterior', () => {
        renderPage(<ProjectReport {...projectProps(true)} />);

        const kpis = screen.getByRole('region', {
            name: 'Indicadores del periodo',
        });
        expect(within(kpis).getByText('Precisión de estimación')).toBeTruthy();
        expect(norm(within(kpis).getByText('60 %').textContent)).toBe('60 %');
        expect(
            norm(within(kpis).getByText(/Desviación: \+66,7/).textContent),
        ).toBe('Desviación: +66,7 % en 1 tareas completadas');
        // 940 frente a 470: el doble.
        expect(
            norm(
                within(kpis).getByText(/más que en el periodo anterior/)
                    .textContent,
            ),
        ).toBe('100 % más que en el periodo anterior');
        expect(within(kpis).getByText('Rentabilidad')).toBeTruthy();
        // Proyecto de bolsas: el exceso con lo que va dentro de ellas.
        expect(
            within(kpis).getByText('10:00 dentro de las bolsas'),
        ).toBeTruthy();

        // Filtros sin cliente ni proyecto.
        expect(
            screen.queryByRole('combobox', { name: /Proyectos/ }),
        ).toBeNull();
        expect(
            screen.getByRole('link', { name: 'Informe de Bodega Ñandú' }),
        ).toHaveProperty(
            'href',
            expect.stringContaining('/informes/clientes/4?'),
        );
    });

    it('un proyecto por horas no habla de bolsas: ni en el exceso ni en las tablas', () => {
        const props = projectProps(false);
        renderPage(
            <ProjectReport
                {...props}
                project={{
                    ...props.project,
                    billing_type: 'time_and_materials',
                }}
            />,
        );

        const kpis = screen.getByRole('region', {
            name: 'Indicadores del periodo',
        });
        expect(within(kpis).queryByText(/dentro de las bolsas/)).toBeNull();
        expect(
            within(
                screen.getByRole('table', {
                    name: 'Horas por persona (tabla)',
                }),
            ).queryByRole('columnheader', { name: 'Dentro de bolsa' }),
        ).toBeNull();
    });

    it('estados vacíos de tareas, tipos e hitos; horas por persona con la tabla', () => {
        renderPage(<ProjectReport {...projectProps(false)} />);

        expect(screen.getByText('Este proyecto no tiene tareas')).toBeTruthy();
        expect(screen.getByText('Sin tareas')).toBeTruthy();
        expect(screen.getByText('Sin hitos')).toBeTruthy();
        expect(
            screen.getByRole('table', { name: 'Horas por persona (tabla)' }),
        ).toBeTruthy();
        expect(screen.queryByText('Rentabilidad')).toBeNull();
        expect(screen.queryByText(/€/)).toBeNull();
    });
});

const billingProps = (
    overrides: Partial<R2BillingProps> = {},
): R2BillingProps => ({
    filters: filters(true),
    report_request: {
        kind: 'billing',
        route_params: {},
        query: {
            periodo: 'semana',
            fecha: '2026-09-21',
            persona: [3],
            cliente: [4],
        },
    },
    client: null,
    clients: [
        { id: 4, name: 'Bodega Ñandú', is_active: true },
        { id: 5, name: 'Otro cliente', is_active: false },
    ],
    summary: null,
    scope: { team_only: false },
    export_limit: 19999,
    can: { viewReport: true },
    ...overrides,
});

describe('horas para facturar', () => {
    it('sin cliente pide elegir uno y no ofrece exportar', () => {
        renderPage(<BillingReport {...billingProps()} />);

        expect(screen.getAllByText('Elige un cliente').length).toBeGreaterThan(
            0,
        );
        expect(
            screen.queryByRole('button', { name: 'Exportar el detalle' }),
        ).toBeNull();
    });

    it('al elegir cliente visita la misma página con cliente[] y los filtros, sin los proyectos ni las bolsas del anterior', async () => {
        // Radix Select usa la captura del puntero, que jsdom no tiene.
        if (!('hasPointerCapture' in Element.prototype)) {
            Object.assign(Element.prototype, {
                hasPointerCapture: () => false,
                releasePointerCapture: () => {},
            });
        }
        const user = userEvent.setup();
        const base = filters(true);
        renderPage(
            <BillingReport
                {...billingProps({
                    filters: {
                        ...base,
                        query: {
                            ...base.query,
                            cliente: [5],
                            proyecto: [21],
                            bolsa: [30],
                        },
                    },
                })}
            />,
        );

        await user.click(screen.getByRole('combobox', { name: 'Cliente' }));
        await user.click(
            await screen.findByRole('option', { name: 'Bodega Ñandú' }),
        );

        expect(inertia.get).toHaveBeenCalledWith(
            '/informes/facturacion',
            {
                periodo: 'semana',
                fecha: '2026-09-21',
                persona: [3],
                cliente: [4],
            },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('con cliente: totales, aviso de horas sin aprobar y exportación del detalle', async () => {
        const user = userEvent.setup();
        renderPage(
            <BillingReport
                {...billingProps({
                    client: { id: 4, name: 'Bodega Ñandú', is_active: true },
                    summary: {
                        rows: [
                            {
                                project: {
                                    id: 10,
                                    code: 'NAN-CAMP',
                                    name: 'Campaña otoño',
                                    billing_type: 'time_and_materials',
                                },
                                bank: null,
                                logged_minutes: 150,
                                in_bank_minutes: 0,
                                overage_minutes: 0,
                                billable_minutes: 120,
                                non_billable_minutes: 30,
                                pending_minutes: 30,
                                pricing: 'hourly',
                                rate: '60.00',
                                price_amount: null,
                                income: '116.00',
                            },
                        ],
                        totals: {
                            entries: 3,
                            logged_minutes: 150,
                            in_bank_minutes: 0,
                            overage_minutes: 0,
                            billable_minutes: 120,
                            non_billable_minutes: 30,
                            pending_minutes: 30,
                            income: '116.00',
                        },
                    },
                })}
            />,
        );

        expect(
            screen.getByText(
                'Hay 0:30 sin aprobar: pueden cambiar antes de facturar.',
            ),
        ).toBeTruthy();
        // «Por horas»: el tipo de facturación del proyecto y cómo se valora.
        expect(screen.getAllByText('Por horas')).toHaveLength(2);

        await user.click(
            screen.getByRole('button', { name: 'Exportar el detalle' }),
        );
        expect(
            screen.getByRole('menuitem', { name: 'CSV (.csv)' }),
        ).toHaveProperty(
            'href',
            expect.stringContaining(
                '/informes/facturacion?periodo=semana&fecha=2026-09-21&persona%5B%5D=3&cliente%5B%5D=4&formato=csv',
            ),
        );
    });

    it('sin horas del cliente en el periodo lo dice', () => {
        renderPage(
            <BillingReport
                {...billingProps({
                    client: { id: 4, name: 'Bodega Ñandú', is_active: true },
                    summary: {
                        rows: [],
                        totals: {
                            entries: 0,
                            logged_minutes: 0,
                            in_bank_minutes: 0,
                            overage_minutes: 0,
                            billable_minutes: 0,
                            non_billable_minutes: 0,
                            pending_minutes: 0,
                            income: '0.00',
                        },
                    },
                })}
            />,
        );

        expect(
            screen.getByText('Bodega Ñandú no tiene horas en este periodo'),
        ).toBeTruthy();
    });
});

describe('horas para facturar: permisos y límite de la exportación', () => {
    const withClient = (
        overrides: Partial<R2BillingProps> = {},
    ): R2BillingProps =>
        billingProps({
            client: { id: 4, name: 'Bodega Ñandú', is_active: true },
            summary: {
                rows: [
                    {
                        project: {
                            id: 10,
                            code: 'NAN-CAMP',
                            name: 'Campaña otoño',
                            billing_type: 'time_and_materials',
                        },
                        bank: null,
                        logged_minutes: 150,
                        in_bank_minutes: 0,
                        overage_minutes: 0,
                        billable_minutes: 150,
                        non_billable_minutes: 0,
                        pending_minutes: 0,
                        pricing: 'hourly',
                        rate: '60.00',
                        price_amount: null,
                        income: '150.00',
                    },
                ],
                totals: {
                    entries: 25300,
                    logged_minutes: 150,
                    in_bank_minutes: 0,
                    overage_minutes: 0,
                    billable_minutes: 150,
                    non_billable_minutes: 0,
                    pending_minutes: 0,
                    income: '150.00',
                },
            },
            ...overrides,
        });

    it('el enlace al informe del cliente solo sale a quien puede verlo', () => {
        const { unmount } = renderPage(<BillingReport {...withClient()} />);
        expect(
            screen.getByRole('link', { name: 'Informe del cliente' }),
        ).toBeTruthy();
        unmount();

        renderPage(
            <BillingReport {...withClient({ can: { viewReport: false } })} />,
        );
        expect(
            screen.queryByRole('link', { name: 'Informe del cliente' }),
        ).toBeNull();
    });

    it('con más entradas de las que caben, avisa y no ofrece exportar', () => {
        renderPage(<BillingReport {...withClient()} />);

        expect(norm(screen.getByRole('status').textContent)).toContain(
            'Este periodo tiene 25.300 entradas y la exportación admite hasta 19.999.',
        );
        expect(
            screen.queryByRole('button', { name: 'Exportar el detalle' }),
        ).toBeNull();
    });

    it('si caben, no hay aviso y se puede exportar', () => {
        const props = withClient();
        renderPage(
            <BillingReport
                {...props}
                summary={
                    props.summary && {
                        ...props.summary,
                        totals: { ...props.summary.totals, entries: 19999 },
                    }
                }
            />,
        );

        expect(screen.queryByText(/la exportación admite hasta/)).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Exportar el detalle' }),
        ).toBeTruthy();
    });
});
