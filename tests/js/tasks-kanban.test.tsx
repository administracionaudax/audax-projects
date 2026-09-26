// @vitest-environment jsdom
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    buildColumns,
    moveTask,
    neighbours,
} from '@/components/tasks/kanban-state';
import { TaskKanban } from '@/components/tasks/task-kanban';
import {
    buildTaskLookups,
    TaskLookupsProvider,
} from '@/components/tasks/task-lookups';
import type {
    Project,
    ProjectTasksPageProps,
    TaskListItem,
    TaskStatus,
} from '@/types';

type Options = {
    onError?: (errors: Record<string, string>) => void;
    onHttpException?: () => boolean | void;
};

const server = vi.hoisted(() => ({
    patch: vi.fn<(url: string, data: unknown, options: Options) => void>(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({
        url: '/proyectos/1/tareas?vista=kanban',
        props: { timer: null },
    }),
    router: {
        patch: (url: string, data: unknown, options: Options) =>
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

const project = {
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
} satisfies Project;

function task(
    id: number,
    statusId: number,
    position: number,
    title: string,
): TaskListItem {
    return {
        id,
        project_id: 1,
        hour_bank_id: null,
        parent_task_id: null,
        title,
        task_type_id: null,
        status_id: statusId,
        priority: 'normal',
        assignee: null,
        assignee_user_id: null,
        start_date: null,
        due_date: null,
        estimated_minutes: null,
        is_billable: true,
        is_milestone: false,
        is_completed: false,
        position,
        completed_at: null,
        logged_minutes: 0,
        subtasks: [],
        estimate_from_subtasks: false,
        effective_estimated_minutes: null,
    };
}

const tasks = [
    task(10, 1, 0, 'Diseñar la home'),
    task(11, 1, 1, 'Maquetar la home'),
    task(12, 2, 0, 'Revisar textos'),
];

const lookups = (canUpdate = true) =>
    buildTaskLookups({
        project,
        statuses,
        types: [],
        banks: [],
        users: [],
        currentUser: { id: 1, department_id: null },
        can: { create: false, update: canUpdate },
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

function renderBoard(canUpdate = true) {
    const onOpen = vi.fn();

    render(
        <TaskLookupsProvider value={lookups(canUpdate)}>
            <TaskKanban
                tasks={tasks}
                statuses={statuses}
                onOpen={onOpen}
                hiddenCompletedCount={0}
                onShowCompleted={vi.fn()}
            />
        </TaskLookupsProvider>,
    );

    return { onOpen };
}

function column(name: string) {
    return screen.getByRole('list', { name: `Tareas en «${name}»` });
}

function titles(name: string): string[] {
    return Array.from(
        column(name).querySelectorAll('[data-test="kanban-card-title"]'),
    ).map((element) => element.textContent ?? '');
}

/**
 * jsdom no maqueta: se da a columnas y tarjetas una geometría de tablero (columnas de 300 px y
 * tarjetas de 70 px) para que el sensor de teclado de dnd-kit sepa qué hay a cada lado.
 */
function mockBoardGeometry() {
    const rect = (
        x: number,
        y: number,
        width: number,
        height: number,
    ): DOMRect =>
        ({
            x,
            y,
            left: x,
            top: y,
            width,
            height,
            right: x + width,
            bottom: y + height,
            toJSON: () => ({}),
        }) as DOMRect;

    return vi
        .spyOn(Element.prototype, 'getBoundingClientRect')
        .mockImplementation(function (this: Element) {
            const element = this as HTMLElement;
            const columnIndex = (statusId: string | undefined) =>
                statuses.findIndex((status) => String(status.id) === statusId);

            if (element.dataset.kanbanList !== undefined) {
                return rect(
                    columnIndex(element.dataset.kanbanList) * 300,
                    50,
                    280,
                    600,
                );
            }

            const card =
                element.dataset.kanbanOverlay !== undefined
                    ? document.querySelector<HTMLElement>(
                          `[data-task-id="${element.dataset.kanbanOverlay}"]`,
                      )
                    : element.dataset.taskId !== undefined
                      ? element
                      : null;

            if (card) {
                return rect(
                    columnIndex(card.dataset.kanbanStatus) * 300 + 10,
                    60 + Number(card.dataset.kanbanIndex) * 70,
                    260,
                    60,
                );
            }

            return rect(0, 0, 0, 0);
        });
}

beforeEach(() => {
    server.patch.mockReset();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('estado del kanban', () => {
    it('agrupa por estado y ordena por posición', () => {
        expect(buildColumns(tasks, statuses)).toEqual({
            1: [10, 11],
            2: [12],
            3: [],
        });
    });

    it('mueve entre columnas y calcula las vecinas para el servidor', () => {
        const columns = moveTask(buildColumns(tasks, statuses), 11, 2, 0);

        expect(columns).toEqual({ 1: [10], 2: [11, 12], 3: [] });
        expect(neighbours(columns, 2, 11)).toEqual({
            before_id: 12,
            after_id: null,
        });
        expect(neighbours(columns, 2, 12)).toEqual({
            before_id: null,
            after_id: 11,
        });

        const empty = moveTask(columns, 10, 3, 5);
        expect(empty[3]).toEqual([10]);
        expect(neighbours(empty, 3, 10)).toEqual({
            before_id: null,
            after_id: null,
        });
    });
});

describe('kanban', () => {
    it('pinta una columna por estado con sus tarjetas y abre el panel desde el título', async () => {
        const user = userEvent.setup();
        const { onOpen } = renderBoard();

        expect(titles('Por hacer')).toEqual([
            'Diseñar la home',
            'Maquetar la home',
        ]);
        expect(titles('En curso')).toEqual(['Revisar textos']);
        expect(titles('Hecha')).toEqual([]);

        await user.click(
            screen.getByRole('button', { name: 'Maquetar la home' }),
        );
        expect(onOpen).toHaveBeenCalledWith(11);
    });

    it('mueve una tarea con el teclado desde el menú «Mover a…» y lo envía al servidor', async () => {
        const user = userEvent.setup();
        renderBoard();

        screen
            .getByRole('button', { name: 'Más opciones de «Maquetar la home»' })
            .focus();
        await user.keyboard('{Enter}');
        const item = await screen.findByRole('menuitem', { name: 'En curso' });
        item.focus();
        await user.keyboard('{Enter}');

        // Actualización optimista: la tarjeta ya está en su nueva columna.
        await waitFor(() =>
            expect(titles('En curso')).toEqual([
                'Revisar textos',
                'Maquetar la home',
            ]),
        );
        expect(titles('Por hacer')).toEqual(['Diseñar la home']);

        expect(server.patch).toHaveBeenCalledTimes(1);
        const [url, data] = server.patch.mock.calls[0];
        expect(url).toBe('/tareas/11/posicion');
        expect(data).toEqual({ status_id: 2, before_id: null, after_id: 12 });
    });

    it('sube una tarea dentro de su columna con el menú', async () => {
        const user = userEvent.setup();
        renderBoard();

        screen
            .getByRole('button', { name: 'Más opciones de «Maquetar la home»' })
            .focus();
        await user.keyboard('{Enter}');
        (await screen.findByRole('menuitem', { name: 'Subir' })).focus();
        await user.keyboard('{Enter}');

        await waitFor(() =>
            expect(titles('Por hacer')).toEqual([
                'Maquetar la home',
                'Diseñar la home',
            ]),
        );
        expect(server.patch.mock.calls[0][1]).toEqual({
            status_id: 1,
            before_id: 10,
            after_id: null,
        });
    });

    it('si el servidor rechaza el cambio, la tarjeta vuelve a su sitio', async () => {
        const user = userEvent.setup();
        server.patch.mockImplementation((_url, _data, options) => {
            options.onError?.({ status_id: 'No puedes mover esta tarea.' });
        });
        renderBoard();

        screen
            .getByRole('button', { name: 'Más opciones de «Diseñar la home»' })
            .focus();
        await user.keyboard('{Enter}');
        (await screen.findByRole('menuitem', { name: 'Hecha' })).focus();
        await user.keyboard('{Enter}');

        await waitFor(() => expect(server.patch).toHaveBeenCalledTimes(1));
        expect(titles('Por hacer')).toEqual([
            'Diseñar la home',
            'Maquetar la home',
        ]);
        expect(titles('Hecha')).toEqual([]);
    });

    it('también vuelve a su sitio si el servidor falla (error 500)', async () => {
        const user = userEvent.setup();
        server.patch.mockImplementation((_url, _data, options) => {
            options.onHttpException?.();
        });
        renderBoard();

        screen
            .getByRole('button', { name: 'Más opciones de «Revisar textos»' })
            .focus();
        await user.keyboard('{Enter}');
        (await screen.findByRole('menuitem', { name: 'Por hacer' })).focus();
        await user.keyboard('{Enter}');

        await waitFor(() => expect(server.patch).toHaveBeenCalledTimes(1));
        expect(titles('En curso')).toEqual(['Revisar textos']);
    });

    it('arrastra con el teclado: espacio para coger, flechas para mover y espacio para soltar', async () => {
        mockBoardGeometry();
        const user = userEvent.setup();
        renderBoard();

        const handle = screen.getByRole('button', {
            name: 'Mover «Diseñar la home»',
        });
        expect(handle.getAttribute('aria-roledescription')).toBe(
            'tarea que se puede mover',
        );
        expect(
            document.getElementById(
                handle.getAttribute('aria-describedby') ?? '',
            )?.textContent,
        ).toContain('pulsa espacio o Intro en su asa');

        handle.focus();
        await user.keyboard(' ');
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 20));
        });
        await user.keyboard('{ArrowRight}');
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 20));
        });
        await user.keyboard(' ');

        await waitFor(() => expect(server.patch).toHaveBeenCalledTimes(1));
        const [url, data] = server.patch.mock.calls[0];
        expect(url).toBe('/tareas/10/posicion');
        expect((data as { status_id: number }).status_id).toBe(2);
        expect(titles('En curso')).toContain('Diseñar la home');
        expect(titles('Por hacer')).toEqual(['Maquetar la home']);

        // Anuncio en español para lectores de pantalla.
        await waitFor(() =>
            expect(document.body.textContent).toContain(
                'Has soltado la tarea «Diseñar la home» en la columna «En curso»',
            ),
        );
    });

    it('Escape cancela el arrastre con el teclado sin tocar nada', async () => {
        mockBoardGeometry();
        const user = userEvent.setup();
        renderBoard();

        screen.getByRole('button', { name: 'Mover «Diseñar la home»' }).focus();
        await user.keyboard(' ');
        await act(async () => {
            await new Promise((resolve) => setTimeout(resolve, 20));
        });
        await user.keyboard('{ArrowRight}');
        await user.keyboard('{Escape}');

        await waitFor(() =>
            expect(titles('Por hacer')).toEqual([
                'Diseñar la home',
                'Maquetar la home',
            ]),
        );
        expect(server.patch).not.toHaveBeenCalled();
    });

    it('sin permiso de edición no se puede mover', () => {
        renderBoard(false);

        expect(
            screen.queryByRole('button', { name: 'Mover «Diseñar la home»' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', {
                name: 'Más opciones de «Diseñar la home»',
            }),
        ).toBeNull();
    });
});
