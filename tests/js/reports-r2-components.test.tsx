// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement, ReactNode } from 'react';
import { cloneElement } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { BreakdownRow } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: { get: vi.fn(), reload: vi.fn(), on: () => () => {} },
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

/** En jsdom no hay layout: el ResponsiveContainer se sustituye por un tamaño fijo. */
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

import { R2BillingTable } from '@/components/reports/r2-billing-table';
import {
    R2BreakdownTable,
    R2OverageValue,
} from '@/components/reports/r2-breakdown-table';
import { R2RenewalHistory } from '@/components/reports/r2-client-banks';
import {
    R2Deviation,
    R2EstimateTable,
} from '@/components/reports/r2-estimate-table';
import {
    bucketLabel,
    deviation,
    fromCents,
    marginRatio,
    monthName,
    subtractMoney,
    sumMoney,
    toCents,
} from '@/components/reports/r2-helpers';
import { barRows, R2HoursBars } from '@/components/reports/r2-hours-bars';
import {
    R2Milestones,
    R2TaskStatus,
} from '@/components/reports/r2-project-status';
import {
    groupSeries,
    R2_OTHERS_KEY,
    R2StackedBarsChart,
} from '@/components/reports/r2-stacked-bars-chart';
import type {
    R2BillingSummary,
    R2ClientBank,
    R2Estimates,
} from '@/components/reports/r2-types';

// Intl usa espacios de no separación (U+00A0 / U+202F) antes de % y de €.
// Páginas y gráficas completas: con la máquina cargada (CI) pueden pasar de los 5 s por defecto.
vi.setConfig({ testTimeout: 20_000 });

const norm = (value: string | null | undefined) =>
    (value ?? '').replace(/[  ]/g, ' ');

const breakdown = (overrides: Partial<BreakdownRow>): BreakdownRow => ({
    key: '1',
    name: 'NAN-WEB · Web corporativa',
    color: null,
    logged_minutes: 790,
    billable_minutes: 790,
    in_bank_minutes: 600,
    overage_minutes: 190,
    income: '1221.67',
    cost: '330.00',
    ...overrides,
});

describe('utilidades de R2', () => {
    it('suma importes en céntimos, sin errores de coma flotante', () => {
        expect(toCents('1221.67')).toBe(122167);
        expect(toCents(null)).toBe(0);
        expect(fromCents(-5)).toBe('-0.05');
        expect(sumMoney(['0.10', '0.20', null, '1221.67'])).toBe('1221.97');
        expect(subtractMoney('1337.67', '390.00')).toBe('947.67');
        expect(marginRatio('1337.67', '390.00')).toBeCloseTo(0.7084, 4);
        expect(marginRatio('0.00', '10.00')).toBeNull();
    });

    it('etiqueta los cubos de tiempo sin cambiar de zona horaria', () => {
        expect(bucketLabel('2026-09-21', 'semana')).toBe('21/09');
        expect(bucketLabel('2026-09-01', 'mes')).toMatch(/sept?\s2026/);
        expect(monthName('2026-09-01')).toBe('septiembre de 2026');
    });

    it('calcula la desviación sobre lo estimado', () => {
        expect(deviation(null, 300)).toBeNull();
        expect(deviation(240, 400)).toEqual({
            minutes: 160,
            ratio: 160 / 240,
        });
        expect(deviation(0, 30)).toEqual({ minutes: 30, ratio: null });
    });

    it('agrupa en «Otros» a partir de la 6.ª serie (D-012: la paleta no se cicla)', () => {
        const series = Array.from({ length: 8 }, (_, i) => ({
            key: String(i + 1),
            label: `P${i + 1}`,
        }));
        const values = Object.fromEntries(
            series.map((serie, i) => [
                serie.key,
                { '2026-09-01': 10 * (i + 1) },
            ]),
        );

        const grouped = groupSeries(series, values, 'Otros');

        expect(grouped.series.map((serie) => serie.label)).toEqual([
            'P1',
            'P2',
            'P3',
            'P4',
            'P5',
            'Otros',
        ]);
        // P6 + P7 + P8 = 60 + 70 + 80.
        expect(grouped.values[R2_OTHERS_KEY]).toEqual({ '2026-09-01': 210 });
        expect(
            groupSeries(series.slice(0, 6), values, 'Otros').series,
        ).toHaveLength(6);
    });

    it('limita las barras a 10 y suma el resto en «Otros»', () => {
        const rows = Array.from({ length: 12 }, (_, i) =>
            breakdown({
                key: String(i),
                name: `Persona ${i}`,
                logged_minutes: 100,
                overage_minutes: 10,
                billable_minutes: 50,
            }),
        );

        const bars = barRows(rows, 'Otros');

        expect(bars).toHaveLength(10);
        expect(bars[9]).toMatchObject({
            label: 'Otros',
            logged: 300,
            overage: 30,
            inside: 270,
            billable: 150,
        });
    });
});

