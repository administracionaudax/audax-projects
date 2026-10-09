// @vitest-environment jsdom
import {
    configure,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import {
    CollectionStatusBadge,
    SaleStatusBadge,
} from '@/components/billing/billing-badges';
import { BillingPanel } from '@/components/billing/billing-panel';
import { InvoiceTable } from '@/components/billing/invoice-table';
import { SaleFilters } from '@/components/billing/sale-filters';
import {
    bulletSegments,
    chartUnits,
    consumptionPct,
    hourlyStatus,
    saleStatus,
    signedMinutes,
} from '@/components/billing/sold-vs-actual-lib';
import { SoldVsActualChart } from '@/components/billing/sold-vs-actual-chart';
import { SoldVsActualKpis } from '@/components/billing/sold-vs-actual-kpis';
import {
    SoldVsActualTable,
    unitHref,
} from '@/components/billing/sold-vs-actual-table';
import { billingNavItems } from '@/components/app-sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import { formatMinutes } from '@/lib/format';
import type {
    HoldedInvoiceSummary,
    SoldVsActualTotals,
    SoldVsActualUnit,
} from '@/types';
import fixture from '../fixtures/billing/sold-vs-actual-status.json';

configure({ testIdAttribute: 'data-test' });

const visits: { url: string; data: unknown }[] = [];

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ url: '/', props: { auth: { can: {} } } }),
    router: {
        get: (url: string, data: unknown) => visits.push({ url, data }),
        post: (url: string, data: unknown) => visits.push({ url, data }),
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
| Facturación (Fase 12, F1; D-390 a D-396): el semáforo con los casos compartidos con Pest, la
| gráfica de bala, la tabla, las cifras con y sin importes, las facturas (con su sugerencia) y la
| sección de la barra lateral.
*/

function unit(overrides: Partial<SoldVsActualUnit>): SoldVsActualUnit {
    return {
        key: 'project:1',
        kind: 'precio_cerrado',
        name: 'Web nueva',
        project: {
            id: 1,
            code: 'ACM-WE1',
            name: 'Web nueva',
            billing_type: 'fixed_price',
        },
        client: { id: 1, name: 'Acme' },
        manager: { id: 2, name: 'Marta' },
        bank: null,
        whole: true,
        months: null,
        sold_minutes: 1200,
        real_minutes: 1320,
        pending_minutes: 0,
        deviation_minutes: 120,
        consumption_pct: 110,
        status: 'over',
        invoices_count: 1,
        invoiced_minutes: 0,
        unbilled_minutes: null,
        ...overrides,
    };
}

const totals: SoldVsActualTotals = {
    units: 2,
    sold_minutes: 1800,
    real_minutes: 1860,
    real_of_sold_minutes: 1860,
    pending_minutes: 90,
    deviation_minutes: 60,
    consumption_pct: 103.3,
    status: 'over',
    by_status: { over: 1, risk: 1, unbilled: 0, ok: 0, billed: 0, none: 0 },
    unbilled_minutes: 0,
    income: '3000.00',
    invoiced: '2500.00',
    collected: '1210.00',
    outstanding: '1815.00',
    overdue: '605.00',
    to_invoice: '500.00',
    planned: '1500.00',
    cost: '900.00',
    margin: '2100.00',
    margin_pct: 70,
};

const bank = unit({
    key: 'bank:7',
    kind: 'bolsa',
    name: 'Bolsa 10 h',
    project: {
        id: 3,
        code: 'ACM-BH',
        name: 'Bolsas',
        billing_type: 'hour_bank',
    },
    bank: { id: 7, name: 'Bolsa 10 h', status: 'active' },
    sold_minutes: 600,
    real_minutes: 540,
    pending_minutes: 90,
    deviation_minutes: -60,
    consumption_pct: 90,
    status: 'risk',
});

describe('semáforo de «Vendido frente a real»', () => {
    it.each(fixture.cases)(
        'vendido $sold y real $real → $pct % y $status',
        ({ sold, real, pct, status }) => {
            const computed = consumptionPct(sold, real);
            expect(computed).toBe(pct);
            expect(saleStatus(computed)).toBe(status);
        },
    );

    it.each(fixture.hourly_cases)(
        'por horas (D-411): facturado $invoiced y real $real → $status',
        ({ invoiced, real, status, unbilled }) => {
            expect(hourlyStatus(invoiced, real)).toBe(status);
            expect(Math.max(0, real - invoiced)).toBe(unbilled);
        },
    );

    it('una unidad por horas con una factura parcial no sale en la gráfica y dice lo pendiente de facturar', () => {
        const hourly = unit({
            key: 'project:9',
            kind: 'horas',
            sold_minutes: null,
            real_minutes: 934,
            invoiced_minutes: 60,
            unbilled_minutes: 874,
            deviation_minutes: null,
            consumption_pct: null,
            status: 'unbilled',
        });
        expect(chartUnits([hourly, bank]).map((row) => row.key)).toEqual([
            'bank:7',
        ]);

        render(
            <TooltipProvider>
                <SoldVsActualTable
                    units={[hourly]}
                    totals={{ ...totals, units: 1 }}
                    financials={false}
                    caption="Unidades"
                />
            </TooltipProvider>,
        );
        expect(screen.getByText('Por facturar')).toBeTruthy();
        expect(screen.getByText('14:34 sin facturar')).toBeTruthy();
        expect(screen.queryByText(/%/)).toBeNull();
    });

    it('la barra de bala usa una escala común y separa lo que pasa de lo vendido', () => {
        expect(bulletSegments(600, 540, 1320)).toEqual({
            inside: (540 / 1320) * 100,
            over: 0,
            track: (600 / 1320) * 100,
        });
        expect(bulletSegments(1200, 1320, 1320)).toEqual({
            inside: (1200 / 1320) * 100,
            over: (120 / 1320) * 100,
            track: (1200 / 1320) * 100,
        });
        expect(bulletSegments(0, 0, 0)).toEqual({
            inside: 0,
            over: 0,
            track: 0,
        });
    });

    it('la gráfica solo lleva lo que tiene horas vendidas, primero lo más consumido', () => {
        const hourly = unit({
            key: 'project:9',
            kind: 'horas',
            sold_minutes: null,
            consumption_pct: null,
            status: 'none',
        });
        expect(
            chartUnits([bank, hourly, unit({})]).map((row) => row.key),
        ).toEqual(['project:1', 'bank:7']);
    });

    it('la desviación lleva su signo', () => {
        expect(signedMinutes(120, formatMinutes)).toBe('+2:00');
        expect(signedMinutes(-90, formatMinutes)).toBe('−1:30');
        expect(signedMinutes(0, formatMinutes)).toBe('0:00');
        expect(signedMinutes(null, formatMinutes)).toBe('—');
    });
});

describe('componentes', () => {
    it('los estados llevan icono y texto, nunca solo color', () => {
        render(
            <>
                <SaleStatusBadge status="over" />
                <SaleStatusBadge status="risk" />
                <CollectionStatusBadge status="overdue" />
                <CollectionStatusBadge status="draft" />
            </>,
        );
        expect(screen.getByText('Pasado')).toBeTruthy();
        expect(screen.getByText('En riesgo')).toBeTruthy();
        expect(screen.getByText('Vencida')).toBeTruthy();
        expect(screen.getByText('Borrador')).toBeTruthy();
    });

    it('la gráfica pinta una barra por unidad con su etiqueta accesible y su vista de tabla', () => {
        render(
            <TooltipProvider>
                <SoldVsActualChart
                    units={[bank, unit({})]}
                    title="Horas vendidas frente a horas reales"
                />
            </TooltipProvider>,
        );
        const chart = screen.getByTestId('sold-vs-actual-chart');
        expect(within(chart).getAllByRole('listitem')).toHaveLength(2);
        expect(
            screen.getByLabelText('Web nueva: 22:00 reales de 20:00 vendidas'),
        ).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Ver como tabla' }));
        expect(screen.getByRole('table')).toBeTruthy();
        expect(screen.getByText('+2:00')).toBeTruthy();
    });

    it('la tabla enlaza cada unidad con su ficha y solo lleva importes con view-financials', () => {
        const { rerender } = render(
            <SoldVsActualTable
                units={[bank, unit({ planned: '1500.00', invoiced: '500.00' })]}
                totals={totals}
                financials={false}
                caption="Tabla"
            />,
        );
        expect(screen.queryByText('Facturado sin IVA')).toBeNull();
        expect(
            screen
                .getByRole('link', { name: /Bolsa 10 h/ })
                .getAttribute('href'),
        ).toBe('/proyectos/3/bolsas/7');
        expect(unitHref(unit({}))).toBe('/proyectos/1/facturacion');

        rerender(
            <SoldVsActualTable
                units={[
                    bank,
                    unit({
                        planned: '1500.00',
                        invoiced: '500.00',
                        margin: '700.00',
                        margin_pct: 35,
                    }),
                ]}
                totals={totals}
                financials
                caption="Tabla"
            />,
        );
        expect(screen.getByText('Facturado sin IVA')).toBeTruthy();
        expect(screen.getByText(/en borrador/)).toBeTruthy();
        expect(screen.getByText('Total (2)')).toBeTruthy();
    });

    it('las cifras con importes llevan el cobro y lo previsto en borradores', () => {
        const { rerender } = render(
            <TooltipProvider>
                <SoldVsActualKpis totals={totals} financials={false} />
            </TooltipProvider>,
        );
        expect(screen.getByText('Consumo de lo vendido')).toBeTruthy();
        expect(screen.queryByText('Margen')).toBeNull();

        rerender(
            <TooltipProvider>
                <SoldVsActualKpis totals={totals} financials />
            </TooltipProvider>,
        );
        expect(screen.getByText('Margen')).toBeTruthy();
        expect(screen.getByText(/Previsto en borradores/)).toBeTruthy();
        expect(screen.getAllByRole('meter')).toHaveLength(2);
    });

    it('una factura sin enlazar ofrece su sugerencia y un borrador no tiene número', () => {
        visits.length = 0;
        const invoice: HoldedInvoiceSummary = {
            id: 5,
            number: 'F260194',
            kind: 'invoice',
            issued_on: '2026-10-02',
            due_on: '2026-11-01',
            contact_name: 'Montó',
            client: { id: 1, name: 'Pinturas Montó' },
            currency: 'EUR',
            subtotal: '5100.00',
            tax_total: '1071.00',
            total: '6171.00',
            paid_total: '0.00',
            pending_total: '6171.00',
            collection_status: 'unpaid',
            is_draft: false,
            tags: ['#bolsadehoras'],
            links: [],
            suggestion: {
                project: { id: 3, code: 'MON-BH', name: 'Bolsas' },
                bank: { id: 7, name: 'Bolsa 4.º trimestre' },
                reason: 'bank',
            },
        };
        render(
            <TooltipProvider>
                <InvoiceTable
                    invoices={[
                        invoice,
                        {
                            ...invoice,
                            id: 6,
                            number: null,
                            is_draft: true,
                            collection_status: 'draft',
                            suggestion: null,
                        },
                    ]}
                    caption="Facturas"
                    today="2026-10-09"
                />
            </TooltipProvider>,
        );

        // La sugerencia, compacta en la celda del proyecto (D-406), y el estado con días (D-407).
        expect(screen.getByTestId('accept-suggestion').textContent).toBe(
            'Enlazar MON-BH',
        );
        expect(screen.getByText('Vence en 23 días')).toBeTruthy();
        fireEvent.click(screen.getByTestId('accept-suggestion'));
        expect(visits).toEqual([
            {
                url: '/facturacion/facturas/5/enlaces',
                data: { project_id: 3, hour_bank_id: 7 },
            },
        ]);
        expect(screen.getAllByText('Borrador').length).toBeGreaterThan(0);
    });

    it('el filtro de tipo de venta va a la URL', () => {
        visits.length = 0;
        render(
            <SaleFilters
                url="/facturacion/vendido-frente-a-real"
                query={{ periodo: 'anio' }}
                kinds={[]}
                manager={null}
                managers={[]}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Fee mensual' }));
        expect(visits).toEqual([
            {
                url: '/facturacion/vendido-frente-a-real',
                data: { periodo: 'anio', venta: ['fee'] },
            },
        ]);
    });

    it('el panel de una ficha sin ventas enseña su estado vacío', () => {
        render(
            <BillingPanel
                panel={{
                    report: {
                        units: [],
                        totals,
                        financials: true,
                        from: '2026-01-01',
                        to: '2026-12-31',
                    },
                    invoices: [],
                    invoice_count: 0,
                }}
            />,
        );
        expect(screen.getByText('Nada vendido en este periodo')).toBeTruthy();
        expect(screen.getByText('Aún no hay facturas')).toBeTruthy();
    });
});

describe('barra lateral', () => {
    it('Facturación: una sola navegación con cada pantalla según su permiso y el contador de Por revisar (D-405)', () => {
        expect(billingNavItems({} as never)).toEqual([]);
        expect(
            billingNavItems({ viewSoldVsActual: true } as never).map(
                (item) => item.href,
            ),
        ).toEqual(['/facturacion/vendido-frente-a-real']);
        // Sin el módulo, lo pendiente de facturar sigue con su permiso de siempre (D-402).
        expect(
            billingNavItems({ exportBillingHours: true } as never).map(
                (item) => item.href,
            ),
        ).toEqual(['/facturacion/por-facturar']);
        const items = billingNavItems(
            {
                viewSoldVsActual: true,
                viewBilling: true,
                exportBillingHours: true,
            } as never,
            { billingReview: 3 },
        );
        // El Resumen primero (I1, D-411), activo solo en su URL exacta.
        expect(items.map((item) => item.title)).toEqual([
            'Resumen',
            'Facturas',
            'Por facturar',
            'Vendido frente a real',
            'Por revisar',
            'Ventas',
            'Ajustes',
        ]);
        expect(items.every((item) => item.items === undefined)).toBe(true);
        expect(items[0].exact).toBe(true);
        expect(items[4].badge).toEqual({
            count: 3,
            label: '3 contactos y facturas por revisar',
        });
        expect(
            billingNavItems({ viewBilling: true } as never, {
                billingReview: 0,
            }).find((item) => item.href === '/facturacion/por-revisar')?.badge,
        ).toBeUndefined();
    });
});
