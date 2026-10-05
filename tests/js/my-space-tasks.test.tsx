// @vitest-environment jsdom
import {
    act,
    configure,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// La app marca los elementos con data-test.
configure({ testIdAttribute: 'data-test' });

const page = vi.hoisted(() => ({
    url: '/mi-espacio?pestana=tareas',
    props: {
        auth: {
            user: { id: 7, name: 'Ana Díaz', roles: ['employee'] },
            can: { useWeeklies: true },
        },
        config: { max_audio_seconds: 60, modules: { weeklies: true } },
        realtime: null,
    } as Record<string, unknown>,
}));

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
        usePage: () => page,
        router: { ...inertia, get: vi.fn(), on: () => () => {} },
        Link: ({
            href,
            children,
            preserveScroll: _preserveScroll,
            ...rest
        }: {
            href: string | { url: string };
            children?: ReactNode;
            [key: string]: unknown;
        }) => (
            <a href={typeof href === 'string' ? href : href.url} {...rest}>
                {children}
            </a>
        ),
    };
});

import { AI_POLL_MS } from '@/components/weeklies/insights/ai-summary-panel';
import { MySpaceTasks } from '@/components/weeklies/tasks/my-space-tasks';
import {
    NOTES_AUTOSAVE_MS,
    TaskNotesField,
} from '@/components/weeklies/tasks/task-notes-field';
import { TaskSuggestionsPanel } from '@/components/weeklies/tasks/task-suggestions-panel';
import { uploadDictation } from '@/components/weeklies/weekly-api';
import {
    catalogClients,
    defaultBank,
    draftProblems,
    filterTasks,
    groupByClient,
    projectsOfClient,
    suggestionDraft,
} from '@/lib/my-space-tasks';
import type {
    MySpaceTask,
    MySpaceTaskProject,
    TaskSuggestionBatch,
} from '@/types/weeklies';

function task(overrides: Partial<MySpaceTask> = {}): MySpaceTask {
    return {
        id: 5,
        title: 'Maquetar la home',
        priority: 'normal',
        due_date: null,
        created_at: '2026-10-01T08:00:00Z',
        completed: false,
        status: { id: 1, name: 'Por hacer', category: 'todo' },
        project: { id: 3, code: 'ACME-WE1', name: 'Web' },
        client: { id: 9, name: 'Acme', icon: '🍷' },
        assigner: null,
        notes: '',
        notes_editable: true,
        archived: false,
        can: { update: true, delete: true },
        ...overrides,
    };
}

const projects: MySpaceTaskProject[] = [
    {
        id: 3,
        code: 'ACME-WE1',
        name: 'Web',
        client: { id: 9, name: 'Acme', icon: '🍷' },
        uses_banks: false,
        banks: [],
    },
    {
        id: 4,
        code: 'ACME-BH1',
        name: 'Bolsa',
        client: { id: 9, name: 'Acme', icon: '🍷' },
        uses_banks: true,
        banks: [
            { id: 40, name: 'Bolsa 20h', department_id: 2 },
            { id: 41, name: 'Bolsa 10h', department_id: null },
        ],
    },
    {
        id: 5,
        code: 'BETA-WE1',
        name: 'Web Beta',
        client: { id: 10, name: 'Beta', icon: null },
        uses_banks: false,
        banks: [],
    },
    {
        id: 6,
        code: 'INT-1',
        name: 'Interno',
        client: null,
        uses_banks: false,
        banks: [],
    },
];

