// @vitest-environment jsdom
import {
    configure,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { billingTabs, BillingTabs } from '@/components/billing/billing-nav';
import {
    InvoicingAgingChart,
    InvoicingOverdueList,
} from '@/components/billing/invoicing-aging';
import { InvoicingKpis } from '@/components/billing/invoicing-kpis';
import {
    amountTicks,
    euroTick,
    monthTick,
    monthTitle,
    previousYearMonth,
    serviceLabel,
    signedPercent,
    variation,
} from '@/components/billing/invoicing-lib';
import { InvoicingMonthChart } from '@/components/billing/invoicing-month-chart';
import { ServiceFilters } from '@/components/billing/service-filters';
import { TooltipProvider } from '@/components/ui/tooltip';
import InvoicingReportPage from '@/pages/billing/report';
import type {
    Abilities,
    InvoicingReport,
    InvoicingReportPageProps,
} from '@/types';

configure({ testIdAttribute: 'data-test' });

const visits: { url: string; data: unknown }[] = [];
let abilities: Partial<Abilities> = {};

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ url: '/', props: { auth: { can: abilities } } }),
    router: {
        get: (url: string, data: unknown) => visits.push({ url, data }),
    },
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        [key: string]: unknown;
    }) => (
        <a href={href} {...(rest as Record<string, string>)}>
            {children}
        </a>
    ),
}));

/*
| Informe de facturación (D-400) y las pestañas de Facturación (D-401): las escalas y etiquetas,
| las cifras, la vista de tabla de cada gráfica, las vencidas con sus enlaces, el filtro de
| servicio y qué pestañas ve cada permiso.
*/

function report(overrides: Partial<InvoicingReport> = {}): InvoicingReport {
    return {
        from: '2026-01-01',
        to: '2026-12-31',
        previous_from: '2025-01-01',
        previous_to: '2025-12-31',
        today: '2026-03-20',
        compare: true,
        services_filter: [],
        kpis: {
            invoiced: '3400.00',
            previous_invoiced: '1200.00',
            variation_pct: '183.3',
            collected: '1210.00',
            outstanding: '3146.00',
            overdue: '3025.00',
            planned: '700.00',
            planned_count: 1,
            count: 4,
            previous_count: 1,
            average: '850.00',
            previous_average: '1200.00',
            credit_notes: '-200.00',
        },
        months: [
            {
                month: '2026-01',
                invoiced: '3000.00',
                planned: '0.00',
                previous: '1200.00',
                count: 2,
            },
            {
                month: '2026-02',
                invoiced: '300.00',
                planned: '0.00',
                previous: '0.00',
                count: 1,
            },
            {
                month: '2026-03',
                invoiced: '100.00',
                planned: '700.00',
                previous: '0.00',
                count: 1,
            },
        ],
        services: [
            { key: 'desarrollo', amount: '1800.00', share: '52.9' },
            { key: 'bolsas', amount: '600.00', share: '17.6' },
        ],
        clients: {
            top: [
                {
                    id: 7,
                    name: 'Beta Foods',
                    amount: '1800.00',
                    share: '52.9',
                    count: 1,
                },
            ],
            rest: null,
            unmatched: { amount: '100.00', share: '2.9', count: 1 },
        },
        aging: [
            { key: 'current', amount: '121.00', count: 1 },
            { key: 'd1_30', amount: '605.00', count: 1 },
            { key: 'd31_60', amount: '0.00', count: 0 },
            { key: 'd61_90', amount: '2420.00', count: 1 },
            { key: 'd90_plus', amount: '0.00', count: 0 },
        ],
        overdue: {
            clients: [
                {
                    client: { id: 7, name: 'Beta Foods' },
                    contact_name: 'Beta',
                    amount: '2420.00',
                    invoices: [
                        {
                            id: 33,
                            number: 'F260003',
                            issued_on: '2026-01-05',
                            due_on: '2026-01-05',
                            days: 74,
                            pending: '2420.00',
                        },
                    ],
                },
                {
                    client: null,
                    contact_name: 'Gamma sin casar',
                    amount: '121.00',
                    invoices: [
                        {
                            id: 35,
                            number: 'F260005',
                            issued_on: '2026-03-01',
                            due_on: '2026-03-10',
                            days: 1,
                            pending: '121.00',
                        },
                    ],
                },
            ],
            total: 2,
            shown: 2,
        },
        ...overrides,
    };
}

function withTooltips(node: ReactNode) {
    return render(<TooltipProvider>{node}</TooltipProvider>);
}

beforeEach(() => {
    visits.length = 0;
    abilities = {};
});

