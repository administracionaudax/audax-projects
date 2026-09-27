// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement, ReactNode } from 'react';
import { cloneElement } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { MetricsSummary } from '@/types';

/*
 * R1 · Componentes de los dashboards de dirección, departamento y persona y de «Mis
 * indicadores»: cifras con sus formatos (h:mm, %, €), estados con icono y texto, estados vacíos,
 * enlaces entre informes y vistas de tabla accesibles de las gráficas.
 */

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({
        url: '/informes/direccion',
        props: { config: { hour_bank_thresholds: [75, 90, 100] } },
    }),
    router: { get: vi.fn(), on: () => () => {} },
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        [key: string]: unknown;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

/** En jsdom no hay layout: el ResponsiveContainer de Recharts se sustituye por un tamaño fijo. */
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

import { R1AtRiskBanks } from '@/components/reports/r1-at-risk-banks';
import {
    formatBarValue,
    percentTicks,
    R1BarChart,
    shortLabel,
} from '@/components/reports/r1-bar-chart';
import {
    breakdownBars,
    R1BreakdownTable,
} from '@/components/reports/r1-breakdown-table';
import { kpiView, R1KpiGrid } from '@/components/reports/r1-kpi-grid';
import { normalize, R1LinkList } from '@/components/reports/r1-link-list';
import {
    memberOccupancyLevel,
    occupancyLevel,
    R1MembersTable,
} from '@/components/reports/r1-members-table';
import {
    R1MyIndicators,
    shareRows,
} from '@/components/reports/r1-my-indicators';
import { R1OverdueTasks } from '@/components/reports/r1-overdue-tasks';
import {
    bucketLabel,
    bucketTitle,
    currencyTicks,
    EVOLUTION_SERIES,
    isFutureBucket,
    R1HoursTrendChart,
} from '@/components/reports/r1-trend-chart';
import type {
    MyIndicators,
    R1Member,
    R1Summary,
    R1TopRows,
} from '@/components/reports/r1-types';
import {
    R1UnloggedDays,
    UNLOGGED_VISIBLE,
} from '@/components/reports/r1-unlogged-days';
import { periodQuery, reportUrls } from '@/components/reports/r1-urls';
import { defineSeries } from '@/components/charts/chart-config';

/**
 * Las páginas enteras (gráficas, tablas, menús) tardan en montarse en jsdom; con la máquina
 * cargada, el límite por defecto de 5 s se queda corto.
 */
vi.setConfig({ testTimeout: 20_000 });

/** Intl usa espacios de no separación (U+00A0 / U+202F) antes de % y €. */
const norm = (value: string | null | undefined) =>
    (value ?? '').replace(/[  ]/g, ' ');

/** Resumen de Diseño en la semana del escenario calculado a mano (MetricsTest), ya cerrada. */
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

const withoutMoney: R1Summary = {
    ...summary,
    income: null,
    cost: null,
    margin: null,
    margin_pct: null,
};

