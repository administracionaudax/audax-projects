// @vitest-environment jsdom
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TaskCalendar } from '@/components/planning/task-calendar';
import {
    buildTaskLookups,
    TaskLookupsProvider,
} from '@/components/tasks/task-lookups';
import type { Project, ProjectTasksPageProps, TaskStatus } from '@/types';
import type { CalendarTask, TaskCalendarData } from '@/types/planning';
import type { ShiftProposal } from '@/types/schedule';

type DragEnd = (event: {
    active: { id: string; data: { current: { task: CalendarTask } } };
    over: { id: string } | null;
}) => void;

const mocks = vi.hoisted(() => ({
    preview:
        vi.fn<
            (
                taskId: number,
                dates: { start_date: string | null; due_date: string },
            ) => Promise<ShiftProposal[]>
        >(),
    save: vi.fn<
        (
            taskId: number,
            body: {
                start_date: string | null;
                due_date: string;
                shift_successors?: boolean;
            },
            options: { onFinish?: () => void },
        ) => void
    >(),
    dragEnd: null as DragEnd | null,
}));

vi.mock('@/components/planning/reschedule-requests', () => ({
    RescheduleError: class extends Error {},
    fetchReschedulePreview: (
        taskId: number,
        dates: { start_date: string | null; due_date: string },
    ) => mocks.preview(taskId, dates),
    saveReschedule: (
        taskId: number,
        body: {
            start_date: string | null;
            due_date: string;
            shift_successors?: boolean;
        },
        options: { onFinish?: () => void },
    ) => mocks.save(taskId, body, options),
}));

// El arrastre real necesita medidas del navegador: se captura el manejador de DndContext y se
// llama como lo haría dnd-kit al soltar sobre un día.
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
    {
        id: 3,
        name: 'Hecha',
        color: '#179FA5',
        category: 'done',
        position: 2,
        is_default: false,
    },
];

const project: Project = {
    id: 1,
    code: 'ACME',
    name: 'Web ACME',
    color: '#0171FF',
    description: null,
    client: { id: 3, name: 'ACME' },
    client_id: 3,
    billing_type: 'time_and_materials',
    status: 'active',
    start_date: null,
    due_date: null,
    budget_minutes: null,
    owner_user_id: 1,
    is_internal: false,
};

function lookups(canUpdate = true) {
    return buildTaskLookups({
        project,
        statuses,
        types: [],
        banks: [],
        users: [],
        currentUser: { id: 1, department_id: null },
        can: { create: canUpdate, update: canUpdate },
        maxAttachmentMb: 50,
    } satisfies Pick<
        ProjectTasksPageProps,
        | 'project'
        | 'statuses'
        | 'types'
        | 'banks'
        | 'users'
        | 'currentUser'
        | 'can'
        | 'maxAttachmentMb'
    >);
}

function calendarTask(
    id: number,
    title: string,
    start: string | null,
    due: string | null,
    extra: Partial<CalendarTask> = {},
): CalendarTask {
    return {
        id,
        title,
        parent_task_id: null,
        parent_title: null,
        status_id: 1,
        priority: 'normal',
        assignee: null,
        start_date: start,
        due_date: due,
        is_milestone: false,
        is_completed: false,
        ...extra,
    };
}

function data(overrides: Partial<TaskCalendarData> = {}): TaskCalendarData {
    return {
        mode: 'month',
        period: '2026-10',
        from: '2026-09-28',
        to: '2026-11-01',
        today: '2026-10-13',
        tasks: [
            calendarTask(10, 'Diseño', '2026-10-05', '2026-10-09', {
                assignee: {
                    id: 5,
                    name: 'Ana García',
                    avatar: null,
                    department_id: null,
                    is_active: true,
                },
            }),
            calendarTask(11, 'Entrega', null, '2026-10-14', {
                is_milestone: true,
            }),
            calendarTask(12, 'Revisión', null, '2026-10-06', {
                status_id: 3,
                is_completed: true,
            }),
        ],
        undated: [calendarTask(20, 'Sin fecha', null, null)],
        undated_total: 1,
        ...overrides,
    };
}

function renderCalendar(calendar: TaskCalendarData = data(), canUpdate = true) {
    const onOpen = vi.fn();
    const onNavigate = vi.fn();

    render(
        <TaskLookupsProvider value={lookups(canUpdate)}>
            <TaskCalendar
                calendar={calendar}
                onOpen={onOpen}
                onNavigate={onNavigate}
            />
        </TaskLookupsProvider>,
    );

    return { onOpen, onNavigate };
}