describe('piezas del informe', () => {
    it('marcas de euros limpias, con el cero y los negativos', () => {
        expect(amountTicks(0, 0)).toEqual([0]);
        expect(amountTicks(0, 3400)).toEqual([0, 1000, 2000, 3000, 4000]);
        expect(amountTicks(-200, 900, 4)).toEqual([-500, 0, 500, 1000]);
        expect(euroTick(850)).toBe('850 €');
        expect(euroTick(3400)).toBe('3,4 k€');
        expect(euroTick(45000)).toBe('45 k€');
        expect(euroTick(1_250_000)).toBe('1,3 M€');
    });

    it('meses, variaciones y servicios', () => {
        expect(monthTick('2026-01', false)).toBe('ene');
        expect(monthTick('2026-01', true)).toBe('ene 26');
        expect(monthTitle('2026-03')).toBe('marzo de 2026');
        expect(previousYearMonth('2026-03')).toBe('2025-03');
        expect(variation('150.00', '100.00')).toBeCloseTo(0.5);
        expect(variation('150.00', '0.00')).toBeNull();
        expect(variation('150.00', null)).toBeNull();
        expect(signedPercent('183.3')).toBe('+183,3 %');
        expect(signedPercent(-3)).toBe('−3 %');
        expect(serviceLabel('inversion')).toBe('Inversión repercutida');
        expect(serviceLabel('sin_desglose')).toBe('Sin desglose por línea');
    });
});

describe('cifras', () => {
    it('facturado, variación con flecha y texto, cobro con IVA, vencido y deltas al comparar', () => {
        withTooltips(<InvoicingKpis report={report()} />);
        const kpis = screen.getByTestId('invoicing-kpis');

        const text = (kpis.textContent ?? '').replace(/\s/g, ' ');
        expect(text).toContain('3.400 €');
        expect(text).toContain('Frente a 2025');
        expect(text).toContain('+183,3 %');
        expect(text).toContain('Mismo periodo de 2025: 1.200 €');
        expect(text).toContain('con -200,00 € de rectificativas');
        expect(text).toContain('2 facturas vencidas');
        expect(text).toContain('1 borrador, sin IVA');
        expect(text).toContain('850 €');
        // Al comparar, el número de facturas y el ticket medio llevan su variación frente al año anterior.
        expect(text).toContain('300 % más que el año anterior');
        expect(text).toContain('29 % menos que el año anterior');
    });

    it('sin facturas el año anterior, lo dice en lugar de un porcentaje', () => {
        withTooltips(
            <InvoicingKpis
                report={report({
                    compare: false,
                    kpis: {
                        ...report().kpis,
                        variation_pct: null,
                        previous_count: null,
                    },
                })}
            />,
        );

        expect(screen.getByTestId('invoicing-kpis').textContent).toContain(
            'Sin facturas',
        );
    });
});

describe('gráficas con su vista de tabla', () => {
    it('facturado por mes: año anterior y variación en la tabla', () => {
        render(<InvoicingMonthChart months={report().months} compare />);
        expect(screen.getByText('Año anterior')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: /Ver como tabla/ }));
        const table = screen.getByRole('table');
        const rows = within(table).getAllByRole('row');

        expect(
            within(rows[0])
                .getAllByRole('columnheader')
                .map((cell) => cell.textContent),
        ).toEqual([
            'Mes',
            'Facturado',
            'Previsto (borradores)',
            'Año anterior',
            'Variación',
            'Facturas',
        ]);
        expect(rows[1].textContent).toContain('enero de 2026');
        expect(rows[1].textContent).toContain('+150 %');
        expect(rows[2].textContent).toContain('—');
    });

    it('sin comparar ni borradores, sin esas series', () => {
        render(
            <InvoicingMonthChart
                months={report().months.map((month) => ({
                    ...month,
                    planned: '0.00',
                    previous: null,
                }))}
                compare={false}
            />,
        );

        expect(screen.queryByText('Año anterior')).toBeNull();
        expect(screen.queryByText('Previsto (borradores)')).toBeNull();
    });

    it('antigüedad: cada tramo con su importe y el total', () => {
        render(<InvoicingAgingChart aging={report().aging} />);
        fireEvent.click(screen.getByRole('button', { name: /Ver como tabla/ }));
        const text = (screen.getByRole('table').textContent ?? '').replace(
            /\s/g,
            ' ',
        );

        expect(text).toContain('61 a 90 días');
        expect(text).toContain('2.420,00 €');
        expect(text).toContain('Total');
        expect(text).toContain('3.146,00 €');
    });
});

describe('vencidas', () => {
    it('por cliente, con el enlace a la factura y, sin cliente casado, a los contactos', () => {
        render(<InvoicingOverdueList overdue={report().overdue} />);
        const list = screen.getByTestId('invoicing-overdue');

        expect(
            within(list)
                .getByRole('link', { name: 'Beta Foods' })
                .getAttribute('href'),
        ).toBe('/clientes/7/facturacion');
        expect(
            within(list)
                .getByRole('link', { name: /F260003/ })
                .getAttribute('href'),
        ).toBe('/facturacion/facturas/33');
        expect(list.textContent).toContain('74 días de retraso');
        expect(list.textContent).toContain('1 día de retraso');
        expect(
            within(list)
                .getByRole('link', { name: 'Casar en Contactos de Holded' })
                .getAttribute('href'),
        ).toBe('/facturacion/contactos');
    });

    it('sin vencidas, lo dice', () => {
        render(
            <InvoicingOverdueList
                overdue={{ clients: [], total: 0, shown: 0 }}
            />,
        );

        expect(
            screen.getByText('No hay facturas vencidas en este periodo.'),
        ).toBeTruthy();
    });
});