describe('KPIs (R1KpiGrid y kpiView)', () => {
    it('formatea cada métrica y explica la precisión con su desviación', () => {
        expect(kpiView('logged', summary, null)).toEqual({
            value: '23:40',
            detail: 'De 60:00 de capacidad',
            delta: undefined,
        });
        expect(norm(kpiView('occupancy', summary, null).value)).toBe('39,4 %');
        expect(norm(kpiView('estimation', summary, null).detail)).toBe(
            'Desviación +40 % en 1 tareas completadas',
        );
        expect(norm(kpiView('margin', summary, null).detail)).toBe(
            'Margen del 71,6 % sobre el ingreso',
        );
        expect(
            kpiView(
                'estimation',
                {
                    ...summary,
                    estimation: {
                        tasks: 0,
                        estimated_minutes: 0,
                        actual_minutes: 0,
                        accuracy: null,
                        deviation: null,
                    },
                },
                null,
            ),
        ).toMatchObject({
            value: null,
            detail: 'Sin tareas completadas con estimación en el periodo',
        });
    });

    it('en un periodo en curso, la capacidad transcurrida hasta ayer es solo un dato: la ocupación es la del SPEC', () => {
        // Hoy es viernes: de lunes a jueves, 48 h de las 60 de la semana.
        const running: R1Summary = {
            ...summary,
            capacity_to_date_minutes: 2880,
        };

        expect(kpiView('capacity', summary, null).detail).toBeUndefined();
        expect(kpiView('capacity', running, null)).toMatchObject({
            value: '60:00',
            detail: 'Transcurrida hasta ayer: 48:00',
        });
        // Imputadas / capacidad del periodo completo (1420 / 3600), no contra las 48 h.
        expect(kpiView('logged', running, null).detail).toBe(
            'De 60:00 de capacidad',
        );
        expect(norm(kpiView('occupancy', running, null).value)).toBe('39,4 %');
    });

    it('avisa de que la variación compara con los mismos días del periodo anterior si sigue en curso', () => {
        const kpis = ['logged', 'occupancy'] as const;
        const { unmount } = render(
            <TooltipProvider>
                <R1KpiGrid
                    summary={summary}
                    comparison={{ ...summary, logged_minutes: 710 }}
                    comparisonPartial
                    kpis={[...kpis]}
                />
            </TooltipProvider>,
        );

        expect(
            screen.getByText(
                'El periodo sigue en curso: la variación compara con los mismos días del periodo anterior.',
            ),
        ).toBeTruthy();
        expect(
            norm(
                screen.getByText(/más que en el periodo anterior/).textContent,
            ),
        ).toBe('100 % más que en el periodo anterior');
        unmount();

        // Sin comparar, o con un periodo cerrado, no hay aviso.
        render(
            <TooltipProvider>
                <R1KpiGrid
                    summary={summary}
                    comparison={null}
                    comparisonPartial
                    kpis={[...kpis]}
                />
                <R1KpiGrid
                    summary={summary}
                    comparison={summary}
                    kpis={[...kpis]}
                />
            </TooltipProvider>,
        );

        expect(screen.queryByText(/sigue en curso/)).toBeNull();
    });

    it('compara con el periodo anterior: el coste que sube es a peor y la precisión no varía', () => {
        const previous: MetricsSummary = {
            ...summary,
            logged_minutes: 1000,
            cost: '500.00',
        };

        expect(kpiView('logged', summary, previous).delta).toEqual({
            current: 1420,
            previous: 1000,
            higherIsBetter: true,
        });
        expect(kpiView('cost', summary, previous).delta).toEqual({
            current: 600,
            previous: 500,
            higherIsBetter: false,
        });
        expect(kpiView('estimation', summary, previous).delta).toBeUndefined();
    });

    it('sin view-financials no pinta las tarjetas económicas', () => {
        const kpis = ['logged', 'occupancy', 'income', 'margin'] as const;
        const { unmount } = render(
            <TooltipProvider>
                <R1KpiGrid
                    summary={withoutMoney}
                    comparison={null}
                    kpis={[...kpis]}
                />
            </TooltipProvider>,
        );

        expect(
            screen
                .getAllByRole('heading', { level: 3 })
                .map((h) => h.textContent),
        ).toEqual(['Horas imputadas', 'Ocupación']);
        unmount();

        render(
            <TooltipProvider>
                <R1KpiGrid
                    summary={summary}
                    comparison={{ ...summary, income: '1000.00' }}
                    kpis={[...kpis]}
                />
            </TooltipProvider>,
        );

        expect(screen.getByText('Ingreso estimado')).toBeTruthy();
        expect(
            screen.getByRole('button', {
                name: 'Qué significa «Rentabilidad»',
            }),
        ).toBeTruthy();
        expect(
            norm(
                screen.getByText(/más que en el periodo anterior/).textContent,
            ),
        ).toBe('111 % más que en el periodo anterior');
    });
});

