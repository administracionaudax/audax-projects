// @vitest-environment jsdom
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement, ReactNode } from 'react';
import { cloneElement } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HourBankMeter } from '@/components/charts/hour-bank-meter';
import {
    bankDates,
    consumedOf,
    formatMonth,
    monthParam,
    projectLabel,
} from '@/components/portal/banks/format';
import { PortalBankCard } from '@/components/portal/banks/portal-bank-card';
import {
    PortalBankEntries,
    PortalBankEntriesTable,
} from '@/components/portal/banks/portal-bank-entries';
import { PortalBankHistoryList } from '@/components/portal/banks/portal-bank-history-list';
import {
    monthlyRows,
    PortalBankMonthlyChart,
} from '@/components/portal/banks/portal-bank-monthly-chart';
import { PortalBankRenewals } from '@/components/portal/banks/portal-bank-renewals';
import { PortalBanksSummary } from '@/components/portal/banks/portal-banks-summary';
import { PortalVisibilityNote } from '@/components/portal/banks/portal-visibility-note';
import type {
    PortalBank,
    PortalBankEntry,
} from '@/components/portal/banks/types';
import type { ProjectsPaginated } from '@/types';

/*
 * P1 · Bolsas del portal (SPEC §11, D-064): tarjetas, resumen, histórico, gráfica por mes, horas con
 * la persona según el ajuste del cliente, filtro por mes con sus estados de carga y error, cadena de
 * renovaciones y la nota de qué horas ve el cliente. Nunca importes.
 */

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
    listeners: {} as Record<string, (event: unknown) => void>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: {
        get: inertia.get,
        reload: inertia.reload,
        on: (name: string, callback: (event: unknown) => void) => {
            inertia.listeners[name] = callback;

            return () => delete inertia.listeners[name];
        },
    },
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
        }) => cloneElement(children, { width: 640, height: 240 }),
    };
});

vi.setConfig({ testTimeout: 20_000 });

beforeEach(() => {
    inertia.get.mockReset();
    inertia.reload.mockReset();
    inertia.listeners = {};
});

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

function entry(overrides: Partial<PortalBankEntry> = {}): PortalBankEntry {
    return {
        id: 1,
        date: '2026-10-01',
        task: 'Maquetación',
        type: { name: 'Maquetación', color: '#179FA5' },
        person: 'L.P.',
        minutes: 240,
        overage_minutes: 120,
        description: 'Ajustes finales',
        ...overrides,
    };
}

function page(
    data: PortalBankEntry[],
    meta: Partial<ProjectsPaginated<PortalBankEntry>['meta']> = {},
): ProjectsPaginated<PortalBankEntry> {
    return {
        data,
        meta: {
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: data.length,
            from: data.length > 0 ? 1 : null,
            to: data.length > 0 ? data.length : null,
            ...meta,
        },
        links: { prev: null, next: null },
    };
}

const MONTHS = [
    { month: '2026-09-01', within_minutes: 1080, overage_minutes: 0 },
    { month: '2026-10-01', within_minutes: 120, overage_minutes: 120 },
];

describe('formato de las bolsas del portal', () => {
    it('meses, vigencia, proyecto y consumido de lo contratado', () => {
        expect(formatMonth('2026-09-01')).toBe('Septiembre de 2026');
        expect(formatMonth('2025-01-01')).toBe('Enero de 2025');
        expect(formatMonth('no')).toBe('');
        expect(monthParam('2026-09-01')).toBe('2026-09');
        expect(projectLabel({ code: 'NAN-WEB', name: 'Web corporativa' })).toBe(
            'NAN-WEB · Web corporativa',
        );
        expect(bankDates({ start_date: '2026-07-01', end_date: null })).toBe(
            'Desde el 01/07/2026',
        );
        expect(
            bankDates({ start_date: '2026-01-01', end_date: '2026-06-30' }),
        ).toBe('Del 01/01/2026 al 30/06/2026');
        expect(consumedOf(bank().figures)).toBe('22:00 de 20:00');
    });
});