describe('R2BreakdownTable', () => {
    const rows = [
        breakdown({}),
        breakdown({
            key: '2',
            name: 'NAN-CAMP · Campaña otoño',
            logged_minutes: 150,
            billable_minutes: 120,
            in_bank_minutes: 150,
            overage_minutes: 0,
            income: '116.00',
            cost: '60.00',
        }),
    ];

    // Los totales del resumen del servidor: el ingreso con los céntimos repartidos (1312,67 €),
    // aunque las filas redondeadas por separado sumasen otra cosa; nunca se suman en el navegador.
    const serverTotal = {
        logged_minutes: 940,
        billable_minutes: 910,
        in_bank_minutes: 600,
        overage_minutes: 190,
        income: '1312.67',
        cost: '390.00',
    };

    it('con view-financials muestra ingreso, coste, rentabilidad y margen, y los totales del servidor (INT-04)', () => {
        render(
            <R2BreakdownTable
                caption="Resumen"
                firstColumn="Proyecto"
                rows={rows}
                total={serverTotal}
                financials
            />,
        );

        const table = screen.getByRole('table', { name: 'Resumen' });
        expect(
            within(table)
                .getAllByRole('columnheader')
                .map((cell) => cell.textContent),
        ).toEqual([
            'Proyecto',
            'Imputadas',
            'Facturables',
            'Dentro de bolsa',
            'Exceso',
            'Ingreso',
            'Coste',
            'Rentabilidad',
            'Margen',
        ]);

        const total = within(table).getByRole('rowheader', { name: 'Total' })
            .parentElement as HTMLElement;
        const cells = within(total)
            .getAllByRole('cell')
            .map((cell) => norm(cell.textContent));
        expect(cells).toEqual([
            '15:40',
            '15:10',
            '10:00',
            'Exceso: +3:10',
            '1.312,67 €',
            '390,00 €',
            '922,67 €',
            '70,3 %',
        ]);
    });

    it('sin view-financials no pinta ninguna columna de dinero', () => {
        render(
            <R2BreakdownTable
                caption="Resumen"
                firstColumn="Proyecto"
                rows={rows.map((row) => ({ ...row, income: null, cost: null }))}
                total={{ ...serverTotal, income: null, cost: null }}
                financials={false}
            />,
        );

        expect(
            screen.queryByRole('columnheader', { name: 'Ingreso' }),
        ).toBeNull();
        expect(screen.queryByText(/€/)).toBeNull();
    });

    it('el exceso va en rojo con icono y texto; sin exceso, 0:00 normal', () => {
        const { container } = render(
            <>
                <R2OverageValue minutes={100} />
                <R2OverageValue minutes={0} />
            </>,
        );

        const overage = screen.getByText('+1:40');
        expect(overage.className).toContain('text-danger');
        expect(overage.querySelector('svg')).not.toBeNull();
        expect(overage.textContent).toBe('Exceso: +1:40');
        expect(container.textContent).toContain('0:00');
    });
});