const top: R1TopRows = {
    rows: [
        {
            key: '7',
            name: 'Acme',
            color: null,
            logged_minutes: 700,
            billable_minutes: 700,
            in_bank_minutes: 600,
            overage_minutes: 100,
            income: '1116.67',
            cost: '350.00',
            margin: '766.67',
        },
        {
            key: null,
            name: 'Interno (sin cliente)',
            color: null,
            logged_minutes: 60,
            billable_minutes: 0,
            in_bank_minutes: 60,
            overage_minutes: 0,
            income: '0.00',
            cost: '30.00',
            margin: '-30.00',
        },
    ],
    others: {
        count: 3,
        logged_minutes: 90,
        billable_minutes: 90,
        in_bank_minutes: 90,
        overage_minutes: 0,
        income: '100.00',
        cost: '45.00',
        margin: '55.00',
    },
};

describe('repartos (R1BreakdownTable y breakdownBars)', () => {
    it('suma el resto como «Otros (n)» en las barras y en la tabla', () => {
        expect(breakdownBars(top)).toEqual([
            { id: '7', label: 'Acme', values: { logged: 700 } },
            {
                id: 'none',
                label: 'Interno (sin cliente)',
                values: { logged: 60 },
            },
            { id: 'others', label: 'Otros (3)', values: { logged: 90 } },
        ]);
    });

    it('enseña % del total, facturabilidad y dinero, y enlaza solo las filas con clave', () => {
        render(
            <R1BreakdownTable
                caption="Top 10 de clientes por horas"
                nameLabel="Cliente"
                top={top}
                totalMinutes={850}
                financials
                emptyLabel="Nada"
                href={(row) => (row.key === null ? null : `/c/${row.key}`)}
            />,
        );

        const table = screen.getByRole('table');
        const rows = within(table).getAllByRole('row');
        expect(
            within(rows[0])
                .getAllByRole('columnheader')
                .map((c) => c.textContent),
        ).toEqual([
            'Cliente',
            'Imputadas',
            '% del total',
            'Facturables',
            'Facturabilidad',
            'Ingreso',
            'Rentabilidad',
        ]);
        expect(norm(rows[1].textContent)).toBe(
            'Acme11:4082,4 %11:40100 %1.116,67 €766,67 €',
        );
        expect(within(rows[1]).getByRole('link').getAttribute('href')).toBe(
            '/c/7',
        );
        expect(within(rows[2]).queryByRole('link')).toBeNull();
        expect(norm(rows[2].textContent)).toContain('-30,00 €');
        expect(norm(rows[3].textContent)).toContain('Otros (3)');
    });

    it('sin datos económicos no pinta sus columnas; sin filas, lo dice', () => {
        const { unmount } = render(
            <R1BreakdownTable
                caption="x"
                nameLabel="Proyecto"
                top={top}
                totalMinutes={850}
                financials={false}
                emptyLabel="Nada"
            />,
        );

        expect(screen.getAllByRole('columnheader')).toHaveLength(5);
        unmount();

        render(
            <R1BreakdownTable
                caption="x"
                nameLabel="Proyecto"
                top={{ rows: [], others: null }}
                totalMinutes={0}
                financials={false}
                emptyLabel="No hay horas en este periodo con estos filtros."
            />,
        );
        expect(
            screen.getByText('No hay horas en este periodo con estos filtros.'),
        ).toBeTruthy();
    });
});

