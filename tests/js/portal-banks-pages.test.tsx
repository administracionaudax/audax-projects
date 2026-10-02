// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';
import { cloneElement } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type {
    PortalBank,
    PortalBankShowProps,
    PortalHomeProps,
} from '@/components/portal/banks/types';
import PortalBankShow from '@/pages/portal/banks/show';
import PortalHome from '@/pages/portal/home';

/*
 * P1 · Páginas de bolsas del portal: el inicio (resumen, activas, anteriores y el estado vacío con el
 * degradado de marca) y el detalle (cifras, PDF, gráfica, horas e histórico), con un solo h1 y sin
 * saltos de nivel en los encabezados, y sin importes.
 */

const layout = vi.hoisted(() => ({ props: null as unknown }));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    setLayoutProps: (props: unknown) => {
        layout.props = props;
    },
    usePage: () => ({
        url: '/portal',
        props: {
            auth: {
                user: {
                    id: 9,
                    name: 'María José López',
                    email: 'mj@example.com',
                    avatar: null,
                    roles: ['client'],
                    is_client: true,
                },
                can: {},
            },
        },
    }),
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

vi.mock('recharts', async (importOriginal) => {
    const actual = await importOriginal<typeof import('recharts')>();

    return {
        ...actual,
        ResponsiveContainer: ({
            children,
        }: {
            children: ReactElement<{ width?: number; height?: number }>;
        }) => cloneElement(children, { width: 640, height: 240 }),
    };
});

vi.setConfig({ testTimeout: 20_000 });

function bank(overrides: Partial<PortalBank> = {}): PortalBank {
    return {
        id: 5,
        name: 'Bolsa Diseño ñ',
        project: { code: 'NAN-WEB', name: 'Web corporativa' },
        status: 'exhausted',
        start_date: '2026-07-01',
        end_date: null,
        closed_at: null,
        figures: {
            total_minutes: 1200,
            within_minutes: 1200,
            overage_minutes: 120,
            remaining_minutes: 0,
            percent: 1.1,
        },
        ...overrides,
    };
}

function homeProps(overrides: Partial<PortalHomeProps> = {}): PortalHomeProps {
    return {
        client: { name: 'Bodega Ñandú' },
        visibility: 'approved',
        thresholds: [75, 90, 100],
        summary: {
            month: '2026-10-01',
            month_minutes: 540,
            month_overage_minutes: 120,
            open_count: 2,
            near_limit_count: 1,
            first_threshold: 75,
        },
        banks: [
            bank({
                id: 7,
                name: 'Bolsa Marketing',
                project: { code: 'NAN-MKT', name: 'Campañas' },
                status: 'active',
                figures: {
                    total_minutes: 3000,
                    within_minutes: 300,
                    overage_minutes: 0,
                    remaining_minutes: 2700,
                    percent: 0.1,
                },
            }),
            bank(),
        ],
        history: [
            bank({
                id: 1,
                name: 'Bolsa primer semestre',
                status: 'renewed',
                start_date: '2026-01-01',
                end_date: '2026-06-30',
            }),
        ],
        ...overrides,
    };
}

function showProps(
    overrides: Partial<PortalBankShowProps> = {},
): PortalBankShowProps {
    return {
        bank: bank(),
        visibility: 'approved',
        thresholds: [75, 90, 100],
        months: [
            { month: '2026-09-01', within_minutes: 1080, overage_minutes: 0 },
            { month: '2026-10-01', within_minutes: 120, overage_minutes: 120 },
        ],
        entries: {
            data: [
                {
                    id: 3,
                    date: '2026-10-01',
                    task: 'Maquetación',
                    type: { name: 'Maquetación', color: '#179FA5' },
                    person: 'Luis Pérez',
                    minutes: 240,
                    overage_minutes: 120,
                    description: 'Ajustes finales',
                },
            ],
            meta: {
                current_page: 1,
                last_page: 1,
                per_page: 25,
                total: 1,
                from: 1,
                to: 1,
            },
            links: { prev: null, next: null },
        },
        filters: { mes: null },
        history: [
            {
                ...bank({
                    id: 1,
                    name: 'Bolsa primer semestre',
                    status: 'renewed',
                }),
                current: false,
            },
            { ...bank(), current: true },
        ],
        ...overrides,
    };
}

/** Un solo h1, el primero, y sin saltos de nivel (WCAG 1.3.1 y 2.4.6). */
function expectOutline(container: HTMLElement) {
    const levels = [
        ...container.querySelectorAll('h1, h2, h3, h4, h5, h6'),
    ].map((h) => Number(h.tagName.slice(1)));

    expect(levels.filter((level) => level === 1)).toHaveLength(1);
    expect(levels[0]).toBe(1);
    levels.slice(1).forEach((level, i) => {
        expect(level - levels[i]).toBeLessThanOrEqual(1);
    });
}

