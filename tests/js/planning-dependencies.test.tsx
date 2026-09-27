// @vitest-environment jsdom
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TaskDependencies } from '@/components/planning/task-dependencies';
import type { TaskPanelData } from '@/types';
import type { DependencyCandidate, LinkedTask } from '@/types/planning';

type Options = {
    only?: string[];
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
};

const server = vi.hoisted(() => ({
    post: vi.fn<(url: string, data: unknown, options: Options) => void>(),
    delete: vi.fn<(url: string, options: Options) => void>(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: {
        post: (url: string, data: unknown, options: Options) =>
            server.post(url, data, options),
        delete: (url: string, options: Options) => server.delete(url, options),
        visit: vi.fn(),
    },
}));

function linked(
    dependencyId: number,
    id: number,
    title: string,
    extra: Partial<LinkedTask['task']> = {},
    conflict = false,
): LinkedTask {
    return {
        dependency_id: dependencyId,
        conflict,
        task: {
            id,
            title,
            parent_task_id: null,
            status_id: 1,
            start_date: null,
            due_date: null,
            is_milestone: false,
            is_completed: false,
            ...extra,
        },
    };
}

function panel(
    overrides: Partial<TaskPanelData> = {},
    canUpdate = true,
): TaskPanelData {
    return {
        task: {
            id: 10,
            project_id: 1,
            hour_bank_id: null,
            parent_task_id: null,
            title: 'Maquetación',
            description: null,
            task_type_id: null,
            status_id: 1,
            priority: 'normal',
            assignee: null,
            assignee_user_id: null,
            start_date: '2026-10-09',
            due_date: '2026-10-20',
            estimated_minutes: null,
            is_billable: true,
            is_milestone: false,
            is_completed: false,
            position: 0,
            completed_at: null,
            creator: null,
            created_at: null,
            updated_at: null,
        },
        project: {
            id: 1,
            code: 'ACME',
            name: 'Web ACME',
            uses_hour_banks: false,
            is_internal: false,
        },
        parent: null,
        subtasks: [],
        estimate_from_subtasks: false,
        effective_estimated_minutes: null,
        watchers: [],
        is_watching: false,
        attachments: [],
        comments: [],
        time_entries: [],
        time_visible_minutes: 0,
        has_time: false,
        activity: [],
        dependencies: {
            predecessors: [
                linked(
                    1,
                    20,
                    'Diseño',
                    { start_date: '2026-10-01', due_date: '2026-10-09' },
                    true,
                ),
            ],
            successors: [
                linked(2, 30, 'Publicación', {
                    due_date: '2026-10-27',
                    is_milestone: true,
                }),
            ],
        },
        reaction_emojis: [],
        delete_blocked: null,
        can: {
            update: canUpdate,
            delete: canUpdate,
            comment: true,
            move: canUpdate,
            log_time: false,
        },
        ...overrides,
    };
}

const candidates: DependencyCandidate[] = [
    {
        id: 20,
        title: 'Diseño',
        parent_title: null,
        start_date: null,
        due_date: '2026-10-09',
        is_milestone: false,
        is_completed: false,
    },
    {
        id: 40,
        title: 'Textos',
        parent_title: 'Contenidos',
        start_date: null,
        due_date: '2026-10-12',
        is_milestone: false,
        is_completed: false,
    },
    {
        id: 50,
        title: 'Briefing',
        parent_title: null,
        start_date: null,
        due_date: null,
        is_milestone: false,
        is_completed: true,
    },
];

const fetchMock = vi.fn<(url: string) => Promise<Response>>();

beforeEach(() => {
    server.post.mockReset();
    server.delete.mockReset();
    fetchMock.mockReset();
    fetchMock.mockImplementation(async () =>
        Response.json({ tasks: candidates }),
    );
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('sección «Dependencias» del panel', () => {
    it('enseña de qué tareas depende y a cuáles bloquea, con el conflicto en icono y texto', () => {
        render(<TaskDependencies panel={panel()} onOpen={vi.fn()} />);

        const predecessors = screen.getByRole('group', { name: 'Depende de' });
        const successors = screen.getByRole('group', { name: 'Bloquea a' });

        expect(predecessors.textContent).toContain('Diseño');
        expect(predecessors.textContent).toContain(
            'Del 01/10/2026 al 09/10/2026',
        );
        expect(
            predecessors.querySelector('[data-test="dependency-conflict"]')
                ?.textContent,
        ).toContain('Conflicto de fechas');
        expect(predecessors.textContent).toContain(
            'esta tarea empieza antes de que acabe «Diseño»',
        );
        expect(successors.textContent).toContain('Publicación');
        expect(successors.textContent).toContain('(Hito)');
        expect(successors.textContent).toContain('Vence el 27/10/2026');
        expect(
            successors.querySelector('[data-test="dependency-conflict"]'),
        ).toBeNull();
    });

    it('sin dependencias lo dice en cada lista', () => {
        render(
            <TaskDependencies
                panel={panel({
                    dependencies: { predecessors: [], successors: [] },
                })}
                onOpen={vi.fn()}
            />,
        );

        expect(screen.getByText('No depende de ninguna tarea.')).toBeTruthy();
        expect(screen.getByText('No bloquea a ninguna tarea.')).toBeTruthy();
    });

    it('pulsar una tarea enlazada abre su panel', async () => {
        const user = userEvent.setup();
        const onOpen = vi.fn();
        render(<TaskDependencies panel={panel()} onOpen={onOpen} />);

        await user.click(screen.getByRole('button', { name: /^Publicación/ }));

        expect(onOpen).toHaveBeenCalledWith(30);
    });

    it('«Quitar» borra la dependencia y recarga solo el panel', async () => {
        const user = userEvent.setup();
        render(<TaskDependencies panel={panel()} onOpen={vi.fn()} />);

        await user.click(
            screen.getByRole('button', {
                name: 'Quitar la dependencia con «Diseño»',
            }),
        );

        expect(server.delete).toHaveBeenCalledTimes(1);
        const [url, options] = server.delete.mock.calls[0];
        expect(url).toBe('/dependencias/1');
        expect(options.only).toEqual(['panel']);
    });

    it('«Añadir» en «Depende de» busca tareas del proyecto (sin las ya enlazadas) y crea la dependencia', async () => {
        const user = userEvent.setup();
        render(<TaskDependencies panel={panel()} onOpen={vi.fn()} />);

        await user.click(
            screen.getByRole('button', {
                name: 'Añadir una tarea de la que depende',
            }),
        );

        const option = await screen.findByRole('option', { name: /Textos/ });
        expect(fetchMock.mock.calls[0][0]).toBe(
            '/tareas/10/dependencias/candidatas',
        );
        // «Diseño» ya es predecesora: no se ofrece otra vez.
        expect(screen.queryByRole('option', { name: /^Diseño/ })).toBeNull();
        expect(option.textContent).toContain('En «Contenidos»');
        expect(
            screen.getByRole('option', { name: /Briefing/ }).textContent,
        ).toContain('Completada');

        await user.type(screen.getByRole('combobox'), 'tex');
        await waitFor(() =>
            expect(fetchMock.mock.calls.at(-1)?.[0]).toBe(
                '/tareas/10/dependencias/candidatas?buscar=tex',
            ),
        );

        await user.click(await screen.findByRole('option', { name: /Textos/ }));

        expect(server.post).toHaveBeenCalledTimes(1);
        const [url, data, options] = server.post.mock.calls[0];
        expect(url).toBe('/proyectos/1/dependencias');
        expect(data).toEqual({
            predecessor_task_id: 40,
            successor_task_id: 10,
        });
        expect(options.only).toEqual(['panel']);
    });

    it('«Añadir» en «Bloquea a» enlaza en el otro sentido y enseña junto al botón el error del servidor (ciclo)', async () => {
        const user = userEvent.setup();
        server.post.mockImplementation((_url, _data, options) =>
            options.onError?.({
                successor_task_id:
                    'Esa dependencia crearía un ciclo: la tarea ya depende, directa o indirectamente, de la otra.',
            }),
        );
        render(<TaskDependencies panel={panel()} onOpen={vi.fn()} />);

        await user.click(
            screen.getByRole('button', {
                name: 'Añadir una tarea a la que bloquea',
            }),
        );
        await user.click(await screen.findByRole('option', { name: /Diseño/ }));

        const [, data] = server.post.mock.calls[0];
        expect(data).toEqual({
            predecessor_task_id: 10,
            successor_task_id: 20,
        });

        const successors = screen.getByRole('group', { name: 'Bloquea a' });
        expect(within(successors).getByRole('alert').textContent).toContain(
            'crearía un ciclo',
        );
        // El error es de esa lista, no de la otra.
        expect(
            within(
                screen.getByRole('group', { name: 'Depende de' }),
            ).queryByRole('alert'),
        ).toBeNull();
    });

    it('si falla la búsqueda lo dice', async () => {
        const user = userEvent.setup();
        fetchMock.mockImplementation(
            async () => new Response('', { status: 500 }),
        );
        render(<TaskDependencies panel={panel()} onOpen={vi.fn()} />);

        await user.click(
            screen.getByRole('button', {
                name: 'Añadir una tarea de la que depende',
            }),
        );

        expect(
            await screen.findByText(
                'No se han podido cargar las tareas. Inténtalo de nuevo.',
            ),
        ).toBeTruthy();
    });

    it('en solo lectura no se añade ni se quita', () => {
        render(<TaskDependencies panel={panel({}, false)} onOpen={vi.fn()} />);

        expect(screen.queryByRole('button', { name: /^Añadir/ })).toBeNull();
        expect(screen.queryByRole('button', { name: /^Quitar/ })).toBeNull();
        expect(
            screen.getByText(
                'Solo quien puede editar la tarea cambia sus dependencias.',
            ),
        ).toBeTruthy();
    });

    it('sin datos de dependencias (panel antiguo) no pinta nada', () => {
        const { container } = render(
            <TaskDependencies
                panel={panel({ dependencies: undefined })}
                onOpen={vi.fn()}
            />,
        );

        expect(container.textContent).toBe('');
    });
});
