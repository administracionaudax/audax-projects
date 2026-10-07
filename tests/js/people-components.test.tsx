// @vitest-environment jsdom
import { configure, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { peopleNavItems } from '@/components/app-sidebar';
import { ClockButton } from '@/components/people/clock-button';
import { CorrectionCard } from '@/components/people/correction-card';
import { CorrectionDialog } from '@/components/people/correction-dialog';
import PendingPage from '@/pages/people/pending';
import TeamWorkdayPage from '@/pages/people/team';
import WorkdayPage from '@/pages/people/workday';
import type { Abilities } from '@/types';
import type {
    ClockShared,
    Correction,
    WorkdayDay,
    WorkdayPageProps,
} from '@/types/people';

configure({ testIdAttribute: 'data-test' });
vi.setConfig({ testTimeout: 20_000 });

/*
| Pantallas del registro de jornada (Fase 11, R1): el botón de fichar (nunca manda la hora), la
| pregunta del temporizador, el formulario de corrección, Mi jornada, la jornada del equipo y la
| bandeja de pendientes.
*/

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    get: vi.fn(),
    page: {
        url: '/personas/jornada',
        props: {} as Record<string, unknown>,
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
        visit: vi.fn(),
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

const abilities = (overrides: Partial<Abilities> = {}): Abilities =>
    ({
        viewHourBanks: false,
        viewAdmin: false,
        viewFinancials: false,
        createClients: false,
        createProjects: false,
        approveTime: false,
        lockTime: false,
        manageUsers: false,
        manageSettings: false,
        viewTeamAbsences: false,
        viewClients: true,
        viewWorkload: true,
        viewAbsences: true,
        viewReports: true,
        usePeople: true,
        clock: true,
        viewPeopleTeam: false,
        ...overrides,
    }) as Abilities;

const clock = (overrides: Partial<ClockShared> = {}): ClockShared => ({
    status: 'off',
    since: null,
    running_since: null,
    worked_seconds: 0,
    work_mode: 'remote',
    unclosed_date: null,
    server_now: new Date().toISOString(),
    ...overrides,
});

function day(overrides: Partial<WorkdayDay> = {}): WorkdayDay {
    return {
        date: '2026-10-05',
        weekday: 1,
        expected_minutes: 480,
        capacity_minutes: 480,
        base_minutes: 480,
        holiday: null,
        absence: null,
        employed: true,
        registered: true,
        schedule: {
            start_from: '08:00',
            start_to: '10:00',
            expected_pause_minutes: 60,
        },
        workdays: [
            {
                clock_in: '2026-10-05T07:00:00Z',
                clock_out: '2026-10-05T16:00:00Z',
                open: false,
                stale: false,
                segments: [
                    {
                        kind: 'work',
                        from: '2026-10-05T07:00:00Z',
                        to: '2026-10-05T12:00:00Z',
                        work_mode: 'on_site',
                    },
                    {
                        kind: 'pause',
                        from: '2026-10-05T12:00:00Z',
                        to: '2026-10-05T13:00:00Z',
                        work_mode: null,
                    },
                    {
                        kind: 'work',
                        from: '2026-10-05T13:00:00Z',
                        to: '2026-10-05T16:00:00Z',
                        work_mode: 'on_site',
                    },
                ],
            },
        ],
        events: [
            {
                id: 11,
                kind: 'clock_in',
                at: '2026-10-05T07:00:00Z',
                work_mode: 'on_site',
                pause_type: null,
                source: 'web',
                correction_id: null,
            },
            {
                id: 12,
                kind: 'pause_start',
                at: '2026-10-05T12:00:00Z',
                work_mode: null,
                pause_type: 'meal',
                source: 'web',
                correction_id: null,
            },
            {
                id: 13,
                kind: 'pause_end',
                at: '2026-10-05T13:00:00Z',
                work_mode: 'on_site',
                pause_type: null,
                source: 'web',
                correction_id: null,
            },
            {
                id: 14,
                kind: 'clock_out',
                at: '2026-10-05T16:00:00Z',
                work_mode: null,
                pause_type: null,
                source: 'web',
                correction_id: null,
            },
        ],
        worked_minutes: 480,
        pause_minutes: 60,
        difference_minutes: 0,
        excess_minutes: 0,
        modes: ['on_site'],
        incidents: [],
        in_progress: false,
        status: 'ok',
        pending_corrections: 0,
        disputed_corrections: 0,
        ...overrides,
    };
}

function correction(overrides: Partial<Correction> = {}): Correction {
    return {
        id: 7,
        user: { id: 3, name: 'Elena Empleada' },
        date: '2026-10-05',
        status: 'pending',
        reason: 'Olvidé fichar la salida',
        proposed_by: { id: 3, name: 'Elena Empleada' },
        proposed_by_subject: true,
        proposed_at: '2026-10-06T07:00:00Z',
        voids: [],
        adds: [
            { kind: 'clock_out', at: '2026-10-05T16:05:00Z', work_mode: null },
        ],
        decided_by: null,
        decided_at: null,
        decision_note: null,
        dispute_reason: null,
        expires_at: '2026-10-13T07:00:00Z',
        can: { decide: true, withdraw: false },
        ...overrides,
    };
}

beforeEach(() => {
    inertia.post.mockReset();
    inertia.get.mockReset();
    inertia.page.props = {
        auth: { user: { id: 3, name: 'Elena' }, can: abilities() },
        people: { clock: clock(), pending: 0 },
        timer: null,
    };
});

describe('botón de fichar', () => {
    it('sin jornada: «Entrar» ficha la entrada con el último modo y nunca manda la hora', async () => {
        render(<ClockButton clock={clock()} />);

        await userEvent.click(screen.getByTestId('clock-in'));

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, data] = inertia.post.mock.calls[0];
        expect(url).toBe('/fichar');
        expect(data).toEqual({
            kind: 'clock_in',
            work_mode: 'remote',
            source: 'web',
        });
        expect(Object.keys(data)).not.toContain('occurred_at');
    });

    it('trabajando: muestra el tiempo de hoy, la comida y la salida', async () => {
        const started = new Date(Date.now() - 90.5 * 60_000).toISOString();
        render(
            <ClockButton
                clock={clock({
                    status: 'working',
                    since: started,
                    running_since: started,
                    worked_seconds: 3600,
                })}
            />,
        );

        expect(screen.getByTestId('clock-worked').textContent).toContain(
            '2:30',
        );

        await userEvent.click(screen.getByTestId('clock-pause'));
        expect(inertia.post.mock.calls[0][1]).toMatchObject({
            kind: 'pause_start',
        });
    });

    it('en la comida: «Volver» ficha la vuelta', async () => {
        render(
            <ClockButton
                clock={clock({
                    status: 'paused',
                    since: '2026-10-05T12:00:00Z',
                })}
            />,
        );

        await userEvent.click(screen.getByTestId('clock-back'));

        expect(inertia.post.mock.calls[0][1]).toMatchObject({
            kind: 'pause_end',
            work_mode: 'remote',
        });
    });

    it('con el temporizador en marcha, pregunta si se para al salir (sí por defecto)', async () => {
        inertia.page.props.timer = {
            task_id: 5,
            task_title: 'Banners',
            project_id: 1,
            project_code: 'ARR',
            project_name: 'Arrieta',
            started_at: new Date().toISOString(),
            description: null,
        };
        const started = new Date().toISOString();
        render(
            <ClockButton
                clock={clock({
                    status: 'working',
                    since: started,
                    running_since: started,
                })}
            />,
        );

        await userEvent.click(screen.getByTestId('clock-out'));

        expect(inertia.post).not.toHaveBeenCalled();
        expect(
            screen.getByText('¿Paras también el temporizador?'),
        ).toBeTruthy();
        expect(document.activeElement).toBe(
            screen.getByTestId('clock-stop-timer'),
        );

        await userEvent.click(screen.getByTestId('clock-keep-timer'));
        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(inertia.post.mock.calls[0][1]).toMatchObject({
            kind: 'clock_out',
        });
    });
});

describe('corrección', () => {
    it('envía los fichajes como deben quedar, con el motivo', async () => {
        render(
            <CorrectionDialog
                subject={{ id: 3, name: 'Elena', is_me: true }}
                date="2026-10-05"
                events={day().events}
                open
                onOpenChange={() => {}}
            />,
        );

        const submit = screen.getByTestId('correction-submit');
        expect((submit as HTMLButtonElement).disabled).toBe(true);

        const times = screen.getAllByTestId('correction-time');
        await userEvent.clear(times[3]);
        await userEvent.type(times[3], '18:30');
        await userEvent.type(
            screen.getByTestId('correction-reason'),
            'Salí a las 18:30',
        );

        expect(screen.getByText('2 cambios')).toBeTruthy();
        await userEvent.click(submit);

        const [url, data] = inertia.post.mock.calls[0];
        expect(url).toBe('/personas/correcciones');
        expect(data.user_id).toBe(3);
        expect(data.date).toBe('2026-10-05');
        expect(data.reason).toBe('Salí a las 18:30');
        expect(data.rows[3]).toEqual({
            id: 14,
            kind: 'clock_out',
            time: '18:30',
            next_day: false,
            work_mode: null,
        });
    });

    it('la tarjeta: aceptar o rechazar con motivo; lo anulado tachado', async () => {
        render(
            <CorrectionCard
                correction={correction({ voids: [day().events[3]] })}
            />,
        );

        expect(screen.getByText(/18:00 Salida/).className).toContain(
            'line-through',
        );

        await userEvent.click(screen.getByTestId('correction-accept'));
        expect(inertia.post.mock.calls[0][0]).toBe(
            '/personas/correcciones/7/aceptar',
        );

        await userEvent.click(screen.getByTestId('correction-reject'));
        const dialog = screen.getByRole('dialog');
        expect(
            (
                within(dialog).getByTestId(
                    'correction-reject-submit',
                ) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        await userEvent.type(
            within(dialog).getByTestId('correction-reject-note'),
            'No consta',
        );
        await userEvent.click(
            within(dialog).getByTestId('correction-reject-submit'),
        );
        expect(inertia.post.mock.calls[1]).toEqual([
            '/personas/correcciones/7/rechazar',
            { note: 'No consta' },
            expect.anything(),
        ]);
    });
});

describe('pantallas', () => {
    const props = (
        overrides: Partial<WorkdayPageProps> = {},
    ): WorkdayPageProps => ({
        subject: { id: 3, name: 'Elena', avatar: null, is_me: true },
        month: '2026-10',
        today: '2026-10-06',
        days: [
            day(),
            day({
                date: '2026-10-06',
                status: 'incident',
                incidents: ['missing_clock_out'],
                workdays: [],
                events: [],
                worked_minutes: 0,
                difference_minutes: -480,
            }),
            day({
                date: '2026-10-07',
                status: 'future',
                workdays: [],
                events: [],
                worked_minutes: 0,
                difference_minutes: null,
            }),
        ],
        totals: {
            worked_minutes: 480,
            expected_minutes: 960,
            difference_minutes: -480,
            excess_minutes: 0,
            incident_days: 1,
            pending_days: 0,
            days_worked: 1,
        },
        week: {
            worked_minutes: 480,
            expected_minutes: 960,
            difference_minutes: -480,
            excess_minutes: 0,
            incident_days: 1,
            pending_days: 0,
            days_worked: 1,
        },
        today_day: null,
        detail: null,
        awaiting_me: [],
        can: { propose: true },
        ...overrides,
    });

    it('Mi jornada: el diario hasta hoy (lo más reciente arriba), incidencias y abrir un día', async () => {
        render(<WorkdayPage {...props()} />);

        const rows = screen.getAllByTestId('diary-row');
        expect(rows.map((row) => row.getAttribute('data-date'))).toEqual([
            '2026-10-06',
            '2026-10-05',
        ]);
        expect(within(rows[0]).getByText('Falta la salida')).toBeTruthy();
        expect(
            within(rows[1]).getByText('09:00–14:00 · 15:00–18:00'),
        ).toBeTruthy();

        await userEvent.click(within(rows[1]).getByTestId('diary-open'));
        expect(inertia.get).toHaveBeenCalledWith(
            '/personas/jornada?mes=2026-10&dia=2026-10-05',
            {},
            expect.objectContaining({ only: ['detail'] }),
        );
    });

    it('Mi jornada: las correcciones que esperan mi conformidad, arriba', () => {
        render(
            <WorkdayPage
                {...props({
                    awaiting_me: [
                        correction({
                            proposed_by: { id: 2, name: 'Raúl' },
                            proposed_by_subject: false,
                        }),
                    ],
                })}
            />,
        );

        expect(screen.getByTestId('awaiting-me').textContent).toContain(
            'Una corrección espera tu conformidad',
        );
    });

    it('Jornada del equipo: cómo está cada uno y las celdas al diario de esa persona', () => {
        render(
            <TeamWorkdayPage
                week="2026-W41"
                previous_week="2026-W40"
                next_week={null}
                dates={['2026-10-05']}
                today="2026-10-05"
                departments={[]}
                department_id={null}
                pending={2}
                members={[
                    {
                        id: 3,
                        name: 'Elena Empleada',
                        avatar: null,
                        department: null,
                        now: {
                            state: 'working',
                            since: '2026-10-05T07:00:00Z',
                        },
                        days: [
                            {
                                date: '2026-10-05',
                                worked_minutes: 300,
                                expected_minutes: 480,
                                difference_minutes: null,
                                status: 'in_progress',
                                incidents: [],
                                holiday: false,
                                absence: false,
                                in_progress: true,
                            },
                        ],
                        totals: {
                            worked_minutes: 300,
                            expected_minutes: 0,
                            difference_minutes: 300,
                            excess_minutes: 0,
                            incident_days: 0,
                            pending_days: 0,
                            days_worked: 1,
                        },
                    },
                ]}
            />,
        );

        expect(screen.getByTestId('team-now').textContent).toContain(
            'Trabajando',
        );
        expect(screen.getByTestId('team-cell').getAttribute('href')).toBe(
            '/personas/equipo/3?mes=2026-10&dia=2026-10-05',
        );
        expect(screen.getByTestId('team-cell').textContent).toContain(
            '5:00 / 8:00',
        );
        expect(screen.getByTestId('team-pending').textContent).toContain(
            'Tienes 2 correcciones por decidir.',
        );
    });

    it('Pendientes: aceptar varias a la vez', async () => {
        render(
            <PendingPage
                corrections={[
                    correction(),
                    correction({ id: 8, date: '2026-10-02' }),
                ]}
                manages_all={false}
            />,
        );

        await userEvent.click(screen.getByTestId('pending-select-all'));
        await userEvent.click(screen.getByTestId('pending-accept-selected'));

        expect(inertia.post).toHaveBeenCalledWith(
            '/personas/correcciones/aceptar',
            { ids: [7, 8] },
            expect.anything(),
        );
    });
});

describe('barra lateral', () => {
    it('«Mi jornada» con el contador y, debajo, Mi registro y Documentos; para responsables, el equipo; para RR. HH., informes e Inspección', () => {
        const mine = peopleNavItems(abilities(), { peoplePending: 0 });
        expect(mine[0].title).toBe('Mi jornada');
        expect(mine[0].badge).toBeUndefined();
        expect(mine[0].items?.map((item) => item.title)).toEqual([
            'Mi registro',
            'Documentos',
        ]);

        const manager = peopleNavItems(abilities({ viewPeopleTeam: true }), {
            peoplePending: 3,
        });
        expect(manager[0].badge).toEqual({
            count: 3,
            label: '3 pendientes de decidir',
        });
        expect(manager[0].items?.map((item) => item.title)).toEqual([
            'Mi registro',
            'Documentos',
            'Jornada del equipo',
            'Pendientes',
            'Cierres',
            'Horas extra',
        ]);

        const hr = peopleNavItems(
            abilities({ viewPeopleTeam: true, managePeopleRegister: true }),
            { pendingClose: true, unreadDocuments: 1 },
        );
        expect(hr[0].items?.map((item) => item.title).slice(-2)).toEqual([
            'Informes',
            'Inspección',
        ]);
        expect(hr[0].badge).toEqual({
            count: 2,
            label: '2 cosas por revisar',
        });

        expect(
            peopleNavItems(abilities({ usePeople: false })).map(
                (item) => item.title,
            ),
        ).toEqual(['Ausencias']);
    });
});
