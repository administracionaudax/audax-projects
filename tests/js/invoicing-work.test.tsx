// @vitest-environment jsdom
import { router as coreRouter } from '@inertiajs/core';
import {
    configure,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { invoiceTabs } from '@/components/invoicing/invoice-views';
import {
    periodicityText,
    RecurringStateBadge,
} from '@/components/invoicing/recurring-parts';
import {
    nextDay,
    ScheduleDialog,
} from '@/components/invoicing/schedule-dialog';
import {
    defaultSelection,
    selectionPayload,
    selectionTotal,
    WorkDraftSheet,
} from '@/components/invoicing/work-draft-sheet';
import { Button } from '@/components/ui/button';
import type { RecurringRow, SalesDocumentDetail, UnbilledWork } from '@/types';

configure({ testIdAttribute: 'data-test' });

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({
        url: '/facturacion/por-facturar',
        props: { auth: { can: {} }, errors: {} },
    }),
    Link: ({
        href,
        children,
        preserveScroll: _p,
        preserveState: _s,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        preserveScroll?: boolean;
        preserveState?: boolean;
        [key: string]: unknown;
    }) => (
        <a href={href} {...(rest as Record<string, string>)}>
            {children}
        </a>
    ),
}));

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

/*
| E3: el panel «Crear borrador» desde el trabajo (lo que llega marcado, el total al céntimo, lo que se
| manda y el exceso que se ofrece con la bolsa siguiente), el diálogo de programar y las piezas de
| las recurrentes.
*/

const WORK: UnbilledWork = {
    client: { id: 3, name: 'Bodegas Arrieta' },
    from: '2027-01-01',
    to: '2027-02-15',
    grouping: 'task',
    hours: [
        {
            project: { id: 10, code: 'ARR-WEB', name: 'Web' },
            minutes: 207,
            amount: '229.84',
            pending_minutes: 45,
            drafts: [],
            lines: [
                {
                    key: 'task:1@65.00',
                    label: 'Portada',
                    description: 'Portada · enero de 2027',
                    minutes: 140,
                    rate: '65.00',
                    amount: '151.67',
                    entry_ids: [1, 2],
                    service_id: 5,
                },
                {
                    key: 'task:2@70.00',
                    label: 'Carrito',
                    description: 'Carrito · enero de 2027',
                    minutes: 67,
                    rate: '70.00',
                    amount: '78.17',
                    entry_ids: [3],
                    service_id: null,
                },
            ],
        },
    ],
    banks: [
        {
            bank: { id: 21, name: 'Bolsa febrero' },
            project: { id: 11, code: 'ARR-BDH', name: 'Mantenimiento' },
            minutes: 6000,
            price: '5100.00',
            start_date: '2027-02-01',
            previous: { id: 20, name: 'Bolsa enero' },
            line: {
                quantity: '100.0000',
                unit: 'hour',
                unit_price: '60.0000',
                discount_pct: '15.00',
                description: 'Bolsa de horas 100h febrero 2027 · Mantenimiento',
                service_id: 6,
            },
        },
    ],
    overage: [
        {
            bank: { id: 20, name: 'Bolsa enero' },
            project: { id: 11, code: 'ARR-BDH', name: 'Mantenimiento' },
            next_bank: { id: 21, name: 'Bolsa febrero' },
            minutes: 30,
            amount: '30.00',
            lines: [
                { rate: '60.00', minutes: 30, amount: '30.00', entry_ids: [9] },
            ],
        },
    ],
    fees: [
        {
            project: { id: 12, code: 'ARR-FEE', name: 'Redes' },
            month: '2027-01',
            amount: '1200.00',
            description: 'Fee de enero de 2027 · Redes',
        },
    ],
    fixed: [
        {
            project: { id: 13, code: 'ARR-APP', name: 'App' },
            price: '10000.00',
            invoiced: '5000.00',
            pending: '5000.00',
            description: 'App · Hito',
        },
    ],
};

