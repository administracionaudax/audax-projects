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
    daysBetween,
    relativeCollection,
    syncTone,
    timeAgo,
} from '@/components/billing/billing-time';
import { CollectionBar } from '@/components/billing/collection-bar';
import { periodLabel } from '@/components/billing/filter-chips';
import { toQueryString } from '@/components/billing/invoice-table';
import { invoiceTimeline } from '@/components/billing/invoice-timeline';
import { deviationWords } from '@/components/billing/sold-vs-actual-kpis';
import { ViewTabs } from '@/components/billing/view-tabs';
import { formatDurationWords } from '@/lib/format';
import type { HoldedInvoiceDetail } from '@/types';

configure({ testIdAttribute: 'data-test' });

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
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
| Facturación, tanda 1 del rediseño (D-405 a D-410): duraciones en palabras con su signo, el estado
| de cobro con días, cuánto hace de la última lectura de Holded, la query del listado, la barra de
| importes, las vistas con su número, los nombres del periodo y la línea de tiempo de la ficha.
*/

describe('duraciones en palabras (D-410)', () => {
    it.each([
        [0, '0 h'],
        [15, '15 min'],
        [60, '1 h'],
        [2775, '46 h 15 min'],
        [-2775, '46 h 15 min'],
        [89580, '1.493 h'],
    ])('%s min → %s', (minutes, expected) => {
        expect(formatDurationWords(minutes)).toBe(expected);
    });

    it('la desviación dice si está por encima o por debajo, nunca «−1493:45»', () => {
        expect(deviationWords(2775)).toBe('46 h 15 min por encima');
        expect(deviationWords(-89580)).toBe('1.493 h por debajo');
        expect(deviationWords(0)).toBe('Igual que lo vendido');
    });
});

describe('estado de cobro con días (D-407)', () => {
    const base = {
        collection_status: 'unpaid' as const,
        due_on: '2026-10-01',
        total: '1452.00',
        pending_total: '1452.00',
    };

    it('vencida, vence hoy, vence mañana o en N días', () => {
        expect(relativeCollection(base, '2026-10-09')).toEqual({
            status: 'overdue',
            label: 'Vencida hace 8 días',
            days: 8,
        });
        expect(relativeCollection(base, '2026-10-02').label).toBe(
            'Vencida hace 1 día',
        );
        expect(relativeCollection(base, '2026-10-01').label).toBe('Vence hoy');
        expect(relativeCollection(base, '2026-09-30').label).toBe(
            'Vence mañana',
        );
        expect(relativeCollection(base, '2026-09-26')).toEqual({
            status: 'unpaid',
            label: 'Vence en 5 días',
            days: 5,
        });
    });

    it('cobrada en parte con su porcentaje; cobrada, anulada y borrador sin días', () => {
        expect(
            relativeCollection(
                {
                    ...base,
                    collection_status: 'partial',
                    pending_total: '871.20',
                },
                '2026-09-20',
            ).label,
        ).toBe('Cobrada en parte · 40 %');
        expect(
            relativeCollection(
                { ...base, collection_status: 'paid', pending_total: '0' },
                '2026-10-09',
            ),
        ).toEqual({ status: 'paid', label: 'Cobrada', days: null });
        expect(
            relativeCollection(
                { ...base, collection_status: 'cancelled' },
                '2026-10-09',
            ).label,
        ).toBe('Anulada');
        expect(
            relativeCollection(
                { ...base, collection_status: 'draft', is_draft: true },
                '2026-10-09',
            ).label,
        ).toBe('Borrador');
        // Sin nada pendiente, cobrada aunque Holded no la haya vuelto a leer.
        expect(
            relativeCollection({ ...base, pending_total: '0.00' }, '2026-10-09')
                .status,
        ).toBe('paid');
    });

    it('cuenta días naturales entre fechas, sin zonas', () => {
        expect(daysBetween('2026-03-28', '2026-03-30')).toBe(2);
        expect(daysBetween('2026-10-30', '2026-10-25')).toBe(-5);
    });
});

describe('última lectura de Holded (R6, D-409)', () => {
    const now = new Date('2026-10-09T10:00:00Z');

    it('cuánto hace', () => {
        expect(timeAgo('2026-10-09T09:59:40Z', now)).toBe('ahora mismo');
        expect(timeAgo('2026-10-09T09:48:00Z', now)).toBe('hace 12 min');
        expect(timeAgo('2026-10-09T07:00:00Z', now)).toBe('hace 3 h');
        expect(timeAgo('2026-10-07T07:00:00Z', now)).toBe('hace 2 días');
    });

    it('bien, vieja (más de 26 h), fallida, en curso o nunca', () => {
        const run = {
            status: 'ok' as const,
            started_at: '2026-10-08T20:00:00Z',
            finished_at: '2026-10-08T20:01:00Z',
        };
        expect(syncTone(run, now)).toBe('ok');
        expect(
            syncTone(
                {
                    ...run,
                    started_at: '2026-10-08T07:00:00Z',
                    finished_at: '2026-10-08T07:01:00Z',
                },
                now,
            ),
        ).toBe('stale');
        expect(syncTone({ ...run, status: 'failed' }, now)).toBe('failed');
        expect(syncTone({ ...run, status: 'running' }, now)).toBe('running');
        expect(syncTone(null, now)).toBe('never');
    });
});