describe('gráficas de R1', () => {
    it('ayudas de ejes: marcas de %, etiquetas cortas y euros redondos', () => {
        expect(percentTicks(0.4)).toEqual([0, 0.25, 0.5, 0.75, 1]);
        expect(percentTicks(1.3)).toEqual([0, 0.25, 0.5, 0.75, 1, 1.25, 1.5]);
        expect(shortLabel('P1001 · Rediseño de la web corporativa')).toBe(
            'P1001 · Rediseño…',
        );
        expect(norm(formatBarValue('percent', 0.85))).toBe('85 %');
        expect(formatBarValue('minutes', 90)).toBe('1:30');
        expect(currencyTicks(0)).toEqual([0]);
        expect(currencyTicks(2111.67)).toEqual([0, 1000, 2000, 3000]);
        expect(bucketLabel('semana', '2026-09-21')).toBe('21/09');
        expect(bucketTitle('semana', '2026-09-21')).toBe(
            'Semana del 21/09/2026',
        );
        expect(bucketTitle('mes', '2026-09-01')).toBe('septiembre de 2026');
    });

    it('la evolución usa los colores en orden fijo (D-012), también el ingreso', () => {
        expect(
            EVOLUTION_SERIES.map((serie) => [serie.key, serie.color]),
        ).toEqual([
            ['logged', 'var(--chart-1)'],
            ['capacity', 'var(--chart-2)'],
            ['billable', 'var(--chart-3)'],
            ['income', 'var(--chart-4)'],
        ]);
    });

    it('la evolución tiene vista de tabla con la ocupación de cada periodo y no inventa horas futuras', async () => {
        const user = userEvent.setup();
        render(
            <R1HoursTrendChart
                title="Horas imputadas, facturables y capacidad"
                bucket="semana"
                today="2026-09-25"
                points={[
                    {
                        bucket: '2026-09-14',
                        logged_minutes: 0,
                        billable_minutes: 0,
                        capacity_minutes: 3600,
                        income: null,
                    },
                    {
                        bucket: '2026-09-21',
                        logged_minutes: 1420,
                        billable_minutes: 1360,
                        capacity_minutes: 3600,
                        income: null,
                    },
                    {
                        bucket: '2026-09-28',
                        logged_minutes: 0,
                        billable_minutes: 0,
                        capacity_minutes: 3600,
                        income: null,
                    },
                ]}
            />,
        );

        await user.click(screen.getByRole('button', { name: /tabla/i }));
        const rows = within(screen.getByRole('table')).getAllByRole('row');

        expect(norm(rows[2].textContent)).toBe(
            'Semana del 21/09/202623:4022:4060:0039,4 %',
        );
        // La semana siguiente aún no ha empezado: sin horas ni ocupación, con su capacidad.
        expect(norm(rows[3].textContent)).toBe('Semana del 28/09/2026——60:00—');
        expect(isFutureBucket('2026-09-28', '2026-09-25')).toBe(true);
        expect(isFutureBucket('2026-09-21', '2026-09-25')).toBe(false);
    });

    it('las barras de miembros van en % con su tabla accesible', async () => {
        const user = userEvent.setup();
        render(
            <R1BarChart
                title="Ocupación y facturabilidad por persona"
                categoryLabel="Persona"
                format="percent"
                series={defineSeries([
                    { key: 'occupancy', label: 'Ocupación' },
                    { key: 'billability', label: 'Facturabilidad' },
                ] as const)}
                rows={[
                    {
                        id: '1',
                        label: 'Luis',
                        values: { occupancy: 0.6333, billability: 0.9211 },
                    },
                    {
                        id: '2',
                        label: 'Zoe',
                        values: { occupancy: 0, billability: null },
                    },
                ]}
            />,
        );

        await user.click(screen.getByRole('button', { name: /tabla/i }));
        const rows = within(screen.getByRole('table')).getAllByRole('row');

        expect(norm(rows[1].textContent)).toBe('Luis63 %92 %');
        expect(norm(rows[2].textContent)).toBe('Zoe0 %—');
    });
});

const member = (overrides: Partial<R1Member>): R1Member => ({
    id: 1,
    name: 'Luis',
    is_active: true,
    capacity_minutes: 1200,
    capacity_to_date_minutes: 1200,
    logged_minutes: 760,
    billable_minutes: 700,
    occupancy: 0.6333,
    pace: null,
    billability: 0.9211,
    billable_productivity: 0.5833,
    income: null,
    cost: null,
    margin: null,
    ...overrides,
});