describe('lo que llega marcado y el total (E3)', () => {
    it('marca las horas, las bolsas, el exceso que va con una bolsa marcada y los fees; no los precios cerrados', () => {
        const selection = defaultSelection(WORK);

        expect([...selection.hours]).toEqual([
            '10|task:1@65.00',
            '10|task:2@70.00',
        ]);
        expect([...selection.banks]).toEqual([21]);
        expect([...selection.overage]).toEqual([20]);
        expect([...selection.fees]).toEqual(['12|2027-01']);
        expect(selection.fixed[13]).toEqual({
            on: false,
            amount: '5000.00',
            description: 'App · Hito',
        });
        // 151,67 + 78,17 + 5.100 + 30 + 1.200, al céntimo.
        expect(selectionTotal(WORK, selection)).toBe('6559.84');
    });

    it('el exceso de una bolsa renovada no llega marcado si su bolsa siguiente no está en la lista', () => {
        const selection = defaultSelection({ ...WORK, banks: [] });

        expect([...selection.overage]).toEqual([]);
    });

    it('manda las entradas de cada línea marcada y el importe de un hito con coma', () => {
        const selection = defaultSelection(WORK);
        selection.hours.delete('10|task:2@70.00');
        selection.fixed[13] = {
            on: true,
            amount: '2500,50',
            description: 'App · 25 %',
        };

        expect(selectionTotal(WORK, selection)).toBe('8982.17');
        expect(selectionPayload(WORK, selection, 'person')).toEqual({
            agrupar: 'person',
            entries: [1, 2],
            overage_entries: [9],
            banks: [21],
            fees: [{ project_id: 12, month: '2027-01' }],
            fixed: [
                {
                    project_id: 13,
                    amount: '2500.50',
                    description: 'App · 25 %',
                },
            ],
        });
    });
});

describe('el panel «Crear borrador»', () => {
    it('carga lo pendiente, deja desmarcar y crea el borrador con lo marcado', async () => {
        const user = userEvent.setup();
        const fetchMock = vi
            .fn()
            .mockResolvedValue({ ok: true, json: async () => WORK });
        vi.stubGlobal('fetch', fetchMock);
        const visit = vi
            .spyOn(coreRouter, 'visit')
            .mockImplementation(() => {});

        render(
            <WorkDraftSheet
                client={{ id: 3, name: 'Bodegas Arrieta' }}
                from="2027-01-01"
                to="2027-02-15"
                trigger={<Button>Crear borrador</Button>}
            />,
        );
        await user.click(
            screen.getByRole('button', { name: 'Crear borrador' }),
        );

        await waitFor(() =>
            expect(screen.getByTestId('work-hours')).toBeTruthy(),
        );
        expect(String(fetchMock.mock.calls[0][0])).toContain(
            '/facturacion/por-facturar/clientes/3/borrador?desde=2027-01-01&hasta=2027-02-15&agrupar=task',
        );
        expect(screen.getByTestId('work-total').textContent).toMatch(
            /6559,84\s€|6\.559,84\s€/,
        );
        // Lo pendiente de aprobar se ve aparte y no entra.
        expect(screen.getByTestId('work-hours').textContent).toContain(
            'sin aprobar',
        );

        await user.click(
            within(screen.getByTestId('work-fees')).getByRole('checkbox'),
        );
        expect(screen.getByTestId('work-total').textContent).toMatch(
            /5\.359,84\s€|5359,84\s€/,
        );

        await user.click(screen.getByTestId('work-create'));
        expect(visit).toHaveBeenCalled();
        const [url, options] = visit.mock.calls[0] as [
            string,
            { method: string; data: Record<string, unknown> },
        ];
        expect(String(url)).toContain(
            '/facturacion/por-facturar/clientes/3/borrador',
        );
        expect(options.method).toBe('post');
        expect(options.data).toMatchObject({
            agrupar: 'task',
            entries: [1, 2, 3],
            banks: [21],
            overage_entries: [9],
            fees: [],
        });
    });

    it('sin nada pendiente, lo dice', async () => {
        const user = userEvent.setup();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    ...WORK,
                    hours: [],
                    banks: [],
                    overage: [],
                    fees: [],
                    fixed: [],
                }),
            }),
        );

        render(
            <WorkDraftSheet
                client={{ id: 3, name: 'Bodegas Arrieta' }}
                trigger={<Button>Crear borrador</Button>}
            />,
        );
        await user.click(
            screen.getByRole('button', { name: 'Crear borrador' }),
        );

        expect(
            await screen.findByText('Nada por facturar en este periodo'),
        ).toBeTruthy();
        expect(screen.queryByTestId('work-create')).toBeNull();
    });
});