function chip(title: string): HTMLElement {
    return screen.getByRole('button', { name: new RegExp(`^${title}\\.`) });
}

beforeEach(() => {
    mocks.preview.mockReset();
    mocks.save.mockReset();
    mocks.dragEnd = null;
});

describe('rejilla del mes', () => {
    it('empieza en lunes, pinta cada tarea el día de su entrega y los hitos con su rombo', () => {
        renderCalendar();

        const table = screen.getByRole('table', {
            name: 'Calendario de tareas: octubre de 2026',
        });
        const headers = within(table).getAllByRole('columnheader');
        expect(headers).toHaveLength(7);
        expect(headers[0].textContent).toMatch(/^lun/);
        expect(headers[6].textContent).toMatch(/^dom/);

        const ninth = table.querySelector('[data-date="2026-10-09"]');
        expect(ninth?.textContent).toContain('Diseño');
        const fourteenth = table.querySelector('[data-date="2026-10-14"]');
        expect(fourteenth?.textContent).toContain('Entrega');

        // Nombre accesible con estado, responsable y fechas; el hito lo dice con texto.
        expect(chip('Diseño').getAttribute('aria-label')).toBe(
            'Diseño. Por hacer. Responsable: Ana García. Del 05/10/2026 al 09/10/2026. Vencida',
        );
        expect(chip('Entrega').getAttribute('aria-label')).toContain('Hito');
        expect(chip('Revisión').getAttribute('aria-label')).toContain('Hecha');
        // Leyenda de estados con icono y texto.
        const legend = screen.getByRole('list', { name: 'Leyenda de estados' });
        expect(legend.textContent).toContain('Por hacer');
        expect(legend.textContent).toContain('Hito');
    });

    it('si no caben, «+N más» abre las tareas de ese día', async () => {
        const user = userEvent.setup();
        const many = Array.from({ length: 5 }, (_, index) =>
            calendarTask(30 + index, `Tarea ${index + 1}`, null, '2026-10-20'),
        );
        const { onOpen } = renderCalendar(data({ tasks: many }));

        const day = document.querySelector('[data-date="2026-10-20"]');
        expect(
            day?.querySelectorAll('[data-test="calendar-chip"]'),
        ).toHaveLength(3);

        await user.click(
            screen.getByRole('button', {
                name: '+2 más: ver las tareas del 20/10/2026',
            }),
        );
        const dialog = await screen.findByRole('dialog');
        await user.click(
            within(dialog).getByRole('button', { name: /^Tarea 5\./ }),
        );

        expect(onOpen).toHaveBeenCalledWith(34);
    });

    it('pulsar una tarea abre su panel; las flechas de navegación cambian de mes', async () => {
        const user = userEvent.setup();
        const { onOpen, onNavigate } = renderCalendar();

        await user.click(chip('Entrega'));
        expect(onOpen).toHaveBeenCalledWith(11);

        await user.click(screen.getByRole('button', { name: 'Mes siguiente' }));
        expect(onNavigate).toHaveBeenLastCalledWith('month', '2026-11');
        await user.click(screen.getByRole('button', { name: 'Mes anterior' }));
        expect(onNavigate).toHaveBeenLastCalledWith('month', '2026-09');
        await user.click(screen.getByRole('button', { name: 'Hoy' }));
        expect(onNavigate).toHaveBeenLastCalledWith('month', '2026-10');
        await user.click(screen.getByRole('radio', { name: 'Semana' }));
        expect(onNavigate).toHaveBeenLastCalledWith('week', '2026-10-12');
    });

    it('sin tareas en el periodo lo dice', () => {
        renderCalendar(data({ tasks: [] }));

        expect(
            screen.getByText('No hay tareas con entrega en octubre de 2026.'),
        ).toBeTruthy();
    });
});

