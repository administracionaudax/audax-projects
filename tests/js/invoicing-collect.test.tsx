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
import { AgingTable } from '@/components/invoicing/aging-table';
import {
    parseAmount,
    RecordPaymentDialog,
    SendDocumentDialog,
} from '@/components/invoicing/collect-dialogs';
import {
    fillTemplate,
    ruleLabel,
    sampleValues,
} from '@/components/invoicing/mailing-settings';
import { Button } from '@/components/ui/button';
import type { EmailCompose, SalesDocumentDetail } from '@/types';

configure({ testIdAttribute: 'data-test' });

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({
        url: '/facturacion/documentos/7',
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
| Los diálogos de E2 (D-436 a D-442): registrar un cobro (cuánto queda, no pasarse), enviar por email
| (destinatarios propuestos, modo de prueba) y las ayudas de Ajustes y de «Por cobrar».
*/

function invoice(
    overrides: Partial<SalesDocumentDetail> = {},
): SalesDocumentDetail {
    return {
        id: 7,
        view_id: -7,
        type: 'invoice',
        status: 'issued',
        is_test: false,
        number: 'F270001',
        series: null,
        issue_date: '2027-02-01',
        operation_date: null,
        due_date: '2027-03-03',
        client: { id: 1, name: 'Bodegas Arrieta' },
        client_name: 'Bodegas Arrieta',
        client_snapshot: null,
        language: 'es',
        subtotal: '1000.00',
        discount_total: '0.00',
        tax_total: '210.00',
        withholding_total: '0.00',
        withholding_rate: null,
        total: '1210.00',
        paid_total: '500.00',
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
        collection: 'partial',
        pending: '710.00',
        remaining: '710.00',
        sent_at: null,
        first_viewed_at: null,
        last_viewed_at: null,
        view_count: 0,
        payments: [],
        emails: [],
        reminder_problems: [],
        attachments: [],
        source: null,
        pdf_ready: true,
        pdf_sha256: null,
        lines: [],
        taxes: [],
        links: [],
        records: [],
        ...overrides,
    };
}

const METHODS = [
    { value: 'transfer' as const, label: 'Transferencia' },
    { value: 'cash' as const, label: 'Efectivo' },
];

describe('importes del diálogo de cobro', () => {
    it('entiende la coma, los puntos de miles y el símbolo del euro', () => {
        expect(parseAmount('710')).toBe('710.00');
        expect(parseAmount('1.210,5')).toBe('1210.50');
        expect(parseAmount('300,25 €')).toBe('300.25');
        expect(parseAmount('abc')).toBe('');
        expect(parseAmount('')).toBe('');
    });
});

describe('Registrar cobro (D-436)', () => {
    it('propone lo pendiente, dice cómo quedará y no deja pasarse', async () => {
        const user = userEvent.setup();
        const post = vi.spyOn(coreRouter, 'visit').mockImplementation(() => {});
        render(
            <RecordPaymentDialog
                document={invoice()}
                methods={METHODS}
                today="2027-02-10"
                trigger={<Button>Registrar cobro</Button>}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Registrar cobro' }),
        );
        const amount = screen.getByTestId('payment-amount');
        expect(amount).toHaveProperty('value', '710.00');
        expect(screen.getByTestId('payment-summary').textContent).toContain(
            'La factura quedará cobrada',
        );

        await user.clear(amount);
        await user.type(amount, '200');
        expect(screen.getByTestId('payment-summary').textContent).toMatch(
            /Quedarán 510,00\s€ pendientes/,
        );

        await user.clear(amount);
        await user.type(amount, '800');
        expect(screen.getByTestId('payment-summary').textContent).toContain(
            'Es más de lo pendiente',
        );
        expect(screen.getByTestId('payment-confirm')).toHaveProperty(
            'disabled',
            true,
        );

        await user.clear(amount);
        await user.type(amount, '200,50');
        await user.click(screen.getByTestId('payment-confirm'));
        expect(post).toHaveBeenCalled();
        const [url, options] = post.mock.calls[0] as [
            string,
            { method: string; data: Record<string, unknown> },
        ];
        expect(String(url)).toContain('/facturacion/documentos/7/cobros');
        expect(options.method).toBe('post');
        expect(options.data).toMatchObject({
            amount: '200.50',
            paid_on: '2027-02-10',
            method: 'transfer',
        });
    });

    it('en una rectificativa, la devolución', async () => {
        const user = userEvent.setup();
        render(
            <RecordPaymentDialog
                document={invoice({
                    type: 'credit_note',
                    total: '-1210.00',
                    paid_total: '0.00',
                    remaining: '1210.00',
                })}
                methods={METHODS}
                today="2027-02-10"
                trigger={<Button>Abrir</Button>}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Abrir' }));
        expect(
            screen.getByRole('heading', {
                name: 'Registrar la devolución de F270001',
            }),
        ).toBeTruthy();
        expect(screen.getByTestId('payment-confirm').textContent).toContain(
            'Registrar la devolución',
        );
    });
});

function compose(overrides: Partial<EmailCompose> = {}): EmailCompose {
    return {
        kind: 'invoice',
        language: 'es',
        subject: 'Factura F270001 de Audax Studio',
        body: 'Hola:\n\nAdjuntamos la factura F270001.',
        suggestions: [
            {
                email: 'facturas@bodegas.test',
                label: 'Email de facturación',
                source: 'billing',
                default: true,
            },
            {
                email: 'inigo@bodegas.test',
                label: 'Íñigo',
                source: 'holded',
                default: false,
            },
        ],
        to: ['facturas@bodegas.test'],
        attachments: ['F270001.pdf', 'contrato.pdf'],
        from: { name: 'Audax Studio', email: 'administracion@audaxstudio.com' },
        reply_to: null,
        bcc: 'copia@audaxstudio.com',
        link_days: 60,
        test_mode: false,
        self_email: 'ana@audax.test',
        ...overrides,
    };
}

describe('Enviar por email (D-438 a D-441)', () => {
    it('trae los destinatarios propuestos, deja añadir otro y quitarlo, y avisa de la copia oculta', async () => {
        const user = userEvent.setup();
        const fetch = vi
            .fn()
            .mockResolvedValue({ ok: true, json: async () => compose() });
        vi.stubGlobal('fetch', fetch);

        render(
            <SendDocumentDialog
                document={invoice()}
                trigger={<Button>Enviar</Button>}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Enviar' }));

        const to = await screen.findByTestId('send-to');
        expect(fetch.mock.calls[0][0]).toBe('/facturacion/documentos/7/envio');
        expect(to.textContent).toContain('facturas@bodegas.test');
        expect(
            screen.getByText(
                'Con copia oculta a copia@audaxstudio.com (Ajustes).',
            ),
        ).toBeTruthy();
        expect(screen.getByTestId('send-attachments').textContent).toContain(
            'contrato.pdf',
        );

        // La propuesta del contacto de Holded se añade con un clic.
        await user.click(
            within(to).getByRole('button', {
                name: /Íñigo · inigo@bodegas.test/,
            }),
        );
        expect(to.textContent).toContain('inigo@bodegas.test');

        // Un email mal escrito no entra.
        const input = within(to).getByRole('textbox');
        await user.type(input, 'no-es-email{Enter}');
        expect(screen.getByText('Ese email no es válido.')).toBeTruthy();

        // Sin destinatarios no se puede enviar.
        await user.clear(input);
        await user.click(
            within(to).getByRole('button', {
                name: 'Quitar facturas@bodegas.test',
            }),
        );
        await user.click(
            within(to).getByRole('button', {
                name: 'Quitar inigo@bodegas.test',
            }),
        );
        expect(screen.getByTestId('send-confirm')).toHaveProperty(
            'disabled',
            true,
        );
    });

    it('en modo de prueba avisa de que solo te llega a ti y el recordatorio pide su plantilla', async () => {
        const user = userEvent.setup();
        const fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => compose({ kind: 'reminder', test_mode: true }),
        });
        vi.stubGlobal('fetch', fetch);

        render(
            <SendDocumentDialog
                document={invoice()}
                reminder
                trigger={<Button>Recordar</Button>}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Recordar' }));

        await waitFor(() =>
            expect(screen.getByTestId('send-test-mode')).toBeTruthy(),
        );
        expect(fetch.mock.calls[0][0]).toBe(
            '/facturacion/documentos/7/envio?recordatorio=1',
        );
        expect(screen.getByTestId('send-test-mode').textContent).toContain(
            'solo te llegará a ti (ana@audax.test)',
        );
        expect(screen.getByTestId('send-confirm').textContent).toContain(
            'Enviármelo a mí',
        );
    });

    it('si no se puede preparar, lo dice', async () => {
        const user = userEvent.setup();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({ ok: false, status: 500 }),
        );

        render(
            <SendDocumentDialog
                document={invoice()}
                trigger={<Button>Enviar</Button>}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Enviar' }));

        expect(
            await screen.findByText(/No se ha podido preparar el email/),
        ).toBeTruthy();
    });
});

describe('Ajustes del correo y los recordatorios (D-439 y D-442)', () => {
    it('la vista previa cambia las variables conocidas y deja las demás', () => {
        const values = sampleValues('Audax Studio');
        expect(
            fillTemplate(
                'Factura [numero] de [empresa] para [cliente]',
                values,
            ),
        ).toBe('Factura F270001 de Audax Studio para Hoteles Mirador, S.A.');
        expect(fillTemplate('[desconocida] y [importe]', values)).toBe(
            '[desconocida] y 1.210,00 €',
        );
    });

    it('cada escalón dice cuándo sale', () => {
        expect(ruleLabel(-3)).toBe('3 días antes del vencimiento');
        expect(ruleLabel(0)).toBe('El día del vencimiento');
        expect(ruleLabel(1)).toBe('1 día después del vencimiento');
        expect(ruleLabel(30)).toBe('30 días después del vencimiento');
    });
});

describe('«Por cobrar» con la antigüedad por cliente (D-437)', () => {
    it('cada celda filtra por cliente y tramo, y la elegida se quita al volver a pulsarla', () => {
        render(
            <AgingTable
                aging={{
                    rows: [
                        {
                            client: { id: 4, name: 'Bodegas Arrieta' },
                            contact_name: null,
                            buckets: {
                                current: '0.00',
                                d1_30: '710.00',
                                d31_60: '0.00',
                                d61_90: '0.00',
                                d90_plus: '500.00',
                            },
                            total: '1210.00',
                            count: 2,
                        },
                    ],
                    totals: {
                        current: '0.00',
                        d1_30: '710.00',
                        d31_60: '0.00',
                        d61_90: '0.00',
                        d90_plus: '500.00',
                    },
                    total: '1210.00',
                    more: 0,
                }}
                query={{ vista: 'por-cobrar', buscar: 'vendimia' }}
                selected={{ client: 4, age: 'mas-90' }}
            />,
        );

        const recent = screen.getByRole('link', {
            name: 'Ver lo pendiente de Bodegas Arrieta, 1 a 30 días',
        });
        expect(recent.getAttribute('href')).toBe(
            '/facturacion/facturas?vista=por-cobrar&buscar=vendimia&cliente=4&antiguedad=1-30',
        );

        const old = screen.getByRole('link', {
            name: 'Ver lo pendiente de Bodegas Arrieta, Más de 90 días',
        });
        expect(old.getAttribute('aria-current')).toBe('true');
        expect(old.getAttribute('href')).toBe(
            '/facturacion/facturas?vista=por-cobrar&buscar=vendimia',
        );
        expect(screen.getByText('2 facturas')).toBeTruthy();
    });
});