describe('filtro de servicio', () => {
    it('añade y quita servicios en la URL; «Todos» los quita', () => {
        const query = { periodo: 'anio' as const, fecha: '2026-01-01' };
        const { rerender } = render(
            <ServiceFilters
                url="/facturacion/informe"
                query={query}
                services={['bolsas', 'fees', 'seo']}
                selected={[]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Fees' }));
        expect(visits.at(-1)).toEqual({
            url: '/facturacion/informe',
            data: { ...query, servicio: ['fees'] },
        });

        rerender(
            <ServiceFilters
                url="/facturacion/informe"
                query={{ ...query, servicio: ['fees'] }}
                services={['bolsas', 'fees', 'seo']}
                selected={['fees']}
            />,
        );
        expect(
            screen
                .getByRole('button', { name: 'Fees' })
                .getAttribute('aria-pressed'),
        ).toBe('true');
        fireEvent.click(screen.getByRole('button', { name: 'Todos' }));
        expect(visits.at(-1)).toEqual({
            url: '/facturacion/informe',
            data: query,
        });
    });
});

describe('pestañas de Facturación (D-401)', () => {
    it('cada pestaña con su permiso', () => {
        expect(billingTabs({} as Abilities)).toEqual([]);
        expect(
            billingTabs({ viewSoldVsActual: true } as Abilities).map(
                (tab) => tab.id,
            ),
        ).toEqual(['vendido']);
        expect(
            billingTabs({ exportBillingHours: true } as Abilities).map(
                (tab) => tab.id,
            ),
        ).toEqual(['horas']);
        expect(
            billingTabs({
                viewBilling: true,
                viewSoldVsActual: true,
                exportBillingHours: true,
            } as Abilities).map((tab) => tab.id),
        ).toEqual([
            'informe',
            'vendido',
            'horas',
            'facturas',
            'contactos',
            'ajustes',
        ]);
    });

    it('con una sola pestaña no se pintan; con varias, la actual marcada', () => {
        abilities = { viewSoldVsActual: true };
        const { container, rerender } = render(
            <BillingTabs current="vendido" />,
        );
        expect(container.innerHTML).toBe('');

        abilities = {
            viewSoldVsActual: true,
            viewBilling: true,
            exportBillingHours: true,
        };
        rerender(<BillingTabs current="informe" />);
        const nav = screen.getByRole('navigation', {
            name: 'Secciones de facturación',
        });
        expect(within(nav).getAllByRole('link')).toHaveLength(6);
        expect(
            within(nav)
                .getByRole('link', { name: 'Informe' })
                .getAttribute('aria-current'),
        ).toBe('page');
    });
});

describe('la página', () => {
    const props = (
        overrides: Partial<InvoicingReportPageProps> = {},
    ): InvoicingReportPageProps => ({
        filters: {
            query: { periodo: 'anio', fecha: '2026-01-01', comparar: '1' },
            period: 'anio',
            from: '2026-01-01',
            to: '2026-12-31',
            compare: true,
            previous: { periodo: 'anio', fecha: '2025-01-01', comparar: '1' },
            next: { periodo: 'anio', fecha: '2027-01-01', comparar: '1' },
            comparison: { from: '2025-01-01', to: '2025-12-31' },
            can_see_financials: true,
        },
        services: ['bolsas', 'fees'],
        report: report(),
        report_request: {
            kind: 'invoicing',
            route_params: {},
            query: { periodo: 'anio' },
        },
        last_sync: null,
        ...overrides,
    });

    it('cifras, gráficas, ranking con «Sin cliente casado» y su enlace a los contactos', () => {
        abilities = { viewBilling: true };
        withTooltips(<InvoicingReportPage {...props()} />);

        expect(
            screen.getByRole('heading', { name: 'Informe de facturación' }),
        ).toBeTruthy();
        expect(screen.getByText('Comparar con el año anterior')).toBeTruthy();
        expect(
            screen.getByText('Comparado con: del 01/01/2025 al 31/12/2025'),
        ).toBeTruthy();
        expect(
            screen.getByTestId('invoicing-unmatched').getAttribute('href'),
        ).toBe('/facturacion/contactos');
        expect(screen.getByTestId('invoicing-clients')).toBeTruthy();
        expect(screen.getByTestId('invoicing-services')).toBeTruthy();
    });

    it('sin nada en el periodo, el estado vacío', () => {
        abilities = { viewBilling: true };
        const empty = report({
            kpis: {
                ...report().kpis,
                invoiced: '0.00',
                planned: '0.00',
                outstanding: '0.00',
                count: 0,
            },
        });
        withTooltips(<InvoicingReportPage {...props({ report: empty })} />);

        expect(
            screen.getByText('No hay facturas en este periodo'),
        ).toBeTruthy();
        expect(screen.queryByTestId('invoicing-kpis')).toBeNull();
    });
});
