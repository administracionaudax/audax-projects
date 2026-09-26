// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement, ReactNode } from 'react';
import { cloneElement } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { offersRenewal } from '@/components/hour-banks/hour-bank-actions';
import { HourBankCard } from '@/components/hour-banks/hour-bank-card';
import { HourBankFields } from '@/components/hour-banks/hour-bank-fields';
import { HourBankHistory } from '@/components/hour-banks/hour-bank-history';
import { renewalDefaults } from '@/components/hour-banks/hour-bank-renew-dialog';
import { HourBankTasksTable } from '@/components/hour-banks/hour-bank-tasks-table';
import { HourBankWeeklyChart } from '@/components/hour-banks/hour-bank-weekly-chart';
import { HourBanksOverviewTable } from '@/components/hour-banks/hour-banks-overview-table';
import { overviewQuery } from '@/components/hour-banks/overview-query';
import type {
    HourBankCard as HourBankCardData,
    HourBankTaskRow,
} from '@/types';

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
        <a href={href} {...rest}>
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

function bank(overrides: Partial<HourBankCardData> = {}): HourBankCardData {
    return {
        id: 5,
        project_id: 2,
        name: 'Bolsa T4',
        department: { id: 1, name: 'Desarrollo', color: '#179FA5' },
        department_id: 1,
        total_minutes: 600,
        consumed_minutes: 480,
        overage_minutes: 0,
        remaining_minutes: 120,
        in_bank_minutes: 480,
        consumed_pct: 80,
        status: 'active',
        overage_policy: 'inherit',
        effective_overage_policy: 'allow',
        start_date: '2026-10-01',
        end_date: '2026-12-31',
        renewed_from_id: null,
        invoice_reference: null,
        notes: null,
        closed_at: null,
        closed_remaining_minutes: null,
        committed_minutes: 180,
        open_tasks_count: 3,
        ...overrides,
    };
}

describe('offersRenewal (D-035)', () => {
    const can = { renew: true };

    /** Bolsa de 10 h con esos minutos consumidos (y de exceso). */
    function state(
        status: 'active' | 'exhausted' | 'closed' | 'renewed',
        consumed: number,
        overage = 0,
    ) {
        return {
            status,
            consumed_minutes: consumed,
            overage_minutes: overage,
            total_minutes: 600,
        };
    }

    it('se ofrece en una bolsa agotada o desde el primer umbral', () => {
        expect(offersRenewal(state('exhausted', 600), can, 75)).toBe(true);
        expect(offersRenewal(state('active', 450), can, 75)).toBe(true);
        expect(offersRenewal(state('active', 449), can, 75)).toBe(false);
        expect(offersRenewal(state('active', 426), can, 70)).toBe(true);
    });

    it('cuenta lo que va dentro de la bolsa, como el servidor: el exceso de bloqueadas no la acerca a agotarse', () => {
        // 10 h consumidas pero 5 h de exceso ya facturado: dentro solo hay 5 h (50 %).
        expect(offersRenewal(state('active', 600, 300), can, 75)).toBe(false);
    });

    it('nunca en bolsas cerradas o renovadas, ni sin permiso', () => {
        expect(offersRenewal(state('closed', 600), can, 75)).toBe(false);
        expect(offersRenewal(state('renewed', 600), can, 75)).toBe(false);
        expect(
            offersRenewal(state('exhausted', 720, 120), { renew: false }, 75),
        ).toBe(false);
    });
});

describe('renewalDefaults', () => {
    it('propone los mismos parámetros desde el día siguiente al fin, sin factura ni notas', () => {
        const defaults = renewalDefaults(
            bank({
                invoice_reference: 'F-1',
                notes: 'x',
                hourly_rate: '60.00',
            }),
            '2026-09-26',
        );

        expect(defaults).toMatchObject({
            name: 'Bolsa T4',
            department_id: 1,
            total_minutes: 600,
            start_date: '2027-01-01',
            end_date: null,
            overage_policy: 'inherit',
            hourly_rate: '60.00',
            invoice_reference: '',
            notes: '',
            move_open_tasks: true,
        });
    });

    it('sin fecha de fin empieza hoy; sin tareas abiertas no las mueve', () => {
        const defaults = renewalDefaults(
            bank({ end_date: null, open_tasks_count: 0 }),
            '2026-09-26',
        );

        expect(defaults.start_date).toBe('2026-09-26');
        expect(defaults.move_open_tasks).toBe(false);
    });
});