function batch(
    overrides: Partial<TaskSuggestionBatch> = {},
): TaskSuggestionBatch {
    return {
        state: 'done',
        stuck: false,
        cycle: {
            id: 2,
            number: 'W40-26',
            label: 'Semana 40 (Lun 28/09 - Vie 02/10)',
        },
        items: [
            {
                key: 'a',
                title: 'Revisar el banner',
                client_id: 10,
                client_name: 'Beta',
                project_id: 5,
                hour_bank_id: null,
                author_id: 8,
                author_name: 'Raúl Gestor',
            },
            {
                key: 'b',
                title: 'Preparar la reunión',
                client_id: 9,
                client_name: 'Acme',
                project_id: null,
                hour_bank_id: null,
                author_id: null,
                author_name: null,
            },
        ],
        skipped: 1,
        error: null,
        generated_at: '2026-10-07T08:00:00Z',
        ...overrides,
    };
}

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
    Object.values(inertia).forEach((mock) => mock.mockReset());
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('piezas puras de las tareas de Mi espacio', () => {
    const done = task({
        id: 6,
        completed: true,
        status: { id: 3, name: 'Hecha', category: 'done' },
    });
    const hidden = task({ id: 7, archived: true });
    const general = task({
        id: 8,
        client: null,
        project: { id: 6, code: 'INT-1', name: 'Interno' },
    });

    it('filtra por Todas, Pendientes o Completadas y por archivadas (F-056 y F-057)', () => {
        const all = [task(), done, hidden, general];

        expect(filterTasks(all, 'all', false).map((t) => t.id)).toEqual([
            5, 6, 8,
        ]);
        expect(filterTasks(all, 'todo', false).map((t) => t.id)).toEqual([
            5, 8,
        ]);
        expect(filterTasks(all, 'done', false).map((t) => t.id)).toEqual([6]);
        expect(filterTasks(all, 'all', true).map((t) => t.id)).toEqual([7]);
    });

    it('agrupa por cliente en el orden de llegada, con las generales aparte (F-055)', () => {
        const groups = groupByClient([
            general,
            task(),
            task({ id: 9, client: { id: 10, name: 'Beta', icon: null } }),
            done,
        ]);

        expect(groups.map((g) => [g.key, g.tasks.map((t) => t.id)])).toEqual([
            ['general', [8]],
            ['client-9', [5, 6]],
            ['client-10', [9]],
        ]);
    });

    it('clientes y proyectos del catálogo, y la bolsa por defecto', () => {
        expect(catalogClients(projects).map((c) => c.id)).toEqual([
            9,
            10,
            null,
        ]);
        expect(projectsOfClient(projects, 9).map((p) => p.id)).toEqual([3, 4]);
        expect(projectsOfClient(projects, null).map((p) => p.id)).toEqual([6]);
        expect(defaultBank(projects[1])).toBe(40);
        expect(defaultBank(projects[0])).toBeNull();
    });

    it('el borrador de una propuesta respeta la sugerencia si sigue siendo válida', () => {
        const draft = suggestionDraft(batch().items[0], projects);

        expect(draft).toMatchObject({
            key: 'a',
            selected: true,
            projectId: 5,
            bankId: null,
            priority: 'normal',
        });
        expect(draftProblems(draft, projects)).toEqual([]);

        const missing = suggestionDraft(batch().items[1], projects);
        expect(missing.projectId).toBeNull();
        expect(draftProblems({ ...missing, title: ' ' }, projects)).toEqual([
            'title',
            'project',
        ]);

        const bank = suggestionDraft(
            { ...batch().items[1], project_id: 4, hour_bank_id: 999 },
            projects,
        );
        expect(bank.bankId).toBe(40);
        expect(draftProblems({ ...bank, bankId: null }, projects)).toEqual([
            'bank',
        ]);
    });
});