describe('PortalBankCard', () => {
    it('enlaza al detalle y enseña el estado, la barra con el exceso aparte y la vigencia', () => {
        render(<PortalBankCard bank={bank()} thresholds={[75, 90, 100]} />);

        const card = screen.getByRole('article', { name: 'Bolsa Diseño ñ' });
        expect(
            within(card)
                .getByRole('link', { name: 'Bolsa Diseño ñ' })
                .getAttribute('href'),
        ).toBe('/portal/bolsas/5');
        expect(
            within(card)
                .getByRole('link', {
                    name: 'Ver el detalle de Bolsa Diseño ñ',
                })
                .getAttribute('href'),
        ).toBe('/portal/bolsas/5');
        expect(
            within(card).getByText('NAN-WEB · Web corporativa'),
        ).toBeTruthy();
        expect(within(card).getByText('Desde el 01/07/2026')).toBeTruthy();

        const meter = within(card).getByRole('meter', {
            name: 'Consumo de Bolsa Diseño ñ',
        });
        expect(meter.getAttribute('aria-valuenow')).toBe('1200');
        expect(meter.getAttribute('aria-valuetext')).toContain(
            '2:00 de exceso',
        );
        expect(within(card).getByText('+2:00 de exceso')).toBeTruthy();
        // Estado con icono y texto (dos veces: el del cliente y el nivel de la barra).
        expect(within(card).getAllByText(/Agotada/).length).toBeGreaterThan(0);
        // Nada de planificación interna ni importes.
        expect(within(card).queryByText('Comprometidas')).toBeNull();
        expect(card.textContent).not.toContain('€');
    });
});

describe('HourBankMeter', () => {
    it('sin showCommitted no enseña las horas comprometidas ni su aviso', () => {
        const { rerender } = render(
            <HourBankMeter
                name="B"
                consumed={500}
                total={600}
                committed={300}
            />,
        );
        expect(screen.getByText('Comprometidas')).toBeTruthy();
        expect(
            screen.getByText(/Las tareas planificadas superan/),
        ).toBeTruthy();

        rerender(
            <HourBankMeter
                name="B"
                consumed={500}
                total={600}
                committed={300}
                showCommitted={false}
            />,
        );
        expect(screen.queryByText('Comprometidas')).toBeNull();
        expect(
            screen.queryByText(/Las tareas planificadas superan/),
        ).toBeNull();
        expect(screen.getByText('Restantes')).toBeTruthy();
    });
});

describe('PortalBanksSummary', () => {
    it('horas del mes con su exceso, bolsas activas y cerca del límite desde el primer umbral', () => {
        render(
            <PortalBanksSummary
                summary={{
                    month: '2026-10-01',
                    month_minutes: 540,
                    month_overage_minutes: 120,
                    open_count: 2,
                    near_limit_count: 1,
                    first_threshold: 75,
                }}
            />,
        );

        const summary = screen.getByRole('region', { name: 'Resumen' });
        expect(
            within(summary).getByText('Horas de octubre de 2026'),
        ).toBeTruthy();
        expect(within(summary).getByText('9:00')).toBeTruthy();
        expect(within(summary).getByText('+2:00 de exceso')).toBeTruthy();
        expect(within(summary).getByText('Bolsas activas')).toBeTruthy();
        expect(within(summary).getByText('Cerca del límite')).toBeTruthy();
        expect(
            within(summary).getByText('Al 75 % o más de sus horas'),
        ).toBeTruthy();
    });

    it('sin horas este mes lo dice', () => {
        render(
            <PortalBanksSummary
                summary={{
                    month: '2026-10-01',
                    month_minutes: 0,
                    month_overage_minutes: 0,
                    open_count: 1,
                    near_limit_count: 0,
                    first_threshold: 80,
                }}
            />,
        );

        expect(screen.getByText('Todavía no hay horas este mes')).toBeTruthy();
        expect(screen.getByText('Al 80 % o más de sus horas')).toBeTruthy();
    });
});

describe('PortalBankHistoryList', () => {
    it('cada bolsa anterior enlaza a su detalle, con su consumo, el exceso en rojo y su estado', () => {
        render(
            <PortalBankHistoryList
                banks={[
                    bank({
                        id: 1,
                        name: 'Bolsa primer semestre',
                        status: 'renewed',
                        end_date: '2026-06-30',
                        start_date: '2026-01-01',
                        figures: {
                            total_minutes: 600,
                            within_minutes: 600,
                            overage_minutes: 30,
                            remaining_minutes: 0,
                            percent: 1.05,
                        },
                    }),
                    bank({
                        id: 2,
                        name: 'Soporte 2025',
                        status: 'closed',
                        figures: {
                            total_minutes: 600,
                            within_minutes: 450,
                            overage_minutes: 0,
                            remaining_minutes: 150,
                            percent: 0.75,
                        },
                    }),
                ]}
            />,
        );

        const items = screen.getAllByRole('listitem');
        expect(items).toHaveLength(2);
        expect(
            within(items[0])
                .getByRole('link', {
                    name: 'Bolsa primer semestre',
                })
                .getAttribute('href'),
        ).toBe('/portal/bolsas/1');
        expect(within(items[0]).getByText('10:30 de 10:00')).toBeTruthy();
        expect(within(items[0]).getByText('+0:30 de exceso')).toBeTruthy();
        expect(within(items[0]).getByText('Renovada')).toBeTruthy();
        expect(within(items[1]).getByText('Cerrada')).toBeTruthy();
        expect(within(items[1]).queryByText(/de exceso/)).toBeNull();
    });
});