describe('HourBankCard', () => {
    it('muestra consumo, restantes, comprometidas, departamento, fechas y política', () => {
        render(<HourBankCard projectId={2} bank={bank()} />);

        const card = screen.getByRole('article');
        expect(
            within(card)
                .getByRole('link', { name: 'Bolsa T4' })
                .getAttribute('href'),
        ).toBe('/proyectos/2/bolsas/5');
        expect(card.textContent).toContain('Desarrollo');
        expect(card.textContent).toContain('Del 01/10/2026 al 31/12/2026');
        expect(card.textContent).toContain('Activa');
        expect(card.textContent).toContain('Admite exceso');
        // 8:00 consumidas + 3:00 comprometidas superan las 10:00 en 1:00.
        expect(card.textContent).toContain(
            'Las tareas planificadas superan el saldo de la bolsa en 1:00 h.',
        );
    });

    it('en rojo y con icono si hay exceso; con el saldo registrado si está cerrada', () => {
        render(
            <HourBankCard
                projectId={2}
                bank={bank({
                    status: 'closed',
                    consumed_minutes: 690,
                    overage_minutes: 90,
                    remaining_minutes: 0,
                    consumed_pct: 115,
                    closed_at: '2026-09-26T08:00:00Z',
                    closed_remaining_minutes: 0,
                    effective_overage_policy: 'block',
                })}
            />,
        );

        const card = screen.getByRole('article');
        expect(card.textContent).toContain('+1:30 de exceso');
        expect(card.textContent).toContain(
            'Cerrada el 26/09/2026 con 0:00 sin consumir.',
        );
        expect(card.textContent).toContain('No admite exceso');
    });
});

describe('medidores de bolsa: umbrales configurados y exceso del servidor (D-035, D-019)', () => {
    /** Color de la barra del medidor (success, warning o danger). */
    function levelOf(container: HTMLElement): string | null {
        const bar = within(container)
            .getByRole('meter')
            .querySelector('.bg-success, .bg-warning, .bg-danger');

        return (
            bar
                ?.getAttribute('class')
                ?.match(/bg-(success|warning|danger)/)?.[1] ?? null
        );
    }

    it('la tarjeta y la tabla global (mismo medidor que el listado de proyectos) ponen ámbar desde el primer umbral configurado', () => {
        // 65 % con umbrales 60/90/100: ámbar, como el filtro «próximas» y el botón Renovar.
        const at65 = bank({
            consumed_minutes: 390,
            remaining_minutes: 210,
            in_bank_minutes: 390,
            consumed_pct: 65,
            committed_minutes: 0,
        });

        const card = render(
            <HourBankCard
                projectId={2}
                bank={at65}
                thresholds={[60, 90, 100]}
            />,
        );
        expect(levelOf(card.container)).toBe('warning');
        card.unmount();

        const table = render(
            <HourBanksOverviewTable
                banks={[
                    {
                        ...at65,
                        project: {
                            id: 2,
                            code: 'ACME-WEB',
                            name: 'Web',
                            color: '#0171FF',
                            client: null,
                        },
                    },
                ]}
                thresholds={[60, 90, 100]}
            />,
        );
        expect(levelOf(table.container)).toBe('warning');
        table.unmount();

        // Con los umbrales por defecto (75 %), sigue en verde.
        const byDefault = render(<HourBankCard projectId={2} bank={at65} />);
        expect(levelOf(byDefault.container)).toBe('success');
    });

    it('con una bloqueada en exceso y el total ampliado, el saldo y el color salen de lo que va dentro', () => {
        render(
            <HourBankCard
                projectId={2}
                bank={bank({
                    total_minutes: 120,
                    consumed_minutes: 120,
                    overage_minutes: 60,
                    remaining_minutes: 60,
                    in_bank_minutes: 60,
                    consumed_pct: 100,
                    committed_minutes: 0,
                })}
            />,
        );

        const card = screen.getByRole('article');
        const meter = within(card).getByRole('meter');
        expect(meter.getAttribute('aria-valuenow')).toBe('60');
        expect(levelOf(card)).toBe('success');
        expect(card.textContent).toContain('+1:00 de exceso');
        // Restantes: 1:00 (no 0:00).
        expect(
            within(card).getByText('Restantes').nextElementSibling?.textContent,
        ).toBe('1:00');
    });
});