describe('MySpaceTasks (pestaña Tareas)', () => {
    const statuses = { open: 1, done: 3 };

    it('agrupa por cliente, filtra y enseña las archivadas aparte', async () => {
        const user = userEvent.setup();
        render(
            <MySpaceTasks
                tasks={[
                    task(),
                    task({
                        id: 6,
                        title: 'Cerrar el presupuesto',
                        completed: true,
                        assigner: { id: 8, name: 'Raúl Gestor' },
                    }),
                    task({ id: 7, title: 'Tarea archivada', archived: true }),
                    task({ id: 8, title: 'Ordenar el Drive', client: null }),
                ]}
                projects={projects}
                statuses={statuses}
                suggestions={null}
                source={{ id: 2, number: 'W40-26', label: 'Semana 40' }}
            />,
        );

        expect(screen.getByRole('heading', { name: /Acme/ })).toBeTruthy();
        expect(
            screen.getByRole('heading', { name: /Tareas generales/ }),
        ).toBeTruthy();
        expect(screen.getByText('De: Raúl Gestor')).toBeTruthy();
        expect(screen.queryByText('Tarea archivada')).toBeNull();
        expect(
            screen.getByText('Fuente: Semana 40 (reportes enviados)'),
        ).toBeTruthy();

        await user.click(screen.getByTestId('my-space-tasks-filter-done'));
        expect(screen.queryByText('Maquetar la home')).toBeNull();
        expect(screen.getByText('Cerrar el presupuesto')).toBeTruthy();

        await user.click(screen.getByTestId('my-space-tasks-filter-all'));
        await user.click(screen.getByTestId('my-space-tasks-archived'));
        expect(screen.getByText('Tarea archivada')).toBeTruthy();
        expect(screen.queryByText('Maquetar la home')).toBeNull();
        expect(screen.queryByTestId('my-space-tasks-generate')).toBeNull();
    });

    it('marca hecha con un clic, archiva y recupera con las rutas de siempre', async () => {
        const user = userEvent.setup();
        const { rerender } = render(
            <MySpaceTasks
                tasks={[task()]}
                projects={projects}
                statuses={statuses}
                suggestions={null}
                source={null}
            />,
        );

        await user.click(
            screen.getByRole('checkbox', {
                name: 'Marcar «Maquetar la home» como hecha',
            }),
        );
        expect(inertia.patch).toHaveBeenCalledWith(
            '/tareas/5',
            { status_id: 3 },
            expect.objectContaining({
                only: ['my_tasks'],
                preserveScroll: true,
            }),
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Archivar «Maquetar la home» en mi lista',
            }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/mi-espacio/tareas/5/archivar',
            {},
            expect.objectContaining({ only: ['my_tasks'] }),
        );

        rerender(
            <MySpaceTasks
                tasks={[task({ archived: true })]}
                projects={projects}
                statuses={statuses}
                suggestions={null}
                source={null}
            />,
        );
        await user.click(screen.getByTestId('my-space-tasks-archived'));
        await user.click(
            screen.getByRole('button', {
                name: 'Recuperar «Maquetar la home»',
            }),
        );
        expect(inertia.delete).toHaveBeenCalledWith(
            '/mi-espacio/tareas/5/archivar',
            expect.objectContaining({ only: ['my_tasks'] }),
        );
    });

    it('sin weekly cerrada no se puede generar; con ella, pide las sugerencias', async () => {
        const user = userEvent.setup();
        const { rerender } = render(
            <MySpaceTasks
                tasks={[]}
                projects={projects}
                statuses={statuses}
                suggestions={null}
                source={null}
            />,
        );

        expect(
            (screen.getByTestId('my-space-tasks-generate') as HTMLButtonElement)
                .disabled,
        ).toBe(true);
        expect(screen.getByText('Todo limpio por ahora')).toBeTruthy();

        rerender(
            <MySpaceTasks
                tasks={[]}
                projects={projects}
                statuses={statuses}
                suggestions={null}
                source={{ id: 2, number: 'W40-26', label: 'Semana 40' }}
            />,
        );
        await user.click(screen.getByTestId('my-space-tasks-generate'));
        expect(inertia.post).toHaveBeenCalledWith(
            '/mi-espacio/tareas/sugeridas',
            {},
            expect.objectContaining({ only: ['suggestions', 'my_tasks'] }),
        );
    });

    it('sin permiso en el proyecto, la tarea no se edita ni se borra', () => {
        render(
            <MySpaceTasks
                tasks={[task({ can: { update: false, delete: false } })]}
                projects={projects}
                statuses={statuses}
                suggestions={null}
                source={null}
            />,
        );

        expect(
            (screen.getByRole('checkbox') as HTMLButtonElement).disabled,
        ).toBe(true);
        expect(
            screen.queryByRole('button', { name: 'Editar «Maquetar la home»' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', {
                name: 'Eliminar «Maquetar la home»',
            }),
        ).toBeNull();
    });
});

