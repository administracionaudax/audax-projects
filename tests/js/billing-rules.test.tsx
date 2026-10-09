// @vitest-environment jsdom
import {
    configure,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    CreateClientButton,
    draftToForm,
} from '@/components/billing/create-client-dialog';
import {
    MarkNoProjectButton,
    NoProjectNeededPanel,
} from '@/components/billing/no-project-needed';
import {
    UnbilledLines,
    unbilledLineDetail,
} from '@/components/billing/unbilled-lines';
import { unbilledSources } from '@/components/billing/unbilled-sources';
import type {
    ClientDraftResponse,
    UnbilledDetail,
    UnbilledLine,
} from '@/types/billing-rules';

configure({ testIdAttribute: 'data-test' });

const calls = vi.hoisted(() => ({
    post: [] as { url: string; data: unknown }[],
    put: [] as { url: string; data: unknown }[],
    delete: [] as { url: string }[],
    formPost: [] as { url: string; data: unknown }[],
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: {
        post: (url: string, data: unknown) => calls.post.push({ url, data }),
        put: (url: string, data: unknown) => calls.put.push({ url, data }),
        delete: (url: string) => calls.delete.push({ url }),
    },
    // Un useForm mínimo: guarda los datos y apunta el envío (con su transform).
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setAll] = useState<T>(initial);
        let transform = (value: T): unknown => value;

        return {
            data,
            errors: {},
            processing: false,
            setData: (key: keyof T | T, value?: unknown) =>
                typeof key === 'object'
                    ? setAll(key)
                    : setAll((current) => ({ ...current, [key]: value })),
            transform: (callback: (value: T) => unknown) => {
                transform = callback;
            },
            post: (url: string) =>
                calls.formPost.push({ url, data: transform(data) }),
            clearErrors: () => undefined,
        };
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
| Respuestas del propietario del 09/10 en Facturación (D-430 a D-433): crear el cliente desde un
| contacto de Holded (relleno, con los parecidos y casar en vez de crear), «No necesita proyecto»
| (marcar con motivo y deshacer) y las líneas de «Por facturar» de un cliente (precio cerrado con su
| % consumido, exceso para la próxima bolsa o pasado a la siguiente, fees).
*/

const line = (overrides: Partial<UnbilledLine>): UnbilledLine => ({
    source: 'hours',
    project: { id: 1, code: 'OBR-WEB', name: 'Web de la obra' },
    bank: null,
    minutes: 0,
    pending_minutes: 0,
    amount: null,
    oldest: null,
    months: null,
    next_bank: null,
    fixed: null,
    ...overrides,
});

beforeEach(() => {
    calls.post.length = 0;
    calls.put.length = 0;
    calls.delete.length = 0;
    calls.formPost.length = 0;
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('de dónde sale lo que hay por facturar (D-432)', () => {
    it('cuenta los precios cerrados', () => {
        expect(
            unbilledSources({
                hours: 1,
                overage: 0,
                banks: 0,
                fees: 2,
                fixed: 1,
            }),
        ).toBe('Horas · 2 meses de fee · 1 precio cerrado');
        expect(
            unbilledSources({
                hours: 0,
                overage: 0,
                banks: 0,
                fees: 0,
                fixed: 3,
            }),
        ).toBe('3 precios cerrados');
    });
});

describe('el detalle de cada línea de «Por facturar» (D-432 y D-433)', () => {
    it('precio cerrado: lo facturado del precio y las horas, con el % si hay presupuesto', () => {
        const fixed = {
            price: '10000.00',
            invoiced: '5000.00',
            real_minutes: 3600,
            budget_minutes: 6000,
            consumption_pct: 60,
        };
        const detail = unbilledLineDetail(line({ source: 'fixed', fixed }));
        expect(detail).toContain('facturado de');
        expect(detail).toContain('60:00 aprobadas de 100:00');
        expect(detail).toMatch(/60\s%/);

        expect(
            unbilledLineDetail(
                line({
                    source: 'fixed',
                    fixed: {
                        ...fixed,
                        budget_minutes: null,
                        consumption_pct: null,
                    },
                }),
            ),
        ).toMatch(/· 60:00 aprobadas$/);
    });

    it('exceso: el de la bolsa activa, con la próxima; el de una renovada, pasado a la siguiente', () => {
        expect(
            unbilledLineDetail(
                line({ source: 'overage', minutes: 60, oldest: '2026-03-10' }),
            ),
        ).toBe('Se facturará con la próxima bolsa · Desde el 10/03/2026');
        expect(
            unbilledLineDetail(
                line({
                    source: 'carried',
                    minutes: 120,
                    next_bank: { id: 2, name: 'Bolsa febrero' },
                }),
            ),
        ).toBe('Pasado a la bolsa siguiente: Bolsa febrero');
    });

    it('fees: los meses sin factura', () => {
        expect(
            unbilledLineDetail(
                line({ source: 'fees', months: 2, oldest: '2026-02-01' }),
            ),
        ).toBe('2 meses sin factura · Desde el 01/02/2026');
    });

    it('la tabla: el exceso traspasado sin importe y el total de lo pendiente', () => {
        const detail: UnbilledDetail = {
            lines: [
                line({
                    source: 'overage',
                    bank: { id: 3, name: 'Bolsa febrero' },
                    minutes: 60,
                    amount: '50.00',
                }),
                line({
                    source: 'carried',
                    bank: { id: 2, name: 'Bolsa enero' },
                    minutes: 120,
                    next_bank: { id: 3, name: 'Bolsa febrero' },
                }),
            ],
            totals: { minutes: 60, pending_minutes: 0, amount: '50.00' },
            financials: true,
        };
        render(
            <UnbilledLines
                client="Bolsas y Más"
                detail={detail}
                from="2026-01-01"
                to="2026-12-31"
            />,
        );

        const carried = screen.getByTestId('unbilled-line-carried');
        expect(within(carried).getByText('En la bolsa siguiente')).toBeTruthy();
        expect(carried.textContent).toContain(
            'Pasado a la bolsa siguiente: Bolsa febrero',
        );
        expect(screen.getByTestId('unbilled-lines-total').textContent).toMatch(
            /50,00/,
        );
    });
});

describe('crear el cliente desde un contacto de Holded (D-430)', () => {
    const response: ClientDraftResponse = {
        draft: {
            name: 'NARANJAS DEL TURIA',
            tax_id: 'B46111222',
            email: null,
            legal_name: 'NARANJAS DEL TURIA, S.L.',
            address: 'Camí Real, 12',
            postal_code: '46100',
            city: 'Burjassot',
            province: null,
            country_code: 'ES',
        },
        candidates: [
            {
                id: 7,
                name: 'Turia Cítricos',
                tax_id: 'B46111222',
                is_active: true,
                reason: 'nif',
            },
        ],
    };

    it('los vacíos del contacto son texto vacío en el formulario', () => {
        expect(draftToForm(response.draft)).toMatchObject({
            email: '',
            province: '',
            country_code: 'ES',
        });
    });

    it('abre relleno, avisa del parecido y deja casar con él o crear otro', async () => {
        const fetchMock = vi.fn(async () => ({
            ok: true,
            json: async () => response,
        }));
        vi.stubGlobal('fetch', fetchMock);

        render(
            <CreateClientButton
                contact={{ id: 42, name: 'NARANJAS DEL TURIA, S.L.' }}
            />,
        );
        fireEvent.click(screen.getByTestId('create-client'));

        const name = (await screen.findByTestId(
            'create-client-name',
        )) as HTMLInputElement;
        expect(fetchMock).toHaveBeenCalledWith(
            '/facturacion/contactos/42/cliente',
            expect.objectContaining({ credentials: 'same-origin' }),
        );
        expect(name.value).toBe('NARANJAS DEL TURIA');
        expect(
            (
                screen.getByTestId(
                    'create-client-postal_code',
                ) as HTMLInputElement
            ).value,
        ).toBe('46100');

        const candidates = screen.getByTestId('create-client-candidates');
        expect(within(candidates).getByText('Turia Cítricos')).toBeTruthy();
        expect(candidates.textContent).toContain('Mismo NIF');

        // Casar con el que ya existe (la acción de siempre).
        fireEvent.click(within(candidates).getByTestId('create-client-match'));
        expect(calls.put).toEqual([
            {
                url: '/facturacion/contactos/42',
                data: { action: 'assign', client_id: 7 },
            },
        ]);

        // O crear otro, con lo editado y confirmando que es otro.
        fireEvent.change(name, { target: { value: 'Naranjas del Turia' } });
        const submit = screen.getByTestId('create-client-submit');
        expect(submit.textContent).toBe('Crear otro cliente');
        fireEvent.click(submit);
        await waitFor(() => expect(calls.formPost).toHaveLength(1));
        expect(calls.formPost[0]).toMatchObject({
            url: '/facturacion/contactos/42/cliente',
            data: {
                name: 'Naranjas del Turia',
                tax_id: 'B46111222',
                email: '',
                confirmed: true,
            },
        });
    });

    it('si no se pueden leer los datos, lo dice y no deja crear', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({ ok: false, status: 500 })),
        );
        render(<CreateClientButton contact={{ id: 1, name: 'Otro' }} />);
        fireEvent.click(screen.getByTestId('create-client'));

        expect(
            await screen.findByText(
                'No se han podido leer los datos del contacto. Vuelve a intentarlo.',
            ),
        ).toBeTruthy();
        expect(
            (screen.getByTestId('create-client-submit') as HTMLButtonElement)
                .disabled,
        ).toBe(true);
    });
});

describe('«No necesita proyecto» (D-431)', () => {
    it('marca con el motivo', () => {
        render(<MarkNoProjectButton invoice={{ id: 9, number: 'F260050' }} />);
        fireEvent.click(screen.getByTestId('no-project-mark'));
        fireEvent.change(screen.getByTestId('no-project-note'), {
            target: { value: '  Gastos repercutidos  ' },
        });
        fireEvent.click(screen.getByTestId('no-project-confirm'));

        expect(calls.post).toEqual([
            {
                url: '/facturacion/facturas/9/sin-proyecto',
                data: { note: 'Gastos repercutidos' },
            },
        ]);
    });

    it('sin motivo, va vacío', () => {
        render(<MarkNoProjectButton invoice={{ id: 9, number: null }} />);
        fireEvent.click(screen.getByTestId('no-project-mark'));
        fireEvent.click(screen.getByTestId('no-project-confirm'));

        expect(calls.post[0]?.data).toEqual({ note: null });
    });

    it('en la ficha: quién, cuándo y por qué, y «Necesita proyecto» lo deshace', () => {
        render(
            <NoProjectNeededPanel
                invoice={{
                    id: 9,
                    number: 'F260050',
                    no_project: {
                        at: '2026-03-20T09:00:00Z',
                        by: 'Ana Administración',
                        note: 'Gastos de la feria',
                    },
                }}
            />,
        );
        const panel = screen.getByTestId('invoice-no-project');
        expect(panel.textContent).toContain('«Gastos de la feria»');
        expect(panel.textContent).toContain(
            'Marcada por Ana Administración el 20/03/2026',
        );

        fireEvent.click(screen.getByTestId('no-project-undo'));
        expect(calls.delete).toEqual([
            { url: '/facturacion/facturas/9/sin-proyecto' },
        ]);
    });
});
