// @vitest-environment jsdom
import {
    configure,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CalendarFilterBar } from '@/components/calendar/calendar-filter-bar';
import {
    calendarFilterQuery,
    calendarQuery,
    movedTeamDates,
    shiftDate,
} from '@/components/calendar/calendar-query';
import { TeamCalendar, teamSpans } from '@/components/calendar/team-calendar';
import type { TaskStatus } from '@/types';
import type {
    TeamCalendarData,
    TeamCalendarFilters,
    TeamCalendarTask,
} from '@/types/calendar';
import type { ShiftProposal } from '@/types/schedule';

/*
| Calendario del equipo (D-144): mes, semana, día y personas; mover con arrastre y con el teclado
| solo con permiso; crear en un día; filtros; el mes en el móvil.
*/

configure({ testIdAttribute: 'data-test' });
vi.setConfig({ testTimeout: 20_000 });

for (const method of [
    'hasPointerCapture',
    'releasePointerCapture',
    'setPointerCapture',
    'scrollIntoView',
] as const) {
    if (!(method in Element.prototype)) {
        Object.defineProperty(Element.prototype, method, {
            configurable: true,
            value: () => false,
        });
    }
}

type DragEnd = (event: {
    active: { id: string; data: { current: { task: TeamCalendarTask } } };
    over: { id: string } | null;
}) => void;

const mocks = vi.hoisted(() => ({
    preview:
        vi.fn<
            (
                taskId: number,
                dates: { start_date: string | null; due_date: string | null },
            ) => Promise<ShiftProposal[]>
        >(),
    save: vi.fn(),
    dragEnd: null as DragEnd | null,
    mobile: false,
}));

vi.mock('@/components/planning/reschedule-requests', () => ({
    RescheduleError: class extends Error {},
    fetchReschedulePreview: (
        taskId: number,
        dates: { start_date: string | null; due_date: string | null },
    ) => mocks.preview(taskId, dates),
    saveReschedule: (...args: unknown[]) => mocks.save(...args),
}));

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => mocks.mobile }));

vi.mock('@dnd-kit/core', async (importOriginal) => {
    const original = await importOriginal<typeof import('@dnd-kit/core')>();

    return {
        ...original,
        DndContext: ({
            children,
            onDragEnd,
        }: {
            children: ReactNode;
            onDragEnd: DragEnd;
        }) => {
            mocks.dragEnd = onDragEnd;

            return <>{children}</>;
        },
    };
});

const statuses: TaskStatus[] = [
    {
        id: 1,
        name: 'Por hacer',
        color: '#56667A',
        category: 'todo',
        position: 0,
        is_default: true,
    },
    {
        id: 2,
        name: 'En curso',
        color: '#0171FF',
        category: 'in_progress',
        position: 1,
        is_default: false,
    },
];

function task(
    id: number,
    overrides: Partial<TeamCalendarTask> = {},
): TeamCalendarTask {
    return {
        id,
        title: `Tarea ${id}`,
        project_id: 1,
        parent_task_id: null,
        parent_title: null,
        status_id: 1,
        priority: 'normal',
        task_type_id: null,
        assignee_id: 7,
        start_date: null,
        due_date: '2026-10-07',
        is_milestone: false,
        is_completed: false,
        estimated_minutes: null,
        ...overrides,
    };
}

function data(overrides: Partial<TeamCalendarData> = {}): TeamCalendarData {
    return {
        view: 'week',
        date: '2026-10-07',
        from: '2026-10-05',
        to: '2026-10-11',
        today: '2026-10-07',
        people_view: false,
        tasks: [],
        projects: [
            {
                id: 1,
                code: 'HOTEL',
                name: 'Web Hoteles',
                color: '#179FA5',
                can_update: true,
            },
            {
                id: 2,
                code: 'ACME',
                name: 'Web ACME',
                color: '#E65FB3',
                can_update: false,
            },
        ],
        assignees: [
            {
                id: 7,
                name: 'Ana Ruiz',
                avatar: null,
                department_id: 1,
                is_active: true,
            },
        ],
        truncated: false,
        total: 0,
        limit: 1500,
        rows: [],
        ...overrides,
    };
}

