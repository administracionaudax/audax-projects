// @vitest-environment jsdom
import { router as coreRouter } from '@inertiajs/core';
import { configure, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    documentActions,
    documentRole,
} from '@/components/invoicing/document-actions';
import type { DocumentState } from '@/components/invoicing/document-actions';
import {
    formatQuantity,
    taxLabel,
} from '@/components/invoicing/invoicing-format';
import { computeTotals } from '@/components/invoicing/totals';
import type { TotalsLineInput } from '@/components/invoicing/totals';
import { TooltipProvider } from '@/components/ui/tooltip';
import InvoiceEditor from '@/pages/billing/documents/edit';
import type { Abilities, EditorOptions } from '@/types';
import actionsFixture from '../fixtures/billing/document-actions.json';
import totalsFixture from '../fixtures/billing/totals.json';

configure({ testIdAttribute: 'data-test' });

let abilities: Partial<Abilities> = { useInvoicing: true, manageBilling: true };

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({
        url: '/facturacion/facturas/nueva',
        props: { auth: { can: abilities }, billingNav: null, errors: {} },
    }),
    Link: ({
        href,
        children,
        preserveScroll: _p,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        preserveScroll?: boolean;
        [key: string]: unknown;
    }) => (
        <a href={href} {...(rest as Record<string, string>)}>
            {children}
        </a>
    ),
}));

afterEach(() => {
    vi.restoreAllMocks();
    abilities = { useInvoicing: true, manageBilling: true };
});

/*
| La emisión propia en el navegador (PLAN-EMISION E1; D-422 y D-428): los totales con los mismos
| casos que el servidor, la matriz estado → acciones compartida y el editor de factura.
*/

describe('totales de la factura (D-422)', () => {
    it.each(totalsFixture.cases.map((one) => [one.name, one] as const))(
        '%s',
        (_name, one) => {
            expect(
                computeTotals(one.lines as TotalsLineInput[], one.withholding),
            ).toEqual(one.expected);
        },
    );

    it('lo que no es un número cuenta como 0, sin romper el editor', () => {
        expect(
            computeTotals(
                [
                    {
                        quantity: 'abc',
                        unit_price: '10',
                        discount_pct: '',
                        tax: null,
                    },
                ],
                null,
            ).subtotal,
        ).toBe('0.00');
        expect(
            computeTotals(
                [
                    {
                        quantity: '2,5',
                        unit_price: '10',
                        discount_pct: '0',
                        tax: null,
                    },
                ],
                null,
            ).subtotal,
        ).toBe('25.00');
    });
});

describe('matriz estado → acciones (D-428)', () => {
    it.each(
        actionsFixture.cases.map(
            (one) =>
                [`${one.state} · ${one.role ?? 'sin papel'}`, one] as const,
        ),
    )('%s', (_name, one) => {
        expect(
            documentActions(
                one.state as DocumentState,
                one.role as Parameters<typeof documentActions>[1],
            ),
        ).toEqual(one.actions);
    });

    it('el papel sale de las habilidades compartidas', () => {
        expect(documentRole({ useInvoicing: true })).toBe('view');
        expect(documentRole({ useInvoicing: true, manageBilling: true })).toBe(
            'manage',
        );
        expect(
            documentRole({
                useInvoicing: true,
                manageBilling: true,
                voidInvoices: true,
            }),
        ).toBe('admin');
        expect(documentRole({})).toBeNull();
    });
});

describe('formato', () => {
    it('cantidades sin ceros de más y el nombre de cada impuesto', () => {
        expect(formatQuantity('8.0000')).toBe('8');
        expect(formatQuantity('7.5000')).toBe('7,5');
        expect(taxLabel('S1', '21.00')).toBe('IVA 21 %');
        expect(taxLabel('N2', '0.00')).toBe('No sujeta');
        expect(taxLabel('E1', '0.00')).toBe('Exenta');
        expect(taxLabel('S2', '0.00')).toBe('Inversión del sujeto pasivo');
    });
});

function options(overrides: Partial<EditorOptions> = {}): EditorOptions {
    return {
        clients: [
            {
                id: 1,
                name: 'Hoteles Mirador',
                missing: [],
                profile: {
                    tax_id: 'B87654321',
                    legal_name: 'Hoteles Mirador, S.L.',
                    eu_vat_number: null,
                    address: 'Avenida del Puerto, 10',
                    postal_code: '46021',
                    city: 'València',
                    province: 'Valencia',
                    country_code: 'ES',
                    tax_regime: 'general',
                    language: 'es',
                    payment_days: 30,
                    payment_method: null,
                    payment_day: null,
                    billing_emails: [],
                },
            },
            {
                id: 2,
                name: 'Sin Ficha',
                missing: ['tax_id', 'address'],
                profile: {
                    tax_id: null,
                    legal_name: null,
                    eu_vat_number: null,
                    address: null,
                    postal_code: null,
                    city: null,
                    province: null,
                    country_code: 'ES',
                    tax_regime: 'general',
                    language: 'es',
                    payment_days: null,
                    payment_method: null,
                    payment_day: null,
                    billing_emails: [],
                },
            },
        ],
        projects: [
            { id: 5, code: 'MIR-WEB', name: 'Web', client_id: 1, banks: [] },
        ],
        services: [
            {
                id: 9,
                code: 'DES',
                name: 'Desarrollo',
                description: null,
                unit: 'hour',
                unit_price: '60',
                tax_rate_id: 1,
            },
        ],
        taxes: [
            {
                id: 1,
                key: 'iva_21',
                name: 'IVA 21 %',
                rate: '21.00',
                operation_type: 'S1',
                is_default: true,
            },
            {
                id: 2,
                key: 'iva_10',
                name: 'IVA 10 %',
                rate: '10.00',
                operation_type: 'S1',
                is_default: false,
            },
        ],
        withholdings: [
            {
                id: 3,
                key: 'irpf_15',
                name: 'Retención IRPF 15 %',
                rate: '15.00',
                operation_type: null,
                is_default: false,
            },
        ],
        series: [
            {
                id: 4,
                code: 'F',
                name: 'Facturas',
                format: 'F[YY]####',
                is_test: false,
                is_default: true,
                starts_on: '2027-01-01',
                next: 'F270001',
            },
        ],
        payment_methods: [
            {
                id: 6,
                name: 'Transferencia bancaria',
                due_days: 30,
                is_default: true,
            },
        ],
        issuer_missing: [],
        today: '2027-01-04',
        ...overrides,
    };
}