describe('HourBankHistory', () => {
    it('pinta cada cadena de la más antigua a la más reciente', () => {
        render(
            <HourBankHistory
                chains={[
                    [
                        {
                            id: 1,
                            project_id: 2,
                            name: 'T3',
                            status: 'renewed',
                            start_date: '2026-07-01',
                            end_date: '2026-09-30',
                        },
                        {
                            id: 2,
                            project_id: 2,
                            name: 'T4',
                            status: 'active',
                            start_date: '2026-10-01',
                            end_date: null,
                        },
                    ],
                ]}
            />,
        );

        const chain = screen.getByRole('list', {
            name: 'Renovaciones desde «T3»',
        });
        const items = within(chain).getAllByRole('listitem');
        expect(
            items.map((item) => within(item).getByRole('link').textContent),
        ).toEqual(['T3', 'T4']);
        expect(items[0].textContent).toContain('Renovada');
        expect(items[1].textContent).toContain('Desde el 01/10/2026');
        expect(within(items[1]).getByRole('link').getAttribute('href')).toBe(
            '/proyectos/2/bolsas/2',
        );
    });

    it('en el de un cliente, cada cadena dice de qué proyecto es y enlaza a sus bolsas', () => {
        const { container } = render(
            <HourBankHistory
                projects={[
                    { id: 2, code: 'ACME-WEB', name: 'Web', color: '#0171FF' },
                    { id: 3, code: 'ACME-SEO', name: 'SEO', color: '#179FA5' },
                ]}
                chains={[
                    [
                        {
                            id: 1,
                            project_id: 3,
                            name: 'SEO 1',
                            status: 'renewed',
                            start_date: '2026-07-01',
                            end_date: null,
                        },
                        {
                            id: 4,
                            project_id: 3,
                            name: 'SEO 2',
                            status: 'active',
                            start_date: '2026-10-01',
                            end_date: null,
                        },
                    ],
                ]}
            />,
        );

        const chain = container.querySelector<HTMLElement>(
            '[data-test="renewal-chain"]',
        );
        expect(chain).not.toBeNull();
        const project = within(chain as HTMLElement).getByRole('link', {
            name: /ACME-SEO/,
        });
        expect(project.getAttribute('href')).toBe('/proyectos/3');
        expect(project.textContent).toContain('SEO');
        expect(
            within(chain as HTMLElement)
                .getByRole('link', { name: 'SEO 2' })
                .getAttribute('href'),
        ).toBe('/proyectos/3/bolsas/4');
    });

    it('estado vacío sin renovaciones', () => {
        render(
            <HourBankHistory
                chains={[]}
                emptyDescription="Sin renovaciones de este cliente."
            />,
        );

        expect(
            screen.getByText('Aún no se ha renovado ninguna bolsa'),
        ).toBeTruthy();
        expect(
            screen.getByText('Sin renovaciones de este cliente.'),
        ).toBeTruthy();
    });
});

describe('HourBankFields', () => {
    const data = {
        name: 'Bolsa T4',
        department_id: 4,
        total_minutes: 600,
        start_date: '2026-10-01',
        end_date: null,
        overage_policy: 'inherit' as const,
        hourly_rate: '',
        price_amount: '',
        invoice_reference: '',
        notes: '',
    };

    it('un departamento eliminado de la bolsa se muestra como tal', () => {
        render(
            <HourBankFields
                data={data}
                set={() => {}}
                errors={{}}
                departments={[
                    { id: 1, name: 'Diseño' },
                    { id: 4, name: 'Vídeo', deleted: true },
                ]}
                overageDefault="allow"
                canViewFinancials={false}
            />,
        );

        expect(
            screen.getByRole('combobox', { name: 'Departamento' }).textContent,
        ).toContain('Vídeo (eliminado)');
    });

    it('en una bolsa cerrada el total no se puede cambiar y se explica', () => {
        render(
            <HourBankFields
                data={data}
                set={() => {}}
                errors={{}}
                departments={[]}
                overageDefault="allow"
                canViewFinancials={false}
                totalLocked
            />,
        );

        const total = screen.getByLabelText(
            'Total de horas',
        ) as HTMLInputElement;
        expect(total.disabled).toBe(true);
        expect(total.getAttribute('aria-describedby')).toBeTruthy();
        expect(
            screen.getByText(
                'La bolsa está cerrada: para cambiar el total, administración tiene que reabrirla.',
            ),
        ).toBeTruthy();
    });
});

