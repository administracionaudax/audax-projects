// @vitest-environment jsdom
import { configure, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TeamDayPage from '@/pages/day-plan/team';
import TeamWeekPage from '@/pages/day-plan/week';
import type {
    DayPlanLine,
    TeamDayPageProps,
    TeamDayRow,
    TeamWeekPageProps,
} from '@/types/day-plan';

configure({ testIdAttribute: 'data-test' });
vi.setConfig({ testTimeout: 20_000 });

/*
| Equipo hoy y la semana (D-251): textos y checks para todos; cifras y comentarios, solo si llegan.
*/

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    get: vi.fn(),
    page: {
        url: '/dia/equipo',
        props: {
            auth: { user: { id: 1, name: 'Raúl' }, can: { useDayPlan: true } },
        } as Record<string, unknown>,
    },
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => inertia.page,
    router: {
        post: inertia.post,
        get: inertia.get,
        delete: vi.fn(),
        on: () => () => {},
    },
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
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

function line(overrides: Partial<DayPlanLine> = {}): DayPlanLine {
    return {
        id: 1,
        user_id: 2,
        date: '2026-10-07',
        position: 0,
        text: 'Banners',
        status: 'pending',
        not_done_reason: null,
        carry_count: 0,
        carried_from_date: null,
        origin: 'manual',
        client: null,
        project: null,
        task: null,
        created_at: '2026-10-07T06:00:00Z',
        added_late: false,
        planned_minutes: null,
        logged_minutes: null,
        running: false,
        comments: null,
        ...overrides,
    };
}

function row(overrides: Partial<TeamDayRow> = {}): TeamDayRow {
    return {
        user: {
            id: 2,
            name: 'Ana',
            avatar: null,
            department: { id: 1, name: 'Diseño' },
        },
        state: 'plan',
        reason: null,
        note: null,
        items: [
            line(),
            line({ id: 2, text: 'Logo', status: 'done', carry_count: 2 }),
        ],
        figures: null,
        can_comment: false,
        can_remind: false,
        reminded: false,
        ...overrides,
    };
}

function props(
    rows: TeamDayRow[],
    figures: TeamDayPageProps['summary']['figures'] = null,
): TeamDayPageProps {
    return {
        date: '2026-10-07',
        today: '2026-10-07',
        deadline: '08:30',
        past_deadline: true,
        department: 1,
        departments: [
            { id: 1, name: 'Diseño' },
            { id: 2, name: 'Desarrollo' },
        ],
        rows,
        summary: {
            people: rows.length,
            with_plan: 1,
            without_plan: 1,
            away: 0,
            figures,
        },
    };
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.get.mockReset();
});