describe('miembros del departamento', () => {
    const thresholds = { low: 70, high: 110 };

    it('clasifica la ocupación frente a los umbrales', () => {
        expect(occupancyLevel(null, thresholds)).toBe('none');
        expect(occupancyLevel(0.6999, thresholds)).toBe('low');
        expect(occupancyLevel(0.7, thresholds)).toBe('ok');
        expect(occupancyLevel(1.1, thresholds)).toBe('ok');
        expect(occupancyLevel(0.7, { low: 70, high: 110 })).toBe('ok');
        expect(occupancyLevel(0.29, { low: 29, high: 110 })).toBe('ok');
        expect(occupancyLevel(1.11, thresholds)).toBe('high');
        // Con jornada en el periodo pero sin ningún día transcurrido (el primer día, o un periodo
        // futuro), aún no hay nivel: nadie sale «baja».
        expect(occupancyLevel(0, thresholds, true)).toBe('upcoming');
        expect(occupancyLevel(0, thresholds, false)).toBe('low');
    });

    it('en un periodo en curso, el nivel sale del ritmo (hasta ayer), no de la ocupación del periodo entero (D-080)', () => {
        // Periodo cerrado: la ocupación.
        expect(memberOccupancyLevel(member({}), thresholds)).toBe('low');
        // En curso: el ritmo. A mitad de mes, 36 % del mes entero pero 114 % de lo transcurrido.
        expect(
            memberOccupancyLevel(
                member({
                    capacity_minutes: 10560,
                    capacity_to_date_minutes: 3360,
                    occupancy: 0.3636,
                    pace: 1.1429,
                }),
                thresholds,
            ),
        ).toBe('high');
        expect(
            memberOccupancyLevel(
                member({ capacity_to_date_minutes: 960, pace: 0.7917 }),
                thresholds,
            ),
        ).toBe('ok');
        // Sin ningún día transcurrido con jornada, sin nivel.
        expect(
            memberOccupancyLevel(
                member({ capacity_to_date_minutes: 0, occupancy: 0 }),
                thresholds,
            ),
        ).toBe('upcoming');
        // Sin jornada en el periodo.
        expect(
            memberOccupancyLevel(
                member({
                    capacity_minutes: 0,
                    capacity_to_date_minutes: 0,
                    occupancy: null,
                }),
                thresholds,
            ),
        ).toBe('none');
    });

    it('en un periodo en curso enseña la capacidad hasta ayer, el ritmo y «Aún sin datos» si no ha pasado ningún día', () => {
        render(
            <R1MembersTable
                members={[
                    member({ capacity_to_date_minutes: 960, pace: 0.7917 }),
                    member({
                        id: 2,
                        name: 'Ana',
                        capacity_minutes: 2400,
                        capacity_to_date_minutes: 0,
                        logged_minutes: 0,
                        billable_minutes: 0,
                        occupancy: 0,
                        billability: null,
                        billable_productivity: 0,
                    }),
                ]}
                thresholds={thresholds}
                financials={false}
                personHref={(row) => `/informes/personas/${row.id}`}
            />,
        );

        const rows = within(screen.getByRole('table')).getAllByRole('row');
        expect(norm(rows[1].textContent)).toContain('20:00Hasta ayer: 16:00');
        // La ocupación es la del SPEC (760 / 1200); el nivel, el del ritmo (760 / 960 = 79,2 %).
        expect(norm(rows[1].textContent)).toContain(
            '63,3 %En rangoRitmo: 79,2 %',
        );
        expect(norm(rows[2].textContent)).toContain('Aún sin datos');
        expect(norm(rows[2].textContent)).not.toContain('Baja');
    });

    it('cada fila enlaza al informe de la persona y dice el estado con texto', () => {
        render(
            <R1MembersTable
                members={[
                    member({}),
                    member({
                        id: 2,
                        name: 'Ana',
                        occupancy: 1.2,
                        is_active: false,
                    }),
                    member({
                        id: 3,
                        name: 'Zoe',
                        capacity_minutes: 0,
                        logged_minutes: 0,
                        occupancy: null,
                        billability: null,
                    }),
                ]}
                thresholds={thresholds}
                financials={false}
                personHref={(row) => `/informes/personas/${row.id}`}
            />,
        );

        const rows = within(screen.getByRole('table')).getAllByRole('row');
        expect(
            within(rows[1])
                .getByRole('link', { name: 'Luis' })
                .getAttribute('href'),
        ).toBe('/informes/personas/1');
        expect(norm(rows[1].textContent)).toContain('63,3 %Baja');
        expect(norm(rows[2].textContent)).toContain('De baja');
        expect(norm(rows[2].textContent)).toContain('120 %Alta');
        expect(norm(rows[3].textContent)).toContain('—Sin jornada');
        expect(screen.queryByText('Ingreso')).toBeNull();
    });
});