describe('mover con el teclado', () => {
    it('las flechas eligen el día nuevo y Enter pide la propuesta conservando la duración', async () => {
        const user = userEvent.setup();
        mocks.preview.mockResolvedValue([]);
        renderCalendar();

        chip('Diseño').focus();
        // +7 −7 +7 −1 −1: del 09/10 al 14/10 (cinco días más tarde).
        await user.keyboard(
            '{ArrowDown}{ArrowUp}{ArrowDown}{ArrowLeft}{ArrowLeft}',
        );

        expect(
            document.querySelector('[data-test="calendar-live"]')?.textContent,
        ).toBe(
            'Mover «Diseño» al 14/10/2026. Pulsa Enter para confirmar o Escape para cancelar.',
        );
        expect(
            document.querySelector('[data-test="calendar-chip-target"]')
                ?.textContent,
        ).toBe('14/10/2026');
        expect(mocks.preview).not.toHaveBeenCalled();

        await user.keyboard('{Enter}');

        expect(mocks.preview).toHaveBeenCalledWith(10, {
            start_date: '2026-10-10',
            due_date: '2026-10-14',
        });
        await waitFor(() =>
            expect(mocks.save).toHaveBeenCalledWith(
                10,
                {
                    start_date: '2026-10-10',
                    due_date: '2026-10-14',
                    shift_successors: false,
                },
                expect.anything(),
            ),
        );
        // El foco sigue a la tarea hasta su día nuevo.
        await waitFor(() =>
            expect(
                document.activeElement
                    ?.closest('[data-date]')
                    ?.getAttribute('data-date'),
            ).toBe('2026-10-14'),
        );
    });

    it('Escape cancela y, sin día elegido, Enter abre la tarea', async () => {
        const user = userEvent.setup();
        const { onOpen } = renderCalendar();

        chip('Entrega').focus();
        await user.keyboard('{ArrowLeft}{Escape}');
        expect(
            document.querySelector('[data-test="calendar-chip-target"]'),
        ).toBeNull();
        expect(
            document.querySelector('[data-test="calendar-live"]')?.textContent,
        ).toBe('Se cancela el cambio de día de «Entrega».');

        await user.keyboard('{Enter}');
        expect(onOpen).toHaveBeenCalledWith(11);
        expect(mocks.preview).not.toHaveBeenCalled();
    });

    it('en solo lectura las flechas no mueven nada', async () => {
        const user = userEvent.setup();
        renderCalendar(data(), false);

        expect(
            screen.getByText(
                'Solo lectura: pulsa una tarea para ver su detalle.',
            ),
        ).toBeTruthy();
        chip('Diseño').focus();
        await user.keyboard('{ArrowRight}{Enter}');

        expect(mocks.preview).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: /^Asignar fecha/ }),
        ).toBeNull();
    });
});

describe('arrastrar a otro día', () => {
    it('al soltar pide la propuesta con las fechas desplazadas (el hito, sin inicio)', async () => {
        mocks.preview.mockResolvedValue([]);
        const calendar = data();
        renderCalendar(calendar);

        await act(async () => {
            mocks.dragEnd?.({
                active: {
                    id: 'grid:10',
                    data: { current: { task: calendar.tasks[0] } },
                },
                over: { id: 'day:2026-10-20' },
            });
        });
        expect(mocks.preview).toHaveBeenLastCalledWith(10, {
            start_date: '2026-10-16',
            due_date: '2026-10-20',
        });

        await act(async () => {
            mocks.dragEnd?.({
                active: {
                    id: 'grid:11',
                    data: { current: { task: calendar.tasks[1] } },
                },
                over: { id: 'day:2026-10-02' },
            });
        });
        expect(mocks.preview).toHaveBeenLastCalledWith(11, {
            start_date: null,
            due_date: '2026-10-02',
        });
    });

    it('soltar fuera del calendario o en el mismo día no hace nada', async () => {
        const calendar = data();
        renderCalendar(calendar);

        await act(async () => {
            mocks.dragEnd?.({
                active: {
                    id: 'grid:10',
                    data: { current: { task: calendar.tasks[0] } },
                },
                over: null,
            });
            mocks.dragEnd?.({
                active: {
                    id: 'grid:10',
                    data: { current: { task: calendar.tasks[0] } },
                },
                over: { id: 'day:2026-10-09' },
            });
        });

        expect(mocks.preview).not.toHaveBeenCalled();
    });

    it('una tarea sin fecha se lleva a un día (entrega ese día)', async () => {
        mocks.preview.mockResolvedValue([]);
        const calendar = data();
        renderCalendar(calendar);

        await act(async () => {
            mocks.dragEnd?.({
                active: {
                    id: 'undated:20',
                    data: { current: { task: calendar.undated[0] } },
                },
                over: { id: 'day:2026-10-22' },
            });
        });

        expect(mocks.preview).toHaveBeenCalledWith(20, {
            start_date: null,
            due_date: '2026-10-22',
        });
    });
});

