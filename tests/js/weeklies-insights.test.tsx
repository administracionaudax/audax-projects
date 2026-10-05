// @vitest-environment jsdom
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import fixture from '../fixtures/weeklies/project-status-board.json';

const page = vi.hoisted(() => ({
    url: '/equipo',
    props: {
        auth: { user: { id: 1 }, can: {} },
        config: { modules: {} },
    } as Record<string, unknown>,
}));

const router = vi.hoisted(() => ({
    post: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
    get: vi.fn(),
}));

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));

vi.mock('sonner', () => ({ toast }));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        router,
        usePage: () => page,
        setLayoutProps: vi.fn(),
        Head: () => null,
        Link: ({
            href,
            children,
            prefetch: _prefetch,
            preserveScroll: _preserveScroll,
            ...rest
        }: {
            href: string | { url: string };
            children?: ReactNode;
            prefetch?: boolean;
            preserveScroll?: boolean;
            [key: string]: unknown;
        }) => (
            <a href={typeof href === 'string' ? href : href.url} {...rest}>
                {children}
            </a>
        ),
    };
});

import { TooltipProvider } from '@/components/ui/tooltip';
import { AiSummaryPanel } from '@/components/weeklies/insights/ai-summary-panel';
import {
    ClientSatisfactionPanel,
    ClientTeamPanel,
} from '@/components/weeklies/insights/client-weekly-panels';
import { ProjectStatusView } from '@/components/weeklies/insights/project-status-view';
import { formatPoints } from '@/components/weeklies/insights/satisfaction-chart';
import {
    deltaLabel,
    deltaTone,
    deltaValueLabel,
    expectedPercent,
    isOverBudget,
    progressPercent,
} from '@/lib/project-status';
import { filterTeam } from '@/lib/team-filter';
import TeamIndex from '@/pages/team/index';
import TeamShow from '@/pages/team/show';
import type {
    AiSummary,
    ClientWeeklyTeamTab,
    ProjectStatusClient,
    ProjectStatusEntry,
    TeamIndexPageProps,
    TeamMemberRow,
    TeamShowPageProps,
} from '@/types/weekly-insights';

const wrap = (node: ReactNode) =>
    render(<TooltipProvider>{node}</TooltipProvider>);

/** Elementos por su `data-test` (la convención de la app y de los E2E). */
const byTest = (name: string, root: ParentNode = document): HTMLElement[] =>
    Array.from(root.querySelectorAll<HTMLElement>(`[data-test="${name}"]`));

beforeEach(() => {
    router.post.mockReset();
    router.reload.mockReset();
    toast.success.mockReset();
    toast.error.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
});

/** Las filas esperadas del fixture compartido con Pest, con la forma de ProjectStatusBoard. */
function boardFromFixture(): ProjectStatusClient[] {
    return fixture.expected.map((group, index) => ({
        client: {
            id: index + 1,
            name: group.client,
            icon: index === 0 ? '🍷' : null,
        },
        badges: group.badges as ProjectStatusClient['badges'],
        projects: group.projects.map((project, position) => ({
            project_id: (index + 1) * 10 + position,
            code: project.code,
            name: project.name,
            billing_type:
                project.billing_type as ProjectStatusEntry['billing_type'],
            budget_minutes: project.budget_minutes,
            consumed_minutes: project.consumed_minutes,
            expected_minutes: project.expected_minutes,
            deviation_minutes: project.deviation_minutes,
            week_minutes: project.week_minutes,
            kind_code: project.kind_code as ProjectStatusEntry['kind_code'],
            project_status: 'active',
        })),
    }));
}

describe('estado de proyectos: las piezas puras con el fixture de Pest', () => {
    const rows = boardFromFixture().flatMap((group) => group.projects);
    const expected = fixture.expected.flatMap((group) => group.projects);

    it.each(expected.map((project, index) => [project.code, index] as const))(
        '%s: progreso, exceso, lo esperado y la desviación',
        (_code, index) => {
            const row = rows[index];
            const ui = expected[index].ui;

            expect(Math.round(progressPercent(row) * 100) / 100).toBe(
                ui.progress,
            );
            expect(isOverBudget(row)).toBe(ui.over);
            expect(expectedPercent(row)).toBe(ui.expected_percent);
            expect(deltaTone(row)).toBe(ui.tone);
            expect(deltaValueLabel(row)).toBe(ui.delta_value);
        },
    );

    it('la frase de la desviación', () => {
        const fee = rows.find((row) => row.code === 'ACME-FE1')!;

        expect(deltaLabel(fee)).toBe('-0:43 por debajo de lo esperado');
        expect(deltaLabel({ ...fee, deviation_minutes: 90 })).toBe(
            '+1:30 sobre lo esperado',
        );
        expect(deltaLabel({ ...fee, deviation_minutes: 0 })).toBe(
            'En línea con lo esperado',
        );
    });
});