describe('bolsas en riesgo y tareas vencidas', () => {
    it('cada bolsa con su barra y el enlace a su detalle; si no hay, lo dice', () => {
        const { unmount } = render(
            <R1AtRiskBanks
                atRisk={{
                    count: 2,
                    threshold: 75,
                    banks: [
                        {
                            id: 9,
                            name: 'Bolsa web',
                            status: 'exhausted',
                            project: {
                                id: 4,
                                code: 'P1001',
                                name: 'Web',
                                color: '#0171FF',
                            },
                            client: 'Acme',
                            total_minutes: 600,
                            consumed_minutes: 700,
                            overage_minutes: 100,
                            committed_minutes: 0,
                            ratio: 1,
                        },
                    ],
                }}
            />,
        );

        expect(
            screen
                .getByRole('link', { name: 'Bolsa web' })
                .getAttribute('href'),
        ).toBe('/proyectos/4/bolsas/9');
        expect(screen.getByText(/P1001 · Web · Acme/)).toBeTruthy();
        expect(screen.getByText('Se muestran 1 de 2.')).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: 'Ver todas en Bolsas' })
                .getAttribute('href'),
        ).toBe('/bolsas?proximas=1');
        unmount();

        render(
            <R1AtRiskBanks atRisk={{ count: 0, threshold: 80, banks: [] }} />,
        );
        expect(
            screen.getByText('Ninguna bolsa abierta llega al 80 % de consumo.'),
        ).toBeTruthy();
    });

    it('las vencidas con su retraso, su responsable y el enlace a la tarea', () => {
        render(
            <R1OverdueTasks
                overdue={{
                    count: 12,
                    tasks: [
                        {
                            id: 31,
                            title: 'Maquetar la home',
                            project_id: 4,
                            project: {
                                code: 'P1001',
                                name: 'Web',
                                color: '#0171FF',
                            },
                            assignee: null,
                            due_date: '2026-09-20',
                            days_overdue: 5,
                            is_milestone: true,
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByText('Tareas vencidas: 12')).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: /Maquetar la home/ })
                .getAttribute('href'),
        ).toBe('/proyectos/4/tareas?tarea=31');
        expect(screen.getByLabelText('Hito')).toBeTruthy();
        expect(screen.getByText('Sin asignar')).toBeTruthy();
        expect(screen.getByText('Vencía el 20/09/2026')).toBeTruthy();
        expect(screen.getByText('Días de retraso: 5')).toBeTruthy();
        expect(
            screen.getByText('Se muestran las 1 más antiguas de 12.'),
        ).toBeTruthy();
    });
});