describe('Equipo hoy', () => {
    it('sin cifras: textos, checks y «↻ ×N», sin horas ni comentarios', () => {
        render(
            <TeamDayPage
                {...props([
                    row(),
                    row({
                        user: {
                            id: 3,
                            name: 'Luis',
                            avatar: null,
                            department: null,
                        },
                        state: 'no_plan',
                        items: [],
                    }),
                ])}
            />,
        );

        const ana = screen.getAllByTestId('day-plan-team-row')[0];
        expect(within(ana).getAllByTestId('day-plan-team-line')).toHaveLength(
            2,
        );
        expect(within(ana).queryByTestId('day-plan-team-figures')).toBeNull();
        expect(within(ana).queryByTestId('day-plan-line-figures')).toBeNull();
        expect(within(ana).queryByTestId('day-plan-comments')).toBeNull();
        expect(
            within(ana).getByTestId('day-plan-carry-count').textContent,
        ).toContain('2');
        expect(screen.getAllByTestId('day-plan-state')[1].textContent).toBe(
            'Sin plan',
        );
        expect(screen.getByTestId('day-plan-team-summary').textContent).toBe(
            '1 de 2 con plan · 1 sin plan',
        );
    });

    it('con cifras: previsto frente a jornada, hechas, temporizador, comentarios y «Recordar»', async () => {
        const user = userEvent.setup();
        render(
            <TeamDayPage
                {...props(
                    [
                        row({
                            items: [
                                line({
                                    planned_minutes: 90,
                                    logged_minutes: 40,
                                    comments: [
                                        {
                                            id: 9,
                                            body: '¿Para cuándo?',
                                            user: { id: 1, name: 'Raúl' },
                                            created_at: null,
                                            can_delete: true,
                                        },
                                    ],
                                }),
                            ],
                            figures: {
                                published_at: '2026-10-07T07:12:00Z',
                                capacity_minutes: 480,
                                planned_minutes: 540,
                                logged_minutes: 40,
                                done: 0,
                                total: 1,
                                carried: 0,
                                running: {
                                    item_id: 1,
                                    text: 'Banners',
                                    started_at: '2026-10-07T07:30:00Z',
                                },
                            },
                            can_comment: true,
                        }),
                        row({
                            user: {
                                id: 3,
                                name: 'Luis',
                                avatar: null,
                                department: null,
                            },
                            state: 'no_plan',
                            items: [],
                            figures: {
                                published_at: null,
                                capacity_minutes: 480,
                                planned_minutes: 0,
                                logged_minutes: 0,
                                done: 0,
                                total: 0,
                                carried: 0,
                                running: null,
                            },
                            can_remind: true,
                        }),
                    ],
                    { done: 0, total: 1, carried: 0 },
                )}
            />,
        );

        const [ana, luis] = screen.getAllByTestId('day-plan-team-row');
        expect(
            within(ana).getByTestId('day-plan-team-figures').textContent,
        ).toContain('Previsto 9:00 de 8:00');
        expect(
            within(ana).getByTestId('day-plan-team-running').textContent,
        ).toBe('Ahora: Banners');
        expect(within(ana).getByTestId('day-plan-state').textContent).toBe(
            'Plan a las 09:12',
        );
        expect(
            within(ana).getByTestId('day-plan-comment').textContent,
        ).toContain('¿Para cuándo?');

        await user.click(within(luis).getByTestId('day-plan-remind'));
        expect(inertia.post).toHaveBeenCalledWith(
            '/dia/equipo/3/recordar',
            {},
            expect.anything(),
        );
    });

    it('filtra por persona en el navegador y por departamento en la URL', async () => {
        const user = userEvent.setup();
        render(
            <TeamDayPage
                {...props([
                    row(),
                    row({
                        user: {
                            id: 3,
                            name: 'Luis',
                            avatar: null,
                            department: null,
                        },
                    }),
                ])}
            />,
        );

        await user.type(screen.getByTestId('day-plan-search'), 'lui');
        expect(screen.getAllByTestId('day-plan-team-row')).toHaveLength(1);

        await user.selectOptions(
            screen.getByTestId('day-plan-department'),
            'todos',
        );
        expect(inertia.get).toHaveBeenCalledWith(
            '/dia/equipo',
            { departamento: 'todos' },
            expect.anything(),
        );
    });
});

describe('Semana del equipo', () => {
    const week = (figures: boolean): TeamWeekPageProps => ({
        week: '2026-W41',
        previous_week: '2026-W40',
        next_week: '2026-W42',
        current_week: '2026-W41',
        days: ['2026-10-05', '2026-10-06'],
        today: '2026-10-06',
        department: 'all',
        departments: [],
        rows: [
            {
                user: { id: 2, name: 'Ana', avatar: null, department: null },
                days: [
                    {
                        date: '2026-10-05',
                        state: 'plan',
                        reason: null,
                        items: [line({ date: '2026-10-05' })],
                        figures: figures
                            ? { done: 0, total: 1, carried: 0 }
                            : null,
                    },
                    {
                        date: '2026-10-06',
                        state: 'away',
                        reason: null,
                        items: [],
                        figures: null,
                    },
                ],
                figures: figures
                    ? { done: 0, total: 1, days_with_plan: 1 }
                    : null,
            },
        ],
    });

    it('enseña el estado del día y abre las líneas al pulsar la celda', async () => {
        const user = userEvent.setup();
        render(<TeamWeekPage {...week(false)} />);

        const cells = screen.getAllByTestId('day-plan-week-cell');
        expect(cells[0].textContent).toContain('Con plan');
        expect(cells[1].textContent).toContain('Ausente');
        expect(cells[1].hasAttribute('disabled')).toBe(true);

        await user.click(cells[0]);
        expect(
            within(await screen.findByTestId('day-plan-week-lines')).getByText(
                'Banners',
            ),
        ).toBeTruthy();
    });

    it('con cifras, «hechas / planificadas» y el total de la semana', () => {
        render(<TeamWeekPage {...week(true)} />);

        expect(
            screen.getAllByTestId('day-plan-week-cell')[0].textContent,
        ).toContain('0/1');
        expect(screen.getByTestId('day-plan-week-row').textContent).toMatch(
            /0\/1 · 0\s%/u,
        );
    });
});