describe('vista «Estado de proyectos»', () => {
    it('por cliente: insignias, tarjetas con consumo, lo esperado del fee y su desviación', () => {
        wrap(<ProjectStatusView clients={boardFromFixture()} />);

        const cards = byTest('project-status-client-card');
        expect(cards).toHaveLength(2);
        const acme = within(cards[0]);
        expect(acme.getByRole('link', { name: 'Acme' })).toBeTruthy();
        expect(
            acme.getByRole('list', { name: 'Proyectos abiertos por tipo' }),
        ).toBeTruthy();
        expect(byTest('project-status-card', cards[0])).toHaveLength(4);
        expect(acme.getByText('Esperado: 5:43 (28.58 % del fee)')).toBeTruthy();
        expect(acme.getByText('-0:43 por debajo de lo esperado')).toBeTruthy();
        expect(acme.getAllByText('Sin horas asignadas').length).toBeGreaterThan(
            0,
        );
        expect(
            acme.getByRole('img', {
                name: 'Consumido 102:40 de 100:00 (103 %)',
            }),
        ).toBeTruthy();
    });

    it('filtra por cliente y por tipo, y en la tabla ordena por progreso', async () => {
        const user = userEvent.setup();
        wrap(<ProjectStatusView clients={boardFromFixture()} />);

        await user.selectOptions(screen.getByLabelText('Tipo'), 'monthly_fee');
        expect(byTest('project-status-card')).toHaveLength(1);

        await user.selectOptions(screen.getByLabelText('Tipo'), '');
        await user.selectOptions(screen.getByLabelText('Cliente'), '2');
        expect(byTest('project-status-client-card')).toHaveLength(1);

        await user.click(
            screen.getByRole('button', { name: 'Limpiar filtros' }),
        );
        await user.click(screen.getByRole('button', { name: 'Tabla' }));
        expect(
            screen
                .getByRole('button', { name: 'Tabla' })
                .getAttribute('aria-pressed'),
        ).toBe('true');

        await user.click(
            screen.getByRole('button', { name: 'Ordenar por Progreso' }),
        );
        const codes = () =>
            byTest('project-status-row').map(
                (row) => row.querySelector('a')?.textContent,
            );
        expect(codes()[0]).toContain('ACME-SOP');

        await user.click(
            screen.getByRole('button', { name: 'Ordenar por Progreso' }),
        );
        expect(codes()[0]).toContain('ACME-WE1');
    });

    it('sin proyectos abiertos, un estado vacío', () => {
        wrap(<ProjectStatusView clients={[]} />);

        expect(screen.getByText('No hay proyectos abiertos')).toBeTruthy();
    });
});

const summary = (overrides: Partial<AiSummary> = {}): AiSummary => ({
    kind: 'client_summary',
    state: 'done',
    stuck: false,
    content: '## Estado actual\nTodo en orden.',
    items: null,
    error: null,
    generated_at: '2026-10-08T10:00:00+00:00',
    requested_by: {
        id: 2,
        name: 'Raúl',
        avatar: null,
        department_id: null,
        is_active: true,
    },
    ...overrides,
});