function draftDocument(
    overrides: Partial<SalesDocumentDetail> = {},
): SalesDocumentDetail {
    return {
        id: 7,
        view_id: -7,
        type: 'invoice',
        status: 'draft',
        is_test: true,
        number: null,
        series: null,
        issue_date: '2027-02-15',
        operation_date: null,
        due_date: null,
        client: { id: 3, name: 'Bodegas Arrieta' },
        client_name: 'Bodegas Arrieta',
        client_snapshot: null,
        language: 'es',
        subtotal: '100.00',
        discount_total: '0.00',
        tax_total: '21.00',
        withholding_total: '0.00',
        withholding_rate: null,
        total: '121.00',
        paid_total: '0.00',
        body: null,
        internal_note: null,
        customer_reference: null,
        payment_method: null,
        payment_text: null,
        rectification: null,
        rectifications: [],
        cancelled_by: null,
        voided: null,
        no_project: null,
        issued_at: null,
        issued_by: null,
        created_at: null,
        created_by: null,
        collection: 'unpaid',
        pending: '0.00',
        remaining: '0.00',
        sent_at: null,
        first_viewed_at: null,
        last_viewed_at: null,
        view_count: 0,
        payments: [],
        emails: [],
        reminder_problems: [],
        attachments: [],
        source: null,
        pdf_ready: false,
        pdf_sha256: null,
        lines: [],
        taxes: [],
        links: [],
        records: [],
        ...overrides,
    } as SalesDocumentDetail;
}

describe('Programar la emisión', () => {
    it('propone mañana, avisa del modo de prueba y programa con «Enviar al emitir»', async () => {
        const user = userEvent.setup();
        const visit = vi
            .spyOn(coreRouter, 'visit')
            .mockImplementation(() => {});

        render(
            <ScheduleDialog
                document={draftDocument()}
                today="2027-02-15"
                autoActive={false}
                trigger={<Button>Programar…</Button>}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Programar…' }));

        expect(screen.getByTestId('schedule-test-mode').textContent).toContain(
            'no se emitirá sola',
        );
        await user.click(screen.getByRole('checkbox'));
        await user.click(screen.getByTestId('schedule-submit'));

        const [url, options] = visit.mock.calls[0] as [
            string,
            { method: string; data: Record<string, unknown> },
        ];
        expect(String(url)).toContain('/facturacion/documentos/7/programar');
        expect(options.data).toEqual({
            scheduled_for: '2027-02-16',
            scheduled_send: true,
        });
    });

    it('el día siguiente, también a fin de mes y de año', () => {
        expect(nextDay('2027-02-28')).toBe('2027-03-01');
        expect(nextDay('2028-02-28')).toBe('2028-02-29');
        expect(nextDay('2026-12-31')).toBe('2027-01-01');
    });
});

const ROW: RecurringRow = {
    id: 1,
    url: '/facturacion/recurrentes/1',
    name: 'Fee',
    client: { id: 3, name: 'Bodegas Arrieta' },
    project: null,
    periodicity: 'monthly',
    interval_months: 1,
    day_of_month: 29,
    next_run_on: '2027-02-28',
    subtotal: '1200.00',
    mode: 'draft',
    auto_send: false,
    state: 'active',
    from_holded: false,
    reviewed: false,
    occurrences_count: 0,
    max_occurrences: null,
    end_date: null,
    last_error: null,
};

describe('las piezas de las recurrentes', () => {
    it('la periodicidad y el día en palabras', () => {
        expect(periodicityText(ROW)).toBe('Mensual · día 29');
        expect(
            periodicityText({
                ...ROW,
                periodicity: 'custom',
                interval_months: 4,
                day_of_month: 31,
            }),
        ).toBe('Cada 4 meses · último día');
    });

    it('el estado: activa, de Holded por revisar, con un error', () => {
        const { rerender } = render(<RecurringStateBadge row={ROW} />);
        expect(screen.getByText('Activa')).toBeTruthy();
        rerender(
            <RecurringStateBadge
                row={{ ...ROW, state: 'paused', from_holded: true }}
            />,
        );
        expect(screen.getByText('De Holded, por revisar')).toBeTruthy();
        rerender(
            <RecurringStateBadge
                row={{ ...ROW, last_error: 'Falta el NIF' }}
            />,
        );
        expect(screen.getByText('Con un error')).toBeTruthy();
    });

    it('las pestañas de Facturas: «Programadas» solo con alguna y «Recurrentes» con la emisión', () => {
        const href = (view: string) => `/facturacion/facturas?vista=${view}`;
        const without = invoiceTabs({
            views: { todas: 3, programadas: 0 },
            current: 'todas',
            href,
            recurring: null,
        }).map((tab) => tab.id);
        expect(without).not.toContain('programadas');
        expect(without).not.toContain('recurrentes');

        const tabs = invoiceTabs({
            views: { todas: 3, programadas: 2 },
            current: 'recurrentes',
            href,
            recurring: 5,
        });
        expect(tabs.map((tab) => tab.id)).toContain('programadas');
        expect(tabs.at(-1)).toEqual({
            id: 'recurrentes',
            label: 'Recurrentes',
            href: '/facturacion/recurrentes',
            count: 5,
        });
    });
});