function renderCalendar(calendar: TeamCalendarData) {
    const onNavigate = vi.fn();
    const onCreate = vi.fn();
    const onOpen = vi.fn();
    render(
        <TeamCalendar
            calendar={calendar}
            statuses={statuses}
            context={{
                statusById: new Map(
                    statuses.map((status) => [status.id, status]),
                ),
                projectById: new Map(
                    calendar.projects.map((project) => [project.id, project]),
                ),
                assigneeById: new Map(
                    calendar.assignees.map((user) => [user.id, user]),
                ),
                onOpen,
            }}
            onNavigate={onNavigate}
            onCreate={onCreate}
        />,
    );

    return { onNavigate, onCreate, onOpen };
}

beforeEach(() => {
    mocks.preview.mockReset().mockResolvedValue([]);
    mocks.save.mockReset();
    mocks.mobile = false;
});

describe('fechas y URL', () => {
    it('mueve el inicio y la entrega lo mismo (o solo la fecha que tenga)', () => {
        expect(
            movedTeamDates(
                {
                    start_date: '2026-10-05',
                    due_date: '2026-10-07',
                    is_milestone: false,
                },
                '2026-10-09',
            ),
        ).toEqual({ start_date: '2026-10-07', due_date: '2026-10-09' });
        expect(
            movedTeamDates(
                {
                    start_date: '2026-10-05',
                    due_date: null,
                    is_milestone: false,
                },
                '2026-10-12',
            ),
        ).toEqual({ start_date: '2026-10-12', due_date: null });
        expect(
            movedTeamDates(
                {
                    start_date: null,
                    due_date: '2026-10-07',
                    is_milestone: true,
                },
                '2026-10-01',
            ),
        ).toEqual({ start_date: null, due_date: '2026-10-01' });
    });

    it('navega por meses, semanas (desde el lunes) y días', () => {
        expect(shiftDate('month', '2026-12-15', 1)).toBe('2027-01-01');
        expect(shiftDate('week', '2026-10-07', -1)).toBe('2026-09-28');
        expect(shiftDate('day', '2026-10-31', 1)).toBe('2026-11-01');
    });

    it('lleva la vista y los filtros a la URL en español, sin los vacíos', () => {
        const filters: TeamCalendarFilters = {
            view: 'day',
            people: true,
            date: '2026-10-09',
            persons: [3, 7],
            department: 2,
            projects: [],
            clients: [5],
            done: true,
            priority: 'high',
            types: [],
            milestones: true,
            unassigned: false,
            mine: true,
            q: ' web ',
        };

        expect(calendarFilterQuery(filters)).toEqual({
            vista: 'dia',
            personas: '1',
            persona: '3,7',
            cliente: '5',
            departamento: '2',
            hechas: '1',
            prioridad: 'high',
            hitos: '1',
            mias: '1',
            q: 'web',
        });
        expect(calendarQuery(filters, '2026-10-07').fecha).toBe('2026-10-09');
        expect(
            calendarQuery({ ...filters, date: '2026-10-07' }, '2026-10-07'),
        ).not.toHaveProperty('fecha');
    });

    it('reparte las franjas en filas sin solaparse', () => {
        const days = [
            '2026-10-05',
            '2026-10-06',
            '2026-10-07',
            '2026-10-08',
            '2026-10-09',
            '2026-10-10',
            '2026-10-11',
        ];
        const spans = teamSpans(
            [
                task(1, { start_date: '2026-10-01', due_date: '2026-10-06' }),
                task(2, { start_date: '2026-10-06', due_date: '2026-10-20' }),
                task(3, { start_date: '2026-10-08', due_date: '2026-10-09' }),
            ],
            days,
        );

        expect(
            spans.map((span) => [
                span.task.id,
                span.startColumn,
                span.endColumn,
                span.lane,
                span.continuesBefore,
                span.continuesAfter,
            ]),
        ).toEqual([
            [1, 0, 1, 0, true, false],
            [2, 1, 6, 1, false, true],
            [3, 3, 4, 0, false, false],
        ]);
    });
});