describe('días sin imputar', () => {
    const days = Array.from({ length: UNLOGGED_VISIBLE + 2 }, (_, index) => ({
        date: `2026-09-${String(index + 1).padStart(2, '0')}`,
        capacity_minutes: 480,
        week: '2026-W36',
    }));

    it('enseña los primeros, el total y abre la hoja semanal de cada día', async () => {
        const user = userEvent.setup();
        render(
            <R1UnloggedDays
                days={days}
                timesheetHref={(day) => `/horas?semana=${day.week}&persona=5`}
            />,
        );

        expect(
            screen.getByText('Días sin imputar: 12 (96:00 de jornada)'),
        ).toBeTruthy();
        expect(
            within(screen.getByRole('list')).getAllByRole('listitem'),
        ).toHaveLength(UNLOGGED_VISIBLE);
        expect(
            within(screen.getByRole('list'))
                .getAllByRole('link')[0]
                .getAttribute('href'),
        ).toBe('/horas?semana=2026-W36&persona=5');

        await user.click(
            screen.getByRole('button', { name: 'Ver los 12 días' }),
        );
        expect(
            within(screen.getByRole('list')).getAllByRole('listitem'),
        ).toHaveLength(12);
        expect(
            screen
                .getByRole('button', { name: 'Ver menos' })
                .getAttribute('aria-expanded'),
        ).toBe('true');
    });

    it('sin días pendientes lo celebra con texto', () => {
        render(<R1UnloggedDays days={[]} timesheetHref={() => '/horas'} />);

        expect(
            screen.getByText('No hay días sin imputar en el periodo.'),
        ).toBeTruthy();
    });
});

describe('listas del índice', () => {
    const items = Array.from({ length: 9 }, (_, index) => ({
        id: index + 1,
        label: index === 0 ? 'Diseño' : `Departamento ${index + 1}`,
        href: `/informes/departamentos/${index + 1}`,
        muted: index === 1 ? 'Archivado' : null,
    }));

    it('busca sin tildes cuando la lista es larga', async () => {
        const user = userEvent.setup();
        render(
            <R1LinkList
                label="Departamentos"
                items={items}
                emptyLabel="Nada"
            />,
        );

        expect(normalize('Diseño')).toBe('diseno');
        expect(screen.getAllByRole('link')).toHaveLength(9);
        expect(screen.getByText('Archivado')).toBeTruthy();

        await user.type(
            screen.getByLabelText('Buscar en Departamentos'),
            'diseno',
        );
        expect(
            screen.getAllByRole('link').map((link) => link.textContent),
        ).toEqual(['Diseño']);

        await user.clear(screen.getByLabelText('Buscar en Departamentos'));
        await user.type(
            screen.getByLabelText('Buscar en Departamentos'),
            'zzz',
        );
        expect(screen.getByText('Nada coincide con «zzz».')).toBeTruthy();
    });

    it('sin elementos enseña el estado vacío y con pocos no ofrece buscador', () => {
        const { unmount } = render(
            <R1LinkList
                label="Clientes"
                items={[]}
                emptyLabel="No hay clientes."
            />,
        );
        expect(screen.getByText('No hay clientes.')).toBeTruthy();
        unmount();

        render(
            <R1LinkList
                label="Clientes"
                items={items.slice(0, 3)}
                emptyLabel="x"
            />,
        );
        expect(screen.queryByRole('searchbox')).toBeNull();
    });
});

describe('enlaces entre informes', () => {
    it('construye las URL en español con la query de la barra de filtros', () => {
        expect(reportUrls.index()).toBe('/informes');
        expect(reportUrls.direction()).toBe('/informes/direccion');
        expect(
            decodeURIComponent(
                reportUrls.person(5, {
                    periodo: 'semana',
                    fecha: '2026-09-21',
                    cliente: [1, 2],
                }),
            ),
        ).toBe(
            '/informes/personas/5?periodo=semana&fecha=2026-09-21&cliente[]=1&cliente[]=2',
        );
        expect(reportUrls.client(3)).toBe('/informes/clientes/3');
        expect(reportUrls.project(4)).toBe('/informes/proyectos/4');
        expect(reportUrls.detail({ persona: [5] })).toBe(
            '/informes/detalle?persona%5B%5D=5',
        );
        expect(
            periodQuery({
                periodo: 'rango',
                desde: '2026-09-01',
                hasta: '2026-09-10',
                comparar: '1',
                persona: [5],
            }),
        ).toEqual({
            periodo: 'rango',
            fecha: undefined,
            desde: '2026-09-01',
            hasta: '2026-09-10',
            comparar: '1',
        });
    });
});