describe('PortalBankMonthlyChart', () => {
    it('filas por mes con lo que va dentro y el exceso', () => {
        expect(monthlyRows(MONTHS)).toEqual([
            expect.objectContaining({
                id: '2026-09-01',
                inBank: 1080,
                overage: 0,
                total: 1080,
            }),
            expect.objectContaining({
                id: '2026-10-01',
                inBank: 120,
                overage: 120,
                total: 240,
            }),
        ]);
    });

    it('tiene su resumen accesible y la tabla alternativa con cada mes', async () => {
        const user = userEvent.setup();
        render(<PortalBankMonthlyChart months={MONTHS} />);

        expect(
            screen.getByRole('img', {
                name: 'Consumo por mes: 2 meses con horas, 22:00 en total y 2:00 de exceso.',
            }),
        ).toBeTruthy();
        // Con exceso, la leyenda nombra las dos series.
        expect(screen.getByText('Dentro de la bolsa')).toBeTruthy();
        expect(screen.getByText('Exceso')).toBeTruthy();

        await user.click(
            screen.getByRole('button', { name: 'Ver como tabla' }),
        );

        const table = screen.getByRole('table');
        const rows = within(table).getAllByRole('row');
        expect(rows).toHaveLength(3);
        expect(within(rows[1]).getByText('Septiembre de 2026')).toBeTruthy();
        // Dentro 18:00, exceso 0:00 y total 18:00.
        expect(within(rows[1]).getAllByText('18:00')).toHaveLength(2);
        expect(within(rows[2]).getByText('Octubre de 2026')).toBeTruthy();
        expect(within(rows[2]).getAllByText('2:00')).toHaveLength(2);
        expect(within(rows[2]).getByText('4:00')).toBeTruthy();
    });

    it('sin exceso, la leyenda no lo nombra', () => {
        render(
            <PortalBankMonthlyChart
                months={[
                    {
                        month: '2026-09-01',
                        within_minutes: 60,
                        overage_minutes: 0,
                    },
                ]}
            />,
        );

        expect(screen.queryByText('Exceso')).toBeNull();
    });
});

describe('PortalBankEntriesTable', () => {
    it('fecha, tarea, tipo, persona como la ve el cliente, duración con su exceso y descripción', () => {
        render(
            <PortalBankEntriesTable
                entries={[
                    entry(),
                    entry({
                        id: 2,
                        date: '2026-09-10',
                        task: 'Diseño de la home',
                        type: null,
                        person: 'Equipo',
                        minutes: 600,
                        overage_minutes: 0,
                        description: null,
                    }),
                ]}
                emptyTitle="Vacío"
            />,
        );

        const region = screen.getByRole('region', { name: 'Tabla de horas' });
        const headers = within(region)
            .getAllByRole('columnheader')
            .map((th) => th.textContent);
        expect(headers).toEqual([
            'Fecha',
            'Tarea',
            'Tipo',
            'Persona',
            'Duración',
            'Descripción',
        ]);

        const rows = within(region).getAllByRole('row');
        expect(within(rows[1]).getByText('01/10/2026')).toBeTruthy();
        expect(within(rows[1]).getAllByText('Maquetación')).toHaveLength(2);
        expect(within(rows[1]).getByText('L.P.')).toBeTruthy();
        expect(within(rows[1]).getByText('4:00')).toBeTruthy();
        expect(within(rows[1]).getByText('+2:00 de exceso')).toBeTruthy();
        expect(within(rows[1]).getByText('Ajustes finales')).toBeTruthy();
        expect(within(rows[2]).getByText('Sin tipo')).toBeTruthy();
        expect(within(rows[2]).getByText('Equipo')).toBeTruthy();
        expect(within(rows[2]).getByText('10:00')).toBeTruthy();
        expect(region.textContent).not.toContain('€');
    });

    it('sin entradas, su estado vacío', () => {
        render(
            <PortalBankEntriesTable
                entries={[]}
                emptyTitle="No hay horas en este mes."
            />,
        );

        expect(screen.getByText('No hay horas en este mes.')).toBeTruthy();
        expect(screen.queryByRole('table')).toBeNull();
    });
});