describe('TaskSuggestionsPanel (F-062: revisar antes de crear)', () => {
    it('mientras se genera, recarga la tanda cada 3 s', () => {
        vi.useFakeTimers();
        render(
            <TaskSuggestionsPanel
                batch={batch({ state: 'running', items: [] })}
                projects={projects}
            />,
        );

        expect(screen.getByText('Buscando tareas en la weekly…')).toBeTruthy();
        act(() => {
            vi.advanceTimersByTime(AI_POLL_MS);
        });
        expect(inertia.reload).toHaveBeenCalledWith({
            only: ['suggestions', 'my_tasks'],
        });
    });

    it('no crea nada si falta el proyecto; con todo revisado, crea solo las marcadas', async () => {
        const user = userEvent.setup();
        render(<TaskSuggestionsPanel batch={batch()} projects={projects} />);

        expect(
            screen.getByText(/Son propuestas de la IA y pueden tener errores/),
        ).toBeTruthy();
        expect(
            screen.getByText(/Las que ya tenías se han omitido \(1\)/),
        ).toBeTruthy();
        expect(screen.getByText('Del reporte de Raúl Gestor')).toBeTruthy();

        await user.click(screen.getByTestId('task-suggestions-create'));
        expect(screen.getByText('Elige el proyecto.')).toBeTruthy();
        expect(inertia.post).not.toHaveBeenCalled();

        // La segunda no se crea: se desmarca. La primera, con el título retocado.
        await user.click(
            screen.getByRole('checkbox', {
                name: 'Crear «Preparar la reunión»',
            }),
        );
        const title = within(
            screen.getByTestId('task-suggestion-a'),
        ).getByTestId('task-suggestion-title');
        await user.clear(title);
        await user.type(title, 'Revisar el banner de Beta');
        await user.click(screen.getByTestId('task-suggestions-create'));

        expect(inertia.post).toHaveBeenCalledWith(
            '/mi-espacio/tareas/sugeridas/crear',
            {
                tasks: [
                    {
                        key: 'a',
                        title: 'Revisar el banner de Beta',
                        project_id: 5,
                        hour_bank_id: null,
                        priority: 'normal',
                        due_date: null,
                    },
                ],
            },
            expect.objectContaining({ only: ['suggestions', 'my_tasks'] }),
        );
    });

    it('enseña los errores del servidor en su propuesta', async () => {
        const user = userEvent.setup();
        inertia.post.mockImplementation(
            (
                _url: string,
                _data: unknown,
                options: { onError?: (errors: Record<string, string>) => void },
            ) => {
                options.onError?.({
                    'tasks.0.project_id':
                        'No puedes crear tareas en ese proyecto.',
                });
            },
        );
        render(
            <TaskSuggestionsPanel
                batch={batch({ items: [batch().items[0]] })}
                projects={projects}
            />,
        );

        await user.click(screen.getByTestId('task-suggestions-create'));

        expect(
            within(screen.getByTestId('task-suggestion-a')).getByText(
                'No puedes crear tareas en ese proyecto.',
            ),
        ).toBeTruthy();
    });

    it('descarta una propuesta o todas', async () => {
        const user = userEvent.setup();
        render(<TaskSuggestionsPanel batch={batch()} projects={projects} />);

        await user.click(
            screen.getByRole('button', {
                name: 'Descartar «Revisar el banner»',
            }),
        );
        expect(inertia.delete).toHaveBeenCalledWith(
            '/mi-espacio/tareas/sugeridas',
            expect.objectContaining({ data: { keys: ['a'] } }),
        );

        await user.click(screen.getByTestId('task-suggestions-dismiss-all'));
        expect(inertia.delete).toHaveBeenLastCalledWith(
            '/mi-espacio/tareas/sugeridas',
            expect.objectContaining({ data: {} }),
        );
    });

    it('sin tareas nuevas o con un error, lo dice', () => {
        const { rerender } = render(
            <TaskSuggestionsPanel
                batch={batch({ items: [], skipped: 2 })}
                projects={projects}
            />,
        );
        expect(
            screen.getByText(
                /Todas las tareas que ha encontrado la IA ya las tienes/,
            ),
        ).toBeTruthy();

        rerender(
            <TaskSuggestionsPanel
                batch={batch({
                    items: [],
                    state: 'failed',
                    error: 'La IA no responde.',
                })}
                projects={projects}
            />,
        );
        expect(screen.getByRole('alert').textContent).toContain(
            'La IA no responde.',
        );
    });
});