describe('vista global de bolsas', () => {
    it('overviewQuery solo lleva los filtros que no están por defecto', () => {
        expect(
            overviewQuery({
                cliente: null,
                departamento: null,
                estado: '',
                proximas: false,
            }),
        ).toEqual({});
        expect(
            overviewQuery({
                cliente: 1,
                departamento: 2,
                estado: 'todas',
                proximas: true,
            }),
        ).toEqual({
            cliente: 1,
            departamento: 2,
            estado: 'todas',
            proximas: 1,
        });
    });

    it('la tabla enlaza al detalle y marca el exceso y lo que falta', () => {
        render(
            <HourBanksOverviewTable
                banks={[
                    bank({
                        project: {
                            id: 2,
                            code: 'ACME-WEB',
                            name: 'Web',
                            color: '#0171FF',
                            client: { id: 1, name: 'Acme' },
                        },
                        consumed_minutes: 660,
                        overage_minutes: 60,
                        remaining_minutes: 0,
                        consumed_pct: 110,
                        status: 'exhausted',
                    }),
                    bank({
                        id: 6,
                        name: 'Justa',
                        project: {
                            id: 3,
                            code: 'LUR',
                            name: 'Lur',
                            color: '#179FA5',
                            client: null,
                        },
                        committed_minutes: 180,
                    }),
                ]}
            />,
        );

        const rows = within(screen.getByRole('table')).getAllByRole('row');
        expect(
            within(rows[1])
                .getByRole('link', { name: 'Bolsa T4' })
                .getAttribute('href'),
        ).toBe('/proyectos/2/bolsas/5');
        expect(rows[1].textContent).toContain('ACME-WEB · Web');
        expect(rows[1].textContent).toContain('Acme');
        expect(rows[1].textContent).toContain('+1:00');
        expect(rows[1].textContent).toContain('Agotada');
        expect(rows[2].textContent).toContain('Sin cliente');
        expect(rows[2].textContent).toContain('Faltan 1:00');
    });
});

describe('HourBankTasksTable', () => {
    const row = (overrides: Partial<HourBankTaskRow>): HourBankTaskRow => ({
        id: 1,
        title: 'Tarea',
        parent_task_id: null,
        depth: 0,
        is_completed: false,
        is_milestone: false,
        status: { name: 'En curso', color: '#0171FF', category: 'in_progress' },
        assignee: null,
        estimated_minutes: 120,
        logged_minutes: 30,
        committed_minutes: 90,
        ...overrides,
    });

    it('un padre con subtareas estimadas no suma: suman ellas', () => {
        render(
            <HourBankTasksTable
                projectId={2}
                tasks={[
                    row({
                        id: 1,
                        title: 'Padre',
                        estimated_minutes: 180,
                        logged_minutes: 0,
                        committed_minutes: null,
                    }),
                    row({ id: 2, title: 'Hija', depth: 1, parent_task_id: 1 }),
                    row({
                        id: 3,
                        title: 'Hecha',
                        is_completed: true,
                        committed_minutes: 0,
                        status: {
                            name: 'Hecha',
                            color: '#179FA5',
                            category: 'done',
                        },
                    }),
                ]}
            />,
        );

        const rows = within(screen.getByRole('table')).getAllByRole('row');
        expect(rows[1].textContent).toContain('Se cuenta en sus subtareas');
        expect(within(rows[2]).getByRole('rowheader').textContent).toContain(
            'Subtarea:',
        );
        expect(
            within(rows[1])
                .getByRole('link', { name: 'Padre' })
                .getAttribute('href'),
        ).toBe('/proyectos/2/tareas?tarea=1');
        // Total: imputadas 0:00 + 0:30 + 0:30; comprometidas 1:30.
        expect(rows.at(-1)?.textContent).toContain('1:00');
        expect(rows.at(-1)?.textContent).toContain('1:30');
    });
});

describe('HourBankWeeklyChart', () => {
    it('tiene alternativa en tabla con dentro de la bolsa, exceso y total', async () => {
        const user = userEvent.setup();
        render(
            <HourBankWeeklyChart
                weeks={[
                    {
                        week: '2026-W39',
                        week_start: '2026-09-21',
                        in_bank_minutes: 480,
                        overage_minutes: 0,
                    },
                    {
                        week: '2026-W40',
                        week_start: '2026-09-28',
                        in_bank_minutes: 120,
                        overage_minutes: 60,
                    },
                ]}
            />,
        );

        expect(screen.getByRole('img').getAttribute('aria-label')).toContain(
            '2 semanas: 11:00 en total, 1:00 en exceso',
        );
        expect(screen.getByText('Exceso')).toBeTruthy();

        await user.click(
            screen.getByRole('button', { name: 'Ver como tabla' }),
        );

        const rows = within(screen.getByRole('table')).getAllByRole('row');
        expect(rows[2].textContent).toContain('Semana del 28/09/2026');
        expect(rows[2].textContent).toContain('2:00');
        expect(rows[2].textContent).toContain('1:00');
        expect(rows[2].textContent).toContain('3:00');
    });

    it('sin exceso no pone la serie ni la leyenda de exceso', () => {
        render(
            <HourBankWeeklyChart
                weeks={[
                    {
                        week: '2026-W39',
                        week_start: '2026-09-21',
                        in_bank_minutes: 480,
                        overage_minutes: 0,
                    },
                ]}
            />,
        );

        expect(screen.queryByText('Exceso')).toBeNull();
    });
});
