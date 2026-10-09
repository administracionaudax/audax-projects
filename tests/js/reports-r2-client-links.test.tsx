// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ClientShow from '@/pages/clients/show';
import type { ClientShowProps } from '@/types';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    setLayoutProps: () => {},
    usePage: () => ({
        url: '/clientes/1',
        props: {
            auth: {
                user: null,
                can: {
                    viewHourBanks: true,
                    viewAdmin: false,
                    viewFinancials: false,
                    createClients: false,
                    createProjects: false,
                    approveTime: false,
                    lockTime: false,
                    manageUsers: false,
                    manageSettings: false,
                },
            },
            config: {
                hour_bank_thresholds: [75, 90, 100],
                timer_warning_hours: 10,
                timer_rounding_minutes: 1,
            },
        },
    }),
    router: { get: vi.fn(), post: vi.fn(), on: () => () => {} },
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

const props = (can: ClientShowProps['can']): ClientShowProps => ({
    client: {
        id: 1,
        name: 'Bodega Ñandú',
        tax_id: null,
        contact_name: null,
        contact_email: null,
        phone: null,
        notes: null,
        is_active: true,
    },
    projects: [],
    hourBanks: [],
    hourBankHistory: [],
    hours: {
        month_minutes: 0,
        year_minutes: 0,
        month_start: '2026-09-01',
        year: 2026,
    },
    can,
});

describe('ficha de cliente: enlaces a los informes (R2)', () => {
    it('«Ver informe» y «Horas para facturar» con sus permisos, a sus URLs', () => {
        render(
            <ClientShow
                {...props({
                    update: false,
                    viewReport: true,
                    viewBilling: true,
                })}
            />,
        );

        expect(
            screen
                .getByRole('link', { name: 'Ver informe' })
                .getAttribute('href'),
        ).toBe('/informes/clientes/1');
        expect(
            screen
                .getByRole('link', { name: 'Horas para facturar' })
                .getAttribute('href'),
        ).toBe('/facturacion/por-facturar?cliente%5B%5D=1');
    });

    it('sin permisos no hay enlaces', () => {
        render(<ClientShow {...props({ update: false })} />);

        expect(screen.queryByRole('link', { name: 'Ver informe' })).toBeNull();
        expect(
            screen.queryByRole('link', { name: 'Horas para facturar' }),
        ).toBeNull();
    });

    it('solo con view-financials: exportar para facturar sí, el informe no', () => {
        render(
            <ClientShow
                {...props({
                    update: false,
                    viewReport: false,
                    viewBilling: true,
                })}
            />,
        );

        expect(screen.queryByRole('link', { name: 'Ver informe' })).toBeNull();
        expect(
            screen.getByRole('link', { name: 'Horas para facturar' }),
        ).toBeTruthy();
    });
});