describe('Inicio del portal', () => {
    it('saluda, explica qué horas ve el cliente y enseña el resumen, las activas y las anteriores', () => {
        const { container } = render(<PortalHome {...homeProps()} />);

        expectOutline(container);
        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
            'Hola, María',
        );
        expect(screen.getByText('Bodega Ñandú')).toBeTruthy();
        expect(
            screen.getByText(/Solo se muestran las horas ya aprobadas/),
        ).toBeTruthy();
        expect(screen.getByRole('region', { name: 'Resumen' })).toBeTruthy();

        const open = screen.getByRole('region', { name: 'Tus bolsas activas' });
        const cards = within(open).getAllByRole('article');
        expect(cards).toHaveLength(2);
        expect(
            within(cards[0]).getByRole('heading', { level: 3 }).textContent,
        ).toBe('Bolsa Marketing');

        const history = screen.getByRole('region', {
            name: 'Bolsas anteriores',
        });
        expect(
            within(history)
                .getByRole('link', { name: 'Bolsa primer semestre' })
                .getAttribute('href'),
        ).toBe('/portal/bolsas/1');
        expect(container.textContent).not.toContain('€');
        // El degradado de marca solo en el estado vacío grande (y en la cabecera del layout).
        expect(container.querySelector('.bg-brand-gradient')).toBeNull();
    });

    it('sin bolsas, un estado vacío grande con el degradado de marca y el saludo como único h1', () => {
        const { container } = render(
            <PortalHome
                {...homeProps({
                    banks: [],
                    history: [],
                    summary: {
                        month: '2026-10-01',
                        month_minutes: 0,
                        month_overage_minutes: 0,
                        open_count: 0,
                        near_limit_count: 0,
                        first_threshold: 75,
                    },
                })}
            />,
        );

        expectOutline(container);
        expect(container.querySelector('.bg-brand-gradient')).not.toBeNull();
        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
            'Hola, María',
        );
        expect(
            screen.getByText(/Todavía no tienes bolsas de horas con nosotros/),
        ).toBeTruthy();
        expect(screen.queryByRole('region', { name: 'Resumen' })).toBeNull();
    });

    it('con solo bolsas anteriores, las activas quedan vacías y el histórico sigue a mano', () => {
        const { container } = render(
            <PortalHome {...homeProps({ banks: [] })} />,
        );

        expectOutline(container);
        expect(
            screen.getByText('No tienes bolsas activas ahora mismo.'),
        ).toBeTruthy();
        expect(
            screen.getByRole('region', { name: 'Bolsas anteriores' }),
        ).toBeTruthy();
    });
});

describe('Detalle de una bolsa en el portal', () => {
    it('cifras, estado, PDF, gráfica por mes, horas e histórico, con migas de pan al inicio', () => {
        const { container } = render(<PortalBankShow {...showProps()} />);

        expectOutline(container);
        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
            'Bolsa Diseño ñ',
        );
        expect(screen.getByText('NAN-WEB · Web corporativa')).toBeTruthy();
        // La vigencia, en la cabecera (y en cada eslabón de la cadena).
        expect(
            screen.getByRole('heading', { level: 1 }).parentElement
                ?.textContent,
        ).toContain('Desde el 01/07/2026');

        const pdf = screen.getByRole('link', {
            name: 'Descargar PDF de consumo de Bolsa Diseño ñ',
        });
        expect(pdf.getAttribute('href')).toBe('/portal/bolsas/5/pdf');
        expect(pdf.hasAttribute('download')).toBe(true);

        expect(
            screen.getByRole('meter', { name: 'Consumo de Bolsa Diseño ñ' }),
        ).toBeTruthy();
        expect(screen.queryByText('Comprometidas')).toBeNull();
        expect(
            screen.getByRole('region', { name: 'Consumo por mes' }),
        ).toBeTruthy();
        expect(screen.getByRole('region', { name: 'Horas' })).toBeTruthy();
        expect(screen.getByText('Ajustes finales')).toBeTruthy();
        expect(
            screen.getByRole('list', {
                name: 'Renovaciones de Bolsa Diseño ñ',
            }),
        ).toBeTruthy();

        expect(layout.props).toEqual({
            breadcrumbs: [
                {
                    title: 'Inicio',
                    href: expect.objectContaining({ url: '/portal' }),
                },
                {
                    title: 'Bolsa Diseño ñ',
                    href: expect.objectContaining({ url: '/portal/bolsas/5' }),
                },
            ],
        });
        expect(container.textContent).not.toContain('€');
    });

    it('una bolsa cerrada dice cuándo se cerró; sin horas, los estados vacíos', () => {
        render(
            <PortalBankShow
                {...showProps({
                    bank: bank({
                        status: 'closed',
                        end_date: '2025-12-31',
                        closed_at: '2026-01-10T09:00:00Z',
                    }),
                    months: [],
                    entries: {
                        data: [],
                        meta: {
                            current_page: 1,
                            last_page: 1,
                            per_page: 25,
                            total: 0,
                            from: null,
                            to: null,
                        },
                        links: { prev: null, next: null },
                    },
                    history: [],
                })}
            />,
        );

        expect(screen.getByText('Cerrada el 10/01/2026')).toBeTruthy();
        expect(
            screen.getByText('Todavía no hay consumo por mes.'),
        ).toBeTruthy();
        expect(
            screen.getByText('Todavía no hay horas que mostrar en esta bolsa.'),
        ).toBeTruthy();
        expect(screen.getByText('Esta bolsa no se ha renovado.')).toBeTruthy();
    });
});
