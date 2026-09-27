// @vitest-environment jsdom
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    buildTaskLookups,
    TaskLookupsProvider,
} from '@/components/tasks/task-lookups';
import { TaskPanel } from '@/components/tasks/task-panel';
import ProjectTasks from '@/pages/projects/tasks';
import type {
    Project,
    ProjectTasksPageProps,
    TaskListItem,
    TaskPanelData,
    TaskStatus,
} from '@/types';

type Options = {
    only?: string[];
    preserveState?: boolean;
    preserveScroll?: boolean;
};

const server = vi.hoisted(() => ({
    url: '/proyectos/1/tareas',
    visit: vi.fn<(url: string, options: Options) => void>(),
    get: vi.fn(),
    patch: vi.fn<(url: string, data: unknown, options: Options) => void>(),
    post: vi.fn<(url: string, data: unknown, options: Options) => void>(),
    delete: vi.fn<(url: string, options: Options) => void>(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ url: server.url, props: { timer: null } }),
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
    router: {
        visit: (url: string, options: Options) => server.visit(url, options),
        get: (...args: unknown[]) => server.get(...args),
        patch: (url: string, data: unknown, options: Options) =>
            server.patch(url, data, options),
        post: (url: string, data: unknown, options: Options) =>
            server.post(url, data, options),
        delete: (url: string, options: Options) => server.delete(url, options),
        reload: vi.fn(),
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
    client: { id: 3, name: 'ACME' },
    client_id: 3,
    billing_type: 'hour_bank',
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
    department_id: 2,
    is_active: true,
};

function listItem(
    id: number,
    title: string,
    extra: Partial<TaskListItem> = {},
): TaskListItem {
    return {
        id,
        project_id: 1,
        hour_bank_id: 5,
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
        position: 0,
        completed_at: null,
        logged_minutes: 0,
        subtasks: [],
        estimate_from_subtasks: false,
        effective_estimated_minutes: null,
        ...extra,
    };
}

function panelData(overrides: Partial<TaskPanelData> = {}): TaskPanelData {
    return {
        task: {
            ...listItem(10, 'Maquetar la home'),
            description:
                '<p>Hola <span data-type="mention" data-id="1" data-label="Ana García">@Ana García</span></p>',
            assignee: ana,
            assignee_user_id: 1,
            estimated_minutes: 120,
            logged_minutes: 90,
            creator: ana,
            created_at: '2026-09-20T08:00:00Z',
            updated_at: '2026-09-21T08:00:00Z',
        },
        project: {
            id: 1,
            code: 'ACME',
            name: 'Web ACME',
            uses_hour_banks: true,
            is_internal: false,
        },
        parent: null,
        subtasks: [],
        estimate_from_subtasks: false,
        effective_estimated_minutes: 120,
        watchers: [ana],
        is_watching: false,
        attachments: [],
        comments: [
            {
                id: 5,
                body: '<p>Revisado</p>',
                author: ana,
                created_at: '2026-09-21T09:00:00Z',
                edited_at: '2026-09-21T10:00:00Z',
                can_update: true,
                can_delete: true,
                reactions: [
                    {
                        emoji: '👍',
                        count: 2,
                        reacted: true,
                        users: ['Ana García', 'Berta'],
                    },
                ],
                attachments: [],
            },
        ],
        time_entries: [],
        time_visible_minutes: 90,
        has_time: true,
        activity: [
            {
                id: 1,
                event: 'updated',
                causer: 'Ana García',
                created_at: '2026-09-21T08:00:00Z',
                changes: [
                    { field: 'status_id', from: 'Por hacer', to: 'En curso' },
                ],
            },
        ],
        reaction_emojis: ['👍', '❤️', '🎉'],
        delete_blocked: 'has_time',
        can: {
            update: true,
            delete: false,
            comment: true,
            move: true,
            log_time: true,
        },
        ...overrides,
    };
}

const baseProps = {
    project,
    canManage: false,
    can: { create: true, update: true },
    view: 'list',
    filters: {
        assignee: null,
        bank: null,
        type: null,
        priority: null,
        status: null,
        mine: false,
        completed: false,
        group: 'status',
    },
    tasks: [
        listItem(10, 'Maquetar la home'),
        listItem(11, 'Diseñar el logo', { position: 1 }),
    ],
    hiddenCompletedCount: 0,
    statuses,
    types: [],
    banks: [
        {
            id: 5,
            name: 'Bolsa Q4',
            status: 'active',
            is_open: true,
            department_id: 2,
            department: null,
            consumed_pct: 30,
        },
        {
            id: 6,
            name: 'Bolsa 2027',
            status: 'active',
            is_open: true,
            department_id: null,
            department: null,
            consumed_pct: 0,
        },
    ],
    users: [{ ...ana, is_member: true }],
    currentUser: { id: 1, department_id: 2 },
    maxAttachmentMb: 50,
    panel: null,
} satisfies ProjectTasksPageProps;

/** Sin datos (`panel` sin pasar), el panel está cargando. */
function renderPanel(panel?: TaskPanelData) {
    const onOpen = vi.fn();
    const onClose = vi.fn();

    render(
        <TaskLookupsProvider value={buildTaskLookups(baseProps)}>
            <TaskPanel
                panel={panel ?? null}
                loading={panel === undefined}
                onOpen={onOpen}
                onClose={onClose}
            />
        </TaskLookupsProvider>,
    );

    return { onOpen, onClose };
}

beforeEach(() => {
    server.url = '/proyectos/1/tareas';
    server.visit.mockReset();
    server.patch.mockReset();
    server.post.mockReset();
    server.delete.mockReset();
});

describe('abrir y cerrar el panel', () => {
    it('al pulsar una tarea pide solo la prop «panel» con ?tarea= (recarga parcial)', async () => {
        const user = userEvent.setup();
        server.url = '/proyectos/1/tareas?vista=kanban';
        render(<ProjectTasks {...baseProps} view="kanban" />);

        await user.click(
            screen.getByRole('button', { name: 'Diseñar el logo' }),
        );

        expect(server.visit).toHaveBeenCalledTimes(1);
        const [url, options] = server.visit.mock.calls[0];
        expect(url).toBe('/proyectos/1/tareas?vista=kanban&tarea=11');
        expect(options).toMatchObject({
            only: ['panel'],
            preserveState: true,
            preserveScroll: true,
        });

        // Mientras llega la tarea, el panel muestra un esqueleto de carga.
        expect(await screen.findByRole('dialog')).toBeTruthy();
        expect(
            screen.getAllByRole('status', { name: 'Cargando la tarea…' })
                .length,
        ).toBeGreaterThan(0);
    });

    it('cerrar el panel quita ?tarea= de la URL', async () => {
        const user = userEvent.setup();
        server.url = '/proyectos/1/tareas?tarea=10&agrupar=tipo';
        render(<ProjectTasks {...baseProps} panel={panelData()} />);

        await user.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Cerrar',
            }),
        );

        const [url, options] = server.visit.mock.calls.at(-1) ?? [];
        expect(url).toBe('/proyectos/1/tareas?agrupar=tipo');
        expect(options).toMatchObject({ only: ['panel'] });

        // Se cierra al momento, sin esperar a la respuesta del servidor.
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    });
});