describe('R2EstimateTable', () => {
    const estimates: R2Estimates = {
        tasks: [
            {
                id: 1,
                parent_id: null,
                depth: 0,
                title: 'Diseño de la home',
                type: { id: 1, name: 'Diseño UI', color: '#0171FF' },
                status: {
                    name: 'Por hacer',
                    color: '#999999',
                    category: 'todo',
                },
                completed: false,
                derived: true,
                estimated_minutes: 200,
                actual_minutes: 390,
            },
            {
                id: 2,
                parent_id: 1,
                depth: 1,
                title: 'Versión móvil',
                type: { id: 1, name: 'Diseño UI', color: '#0171FF' },
                status: {
                    name: 'Por hacer',
                    color: '#999999',
                    category: 'todo',
                },
                completed: false,
                derived: false,
                estimated_minutes: 200,
                actual_minutes: 150,
            },
            {
                id: 3,
                parent_id: null,
                depth: 0,
                title: 'Diseño 2025',
                type: null,
                status: { name: 'Hecha', color: '#00aa00', category: 'done' },
                completed: true,
                derived: false,
                estimated_minutes: null,
                actual_minutes: 300,
            },
        ],
        by_type: [],
        totals: {
            estimated_minutes: 200,
            actual_minutes: 720,
            other_minutes: 30,
            tasks: 2,
            estimated_tasks: 1,
            over_tasks: 1,
        },
    };

    it('marca la estimación derivada de las subtareas, sangra las subtareas y explica la desviación', () => {
        render(<R2EstimateTable projectId={9} estimates={estimates} />);

        expect(screen.getByText('Suma de las subtareas')).toBeTruthy();
        expect(
            screen.getByRole('link', { name: 'Subtarea: Versión móvil' }),
        ).toHaveProperty(
            'href',
            expect.stringContaining('/proyectos/9/tareas?tarea=2'),
        );

        // 390 frente a 200: +3:10 (+95 %), en rojo con su texto para lectores de pantalla.
        const over = screen.getByText(/\+3:10/);
        expect(norm(over.textContent)).toBe(
            'Por encima de lo estimado: +3:10 (+95 %)',
        );
        expect(over.className).toContain('text-danger');

        // 150 frente a 200: dentro de lo estimado.
        expect(norm(screen.getByText(/-0:50/).textContent)).toBe(
            'Dentro de lo estimado: -0:50 (-25 %)',
        );
        expect(screen.getByText('Sin estimar')).toBeTruthy();
        expect(
            screen.getByText('Horas de tareas movidas a otro proyecto'),
        ).toBeTruthy();
        expect(screen.getByText('Total (2 tareas, 1 estimadas)')).toBeTruthy();
    });

    it('R2Deviation sin estimación dice «Sin estimar»', () => {
        render(<R2Deviation estimated={null} actual={30} />);

        expect(screen.getByText('Sin estimar')).toBeTruthy();
    });
});

describe('estado de las tareas e hitos', () => {
    it('cuenta por categoría y vencidas, con icono y texto', () => {
        render(
            <R2TaskStatus
                tasks={{
                    by_status: [
                        {
                            id: 1,
                            name: 'Por hacer',
                            color: '#999',
                            category: 'todo',
                            count: 4,
                        },
                        {
                            id: 2,
                            name: 'En revisión',
                            color: '#555',
                            category: 'in_progress',
                            count: 0,
                        },
                        {
                            id: 3,
                            name: 'Hecha',
                            color: '#0a0',
                            category: 'done',
                            count: 1,
                        },
                    ],
                    by_category: { todo: 4, in_progress: 0, done: 1 },
                    overdue: 1,
                    total: 5,
                }}
            />,
        );

        expect(norm(screen.getByText(/tareas hechas/).textContent)).toBe(
            '1 de 5 tareas hechas (20 %)',
        );
        expect(
            screen.getByText('Vencidas').nextElementSibling?.textContent,
        ).toBe('1');
        // Los estados sin tareas no salen en la lista.
        const list = screen.getByRole('list', { name: 'Tareas por estado' });
        expect(within(list).queryByText('En revisión')).toBeNull();
    });

    it('lista los hitos con su estado y avisa de que el Gantt llega en la Fase 4', () => {
        render(
            <R2Milestones
                milestones={[
                    {
                        id: 1,
                        title: 'Arranque',
                        due_date: '2026-09-01',
                        completed: true,
                        overdue: false,
                    },
                    {
                        id: 2,
                        title: 'Entrega de diseño',
                        due_date: '2026-09-20',
                        completed: false,
                        overdue: true,
                    },
                    {
                        id: 3,
                        title: 'Lanzamiento',
                        due_date: null,
                        completed: false,
                        overdue: false,
                    },
                ]}
            />,
        );

        expect(screen.getByText('Completado')).toBeTruthy();
        expect(screen.getByText('Vencido')).toBeTruthy();
        expect(screen.getByText('Pendiente')).toBeTruthy();
        expect(screen.getByText('Sin fecha')).toBeTruthy();
        expect(screen.getByText('20/09/2026')).toBeTruthy();
        expect(screen.getByText('Llega en la Fase 4')).toBeTruthy();
    });

    it('sin hitos lo dice, también con la fase', () => {
        render(<R2Milestones milestones={[]} />);

        expect(screen.getByText('Sin hitos')).toBeTruthy();
        expect(screen.getByText('Llega en la Fase 4')).toBeTruthy();
    });
});

