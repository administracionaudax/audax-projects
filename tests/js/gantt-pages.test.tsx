// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type {
    GanttIndexPageProps,
    GanttProject,
    GanttTask,
    ProjectGanttPageProps,
} from '@/components/gantt/types';
import GanttIndex from '@/pages/gantt/index';
import ProjectGantt from '@/pages/projects/gantt';
import type { Project } from '@/types';

vi.setConfig({ testTimeout: 20_000 });

// Radix Select usa la captura del puntero, que jsdom no trae.
for (const method of [
    'hasPointerCapture',
    'releasePointerCapture',
    'setPointerCapture',
] as const) {
    if (!(method in Element.prototype)) {
        Object.defineProperty(Element.prototype, method, {
            configurable: true,
            value: () => false,
        });
    }
}

type VisitOptions = {
    only?: string[];
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
    preserveState?: boolean;
    replace?: boolean;
};

const server = vi.hoisted(() => ({
    post: vi.fn<(url: string, data: unknown, options: VisitOptions) => void>(),
    get: vi.fn<(url: string, data: unknown, options: VisitOptions) => void>(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ url: '/proyectos/1/gantt', props: {} }),
    router: {
        post: (url: string, data: unknown, options: VisitOptions) =>
            server.post(url, data, options),
        get: (url: string, data: unknown, options: VisitOptions) =>
            server.get(url, data, options),
        delete: vi.fn(),
        visit: vi.fn(),
        on: () => () => {},
    },
    Link: ({
        href,
        children,
        preserveScroll: _preserveScroll,
        ...rest
    }: {
        href: string | { url: string };
        children?: ReactNode;
        preserveScroll?: boolean;
        [key: string]: unknown;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
}));

const project: Project = {
    id: 1,
    code: 'ACME-WEB',
    name: 'Web corporativa',
    color: '#0171FF',
    description: null,
    client: { id: 3, name: 'Acme' },
    client_id: 3,
    billing_type: 'hour_bank',
    status: 'active',
    start_date: '2026-09-01',
    due_date: null,
    budget_minutes: null,
    owner_user_id: 7,
    is_internal: false,
};

function task(overrides: Partial<GanttTask>): GanttTask {
    return {
        id: 0,
        project_id: 1,
        parent_task_id: null,
        title: `Tarea ${overrides.id}`,
        start_date: null,
        due_date: null,
        is_milestone: false,
        is_completed: false,
        status: {
            id: 1,
            name: 'Por hacer',
            color: '#56667A',
            category: 'todo',
        },
        assignee: null,
        estimated_minutes: null,
        logged_minutes: 0,
        subtasks_count: 0,
        can: { update: true },
        ...overrides,
    };
}

function projectProps(
    overrides: Partial<ProjectGanttPageProps> = {},
): ProjectGanttPageProps {
    return {
        project,
        canManage: true,
        can: { create: true, update: true },
        preferences: { scale: 'week', color: 'status' },
        today: '2026-10-06',
        tasks: [
            task({
                id: 1,
                title: 'Diseño',
                start_date: '2026-10-05',
                due_date: '2026-10-07',
            }),
            task({ id: 2, title: 'Sin fecha' }),
        ],
        dependencies: [],
        range: { start: '2026-09-28', end: '2026-10-21' },
        statuses: [
            { id: 1, name: 'Por hacer', color: '#56667A', category: 'todo' },
        ],
        banks: [
            {
                id: 11,
                name: 'Bolsa Diseño',
                status: 'active',
                is_open: true,
                department_id: 2,
                department: { id: 2, name: 'Diseño', color: '#0171FF' },
                consumed_pct: 40,
            },
        ],
        currentUser: { id: 7, department_id: 2 },
        ...overrides,
    };
}

afterEach(() => {
    server.post.mockReset();
    server.get.mockReset();
});

describe('pestaña Gantt del proyecto', () => {
    it('la pestaña Gantt está activa y enlazada; enseña el diagrama y la lista «Sin fechas»', () => {
        render(<ProjectGantt {...projectProps()} />);

        const tab = screen.getByRole('link', { name: 'Gantt' });
        expect(tab.getAttribute('href')).toBe('/proyectos/1/gantt');
        expect(tab.getAttribute('aria-current')).toBe('page');
        expect(screen.queryByText('Llega en la Fase 4')).toBeNull();
        expect(
            screen.getByRole('region', {
                name: 'Diagrama de Gantt de «Web corporativa»',
            }),
        ).toBeTruthy();
        expect(
            within(
                screen.getByRole('region', { name: 'Sin fechas (1)' }),
            ).getByText('Sin fecha'),
        ).toBeTruthy();
    });

    it('sin tareas: estado vacío', () => {
        render(<ProjectGantt {...projectProps({ tasks: [] })} />);

        expect(
            screen.getByText('Este proyecto aún no tiene tareas'),
        ).toBeTruthy();
    });

    it('sin tareas con fechas: estado vacío en el diagrama y la lista aparte', () => {
        render(
            <ProjectGantt
                {...projectProps({
                    tasks: [task({ id: 2, title: 'Sin fecha' })],
                })}
            />,
        );

        expect(
            screen.getByText('Ninguna tarea tiene fechas todavía'),
        ).toBeTruthy();
        expect(
            screen.getByRole('region', { name: 'Sin fechas (1)' }),
        ).toBeTruthy();
    });

    it('«Nueva tarea» crea la tarea con sus fechas y su bolsa con tasks.store', async () => {
        const user = userEvent.setup();
        render(<ProjectGantt {...projectProps()} />);

        await user.click(screen.getByRole('button', { name: 'Nueva tarea' }));
        const dialog = await screen.findByRole('dialog', {
            name: 'Nueva tarea',
        });

        await user.click(
            within(dialog).getByRole('button', { name: 'Crear tarea' }),
        );
        expect(within(dialog).getByRole('alert').textContent).toContain(
            'Escribe el título de la tarea.',
        );
        expect(server.post).not.toHaveBeenCalled();

        await user.type(within(dialog).getByLabelText('Título'), 'Prototipo');
        await user.click(
            within(dialog).getByRole('button', { name: 'Crear tarea' }),
        );

        const [url, data, options] = server.post.mock.calls[0];
        expect(url).toBe('/proyectos/1/tareas');
        expect(data).toEqual({
            title: 'Prototipo',
            start_date: null,
            due_date: null,
            is_milestone: false,
            hour_bank_id: 11,
        });
        expect(options.only).toEqual(['tasks', 'dependencies', 'range']);

        options.onError?.({ due_date: 'La fecha no es válida.' });
        expect(
            (await within(dialog).findByRole('alert')).textContent,
        ).toContain('La fecha no es válida.');
    });

    it('quien no puede crear tareas no ve «Nueva tarea»', () => {
        render(
            <ProjectGantt
                {...projectProps({ can: { create: false, update: false } })}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Nueva tarea' }),
        ).toBeNull();
    });

    it('cambiar la escala la lleva a la URL con una recarga parcial', async () => {
        const user = userEvent.setup();
        render(<ProjectGantt {...projectProps()} />);

        await user.click(screen.getByRole('radio', { name: 'Día' }));

        expect(server.get).toHaveBeenCalledWith(
            '/proyectos/1/gantt?escala=dia',
            {},
            expect.objectContaining({ only: ['preferences'], replace: true }),
        );
    });
});

describe('Gantt multiproyecto', () => {
    const group = (id: number, name: string): GanttProject => ({
        id,
        code: `P-${id}`,
        name,
        color: '#179FA5',
        status: 'active',
        uses_hour_banks: false,
        client: null,
        owner: { id: 7, name: 'Ana' },
        start_date: null,
        due_date: null,
        can: { update: true },
    });

    function indexProps(
        overrides: Partial<GanttIndexPageProps> = {},
    ): GanttIndexPageProps {
        return {
            filters: {
                cliente: null,
                departamento: null,
                responsable: null,
                estado: 'active',
            },
            options: {
                clients: [{ id: 3, name: 'Acme' }],
                owners: [{ id: 7, name: 'Ana' }],
                departments: [{ id: 2, name: 'Diseño' }],
            },
            preferences: { scale: 'week', color: 'status' },
            today: '2026-10-06',
            statuses: [
                {
                    id: 1,
                    name: 'Por hacer',
                    color: '#56667A',
                    category: 'todo',
                },
            ],
            limit: {
                exceeded: null,
                projects: 2,
                tasks: 3,
                max_projects: 60,
                max_tasks: 1500,
            },
            projects: [group(1, 'App'), group(2, 'Web')],
            tasks: [
                task({
                    id: 1,
                    project_id: 1,
                    title: 'API',
                    start_date: '2026-10-05',
                    due_date: '2026-10-09',
                }),
                task({
                    id: 2,
                    project_id: 2,
                    title: 'Home',
                    due_date: '2026-10-12',
                }),
                task({ id: 3, project_id: 2, title: 'Sin fecha' }),
            ],
            dependencies: [],
            range: { start: '2026-09-28', end: '2026-10-26' },
            ...overrides,
        };
    }

    it('agrupa por proyecto, con enlace a su Gantt y grupos plegables', async () => {
        const user = userEvent.setup();
        render(<GanttIndex {...indexProps()} />);

        expect(
            screen.getByRole('link', { name: 'Web' }).getAttribute('href'),
        ).toBe('/proyectos/2/gantt');
        expect(screen.getByText(/sin fechas: 1/)).toBeTruthy();

        const toggle = screen.getByRole('button', { name: 'Plegar «Web»' });
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        await user.click(toggle);

        expect(
            screen
                .getByRole('button', { name: 'Desplegar «Web»' })
                .getAttribute('aria-expanded'),
        ).toBe('false');
        expect(
            document.querySelector('[data-gantt-part="bar"][data-task-id="2"]'),
        ).toBeNull();
        expect(
            document.querySelector('[data-gantt-part="bar"][data-task-id="1"]'),
        ).not.toBeNull();
    });

    it('con demasiados proyectos avisa y pide filtrar', () => {
        render(
            <GanttIndex
                {...indexProps({
                    limit: {
                        exceeded: 'projects',
                        projects: 75,
                        tasks: 0,
                        max_projects: 60,
                        max_tasks: 1500,
                    },
                    projects: [],
                    tasks: [],
                })}
            />,
        );

        expect(screen.getByRole('alert').textContent).toContain(
            'Hay 75 proyectos con estos filtros y el máximo por vista es 60.',
        );
        expect(screen.queryByRole('region', { name: /Diagrama/ })).toBeNull();
    });

    it('con demasiadas tareas avisa con los números en español', () => {
        render(
            <GanttIndex
                {...indexProps({
                    limit: {
                        exceeded: 'tasks',
                        projects: 20,
                        tasks: 1834,
                        max_projects: 60,
                        max_tasks: 1500,
                    },
                    projects: [],
                    tasks: [],
                })}
            />,
        );

        expect(screen.getByRole('alert').textContent).toContain(
            'Estos proyectos tienen 1.834 tareas y el máximo por vista es 1.500.',
        );
    });

    it('sin proyectos con estos filtros: estado vacío', () => {
        render(
            <GanttIndex
                {...indexProps({
                    limit: {
                        exceeded: null,
                        projects: 0,
                        tasks: 0,
                        max_projects: 60,
                        max_tasks: 1500,
                    },
                    projects: [],
                    tasks: [],
                })}
            />,
        );

        expect(
            screen.getByText('No hay proyectos con estos filtros'),
        ).toBeTruthy();
    });

    it('los filtros van a la URL con una recarga parcial', async () => {
        const user = userEvent.setup();
        render(<GanttIndex {...indexProps()} />);

        await user.click(screen.getByRole('combobox', { name: 'Cliente' }));
        await user.click(await screen.findByRole('option', { name: 'Acme' }));

        expect(server.get).toHaveBeenCalledWith(
            '/gantt?cliente=3',
            {},
            expect.objectContaining({
                only: [
                    'filters',
                    'preferences',
                    'limit',
                    'projects',
                    'tasks',
                    'dependencies',
                    'range',
                ],
                preserveState: true,
            }),
        );
    });

    it('cambiar la escala conserva los filtros en la URL y no vuelve a pedir las tareas', async () => {
        const user = userEvent.setup();
        render(
            <GanttIndex
                {...indexProps({
                    filters: {
                        cliente: 3,
                        departamento: null,
                        responsable: null,
                        estado: 'active',
                    },
                })}
            />,
        );

        await user.click(screen.getByRole('radio', { name: 'Mes' }));

        expect(server.get).toHaveBeenCalledWith(
            '/gantt?cliente=3&escala=mes',
            {},
            expect.objectContaining({
                only: ['preferences'],
                preserveState: true,
                replace: true,
            }),
        );
    });
});
