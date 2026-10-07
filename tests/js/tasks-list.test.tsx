// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TaskBulkBar } from '@/components/tasks/task-bulk-bar';
import { groupTasks } from '@/components/tasks/task-groups';
import { TaskList } from '@/components/tasks/task-list';
import {
    buildTaskLookups,
    TaskLookupsProvider,
} from '@/components/tasks/task-lookups';
import type { Project, TaskListItem, TaskStatus } from '@/types';

const server = vi.hoisted(() => ({
    patch: vi.fn<(url: string, data: unknown, options: unknown) => void>(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({ url: '/proyectos/1/tareas', props: { timer: null } }),
    router: {
        patch: (url: string, data: unknown, options: unknown) =>
            server.patch(url, data, options),
        post: vi.fn(),
        visit: vi.fn(),
    },
}));

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
        name: 'Hecha',
        color: '#179FA5',
        category: 'done',
        position: 1,
        is_default: false,
    },
];

const project: Project = {
    id: 1,
    code: 'ACME',
    name: 'Web ACME',
    color: '#0171FF',
    description: null,
    client_id: null,
    billing_type: 'time_and_materials',
    status: 'active',
    start_date: null,
    due_date: null,
    budget_minutes: null,
    owner_user_id: 1,
    is_internal: false,
};

const ana = {
    id: 1,
    name: 'Ana García',
    avatar: null,
    department_id: null,
    is_active: true,
};

function task(
    id: number,
    title: string,
    extra: Partial<TaskListItem> = {},
): TaskListItem {
    return {
        id,
        project_id: 1,
        hour_bank_id: null,
        parent_task_id: null,
        title,
        task_type_id: null,
        status_id: 1,
        priority: 'normal',
        assignee: null,
        assignee_user_id: null,
        start_date: null,
        due_date: null,
        estimated_minutes: null,
        is_billable: true,
        is_milestone: false,
        is_completed: false,
        position: id,
        completed_at: null,
        logged_minutes: 30,
        subtasks: [],
        estimate_from_subtasks: false,
        effective_estimated_minutes: null,
        ...extra,
    };
}

const tasks = [
    task(10, 'Diseñar la home', {
        assignee: ana,
        assignee_user_id: 1,
        subtasks: [
            task(20, 'Cabecera', {
                parent_task_id: 10,
                logged_minutes: 45,
                estimated_minutes: 60,
            }),
            task(21, 'Pie', {
                parent_task_id: 10,
                is_completed: true,
                status_id: 2,
                logged_minutes: 0,
            }),
        ],
        estimate_from_subtasks: true,
        effective_estimated_minutes: 60,
    }),
    task(11, 'Maquetar la home'),
];

const lookups = buildTaskLookups({
    project,
    statuses,
    types: [],
    banks: [],
    users: [{ ...ana, is_member: true }],
    currentUser: { id: 1, department_id: null },
    can: { create: true, update: true },
    maxAttachmentMb: 50,
});

function ListWithBulk() {
    const [selection, setSelection] = useState<Set<number>>(new Set());

    return (
        <TaskLookupsProvider value={lookups}>
            <TaskList
                tasks={tasks}
                groupBy="status"
                showCompleted={false}
                selection={selection}
                onSelect={(ids, checked) =>
                    setSelection((current) => {
                        const next = new Set(current);
                        ids.forEach((id) =>
                            checked ? next.add(id) : next.delete(id),
                        );

                        return next;
                    })
                }
                onOpen={vi.fn()}
            />
            {selection.size > 0 ? (
                <TaskBulkBar
                    selection={selection}
                    onClear={() => setSelection(new Set())}
                />
            ) : null}
        </TaskLookupsProvider>
    );
}

beforeEach(() => {
    server.patch.mockReset();
});

describe('agrupar la lista', () => {
    it('por estado: todas las columnas abiertas (las «done» solo si se muestran las completadas)', () => {
        expect(
            groupTasks(tasks, 'status', lookups, false).map(
                (group) => group.label,
            ),
        ).toEqual(['Por hacer']);
        expect(
            groupTasks(tasks, 'status', lookups, true).map(
                (group) => group.label,
            ),
        ).toEqual(['Por hacer', 'Hecha']);
        expect(groupTasks(tasks, 'status', lookups, false)[0].defaults).toEqual(
            { status_id: 1 },
        );
    });

    it('por responsable: un grupo por persona y «Sin responsable» al final', () => {
        const groups = groupTasks(tasks, 'assignee', lookups, false);

        expect(
            groups.map((group) => [
                group.label,
                group.tasks.map((item) => item.id),
            ]),
        ).toEqual([
            ['Ana García', [10]],
            ['Sin responsable', [11]],
        ]);
        expect(groups[0].defaults).toEqual({ assignee_user_id: 1 });
    });
});