describe('gráficas de R2', () => {
    it('las barras apiladas tienen su vista de tabla con los totales', async () => {
        const user = userEvent.setup();
        render(
            <R2StackedBarsChart
                title="Horas imputadas por proyecto"
                bucketColumn="Mes"
                buckets={[
                    {
                        id: '2026-08-01',
                        label: 'ago 2026',
                        longLabel: 'agosto de 2026',
                    },
                    {
                        id: '2026-09-01',
                        label: 'sept 2026',
                        longLabel: 'septiembre de 2026',
                    },
                ]}
                series={[
                    { key: '10', label: 'NAN-WEB' },
                    { key: '11', label: 'NAN-CAMP' },
                ]}
                values={{
                    '10': { '2026-08-01': 300, '2026-09-01': 790 },
                    '11': { '2026-09-01': 150 },
                }}
            />,
        );

        expect(
            screen.getByRole('img', {
                name: /Horas imputadas por proyecto: 20:40 en 2 periodos\. Por serie: NAN-WEB, 18:10, NAN-CAMP, 2:30\./,
            }),
        ).toBeTruthy();
        // El total va encima de cada barra, aunque la última serie no tenga horas ese mes.
        expect(
            [...document.querySelectorAll('svg text')].map(
                (node) => node.textContent,
            ),
        ).toEqual(expect.arrayContaining(['5:00', '15:40']));

        await user.click(
            screen.getByRole('button', { name: 'Ver como tabla' }),
        );
        const table = screen.getByRole('table');
        const september = within(table).getByRole('rowheader', {
            name: 'septiembre de 2026',
        }).parentElement as HTMLElement;
        expect(
            within(september)
                .getAllByRole('cell')
                .map((cell) => cell.textContent),
        ).toEqual(['13:10', '2:30', '15:40']);
    });

    it('las barras de horas dan el exceso aparte en el resumen y en la tabla', async () => {
        const user = userEvent.setup();
        render(
            <R2HoursBars
                title="Horas por persona"
                firstColumn="Persona"
                rows={[
                    breakdown({
                        key: '1',
                        name: 'Luis',
                        logged_minutes: 400,
                        overage_minutes: 0,
                        billable_minutes: 400,
                    }),
                    breakdown({
                        key: '2',
                        name: 'Ana',
                        logged_minutes: 390,
                        overage_minutes: 190,
                        billable_minutes: 390,
                    }),
                ]}
            />,
        );

        expect(
            screen.getByRole('img', {
                name: 'Horas por persona: Luis, 6:40, Ana, 6:30 (3:10 de exceso).',
            }),
        ).toBeTruthy();
        expect(screen.getByText('Exceso')).toBeTruthy();
        // El total al final de cada barra, con exceso o sin él.
        expect(
            [...document.querySelectorAll('svg text')].map(
                (node) => node.textContent,
            ),
        ).toEqual(expect.arrayContaining(['6:40', '6:30']));

        await user.click(
            screen.getByRole('button', { name: 'Ver como tabla' }),
        );
        const ana = screen.getByRole('rowheader', { name: 'Ana' })
            .parentElement as HTMLElement;
        expect(
            within(ana)
                .getAllByRole('cell')
                .map((cell) => cell.textContent),
        ).toEqual(['6:30', '6:30', '+3:10']);
    });
});