describe('vistas', () => {
    it('semana: tarjetas con color, código, estado con texto y responsable, y franjas de rango', () => {
        renderCalendar(
            data({
                tasks: [
                    task(1, { title: 'Portada', status_id: 2 }),
                    task(2, {
                        title: 'Lanzamiento',
                        is_milestone: true,
                        due_date: '2026-10-09',
                        assignee_id: null,
                    }),
                    task(3, {
                        title: 'Rediseño',
                        start_date: '2026-10-05',
                        due_date: '2026-10-08',
                    }),
                ],
            }),
        );

        const chip = screen.getByRole('button', { name: /^Portada\./ });
        expect(chip.getAttribute('aria-label')).toContain(
            'HOTEL · Web Hoteles',
        );
        expect(chip.getAttribute('aria-label')).toContain('En curso');
        expect(chip.getAttribute('aria-label')).toContain('Ana Ruiz');
        expect(within(chip).getByText('En curso')).toBeTruthy();
        expect(within(chip).getByText('HOTEL')).toBeTruthy();
        expect(chip.style.borderLeftColor).toBe('rgb(23, 159, 165)');
        expect(
            screen.getByRole('button', { name: /^Lanzamiento\..*Hito/ }),
        ).toBeTruthy();
        expect(
            screen.getByTestId('team-span').getAttribute('aria-label'),
        ).toContain('Rediseño');
        expect(screen.getByTestId('team-title').textContent).toMatch(/5 oct/);
    });

    it('mes: como mucho tres tarjetas por día y «+N más»', () => {
        renderCalendar(
            data({
                view: 'month',
                from: '2026-09-28',
                to: '2026-11-01',
                tasks: [1, 2, 3, 4, 5].map((id) => task(id)),
            }),
        );

        expect(screen.getByTestId('team-month')).toBeTruthy();
        expect(screen.getAllByTestId('team-chip')).toHaveLength(3);
        expect(screen.getByTestId('team-more').textContent).toContain('2');
    });

    it('mes en el móvil: lista agrupada por día', () => {
        mocks.mobile = true;
        renderCalendar(
            data({
                view: 'month',
                from: '2026-09-28',
                to: '2026-11-01',
                tasks: [task(1), task(2, { due_date: '2026-10-20' })],
            }),
        );

        expect(screen.queryByTestId('team-month')).toBeNull();
        const list = screen.getByTestId('team-month-list');
        expect(within(list).getAllByRole('heading', { level: 3 })).toHaveLength(
            2,
        );
    });

    it('día: lo que vence o empieza ese día y lo que sigue en curso', () => {
        renderCalendar(
            data({
                view: 'day',
                from: '2026-10-07',
                to: '2026-10-07',
                tasks: [
                    task(1, { title: 'Hoy' }),
                    task(2, {
                        title: 'Larga',
                        start_date: '2026-10-01',
                        due_date: '2026-10-20',
                    }),
                ],
            }),
        );

        expect(screen.getByRole('button', { name: /^Hoy\./ })).toBeTruthy();
        expect(screen.getByText('En curso', { selector: 'p' })).toBeTruthy();
        expect(screen.getByTestId('team-span').textContent).toContain('Larga');
    });

    it('personas: filas con avatar, departamento, carga, ausencias y «Sin asignar» solo con tareas', () => {
        const day = (overrides = {}) => ({
            capacity: null,
            load: null,
            absence: null,
            holiday: null,
            ...overrides,
        });
        renderCalendar(
            data({
                view: 'day',
                from: '2026-10-07',
                to: '2026-10-07',
                people_view: true,
                tasks: [task(1, { title: 'De Ana' })],
                rows: [
                    {
                        person: {
                            id: 7,
                            name: 'Ana Ruiz',
                            avatar: null,
                            department: { id: 1, name: 'Diseño' },
                        },
                        show_load: true,
                        days: {
                            '2026-10-07': day({ capacity: 480, load: 600 }),
                        },
                    },
                    {
                        person: {
                            id: 8,
                            name: 'Bea Gil',
                            avatar: null,
                            department: null,
                        },
                        show_load: false,
                        days: {
                            '2026-10-07': day({
                                absence: { partial: false, label: null },
                            }),
                        },
                    },
                    {
                        person: null,
                        show_load: false,
                        days: { '2026-10-07': day() },
                    },
                ],
            }),
        );

        const rows = screen.getAllByTestId('team-person-row');
        expect(rows).toHaveLength(2);
        expect(within(rows[0]).getByText('Diseño')).toBeTruthy();
        expect(
            within(rows[0]).getByRole('button', { name: /^De Ana\./ }),
        ).toBeTruthy();
        const load = within(rows[0]).getByTestId('team-load');
        expect(load.textContent).toContain('10:00 de 8:00');
        expect(load.dataset.level).toBe('over');
        expect(within(rows[1]).getByText('Sin departamento')).toBeTruthy();
        expect(within(rows[1]).getByTestId('team-absence').textContent).toBe(
            'Ausente',
        );
        expect(within(rows[1]).queryByTestId('team-load')).toBeNull();
    });

    it('avisa si hay más tareas de las que caben', () => {
        renderCalendar(data({ truncated: true, total: 2000 }));

        expect(screen.getByTestId('team-truncated').textContent).toContain(
            '1500 de 2000',
        );
    });
});