describe('conflictos con las sucesoras (D-057)', () => {
    const proposals: ShiftProposal[] = [
        {
            task_id: 40,
            title: 'Maquetación',
            start_date: '2026-10-12',
            due_date: '2026-10-16',
            new_start_date: '2026-10-21',
            new_due_date: '2026-10-25',
            shift_days: 9,
            predecessor_id: 10,
        },
    ];

    const moveDesign = async () => {
        const user = userEvent.setup();
        const calendar = data();
        renderCalendar(calendar);

        await act(async () => {
            mocks.dragEnd?.({
                active: {
                    id: 'grid:10',
                    data: { current: { task: calendar.tasks[0] } },
                },
                over: { id: 'day:2026-10-20' },
            });
        });

        return { user, dialog: await screen.findByRole('dialog') };
    };

    it('enseña las sucesoras y «Mover también las sucesoras» guarda con shift_successors', async () => {
        mocks.preview.mockResolvedValue(proposals);
        const { user, dialog } = await moveDesign();

        expect(dialog.textContent).toContain('Hay tareas que dependen de esta');
        expect(dialog.textContent).toContain(
            'Si mueves «Diseño» del 16/10/2026 al 20/10/2026',
        );
        expect(dialog.textContent).toContain('Maquetación');
        expect(dialog.textContent).toContain('Del 21/10/2026 al 25/10/2026');
        expect(dialog.textContent).toContain('+9 días');
        expect(mocks.save).not.toHaveBeenCalled();

        await user.click(
            within(dialog).getByRole('button', {
                name: 'Mover también las sucesoras',
            }),
        );

        expect(mocks.save).toHaveBeenCalledWith(
            10,
            {
                start_date: '2026-10-16',
                due_date: '2026-10-20',
                shift_successors: true,
            },
            expect.anything(),
        );
    });

    it('«Solo esta tarea» guarda sin desplazar las sucesoras', async () => {
        mocks.preview.mockResolvedValue(proposals);
        const { user, dialog } = await moveDesign();

        await user.click(
            within(dialog).getByRole('button', { name: 'Solo esta tarea' }),
        );

        expect(mocks.save).toHaveBeenCalledWith(
            10,
            {
                start_date: '2026-10-16',
                due_date: '2026-10-20',
                shift_successors: false,
            },
            expect.anything(),
        );
    });

    it('«Cancelar» no guarda nada y la tarea vuelve a su día', async () => {
        mocks.preview.mockResolvedValue(proposals);
        const { user, dialog } = await moveDesign();

        // Mientras se decide, la tarea se ve en su día nuevo.
        expect(
            document.querySelector('[data-date="2026-10-20"]')?.textContent,
        ).toContain('Diseño');

        await user.click(
            within(dialog).getByRole('button', { name: 'Cancelar' }),
        );

        expect(mocks.save).not.toHaveBeenCalled();
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(
            document.querySelector('[data-date="2026-10-09"]')?.textContent,
        ).toContain('Diseño');
        // Y el foco vuelve a la tarea, en su día de siempre.
        await waitFor(() =>
            expect(document.activeElement?.getAttribute('data-task-id')).toBe(
                '10',
            ),
        );
    });
});

describe('vista semana y tareas sin fecha', () => {
    it('la semana enseña las franjas del inicio a la entrega y el estado con texto', () => {
        renderCalendar(
            data({
                mode: 'week',
                period: '2026-10-05',
                from: '2026-10-05',
                to: '2026-10-11',
            }),
        );

        const table = screen.getByRole('table', {
            name: /^Calendario de tareas: 5 oct/,
        });
        const span = within(table).getByRole('button', {
            name: '«Diseño», del 05/10/2026 al 09/10/2026',
        });
        expect(span.closest('td')?.getAttribute('colspan')).toBe('5');
        expect(
            table.querySelector('[data-date="2026-10-06"]')?.textContent,
        ).toContain('Hecha');
    });

    it('la lista «Sin fecha» enseña el total y permite asignar fecha', () => {
        renderCalendar(data({ undated_total: 140 }));

        const list = screen.getByRole('region', { name: /^Sin fecha/ });
        expect(list.textContent).toContain('(140)');
        expect(list.textContent).toContain(
            'Se enseñan las 1 más recientes de 140.',
        );
        expect(
            within(list).getByRole('button', {
                name: 'Asignar fecha a «Sin fecha»',
            }),
        ).toBeTruthy();
    });
});