describe('resumen con IA', () => {
    const panel = (value: AiSummary | null) =>
        wrap(
            <AiSummaryPanel
                title="Resumen del cliente (IA)"
                description="Descripción"
                summary={value}
                action="/clientes/3/resumen-ia"
                actionData={{ tipo: 'desempeno' }}
                reloadProp="weekly"
            />,
        );

    it('sin resumen, «Generar resumen» lo pide', async () => {
        panel(null);

        expect(
            screen.getByText(
                'Aún no se ha generado. Se hace con IA a partir de las weeklies.',
            ),
        ).toBeTruthy();
        await userEvent.click(
            screen.getByRole('button', { name: 'Generar resumen' }),
        );
        expect(router.post).toHaveBeenCalledWith(
            '/clientes/3/resumen-ia',
            { tipo: 'desempeno' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('mientras se genera, espera y recarga solo su prop cada 3 s', () => {
        vi.useFakeTimers();
        panel(summary({ state: 'running', content: null }));

        expect(
            (
                screen.getByRole('button', {
                    name: /Generando/,
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        act(() => {
            vi.advanceTimersByTime(3100);
        });
        expect(router.reload).toHaveBeenCalledWith({ only: ['weekly'] });
    });

    it('hecho: el Markdown, quién y cuándo, y «Regenerar»', () => {
        panel(summary());

        expect(
            screen.getByRole('heading', { name: 'Estado actual' }),
        ).toBeTruthy();
        expect(screen.getByText(/por Raúl/)).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Regenerar' })).toBeTruthy();
    });

    it('con error o atascado, lo dice y deja volver a intentarlo', () => {
        const { unmount } = panel(
            summary({
                state: 'failed',
                content: null,
                error: 'La IA no responde.',
            }),
        );

        expect(screen.getByRole('alert').textContent).toContain(
            'La IA no responde.',
        );
        expect(
            screen.getByRole('button', { name: 'Volver a intentarlo' }),
        ).toBeTruthy();
        unmount();

        panel(summary({ state: 'queued', stuck: true, content: null }));
        expect(screen.getByText(/se quedó sin terminar/)).toBeTruthy();
        expect(router.reload).not.toHaveBeenCalled();
    });
});

describe('ficha de cliente: equipo y satisfacción', () => {
    const user = (id: number, name: string) => ({
        id,
        name,
        avatar: null,
        department_id: null,
        is_active: true,
    });

    const team: ClientWeeklyTeamTab = {
        tab: 'equipo',
        owner_id: 1,
        members: [
            {
                user: user(1, 'Raúl'),
                job_title: 'Director',
                role: 'owner',
                projects: [{ id: 5, code: 'ACME-WE1' }],
                last_report_at: null,
                reports: [],
            },
            {
                user: user(2, 'Ana'),
                job_title: null,
                role: 'member',
                projects: [],
                last_report_at: '2026-10-02T15:00:00+00:00',
                reports: [
                    {
                        cycle: {
                            id: 9,
                            number: 'W40-26',
                            label: 'Semana 40',
                            start_date: '2026-09-28',
                            end_date: '2026-10-02',
                            status: 'closed',
                        },
                        body: 'Maquetación de la home',
                        submitted_at: '2026-10-02T15:00:00+00:00',
                    },
                ],
            },
        ],
        ai: summary({
            kind: 'client_team_activity',
            content: null,
            items: { '2': 'Ana maqueta la home.' },
        }),
        my_projects: [
            { id: 5, code: 'ACME-WE1', name: 'Web', can_leave: true },
        ],
    };

    it('el equipo: responsable, la frase de la IA en cada persona y su histórico en un diálogo', async () => {
        wrap(
            <ClientTeamPanel
                clientId={3}
                clientName="Acme"
                data={team}
                joinable={[]}
            />,
        );

        const members = byTest('client-team-member');
        expect(within(members[0]).getByText('Responsable')).toBeTruthy();
        expect(
            within(members[1]).getByText('Ana maqueta la home.'),
        ).toBeTruthy();
        expect(
            within(members[1])
                .getByRole('link', { name: 'Ana' })
                .getAttribute('href'),
        ).toBe('/equipo/2');

        expect(
            within(byTest('client-team-ai')[0]).getByRole('button', {
                name: 'Regenerar',
            }),
        ).toBeTruthy();

        await userEvent.click(
            within(members[1]).getByRole('button', { name: /Histórico/ }),
        );
        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByText('Histórico de Ana')).toBeTruthy();
        expect(within(dialog).getByText('Maquetación de la home')).toBeTruthy();
    });

    it('la satisfacción: actual, semanas y tendencias; sin cierres, un estado vacío', () => {
        const { unmount } = wrap(
            <ClientSatisfactionPanel
                clientName="Acme"
                data={{
                    tab: 'satisfaccion',
                    score: 64,
                    points: [
                        {
                            cycle_id: 1,
                            number: 'W39-26',
                            label: 'Semana 39',
                            end_date: '2026-09-25',
                            score: 58,
                            delta: 0,
                            reasoning: null,
                        },
                        {
                            cycle_id: 2,
                            number: 'W40-26',
                            label: 'Semana 40',
                            end_date: '2026-10-02',
                            score: 64,
                            delta: 6,
                            reasoning: null,
                        },
                    ],
                    deltas: { weekly: 6, monthly: null, quarterly: null },
                }}
            />,
        );

        expect(screen.getByText('64 %')).toBeTruthy();
        expect(screen.getByText('+6 pts')).toBeTruthy();
        expect(screen.getAllByText('N/D')).toHaveLength(2);
        expect(screen.getByText('Evolución de la satisfacción')).toBeTruthy();
        unmount();

        wrap(
            <ClientSatisfactionPanel
                clientName="Acme"
                data={{
                    tab: 'satisfaccion',
                    score: 50,
                    points: [],
                    deltas: { weekly: null, monthly: null, quarterly: null },
                }}
            />,
        );
        expect(
            screen.getByText(
                'Aún no hay datos suficientes para mostrar la evolución de satisfacción.',
            ),
        ).toBeTruthy();
    });

    it('los puntos: con signo, sin él o N/D', () => {
        expect(formatPoints(3.4)).toBe('+3 pts');
        expect(formatPoints(-2)).toBe('-2 pts');
        expect(formatPoints(0)).toBe('0 pts');
        expect(formatPoints(null)).toBe('N/D');
    });
});

const member = (overrides: Partial<TeamMemberRow> = {}): TeamMemberRow => ({
    user: {
        id: 1,
        name: 'Ana',
        avatar: null,
        department_id: 1,
        is_active: true,
    },
    email: 'ana@audax.test',
    job_title: 'Diseñadora',
    department: { id: 1, name: 'Diseño' },
    role: 'employee',
    report_status: 'submitted',
    submitted_at: null,
    absence: null,
    client_ids: [3],
    ...overrides,
});

const teamRows = [
    member(),
    member({
        user: {
            id: 2,
            name: 'Pablo',
            avatar: null,
            department_id: 2,
            is_active: true,
        },
        email: 'pablo@audax.test',
        job_title: null,
        department: { id: 2, name: 'Desarrollo' },
        report_status: 'pending',
        absence: { type: null, until: '2026-10-09' },
        client_ids: [],
    }),
    member({
        user: {
            id: 3,
            name: 'Raúl',
            avatar: null,
            department_id: null,
            is_active: true,
        },
        email: 'raul@audax.test',
        department: null,
        role: 'department_manager',
        report_status: 'exempt',
        client_ids: [3, 4],
    }),
];

describe('equipo', () => {
    it('filtra por texto, departamento, rol, estado y cliente, y ordena', () => {
        const all = { q: '', department: '', role: '', status: '', client: '' };
        const names = (rows: TeamMemberRow[]) =>
            rows.map((row) => row.user.name);
        const byName = { key: 'name', dir: 'asc' } as const;

        expect(
            names(filterTeam(teamRows, { ...all, q: 'audax.test' }, byName)),
        ).toEqual(['Ana', 'Pablo', 'Raúl']);
        expect(
            names(filterTeam(teamRows, { ...all, q: 'diseñ' }, byName)),
        ).toEqual(['Ana', 'Raúl']);
        expect(
            names(filterTeam(teamRows, { ...all, department: 'none' }, byName)),
        ).toEqual(['Raúl']);
        expect(
            names(
                filterTeam(
                    teamRows,
                    { ...all, role: 'department_manager' },
                    byName,
                ),
            ),
        ).toEqual(['Raúl']);
        expect(
            names(filterTeam(teamRows, { ...all, status: 'pending' }, byName)),
        ).toEqual(['Pablo']);
        expect(
            names(filterTeam(teamRows, { ...all, client: '4' }, byName)),
        ).toEqual(['Raúl']);
        expect(
            names(filterTeam(teamRows, all, { key: 'status', dir: 'asc' })),
        ).toEqual(['Ana', 'Pablo', 'Raúl']);
        expect(
            names(
                filterTeam(teamRows, all, { key: 'department', dir: 'desc' }),
            ),
        ).toEqual(['Raúl', 'Ana', 'Pablo']);
    });

    const indexProps: TeamIndexPageProps = {
        members: teamRows,
        cycle: {
            id: 9,
            number: 'W41-26',
            label: 'Semana 41',
            start_date: '2026-10-05',
            end_date: '2026-10-09',
            status: 'active',
        },
        departments: [
            { id: 1, name: 'Diseño' },
            { id: 2, name: 'Desarrollo' },
        ],
        clients: [{ id: 3, name: 'Acme', icon: '🍷' }],
        roles: ['admin', 'department_manager', 'employee'],
        can: { manageUsers: false },
    };

    it('la lista: estado del reporte, ausencia sin el tipo, filtros y copiar el email', async () => {
        // userEvent.setup() pone su propio portapapeles: se lee después.
        const user = userEvent.setup();
        wrap(<TeamIndex {...indexProps} />);

        expect(screen.getByText('Estado de la Semana 41')).toBeTruthy();
        const rows = byTest('team-row');
        expect(rows).toHaveLength(3);
        expect(within(rows[0]).getByText('Enviado')).toBeTruthy();
        expect(
            within(rows[1]).getByText('Ausente hasta el 09/10/2026'),
        ).toBeTruthy();
        expect(
            screen.queryByRole('link', { name: 'Gestionar personas' }),
        ).toBeNull();

        await user.selectOptions(
            screen.getByLabelText('Estado del reporte'),
            'pending',
        );
        expect(byTest('team-row')).toHaveLength(1);
        expect(screen.getByText('1 personas')).toBeTruthy();

        await user.click(
            screen.getByRole('button', { name: 'Copiar el email de Pablo' }),
        );
        expect(await navigator.clipboard.readText()).toBe('pablo@audax.test');
        expect(toast.success).toHaveBeenCalledWith('Email copiado');
    });

    const showProps = (
        overrides: Partial<TeamShowPageProps> = {},
    ): TeamShowPageProps => ({
        person: {
            id: 1,
            name: 'Ana',
            avatar: null,
            department_id: 1,
            is_active: true,
            email: 'ana@audax.test',
            job_title: 'Diseñadora',
            department: { id: 1, name: 'Diseño' },
            role: 'employee',
        },
        absence: null,
        habits: {
            total: 3,
            average_time: '14:10',
            time_of_day: 'afternoon',
            most_common_day: 'friday',
            distribution: { friday: 2, saturday: 1, sunday: 0, other: 0 },
        },
        clients: {
            owned: [
                {
                    id: 3,
                    name: 'Acme',
                    icon: '🍷',
                    badges: [{ tag: 'web', count: 1 }],
                    projects: [{ id: 5, code: 'ACME-WE1', name: 'Web' }],
                },
            ],
            member: [],
        },
        last_reports: [],
        weeks: [],
        cycle: { id: 9, label: 'Semana 41', number: 'W41-26' },
        status: 'pending',
        streak: { submitted: 5, on_time: 4, streak: 3 },
        ai: null,
        can: { viewAi: false, manageUser: false },
        ...overrides,
    });

    it('la ficha: racha y hábitos; sin permiso, sin resúmenes con IA', () => {
        wrap(<TeamShow {...showProps()} />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'Ana' }),
        ).toBeTruthy();
        expect(screen.getByText('14:10')).toBeTruthy();
        expect(screen.getByText('Por la tarde')).toBeTruthy();
        expect(screen.getByText('Pendiente')).toBeTruthy();
        expect(screen.queryByText('Resumen de desempeño (IA)')).toBeNull();
        expect(
            screen.getByRole('link', { name: /Acme/ }).getAttribute('href'),
        ).toBe('/clientes/3');
    });

    it('con permiso, los dos resúmenes y la actividad de cada cliente en su tarjeta', () => {
        wrap(
            <TeamShow
                {...showProps({
                    ai: {
                        performance: summary({ kind: 'person_performance' }),
                        client_activity: summary({
                            kind: 'person_client_activity',
                            content: null,
                            items: { '3': 'Lidera el diseño de la web.' },
                        }),
                    },
                    can: { viewAi: true, manageUser: true },
                })}
            />,
        );

        expect(screen.getByText('Resumen de desempeño (IA)')).toBeTruthy();
        expect(screen.getByText('Actividad por cliente (IA)')).toBeTruthy();
        expect(
            within(byTest('person-client')[0]).getByText(
                'Lidera el diseño de la web.',
            ),
        ).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: 'Editar en Administración' })
                .getAttribute('href'),
        ).toBe('/admin/usuarios/1');
    });
});