describe('listado de facturas (D-406)', () => {
    it('la query en el formato de Laravel, sin vacíos', () => {
        expect(toQueryString({})).toBe('');
        expect(
            toQueryString({
                vista: 'vencidas',
                buscar: '',
                cliente: null,
                servicio: ['fees', 'bolsas'],
                pagina: 2,
            }),
        ).toBe(
            '?vista=vencidas&servicio%5B%5D=fees&servicio%5B%5D=bolsas&pagina=2',
        );
    });

    it('el periodo con su nombre', () => {
        expect(periodLabel('anio', '2026-10-09', null, null)).toBe('Este año');
        expect(periodLabel('anio-anterior', '2026-10-09', null, null)).toBe(
            '2025',
        );
        expect(
            periodLabel('rango', '2026-10-09', '2026-02-01', '2026-02-28'),
        ).toBe('Del 01/02/2026 al 28/02/2026');
        expect(periodLabel('todo', '2026-10-09', null, null)).toBe('Todo');
    });

    it('la barra de importes filtra por tramo y no deja elegir uno vacío', () => {
        const onSelect = vi.fn();
        render(
            <CollectionBar
                data={{
                    vencido: { amount: '11900.35', count: 5 },
                    'por-vencer': { amount: '0.00', count: 0 },
                    cobrado: { amount: '473330.83', count: 22 },
                }}
                selected="cobrado"
                onSelect={onSelect}
            />,
        );
        const bar = screen.getByTestId('collection-bar');

        expect(
            within(bar).getByRole('heading', { name: 'Cobros (con IVA)' }),
        ).toBeTruthy();
        fireEvent.click(screen.getByTestId('collection-vencido'));
        expect(onSelect).toHaveBeenLastCalledWith('vencido');
        expect(
            screen
                .getByTestId('collection-por-vencer')
                .hasAttribute('disabled'),
        ).toBe(true);
        // El tramo elegido se quita al volver a pulsarlo.
        expect(
            screen
                .getByTestId('collection-cobrado')
                .getAttribute('aria-pressed'),
        ).toBe('true');
        fireEvent.click(screen.getByTestId('collection-cobrado'));
        expect(onSelect).toHaveBeenLastCalledWith(null);
        expect(bar.textContent).toContain('5 facturas');
        expect(bar.textContent).toContain('Ninguna factura');
    });

    it('las vistas llevan su número y marcan la actual', () => {
        render(
            <ViewTabs
                label="Vistas de las facturas"
                current="vencidas"
                tabs={[
                    {
                        id: 'todas',
                        label: 'Todas',
                        href: '/facturacion/facturas',
                        count: 27,
                    },
                    {
                        id: 'vencidas',
                        label: 'Vencidas',
                        href: '/facturacion/facturas?vista=vencidas',
                        count: 5,
                    },
                ]}
            />,
        );
        const current = screen.getByRole('link', { name: /^Vencidas\s*5/ });

        expect(current.getAttribute('aria-current')).toBe('page');
        expect(
            screen
                .getByRole('link', { name: /^Todas\s*27/ })
                .getAttribute('aria-current'),
        ).toBeNull();
    });
});

describe('línea de tiempo de la ficha (D-408)', () => {
    const invoice = {
        id: 14,
        number: 'F260314',
        kind: 'invoice',
        issued_on: '2026-08-31',
        due_on: '2026-09-30',
        contact_name: 'Construcciones Lamas',
        client: { id: 3, name: 'Construcciones Lamas' },
        currency: 'EUR',
        subtotal: '4035.00',
        tax_total: '847.35',
        total: '4882.35',
        paid_total: '1000.00',
        pending_total: '3882.35',
        collection_status: 'partial',
        is_draft: false,
        tags: [],
        links: [
            {
                id: 1,
                method: 'manual',
                project: { id: 2, code: 'LAM-INT', name: 'Intranet' },
                bank: null,
                created_by: 'Ana',
                created_at: '2026-10-08T08:30:00Z',
            },
            {
                id: 2,
                method: 'f_code',
                project: { id: 2, code: 'LAM-INT', name: 'Intranet' },
                bank: null,
            },
        ],
        holded_status: null,
        notes: null,
        synced_at: '2026-10-08T20:00:00Z',
        pdf_stored: true,
        lines: [],
        payments: [
            {
                id: 9,
                paid_on: '2026-09-15',
                amount: '1000.00',
                method: 'Transferencia',
            },
        ],
        rectified: null,
        rectifications: [
            {
                id: 20,
                number: 'CN260002',
                issued_on: '2026-10-02',
                subtotal: '-100.00',
            },
        ],
    } as HoldedInvoiceDetail;

    it('emitida, cobro, vencimiento, rectificativa, enlace a mano y la lectura, en orden', () => {
        const events = invoiceTimeline(invoice, '2026-10-09');

        expect(events.map((event) => event.key)).toEqual([
            'issued',
            'payment-9',
            'due',
            'credit-20',
            'link-1',
            'synced',
        ]);
        const due = events.find((event) => event.key === 'due');
        expect(due?.content).toBe('Venció hace 9 días y sigue pendiente');
        expect(due?.tone).toBe('danger');
        expect(String(events[1].content).replace(/[\u00a0\u202f]/g, ' ')).toBe(
            'Cobro de 1.000,00 € (Transferencia)',
        );
        expect(events[4].content).toBe('Enlazada a mano con LAM-INT por Ana');
    });

    it('anulada, al final y sin vencimiento pendiente', () => {
        const events = invoiceTimeline(
            {
                ...invoice,
                collection_status: 'cancelled',
                payments: [],
                rectifications: [],
                links: [],
                synced_at: null,
            },
            '2026-10-09',
        );

        expect(events.map((event) => event.key)).toEqual([
            'issued',
            'due',
            'cancelled',
        ]);
        expect(events[1].content).toBe('Vencimiento');
    });
});