describe('lista de tareas', () => {
    it('muestra las subtareas bajo su tarea, oculta las completadas y suma las horas de las subtareas', async () => {
        const user = userEvent.setup();
        render(<ListWithBulk />);

        const rows = screen.getAllByRole('row').slice(1);
        expect(
            rows.map((row) => within(row).getByRole('rowheader').textContent),
        ).toEqual([
            expect.stringContaining('Diseñar la home'),
            expect.stringContaining('Cabecera'),
            expect.stringContaining('Maquetar la home'),
        ]);
        expect(screen.getByText('(1 completadas ocultas)')).toBeTruthy();
        // 0:30 de la tarea + 0:45 de sus subtareas.
        expect(within(rows[0]).getByText('1:15')).toBeTruthy();

        await user.click(
            screen.getByRole('button', {
                name: 'Ocultar las subtareas de «Diseñar la home»',
            }),
        );
        expect(screen.queryByText('Cabecera')).toBeNull();
    });

    it('sin las horas de todos (null, colaborador externo) no hay columna «Imputadas» (D-134)', () => {
        const hidden = (item: TaskListItem): TaskListItem => ({
            ...item,
            logged_minutes: null,
            subtasks: (item.subtasks ?? []).map(hidden),
        });

        render(
            <TaskLookupsProvider value={lookups}>
                <TaskList
                    tasks={tasks.map(hidden)}
                    groupBy="status"
                    showCompleted={false}
                    selection={new Set()}
                    onSelect={vi.fn()}
                    onOpen={vi.fn()}
                />
            </TaskLookupsProvider>,
        );

        expect(
            screen.queryByRole('columnheader', { name: 'Imputadas' }),
        ).toBeNull();
        expect(screen.queryByText('0:30')).toBeNull();
        expect(screen.queryByText('1:15')).toBeNull();
        expect(screen.queryByText('0:00')).toBeNull();
        // Cada fila tiene tantas celdas como columnas la cabecera.
        const [header, ...rows] = screen.getAllByRole('row');
        const columns = within(header).getAllByRole('columnheader').length;
        for (const row of rows) {
            expect(
                within(row).queryAllByRole('cell').length +
                    within(row).queryAllByRole('rowheader').length,
            ).toBe(columns);
        }
    });

    it('las acciones masivas cambian solo las tareas seleccionadas', async () => {
        const user = userEvent.setup();
        render(<ListWithBulk />);

        await user.click(
            screen.getByRole('checkbox', {
                name: 'Seleccionar «Diseñar la home»',
            }),
        );
        await user.click(
            screen.getByRole('checkbox', {
                name: 'Seleccionar «Maquetar la home»',
            }),
        );

        const bar = screen.getByRole('region', {
            name: 'Tareas seleccionadas: 2',
        });
        await user.click(
            within(bar).getByRole('button', { name: 'Cambiar fechas' }),
        );
        await user.click(
            await screen.findByRole('button', { name: 'Quitar las fechas' }),
        );

        expect(server.patch).toHaveBeenCalledTimes(1);
        const [url, data] = server.patch.mock.calls[0];
        expect(url).toBe('/proyectos/1/tareas/masivo');
        expect(data).toEqual({
            ids: [10, 11],
            start_date: null,
            due_date: null,
        });
    });

    it('seleccionar el grupo marca también las subtareas visibles', async () => {
        const user = userEvent.setup();
        render(<ListWithBulk />);

        await user.click(
            screen.getByRole('checkbox', {
                name: 'Seleccionar todas las tareas de «Por hacer»',
            }),
        );

        expect(
            screen.getByRole('region', { name: 'Tareas seleccionadas: 3' }),
        ).toBeTruthy();
    });
});