describe('interacción', () => {
    it('arrastrar a otro día pide la propuesta con las fechas desplazadas', async () => {
        renderCalendar(
            data({
                tasks: [
                    task(1, {
                        start_date: '2026-10-05',
                        due_date: '2026-10-07',
                    }),
                ],
            }),
        );

        mocks.dragEnd?.({
            active: {
                id: 'grid:1',
                data: {
                    current: {
                        task: task(1, {
                            start_date: '2026-10-05',
                            due_date: '2026-10-07',
                        }),
                    },
                },
            },
            over: { id: 'day:2026-10-09:7' },
        });

        await waitFor(() =>
            expect(mocks.preview).toHaveBeenCalledWith(1, {
                start_date: '2026-10-07',
                due_date: '2026-10-09',
            }),
        );
        await waitFor(() => expect(mocks.save).toHaveBeenCalled());
    });

    it('sin permiso de edición no se mueve (ni arrastrando ni con el teclado)', async () => {
        const user = userEvent.setup();
        const readOnly = task(1, { project_id: 2, title: 'Ajena' });
        renderCalendar(data({ tasks: [readOnly] }));

        mocks.dragEnd?.({
            active: { id: 'grid:1', data: { current: { task: readOnly } } },
            over: { id: 'day:2026-10-09' },
        });
        const chip = screen.getByRole('button', { name: /^Ajena\./ });
        chip.focus();
        await user.keyboard('{ArrowRight}{Enter}');

        expect(mocks.preview).not.toHaveBeenCalled();
        expect(chip.getAttribute('aria-roledescription')).toBeNull();
    });

    it('con el teclado: flechas para elegir el día, Intro para mover y Escape para cancelar', async () => {
        const user = userEvent.setup();
        renderCalendar(data({ tasks: [task(1, { title: 'Portada' })] }));
        const chip = screen.getByRole('button', { name: /^Portada\./ });

        chip.focus();
        await user.keyboard('{ArrowRight}{ArrowRight}');
        expect(screen.getByTestId('team-chip-target').textContent).toContain(
            '09/10/2026',
        );
        await user.keyboard('{Escape}');
        expect(screen.queryByTestId('team-chip-target')).toBeNull();
        expect(mocks.preview).not.toHaveBeenCalled();

        await user.keyboard('{ArrowDown}{Enter}');
        await waitFor(() =>
            expect(mocks.preview).toHaveBeenCalledWith(1, {
                start_date: null,
                due_date: '2026-10-14',
            }),
        );
    });

    it('pulsar una tarea abre su panel y «+» crea una tarea ese día', async () => {
        const user = userEvent.setup();
        const { onOpen, onCreate } = renderCalendar(
            data({ tasks: [task(1, { title: 'Portada' })] }),
        );

        await user.click(screen.getByRole('button', { name: /^Portada\./ }));
        expect(onOpen).toHaveBeenCalledWith(1);

        await user.click(
            screen.getByRole('button', {
                name: 'Crear una tarea el 08/10/2026',
            }),
        );
        expect(onCreate).toHaveBeenCalledWith('2026-10-08');
    });

    it('cambia de vista, de día y a la vista por personas', async () => {
        const user = userEvent.setup();
        const { onNavigate } = renderCalendar(data());

        await user.click(screen.getByRole('radio', { name: 'Mes' }));
        expect(onNavigate).toHaveBeenLastCalledWith(
            'month',
            '2026-10-07',
            false,
        );
        await user.click(
            screen.getByRole('button', { name: 'Semana siguiente' }),
        );
        expect(onNavigate).toHaveBeenLastCalledWith(
            'week',
            '2026-10-12',
            false,
        );
        await user.click(screen.getByRole('switch', { name: 'Por personas' }));
        expect(onNavigate).toHaveBeenLastCalledWith('week', '2026-10-07', true);
    });
});