describe('PortalBankEntries', () => {
    it('filtra por mes con la URL (?mes=AAAA-MM) y vuelve a todos los meses', async () => {
        const user = userEvent.setup();
        render(
            <PortalBankEntries
                bankId={5}
                entries={page([entry()])}
                months={MONTHS}
                month={null}
            />,
        );

        const select = screen.getByLabelText('Mes');
        expect(
            within(select)
                .getAllByRole('option')
                .map((option) => option.textContent),
        ).toEqual(['Todos los meses', 'Octubre de 2026', 'Septiembre de 2026']);

        await user.selectOptions(select, '2026-09');
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/portal/bolsas/5',
            { mes: '2026-09' },
            expect.objectContaining({
                preserveScroll: true,
                only: ['entries', 'filters'],
            }),
        );
    });

    it('con un mes filtrado enseña su total y su propio estado vacío', async () => {
        const user = userEvent.setup();
        render(
            <PortalBankEntries
                bankId={5}
                entries={page([])}
                months={MONTHS}
                month="2026-10"
            />,
        );

        expect(
            screen.getByText(
                'Total de Octubre de 2026: 4:00 (+2:00 de exceso)',
            ),
        ).toBeTruthy();
        expect(screen.getByText('No hay horas en este mes.')).toBeTruthy();

        await user.selectOptions(screen.getByLabelText('Mes'), '');
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/portal/bolsas/5',
            {},
            expect.anything(),
        );
    });

    it('mientras carga lo avisa y atenúa la tabla; si falla la red, ofrece reintentar', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <PortalBankEntries
                bankId={5}
                entries={page([entry()])}
                months={MONTHS}
                month={null}
            />,
        );

        act(() => inertia.listeners.start({}));
        expect(screen.getByText('Cargando las horas…')).toBeTruthy();
        expect(container.querySelector('[aria-busy="true"]')).not.toBeNull();

        act(() => inertia.listeners.networkError({}));
        expect(screen.queryByText('Cargando las horas…')).toBeNull();
        expect(screen.getByRole('alert').textContent).toContain(
            'No se han podido cargar las horas.',
        );

        await user.click(screen.getByRole('button', { name: 'Reintentar' }));
        expect(inertia.reload).toHaveBeenCalled();
    });

    it('sin horas en la bolsa no ofrece el filtro', () => {
        render(
            <PortalBankEntries
                bankId={5}
                entries={page([])}
                months={[]}
                month={null}
            />,
        );

        expect(screen.queryByLabelText('Mes')).toBeNull();
        expect(
            screen.getByText('Todavía no hay horas que mostrar en esta bolsa.'),
        ).toBeTruthy();
    });
});

describe('PortalBankRenewals', () => {
    it('la cadena enlaza a cada bolsa salvo la actual, marcada con aria-current', () => {
        render(
            <PortalBankRenewals
                name="Bolsa Diseño ñ"
                chain={[
                    {
                        ...bank({
                            id: 1,
                            name: 'Bolsa primer semestre',
                            status: 'renewed',
                        }),
                        current: false,
                    },
                    { ...bank(), current: true },
                ]}
            />,
        );

        const chain = screen.getByRole('list', {
            name: 'Renovaciones de Bolsa Diseño ñ',
        });
        const items = within(chain).getAllByRole('listitem');
        expect(
            within(items[0])
                .getByRole('link', {
                    name: 'Bolsa primer semestre',
                })
                .getAttribute('href'),
        ).toBe('/portal/bolsas/1');
        expect(within(items[0]).getByText('Renovada')).toBeTruthy();
        expect(within(items[1]).queryByRole('link')).toBeNull();
        expect(
            items[1].querySelector('[aria-current="page"]')?.textContent,
        ).toContain('Bolsa Diseño ñ');
        expect(within(items[1]).getByText('22:00 de 20:00')).toBeTruthy();
    });

    it('sin renovaciones, su estado vacío', () => {
        render(<PortalBankRenewals name="B" chain={[]} />);

        expect(screen.getByText('Esta bolsa no se ha renovado.')).toBeTruthy();
    });
});

describe('PortalVisibilityNote', () => {
    it('dice qué horas ve el cliente según su ajuste', () => {
        const { rerender } = render(
            <PortalVisibilityNote visibility="approved" />,
        );
        expect(
            screen.getByText(/Solo se muestran las horas ya aprobadas/),
        ).toBeTruthy();

        rerender(<PortalVisibilityNote visibility="submitted" />);
        expect(
            screen.getByText(
                /Se muestran las horas enviadas y las ya aprobadas/,
            ),
        ).toBeTruthy();
    });
});