function renderEditor(
    clientId: number | null = 1,
    extra: Partial<EditorOptions> = {},
) {
    return render(
        <TooltipProvider>
            <InvoiceEditor
                document={null}
                defaults={{
                    client_id: clientId,
                    project_id: null,
                    series_id: 4,
                    issue_date: '2027-01-04',
                    payment_method_id: 6,
                }}
                options={options(extra)}
                preview_token="token"
            />
        </TooltipProvider>,
    );
}

describe('el editor de factura (D-428)', () => {
    it('calcula la base de cada línea y los totales con su cuadro de impuestos al escribir', async () => {
        renderEditor();

        const quantity = screen.getByTestId('line-quantity');
        const price = screen.getByTestId('line-price');
        await userEvent.clear(quantity);
        await userEvent.type(quantity, '15');
        await userEvent.clear(price);
        await userEvent.type(price, '60');
        await userEvent.clear(screen.getByLabelText('Dto. %'));
        await userEvent.type(screen.getByLabelText('Dto. %'), '5');

        expect(screen.getByTestId('line-base').textContent).toMatch(
            /855,00\s€/,
        );
        const totals = screen.getByTestId('invoice-totals');
        expect(within(totals).getByText('Descuentos aplicados')).toBeTruthy();
        expect(
            within(totals).getByRole('row', { name: /IVA 21 %/ }).textContent,
        ).toMatch(/179,55\s€/);
        expect(screen.getByTestId('invoice-total').textContent).toMatch(
            /1034,55\s€|1\.034,55\s€/,
        );
    });

    it('avisa de los datos fiscales que faltan del cliente y ofrece completarlos', () => {
        renderEditor(2);

        expect(screen.getByTestId('client-fiscal').textContent).toContain(
            'Para emitir falta el NIF, la dirección.',
        );
        expect(screen.getByTestId('complete-fiscal').textContent).toBe(
            'Completar datos fiscales',
        );
        expect(screen.queryByTestId('intra-eu-warning')).toBeNull();
    });

    it('a un cliente intracomunitario sin NIF-IVA de la UE le avisa de que no se puede emitir (art. 25 LIVA)', () => {
        const base = options().clients[0];
        renderEditor(3, {
            clients: [
                {
                    ...base,
                    id: 3,
                    name: 'Agence Lumière',
                    missing: ['eu_vat_number'],
                    profile: {
                        ...base.profile,
                        tax_id: null,
                        country_code: 'FR',
                        tax_regime: 'intra_eu',
                    },
                },
            ],
        });

        expect(screen.getByTestId('client-fiscal').textContent).toContain(
            'Para emitir falta el NIF-IVA de la UE.',
        );
        expect(screen.getByTestId('intra-eu-warning').textContent).toContain(
            'art. 25 LIVA',
        );
    });

    it('«Guardar borrador» envía las líneas sin su clave y «Guardar y emitir…» pide emitir', async () => {
        const post = vi.spyOn(coreRouter, 'post').mockImplementation(() => {});
        renderEditor();

        await userEvent.click(screen.getByTestId('add-line'));
        expect(screen.getAllByTestId('editor-line')).toHaveLength(2);
        await userEvent.click(screen.getByTestId('editor-save'));

        const [url, data] = post.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/facturacion/documentos');
        expect(data.client_id).toBe(1);
        expect(data.series_id).toBe(4);
        expect(data.due_date).toBe('2027-02-03');
        expect(data.after).toBeNull();
        expect((data.lines as Record<string, unknown>[])[0]).not.toHaveProperty(
            'key',
        );
        expect((data.lines as Record<string, unknown>[])[0].tax_rate_id).toBe(
            1,
        );

        await userEvent.click(screen.getByTestId('editor-save-issue'));
        expect(
            (
                post.mock.calls[1] as unknown as [
                    string,
                    Record<string, unknown>,
                ]
            )[1].after,
        ).toBe('emitir');
    });

    it('quien solo prepara no ve «Guardar y emitir…»', () => {
        abilities = { useInvoicing: true, manageBilling: false };
        renderEditor();

        expect(screen.queryByTestId('editor-save-issue')).toBeNull();
        expect(
            screen.getByText(
                'Prepara el borrador: lo emite quien tiene permiso para emitir facturas.',
            ),
        ).toBeTruthy();
    });

    it('avisa si faltan datos del emisor', () => {
        renderEditor(1, { issuer_missing: ['tax_id'] });

        expect(screen.getByTestId('issuer-missing').textContent).toContain(
            'Faltan datos del emisor para poder emitir: el NIF.',
        );
    });
});