describe('histórico de renovaciones y resumen para facturar', () => {
    const bank = (overrides: Partial<R2ClientBank>): R2ClientBank => ({
        id: 1,
        project: { id: 5, code: 'NAN-WEB', name: 'Web corporativa' },
        name: 'Bolsa 2025',
        status: 'renewed',
        start_date: '2026-01-01',
        end_date: '2026-08-31',
        total_minutes: 300,
        consumed_minutes: 300,
        overage_minutes: 0,
        in_bank_minutes: 300,
        remaining_minutes: 0,
        committed_minutes: 0,
        period_in_bank_minutes: 0,
        period_overage_minutes: 0,
        ...overrides,
    });

    it('cada cadena con el consumo de cada bolsa y el exceso en rojo', () => {
        render(
            <R2RenewalHistory
                chains={[
                    [
                        bank({}),
                        bank({
                            id: 2,
                            name: 'Bolsa Diseño ñ',
                            status: 'exhausted',
                            total_minutes: 600,
                            consumed_minutes: 790,
                            overage_minutes: 190,
                        }),
                    ],
                ]}
            />,
        );

        const chain = screen.getByRole('list', {
            name: 'Renovaciones de Bolsa 2025',
        });
        expect(within(chain).getByText('5:00 de 5:00')).toBeTruthy();
        expect(within(chain).getByText('13:10 de 10:00')).toBeTruthy();
        expect(within(chain).getByText('+3:10').className).toContain(
            'text-danger',
        );
        expect(
            within(chain).getByRole('link', { name: 'Bolsa Diseño ñ' }),
        ).toHaveProperty(
            'href',
            expect.stringContaining('/proyectos/5/bolsas/2'),
        );
    });

    const summary: R2BillingSummary = {
        rows: [
            {
                project: {
                    id: 5,
                    code: 'NAN-WEB',
                    name: 'Web corporativa',
                    billing_type: 'hour_bank',
                },
                bank: { id: 2, name: 'Bolsa Diseño ñ', status: 'exhausted' },
                logged_minutes: 790,
                in_bank_minutes: 600,
                overage_minutes: 190,
                billable_minutes: 790,
                non_billable_minutes: 0,
                pending_minutes: 90,
                pricing: 'bank_price',
                rate: '70.00',
                price_amount: '1000.00',
                income: '1221.67',
            },
        ],
        totals: {
            logged_minutes: 790,
            in_bank_minutes: 600,
            overage_minutes: 190,
            billable_minutes: 790,
            non_billable_minutes: 0,
            pending_minutes: 90,
            income: '1221.67',
        },
    };

    it('el resumen para facturar lleva tarifa, precio e importe solo con view-financials', () => {
        const { unmount } = render(
            <R2BillingTable summary={summary} financials />,
        );

        expect(
            screen.getByText('Precio de la bolsa (exceso a tarifa)'),
        ).toBeTruthy();
        expect(norm(screen.getByText(/70,00/).textContent)).toBe('70,00 €/h');
        expect(norm(screen.getByText(/1\.000,00/).textContent)).toBe(
            '1.000,00 €',
        );
        expect(screen.getAllByText(/1\.221,67/)).toHaveLength(2);
        unmount();

        render(
            <R2BillingTable
                summary={{
                    rows: summary.rows.map((row) => ({
                        ...row,
                        pricing: null,
                        rate: null,
                        price_amount: null,
                        income: null,
                    })),
                    totals: { ...summary.totals, income: null },
                }}
                financials={false}
            />,
        );

        expect(
            screen.queryByRole('columnheader', { name: 'Importe' }),
        ).toBeNull();
        expect(screen.queryByText(/€/)).toBeNull();
        // Dentro y exceso por separado; lo pendiente de aprobar, con su icono.
        expect(screen.getAllByText('10:00').length).toBeGreaterThan(0);
        expect(screen.getAllByText('+3:10').length).toBeGreaterThan(0);
        expect(
            screen.getAllByText('1:30')[0].querySelector('svg.lucide-clock'),
        ).not.toBeNull();
    });
});