describe('CalendarFilterBar', () => {
    const filters: TeamCalendarFilters = {
        view: 'week',
        people: false,
        date: '2026-10-07',
        persons: [],
        department: null,
        projects: [],
        clients: [],
        done: false,
        priority: null,
        types: [],
        milestones: false,
        unassigned: false,
        mine: false,
        q: null,
    };
    const options = {
        people: [{ id: 7, name: 'Ana Ruiz', avatar: null, department_id: 1 }],
        departments: [{ id: 1, name: 'Diseño' }],
        projects: [
            {
                id: 1,
                code: 'HOTEL',
                name: 'Web Hoteles',
                color: '#179FA5',
                client_id: null,
            },
        ],
        clients: [],
        types: [],
    };

    it('activa «Solo hitos» y elige personas; «Limpiar filtros» conserva la vista', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        const { rerender } = render(
            <CalendarFilterBar
                filters={filters}
                options={options}
                onChange={onChange}
            />,
        );

        await user.click(screen.getByRole('switch', { name: 'Solo hitos' }));
        expect(onChange).toHaveBeenLastCalledWith({
            ...filters,
            milestones: true,
        });

        await user.click(
            screen.getByRole('combobox', { name: 'Persona: Todos' }),
        );
        await user.click(await screen.findByText('Ana Ruiz'));
        expect(onChange).toHaveBeenLastCalledWith({ ...filters, persons: [7] });

        rerender(
            <CalendarFilterBar
                filters={{
                    ...filters,
                    view: 'day',
                    people: true,
                    persons: [7],
                    mine: true,
                }}
                options={options}
                onChange={onChange}
            />,
        );
        await user.click(
            screen.getByRole('button', { name: 'Limpiar filtros' }),
        );
        expect(onChange).toHaveBeenLastCalledWith({
            ...filters,
            view: 'day',
            people: true,
        });
    });

    it('un colaborador no tiene filtro de departamento', () => {
        render(
            <CalendarFilterBar
                filters={filters}
                options={{ ...options, departments: [] }}
                onChange={vi.fn()}
            />,
        );

        expect(screen.queryByText('Departamento')).toBeNull();
    });
});