describe('panel de la tarea', () => {
    it('muestra los datos, la descripción saneada, los comentarios y la actividad', async () => {
        const user = userEvent.setup();
        renderPanel(panelData());

        const dialog = screen.getByRole('dialog', { name: 'Maquetar la home' });
        expect(
            within(dialog).getByRole('textbox', { name: 'Título de la tarea' }),
        ).toHaveProperty('value', 'Maquetar la home');
        expect(dialog.querySelector('[data-type="mention"]')?.textContent).toBe(
            '@Ana García',
        );
        expect(within(dialog).getByText('Revisado')).toBeTruthy();
        expect(within(dialog).getByText('(editado)')).toBeTruthy();
        expect(
            within(dialog).getByText(
                'Ves 1:30 de 1:30 imputadas a esta tarea.',
            ),
        ).toBeTruthy();

        await user.click(
            within(dialog).getByRole('button', { name: 'Actividad (1)' }),
        );
        expect(
            within(dialog).getByText('Estado: Por hacer → En curso'),
        ).toBeTruthy();
    });

    it('guarda el título al pulsar Intro', async () => {
        const user = userEvent.setup();
        renderPanel(panelData());

        const input = screen.getByRole('textbox', {
            name: 'Título de la tarea',
        });
        await user.clear(input);
        await user.type(input, 'Maquetar la home y el blog{Enter}');

        expect(server.patch).toHaveBeenCalledTimes(1);
        const [url, data, options] = server.patch.mock.calls[0];
        expect(url).toBe('/tareas/10');
        expect(data).toEqual({ title: 'Maquetar la home y el blog' });
        expect(options.only).toEqual([
            'tasks',
            'panel',
            'hiddenCompletedCount',
            'calendar',
        ]);
    });

    it('seguir la tarea solo recarga el panel', async () => {
        const user = userEvent.setup();
        renderPanel(panelData());

        await user.click(screen.getByRole('button', { name: 'Seguir' }));

        const [url, data, options] = server.post.mock.calls[0];
        expect(url).toBe('/tareas/10/seguir');
        expect(data).toEqual({});
        expect(options.only).toEqual(['panel']);
    });

    it('explica por qué no se puede borrar una tarea con horas', () => {
        renderPanel(panelData());

        expect(
            screen.getByText(
                'No se puede eliminar: tiene horas imputadas. Complétala en su lugar.',
            ),
        ).toBeTruthy();
    });

    it('avisa antes de cambiar la bolsa de una tarea con horas', async () => {
        const user = userEvent.setup();
        renderPanel(panelData());

        expect(
            screen.getByText('Las horas ya imputadas conservan su bolsa.'),
        ).toBeTruthy();
        expect(
            screen.getByText(
                'Esta tarea ya tiene horas: si cambias la bolsa, esas horas se quedan en la bolsa donde se imputaron.',
            ),
        ).toBeTruthy();
        // El selector de bolsa muestra la actual.
        expect(
            screen.getByRole('combobox', { name: 'Bolsa' }).textContent,
        ).toContain('Bolsa Q4');
        expect(server.patch).not.toHaveBeenCalled();
        await user.keyboard('{Escape}');
    });

    it('la estimación de una tarea con subtareas estimadas es de solo lectura', () => {
        renderPanel(
            panelData({
                estimate_from_subtasks: true,
                effective_estimated_minutes: 270,
                subtasks: [
                    listItem(20, 'Cabecera', {
                        parent_task_id: 10,
                        estimated_minutes: 270,
                    }),
                ],
            }),
        );

        expect(screen.getByText('4:30')).toBeTruthy();
        expect(
            screen.getByText(
                'Es la suma de las estimaciones de sus subtareas: cámbiala en ellas.',
            ),
        ).toBeTruthy();
        expect(
            screen.queryByRole('textbox', { name: 'Estimación' }),
        ).toBeNull();
    });

    it('completa una subtarea con su casilla', async () => {
        const user = userEvent.setup();
        renderPanel(
            panelData({
                subtasks: [listItem(20, 'Cabecera', { parent_task_id: 10 })],
            }),
        );

        await user.click(
            screen.getByRole('checkbox', { name: 'Completar «Cabecera»' }),
        );

        expect(server.patch.mock.calls[0][0]).toBe('/tareas/20');
        expect(server.patch.mock.calls[0][1]).toEqual({ status_id: 2 });
    });

    it('quita la propia reacción de un comentario', async () => {
        const user = userEvent.setup();
        renderPanel(panelData());

        const reaction = screen.getByRole('button', {
            name: '👍 2 (Ana García, Berta). Quitar tu reacción',
        });
        expect(reaction.getAttribute('aria-pressed')).toBe('true');
        await user.click(reaction);

        const [url, data, options] = server.post.mock.calls[0];
        expect(url).toBe('/comentarios/5/reacciones');
        expect(data).toEqual({ emoji: '👍' });
        expect(options.only).toEqual(['panel']);
    });

    it('sin permiso de edición todo se ve, pero no se puede cambiar', () => {
        renderPanel(
            panelData({
                can: {
                    update: false,
                    delete: false,
                    comment: true,
                    move: false,
                    log_time: false,
                },
            }),
        );

        expect(
            screen.queryByRole('textbox', { name: 'Título de la tarea' }),
        ).toBeNull();
        expect(
            screen.getByRole('dialog', { name: 'Maquetar la home' }),
        ).toBeTruthy();
        expect(
            screen
                .getByRole('combobox', { name: 'Estado' })
                .hasAttribute('disabled'),
        ).toBe(true);
        expect(
            screen.queryByRole('button', { name: 'Editar la descripción' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Añadir horas' }),
        ).toBeNull();
        // Comentar sí puede cualquier interno.
        expect(
            screen.getByRole('button', { name: 'Escribe un comentario…' }),
        ).toBeTruthy();
    });

    it('en una subtarea enlaza a su tarea padre y no ofrece moverla', async () => {
        const user = userEvent.setup();
        const { onOpen } = renderPanel(
            panelData({
                parent: { id: 3, title: 'Web nueva' },
                can: {
                    update: true,
                    delete: true,
                    comment: true,
                    move: false,
                    log_time: true,
                },
            }),
        );

        await user.click(
            screen.getByRole('button', { name: 'Subtarea de «Web nueva»' }),
        );
        expect(onOpen).toHaveBeenCalledWith(3);
        expect(
            screen.getByText('Las subtareas usan la bolsa de su tarea padre.'),
        ).toBeTruthy();
    });

    it('mientras carga muestra un esqueleto accesible', () => {
        renderPanel();

        expect(
            screen.getAllByRole('status', { name: 'Cargando la tarea…' })
                .length,
        ).toBeGreaterThan(0);
    });

    it('el título vuelve a su valor con Escape sin guardar ni cerrar el panel', async () => {
        const user = userEvent.setup();
        const { onClose } = renderPanel(panelData());

        const input = screen.getByRole('textbox', {
            name: 'Título de la tarea',
        });
        await user.type(input, ' cambiado');
        await user.keyboard('{Escape}');

        await waitFor(() =>
            expect((input as HTMLInputElement).value).toBe('Maquetar la home'),
        );
        expect(server.patch).not.toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();

        // Sin cambios pendientes, Escape cierra el panel.
        await user.keyboard('{Escape}');
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('al abrirse, el foco va al panel y no al título', async () => {
        renderPanel(panelData());

        const dialog = await screen.findByRole('dialog');
        await waitFor(() => expect(document.activeElement).toBe(dialog));
    });
});
