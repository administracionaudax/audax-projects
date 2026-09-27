// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement, ReactNode } from 'react';
import { cloneElement } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { ReportFiltersProps } from '@/types';

/*
 * R1 · Páginas de informes: qué ve cada uno (D-044), datos económicos solo con view-financials,
 * enlaces entre informes y exportaciones con los filtros de la URL, estados vacíos y de error.
 */

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    listeners: {} as Record<string, (event: unknown) => void>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    setLayoutProps: () => {},
    usePage: () => ({
        url: '/informes',
        props: { config: { hour_bank_thresholds: [75, 90, 100] } },
    }),
    router: {
        get: inertia.get,
        on: (name: string, callback: (event: unknown) => void) => {
            inertia.listeners[name] = callback;

            return () => delete inertia.listeners[name];
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

import { resetReportOptionsCache } from '@/components/reports/report-filter-bar';
import type {
    DepartmentReportProps,
    DirectionReportProps,
    PersonReportProps,
    R1Summary,
    ReportIndexProps,
} from '@/components/reports/r1-types';
import DepartmentReport from '@/pages/reports/department';
import DirectionReport from '@/pages/reports/direction';
import ReportsIndex from '@/pages/reports/index';
import PersonReport from '@/pages/reports/person';

/**
 * Las páginas enteras (gráficas, tablas, menús) tardan en montarse en jsdom; con la máquina
 * cargada, el límite por defecto de 5 s se queda corto.
 */
vi.setConfig({ testTimeout: 20_000 });

const norm = (value: string | null | undefined) =>
    (value ?? '').replace(/[  ]/g, ' ');

const withTooltips = (node: ReactNode) => (
    <TooltipProvider>{node}</TooltipProvider>
);

beforeEach(() => {
    inertia.get.mockReset();
    resetReportOptionsCache();
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
        new Response(
            JSON.stringify({
                people: [],
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

const filters = (
    overrides: Partial<ReportFiltersProps> = {},
): ReportFiltersProps => ({
    query: { periodo: 'semana', fecha: '2026-09-21' },
    period: 'semana',
    from: '2026-09-21',
    to: '2026-09-27',
    compare: false,
    previous: { periodo: 'semana', fecha: '2026-09-14' },
    next: { periodo: 'semana', fecha: '2026-09-28' },
    comparison: null,
    can_see_financials: true,
    ...overrides,
});

const summary: R1Summary = {
    capacity_minutes: 3600,
    capacity_to_date_minutes: 3600,
    logged_minutes: 1420,
    billable_minutes: 1360,
    in_bank_minutes: 1320,
    overage_minutes: 100,
    occupancy: 0.3944,
    billability: 0.9577,
    billable_productivity: 0.3778,
    estimation: {
        tasks: 1,
        estimated_minutes: 300,
        actual_minutes: 420,
        accuracy: 0.7143,
        deviation: 0.4,
    },
    income: '2111.67',
    cost: '600.00',
    margin: '1511.67',
    margin_pct: 0.7159,
};

const noMoney: R1Summary = {
    ...summary,
    income: null,
    cost: null,
    margin: null,
    margin_pct: null,
};

const row = (
    key: string | null,
    name: string,
    minutes: number,
    money: boolean,
    linkable = key !== null,
) => ({
    key,
    linkable,
    name,
    color: null,
    logged_minutes: minutes,
    billable_minutes: minutes,
    in_bank_minutes: minutes,
    overage_minutes: 0,
    income: money ? '100.00' : null,
    cost: money ? '40.00' : null,
    margin: money ? '60.00' : null,
});

describe('índice de informes', () => {
    const base: ReportIndexProps = {
        me: { id: 5, name: 'Ana' },
        direction: false,
        billing: false,
        departments: [],
        clients: null,
        projects: null,
        people: [{ id: 5, name: 'Ana', department: 'Diseño', is_active: true }],
    };

    it('una empleada ve su informe y el detallado, nada más', () => {
        render(<ReportsIndex {...base} />);

        const links = screen.getAllByRole('link');
        expect(links.map((link) => link.getAttribute('href'))).toEqual([
            '/informes/personas/5',
            '/informes/detalle',
        ]);
        expect(screen.queryByText('Dirección')).toBeNull();
        expect(screen.queryByRole('heading', { name: 'Personas' })).toBeNull();
    });

    it('quien puede facturar ve también la exportación para facturar', () => {
        render(<ReportsIndex {...base} billing />);

        expect(
            screen
                .getByRole('link', { name: /Horas para facturar/ })
                .getAttribute('href'),
        ).toBe('/informes/facturacion');
    });

    it('un admin ve dirección, departamentos, clientes, proyectos y personas', () => {
        render(
            <ReportsIndex
                {...base}
                direction
                departments={[{ id: 2, name: 'Diseño', color: '#0171FF' }]}
                clients={[{ id: 3, name: 'Acme', is_active: false }]}
                projects={[
                    {
                        id: 4,
                        code: 'P1001',
                        name: 'Web',
                        color: '#0171FF',
                        client: null,
                        archived: true,
                    },
                ]}
                people={[
                    ...base.people,
                    {
                        id: 6,
                        name: 'Luis',
                        department: 'Diseño',
                        is_active: false,
                    },
                ]}
            />,
        );

        const href = (name: RegExp) =>
            screen.getByRole('link', { name }).getAttribute('href');

        expect(href(/^Dirección/)).toBe('/informes/direccion');
        expect(href(/^Diseño$/)).toBe('/informes/departamentos/2');
        expect(href(/^Acme/)).toBe('/informes/clientes/3');
        expect(href(/^P1001 · Web/)).toBe('/informes/proyectos/4');
        expect(href(/^Luis/)).toBe('/informes/personas/6');
        expect(screen.getByText('Interno · Archivado')).toBeTruthy();
        expect(screen.getByText('Desactivado')).toBeTruthy();
        expect(screen.getByText('Diseño · De baja')).toBeTruthy();
    });
});

const direction = (
    overrides: Partial<DirectionReportProps> = {},
): DirectionReportProps => ({
    filters: filters(),
    summary,
    comparison: null,
    comparison_partial: false,
    limited_to: null,
    series: {
        bucket: 'semana',
        points: [
            {
                bucket: '2026-09-21',
                logged_minutes: 1420,
                billable_minutes: 1360,
                capacity_minutes: 3600,
                income: '2111.67',
            },
        ],
    },
    departments: [row('2', 'Diseño', 1420, true)],
    clients: { rows: [row('3', 'Acme', 1420, true)], others: null },
    projects: { rows: [row('4', 'P1001 · Web', 1420, true)], others: null },
    at_risk: { count: 0, threshold: 75, banks: [] },
    overdue: { count: 0, tasks: [] },
    ...overrides,
});

describe('dashboard de dirección', () => {
    it('un admin ve los KPIs económicos, la evolución del ingreso y exporta cada reparto con los filtros', async () => {
        const user = userEvent.setup();
        render(withTooltips(<DirectionReport {...direction()} />));

        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
            'Dashboard de dirección',
        );
        expect(
            screen.getByRole('heading', { name: 'Ingreso estimado', level: 3 }),
        ).toBeTruthy();
        expect(
            screen.getByRole('heading', { name: 'Rentabilidad', level: 3 }),
        ).toBeTruthy();
        expect(screen.getAllByText('Ingreso estimado').length).toBeGreaterThan(
            1,
        );
        expect(
            screen.queryByText(/Ves los datos de tus departamentos/),
        ).toBeNull();

        expect(
            screen.getByRole('link', { name: 'Acme' }).getAttribute('href'),
        ).toBe('/informes/clientes/3?periodo=semana&fecha=2026-09-21');
        expect(
            screen.getByRole('link', { name: 'Diseño' }).getAttribute('href'),
        ).toBe('/informes/departamentos/2?periodo=semana&fecha=2026-09-21');
        expect(
            screen.getByText('Ninguna bolsa abierta llega al 75 % de consumo.'),
        ).toBeTruthy();
        expect(screen.getByText('No hay tareas vencidas.')).toBeTruthy();

        // Departamentos, top de clientes y top de proyectos.
        const exports = screen.getAllByRole('button', { name: 'Exportar' });
        expect(exports).toHaveLength(3);
        await user.click(exports[2]);
        expect(
            screen
                .getByRole('menuitem', { name: 'CSV (.csv)' })
                .getAttribute('href'),
        ).toBe(
            '/informes/direccion?periodo=semana&fecha=2026-09-21&tabla=proyectos&formato=csv',
        );
    });

    it('no enlaza los departamentos, clientes ni proyectos borrados, pero enseña sus horas', () => {
        render(
            withTooltips(
                <DirectionReport
                    {...direction({
                        departments: [
                            row('2', 'Diseño', 1000, true),
                            row('8', 'Antiguo', 420, true, false),
                        ],
                        clients: {
                            rows: [
                                row('3', 'Acme', 1000, true),
                                row('9', 'Borrado SL', 420, true, false),
                            ],
                            others: null,
                        },
                        projects: {
                            rows: [
                                row('10', 'P0001 · Viejo', 1420, true, false),
                            ],
                            others: null,
                        },
                    })}
                />,
            ),
        );

        expect(screen.getByRole('link', { name: 'Diseño' })).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Antiguo' })).toBeNull();
        expect(screen.getByRole('link', { name: 'Acme' })).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Borrado SL' })).toBeNull();
        expect(screen.getAllByText('Borrado SL').length).toBeGreaterThan(0);
        expect(
            screen.queryByRole('link', { name: 'P0001 · Viejo' }),
        ).toBeNull();
        expect(screen.getAllByText('P0001 · Viejo').length).toBeGreaterThan(0);
    });

    it('con el periodo en curso, avisa de que compara con los mismos días del anterior', () => {
        render(
            withTooltips(
                <DirectionReport
                    {...direction({
                        filters: filters({
                            compare: true,
                            comparison: {
                                from: '2026-09-14',
                                to: '2026-09-18',
                            },
                        }),
                        comparison: { ...summary, logged_minutes: 710 },
                        comparison_partial: true,
                    })}
                />,
            ),
        );

        expect(
            screen.getByText(
                'El periodo sigue en curso: la variación compara con los mismos días del periodo anterior.',
            ),
        ).toBeTruthy();
        expect(
            screen.getByText('Comparado con: del 14/09/2026 al 18/09/2026'),
        ).toBeTruthy();
    });

    it('un responsable ve el aviso de su alcance y ninguna cifra económica', () => {
        render(
            withTooltips(
                <DirectionReport
                    {...direction({
                        filters: filters({
                            can_see_financials: false,
                            query: {
                                periodo: 'semana',
                                fecha: '2026-09-21',
                                departamento: [2],
                            },
                        }),
                        summary: noMoney,
                        limited_to: ['Diseño'],
                        series: {
                            bucket: 'semana',
                            points: [
                                {
                                    bucket: '2026-09-21',
                                    logged_minutes: 1420,
                                    billable_minutes: 1360,
                                    capacity_minutes: 3600,
                                    income: null,
                                },
                            ],
                        },
                        departments: [row('2', 'Diseño', 1420, false)],
                        clients: {
                            rows: [row('3', 'Acme', 1420, false)],
                            others: null,
                        },
                        projects: {
                            rows: [row('4', 'P1', 1420, false)],
                            others: null,
                        },
                    })}
                />,
            ),
        );

        expect(
            screen.getByText('Ves los datos de tus departamentos: Diseño.'),
        ).toBeTruthy();
        expect(screen.queryByText('Ingreso estimado')).toBeNull();
        expect(screen.queryByText('Rentabilidad')).toBeNull();
        expect(screen.queryByText(/€/)).toBeNull();
    });

    it('sin horas enseña los estados vacíos de los repartos', () => {
        render(
            withTooltips(
                <DirectionReport
                    {...direction({
                        departments: [],
                        clients: { rows: [], others: null },
                        projects: { rows: [], others: null },
                    })}
                />,
            ),
        );

        expect(
            screen.getAllByText(
                'No hay horas en este periodo con estos filtros.',
            ),
        ).toHaveLength(4);
    });

    it('si falla la actualización, avisa sin salir de la página y permite reintentar', async () => {
        const user = userEvent.setup();
        window.history.replaceState({}, '', '/informes/direccion');
        render(withTooltips(<DirectionReport {...direction()} />));

        const visit = new URL(
            '/informes/direccion?periodo=mes',
            window.location.origin,
        );
        const { act } = await import('@testing-library/react');
        act(() => {
            inertia.listeners.start({
                detail: { visit: { method: 'get', url: visit } },
            });
        });
        expect(
            screen.getAllByText('Actualizando el informe…').length,
        ).toBeGreaterThan(0);

        const event = new Event('httpException', { cancelable: true });
        act(() => {
            inertia.listeners.httpException(event);
            inertia.listeners.finish({});
        });

        expect(event.defaultPrevented).toBe(true);
        expect(screen.getByRole('alert').textContent).toContain(
            'No se ha podido actualizar el informe.',
        );

        await user.click(screen.getByRole('button', { name: 'Reintentar' }));
        expect(inertia.get).toHaveBeenLastCalledWith(
            visit.href,
            undefined,
            expect.objectContaining({ preserveState: true }),
        );
    });
});

describe('dashboard de departamento', () => {
    const props: DepartmentReportProps = {
        department: { id: 2, name: 'Diseño', color: '#0171FF' },
        filters: filters({ can_see_financials: false }),
        summary: noMoney,
        comparison: null,
        comparison_partial: false,
        members: [
            {
                id: 6,
                name: 'Luis',
                is_active: true,
                capacity_minutes: 1200,
                capacity_to_date_minutes: 1200,
                logged_minutes: 760,
                billable_minutes: 700,
                occupancy: 0.6333,
                billability: 0.9211,
                billable_productivity: 0.5833,
                income: null,
                cost: null,
                margin: null,
            },
        ],
        clients: { rows: [row('3', 'Acme', 760, false)], others: null },
        occupancy_thresholds: { low: 70, high: 110 },
    };

    it('enseña los miembros con enlace a su informe, exporta la tabla y anuncia la carga futura', async () => {
        const user = userEvent.setup();
        render(withTooltips(<DepartmentReport {...props} />));

        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
            'Departamento de Diseño',
        );
        expect(
            screen.getByRole('link', { name: 'Luis' }).getAttribute('href'),
        ).toBe('/informes/personas/6?periodo=semana&fecha=2026-09-21');
        expect(
            norm(
                within(
                    screen.getByRole('table', {
                        name: 'Ocupación y facturabilidad de cada miembro',
                    }),
                ).getAllByRole('row')[1].textContent,
            ),
        ).toContain('63,3 %Baja');
        expect(
            screen.getByText(
                'Ocupación baja por debajo del 70 % y alta por encima del 110 %.',
            ),
        ).toBeTruthy();
        expect(screen.getByText('Carga futura')).toBeTruthy();
        expect(screen.getByText('Llega en la Fase 3')).toBeTruthy();

        await user.click(screen.getByRole('button', { name: 'Exportar' }));
        expect(
            screen
                .getByRole('menuitem', { name: 'Excel (.xlsx)' })
                .getAttribute('href'),
        ).toBe(
            '/informes/departamentos/2?periodo=semana&fecha=2026-09-21&formato=xlsx',
        );
    });

    it('con el periodo en curso explica que la ocupación cuenta todo el periodo y no enlaza clientes borrados', () => {
        render(
            withTooltips(
                <DepartmentReport
                    {...props}
                    summary={{ ...noMoney, capacity_to_date_minutes: 2880 }}
                    clients={{
                        rows: [row('9', 'Borrado SL', 760, false, false)],
                        others: null,
                    }}
                />,
            ),
        );

        expect(
            screen.getByText(
                /El periodo sigue en curso: la ocupación cuenta la capacidad de todo el periodo/,
            ),
        ).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Borrado SL' })).toBeNull();
    });

    it('sin miembros lo dice', () => {
        render(withTooltips(<DepartmentReport {...props} members={[]} />));

        expect(
            screen.getByText(
                'Este departamento no tiene personas en el periodo.',
            ),
        ).toBeTruthy();
    });
});

describe('dashboard de una persona', () => {
    const props: PersonReportProps = {
        person: {
            id: 6,
            name: 'Luis',
            is_active: true,
            department: { id: 2, name: 'Diseño', can_view: true },
        },
        is_self: false,
        filters: filters({ can_see_financials: false }),
        summary: noMoney,
        comparison: null,
        comparison_partial: false,
        clients: { rows: [row('3', 'Acme', 760, false)], others: null },
        projects: { rows: [], others: null },
        types: { rows: [row(null, 'Sin tipo', 760, false)], others: null },
        days: [
            { date: '2026-09-21', minutes: 0 },
            { date: '2026-09-22', minutes: 500 },
        ],
        unlogged: [
            { date: '2026-09-21', capacity_minutes: 240, week: '2026-W39' },
        ],
    };

    it('quien supervisa ve su informe con enlaces al departamento, al detallado y a su hoja semanal', () => {
        render(withTooltips(<PersonReport {...props} />));

        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
            'Informe de Luis',
        );
        expect(
            screen
                .getByRole('link', { name: 'Informe del departamento' })
                .getAttribute('href'),
        ).toBe('/informes/departamentos/2?periodo=semana&fecha=2026-09-21');
        expect(
            decodeURIComponent(
                screen
                    .getByRole('link', { name: 'Informe detallado' })
                    .getAttribute('href') ?? '',
            ),
        ).toBe('/informes/detalle?periodo=semana&fecha=2026-09-21&persona[]=6');
        expect(
            screen
                .getByRole('link', { name: /21\/09\/2026/ })
                .getAttribute('href'),
        ).toBe('/horas?semana=2026-W39&persona=6');
        expect(screen.getByText('Por proyecto')).toBeTruthy();
        expect(
            screen.queryByRole('heading', { name: 'Coste', level: 3 }),
        ).toBeNull();
    });

    it('en el propio informe se llama «Mi informe» y la hoja semanal es la suya', () => {
        render(
            withTooltips(
                <PersonReport
                    {...props}
                    is_self
                    person={{
                        ...props.person,
                        department: { id: 2, name: 'Diseño', can_view: false },
                    }}
                />,
            ),
        );

        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
            'Mi informe',
        );
        expect(
            screen.queryByRole('link', { name: 'Informe del departamento' }),
        ).toBeNull();
        expect(
            screen
                .getByRole('link', { name: /21\/09\/2026/ })
                .getAttribute('href'),
        ).toBe('/horas?semana=2026-W39');
    });
});