describe('TaskNotesField (F-060: notas con autoguardado y dictado)', () => {
    it('guarda la nota al dejar de escribir, sin recargar', async () => {
        vi.useFakeTimers();
        fetchMock.mockResolvedValue(
            new Response(
                JSON.stringify({ task: { id: 5, notes: 'Llamar a Marta' } }),
                {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                },
            ),
        );
        render(<TaskNotesField task={task()} />);

        fireEvent.change(screen.getByLabelText('Notas de «Maquetar la home»'), {
            target: { value: 'Llamar a Marta' },
        });
        expect(fetchMock).not.toHaveBeenCalled();

        await act(async () => {
            vi.advanceTimersByTime(NOTES_AUTOSAVE_MS);
        });

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/mi-espacio/tareas/5/notas');
        expect(init?.method).toBe('PUT');
        expect(JSON.parse(String(init?.body))).toEqual({
            notes: 'Llamar a Marta',
        });
        expect(screen.getByTestId('task-notes-state').textContent).toBe(
            'Guardado',
        );
    });

    it('si falla, avisa y deja reintentar', async () => {
        vi.useFakeTimers();
        fetchMock.mockResolvedValue(new Response('{}', { status: 500 }));
        render(<TaskNotesField task={task()} />);

        fireEvent.change(screen.getByLabelText('Notas de «Maquetar la home»'), {
            target: { value: 'x' },
        });
        await act(async () => {
            vi.advanceTimersByTime(NOTES_AUTOSAVE_MS);
        });

        expect(screen.getByTestId('task-notes-state').textContent).toContain(
            'No se ha podido guardar la nota.',
        );
        expect(screen.getByRole('button', { name: 'Reintentar' })).toBeTruthy();
    });

    it('una descripción con formato se enseña y se edita en la tarea', () => {
        render(
            <TaskNotesField
                task={task({ notes: 'Con negrita', notes_editable: false })}
            />,
        );

        expect(screen.queryByRole('textbox')).toBeNull();
        expect(screen.getByText('Con negrita')).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: 'Edítala en la tarea' })
                .getAttribute('href'),
        ).toBe('/proyectos/3/tareas?tarea=5');
    });

    it('el botón de dictar dice de qué tarea es y el audio va como nota de esa tarea', async () => {
        class FakeRecorder extends EventTarget {
            static isTypeSupported(type: string): boolean {
                return type.startsWith('audio/webm');
            }
        }
        for (const target of [window, globalThis]) {
            Object.defineProperty(target, 'MediaRecorder', {
                configurable: true,
                writable: true,
                value: FakeRecorder,
            });
        }
        Object.defineProperty(navigator, 'mediaDevices', {
            configurable: true,
            value: { getUserMedia: vi.fn() },
        });
        render(<TaskNotesField task={task()} />);

        expect(
            screen.getByRole('button', {
                name: 'Dictar una nota de «Maquetar la home»',
            }),
        ).toBeTruthy();

        fetchMock.mockResolvedValue(
            new Response(
                JSON.stringify({
                    dictation: {
                        id: 1,
                        context: 'task_note',
                        status: 'pending',
                        client_id: null,
                        task_id: 5,
                        text: null,
                        warning: null,
                        created_at: null,
                    },
                }),
                {
                    status: 201,
                    headers: { 'Content-Type': 'application/json' },
                },
            ),
        );
        await uploadDictation({
            taskId: 5,
            file: new File(['x'], 'nota.webm', { type: 'audio/webm' }),
            durationMs: 2000,
        });

        const body = fetchMock.mock.calls[0][1]?.body as FormData;
        expect(body.get('context')).toBe('task_note');
        expect(body.get('task_id')).toBe('5');
        expect(body.get('weekly_cycle_id')).toBeNull();
    });
});