describe('Mis indicadores (Inicio)', () => {
    const indicators: MyIndicators = {
        from: '2026-09-01',
        to: '2026-09-30',
        capacity_minutes: 10560,
        capacity_to_date_minutes: 10560,
        logged_minutes: 660,
        billable_minutes: 660,
        occupancy: 0.0625,
        billability: 1,
        estimation: {
            tasks: 1,
            estimated_minutes: 300,
            actual_minutes: 420,
            accuracy: 0.7143,
            deviation: 0.4,
        },
        clients: [
            { key: '1', name: 'Cliente por horas', logged_minutes: 420 },
            { key: '2', name: 'Otro', logged_minutes: 180 },
        ],
        projects: [
            {
                key: '3',
                name: 'P1 · Por horas',
                color: '#0171FF',
                logged_minutes: 420,
            },
        ],
    };

    it('reparte mis horas y agrupa lo que queda fuera del top en «El resto»', () => {
        expect(shareRows(indicators.clients, 660)).toEqual([
            { id: '1', name: 'Cliente por horas', minutes: 420 },
            { id: '2', name: 'Otro', minutes: 180 },
            { id: 'rest', name: 'El resto', minutes: 60 },
        ]);
        expect(shareRows(indicators.clients, 600)).toHaveLength(2);
    });

    it('enseña mis indicadores del mes y enlaza a mi informe', () => {
        render(
            <TooltipProvider>
                <R1MyIndicators indicators={indicators} userId={5} />
            </TooltipProvider>,
        );

        expect(
            screen.getByText('Del 01/09/2026 al 30/09/2026. Solo tus horas.'),
        ).toBeTruthy();
        const stats = screen.getAllByRole('definition');
        expect(norm(stats[0].textContent)).toBe('6,3 %');
        expect(stats[1].textContent).toBe('11:00 de 176:00');
        expect(norm(stats[2].textContent)).toBe('100 %');
        expect(screen.getByText('Mis horas por cliente')).toBeTruthy();
        // 420 de 660 min: el 64 % de mis horas, en el reparto por cliente y por proyecto.
        expect(screen.getAllByText(/^7:00 · 64 %$/)).toHaveLength(2);
        expect(screen.getAllByText('El resto')).toHaveLength(2);
        expect(
            screen
                .getByRole('link', { name: 'Ver mi informe completo' })
                .getAttribute('href'),
        ).toBe('/informes/personas/5');
        expect(screen.queryByText(/€/)).toBeNull();
    });

    it('con el mes en curso, la ocupación es contra el mes entero y la capacidad hasta ayer, un dato', () => {
        render(
            <TooltipProvider>
                <R1MyIndicators
                    indicators={{
                        ...indicators,
                        capacity_to_date_minutes: 8640,
                    }}
                    userId={5}
                />
            </TooltipProvider>,
        );

        const stats = screen.getAllByRole('definition');
        expect(norm(stats[0].textContent)).toBe('6,3 %');
        expect(stats[1].textContent).toBe(
            '11:00 de 176:00 (hasta ayer, 144:00 de capacidad)',
        );
    });

    it('sin horas ni tareas del mes enseña el estado vacío', () => {
        render(
            <TooltipProvider>
                <R1MyIndicators
                    indicators={{
                        ...indicators,
                        logged_minutes: 0,
                        billable_minutes: 0,
                        occupancy: 0,
                        billability: null,
                        estimation: {
                            tasks: 0,
                            estimated_minutes: 0,
                            actual_minutes: 0,
                            accuracy: null,
                            deviation: null,
                        },
                        clients: [],
                        projects: [],
                    }}
                    userId={5}
                />
            </TooltipProvider>,
        );

        expect(screen.getByText('Aún no tienes horas este mes.')).toBeTruthy();
        expect(
            screen.getByRole('link', { name: 'Ver mi informe completo' }),
        ).toBeTruthy();
    });
});