describe('grupos plegables y filas clicables (D-320, D-324)', () => {
    const completed = [
        ...tasks,
        task(30, 'Publicar', { status_id: 2, is_completed: true }),
    ];

    function FoldableList({
        onOpen = vi.fn(),
        storageKey = 'audax.tasks.groups.1.1',
    }: {
        onOpen?: (taskId: number) => void;
        storageKey?: string | null;
    }) {
        return (
            <TaskLookupsProvider value={lookups}>
                <TaskList
                    tasks={completed}
                    groupBy="status"
                    showCompleted
                    selection={new Set()}
                    onSelect={vi.fn()}
                    onOpen={onOpen}
                    storageKey={storageKey}
                />
            </TaskLookupsProvider>
        );
    }

    beforeEach(() => window.localStorage.clear());

    it('el estado «done» nace plegado y los abiertos desplegados, con su número', () => {
        render(<FoldableList />);

        const todo = screen.getByRole('button', { name: /^Por hacer/ });
        const done = screen.getByRole('button', { name: /^Hecha/ });
        expect(todo.getAttribute('aria-expanded')).toBe('true');
        expect(done.getAttribute('aria-expanded')).toBe('false');
        expect(done.textContent).toContain('(1)');
        expect(
            document.getElementById(done.getAttribute('aria-controls') ?? '')
                ?.hidden,
        ).toBe(true);
        expect(screen.queryByText('Publicar')).toBeNull();
    });

    it('se despliega y pliega con el teclado y se recuerda al volver', async () => {
        const user = userEvent.setup();
        const { unmount } = render(<FoldableList />);

        screen.getByRole('button', { name: /^Hecha/ }).focus();
        await user.keyboard('{Enter}');
        expect(screen.getByText('Publicar')).toBeTruthy();
        await user.click(screen.getByRole('button', { name: /^Por hacer/ }));
        expect(screen.queryByText('Maquetar la home')).toBeNull();
        expect(
            JSON.parse(
                window.localStorage.getItem('audax.tasks.groups.1.1') ?? '{}',
            ),
        ).toEqual({ 'status-2': false, 'status-1': true });
        unmount();

        render(<FoldableList />);
        expect(
            screen
                .getByRole('button', { name: /Por hacer/ })
                .getAttribute('aria-expanded'),
        ).toBe('false');
        expect(screen.getByText('Publicar')).toBeTruthy();
    });

    it('«Plegar todo» y «Desplegar todo»', async () => {
        const user = userEvent.setup();
        render(<FoldableList />);

        await user.click(screen.getByRole('button', { name: 'Plegar todo' }));
        const toggles = () =>
            [
                ...document.querySelectorAll('[data-test="task-group-toggle"]'),
            ].map((toggle) => toggle.getAttribute('aria-expanded'));
        expect(toggles()).toEqual(['false', 'false']);
        await user.click(
            screen.getByRole('button', { name: 'Desplegar todo' }),
        );
        expect(toggles()).toEqual(['true', 'true']);
    });

    it('sin almacenamiento (bloqueado) funciona igual, solo que no recuerda', async () => {
        const getItem = vi
            .spyOn(Storage.prototype, 'getItem')
            .mockImplementation(() => {
                throw new Error('bloqueado');
            });
        const setItem = vi
            .spyOn(Storage.prototype, 'setItem')
            .mockImplementation(() => {
                throw new Error('bloqueado');
            });
        const user = userEvent.setup();
        render(<FoldableList />);

        await user.click(screen.getByRole('button', { name: /^Hecha/ }));
        expect(screen.getByText('Publicar')).toBeTruthy();
        getItem.mockRestore();
        setItem.mockRestore();
    });

    it('un clic en cualquier punto de la fila abre la tarea; la casilla y el temporizador no', async () => {
        const onOpen = vi.fn();
        const user = userEvent.setup();
        render(<FoldableList onOpen={onOpen} />);

        const row = screen
            .getAllByRole('row')
            .find((item) => item.textContent?.includes('Maquetar la home'));
        expect(row).toBeTruthy();

        await user.click(within(row as HTMLElement).getAllByRole('cell')[2]);
        expect(onOpen).toHaveBeenLastCalledWith(11);
        const calls = onOpen.mock.calls.length;

        await user.click(within(row as HTMLElement).getByRole('checkbox'));
        expect(onOpen).toHaveBeenCalledTimes(calls);
    });
});
