// @vitest-environment jsdom
import {
    act,
    configure,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    MyTaskFilterBar,
    MyTaskSortSelect,
    SEARCH_DELAY_MS,
} from '@/components/my-tasks/my-task-filter-bar';
import {
    EMPTY_MY_TASK_FILTERS,
    myTaskQuery,
} from '@/components/my-tasks/my-task-query';
import MyTasks from '@/pages/my-tasks/index';
import type {
    MyTaskFilters,
    MyTaskItem,
    MyTaskOptions,
    MyTasksPageProps,
    TaskStatus,
} from '@/types';

configure({ testIdAttribute: 'data-test' });
vi.setConfig({ testTimeout: 20_000 });

// Radix Select usa la captura del puntero y cmdk, scrollIntoView: jsdom no los trae.
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

/*
| Mis tareas (D-143): barra de filtros, selector de orden, filtros en la URL y guardados en este
| navegador, y «Cargar más».
*/

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    page: {
        url: '/mis-tareas',
        props: {
            auth: { user: { id: 7, name: 'Elena Ruiz' }, can: {} },
            timer: null,
        } as Record<string, unknown>,
    },
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => inertia.page,
    router: { get: inertia.get, post: vi.fn(), on: () => () => {} },
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        [key: string]: unknown;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
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
        id: 5,
        name: 'Hecha',
        color: '#179FA5',
        category: 'done',
        position: 4,
        is_default: false,
    },
];

const options: MyTaskOptions = {
    projects: [
        {
            id: 3,
            code: 'ACME',
            name: 'Web ACME',
            color: '#0171FF',
            client_id: 9,
        },
        {
            id: 4,
            code: 'HOTEL',
            name: 'Hoteles',
            color: '#179FA5',
            client_id: null,
        },
    ],
    clients: [{ id: 9, name: 'ACME S.L.' }],
    types: [],
    priorities: ['low', 'normal', 'high', 'urgent'],
};

function task(id: number, overrides: Partial<MyTaskItem> = {}): MyTaskItem {
    return {
        id,
        project_id: 3,
        hour_bank_id: null,
        parent_task_id: null,
        title: `Tarea ${id}`,
        task_type_id: null,
        status_id: 1,
        priority: 'normal',
        assignee: null,
        assignee_user_id: 7,
        start_date: null,
        due_date: null,
        estimated_minutes: null,
        is_billable: true,
        is_milestone: false,
        is_completed: false,
        position: 0,
        completed_at: null,
        project: { id: 3, code: 'ACME', name: 'Web ACME', color: '#0171FF' },
        hour_bank: null,
        parent: null,
        section: 'no_date',
        assigned_to_me: true,
        my_last_logged_on: null,
        ...overrides,
    } as MyTaskItem;
}

function props(overrides: Partial<MyTasksPageProps> = {}): MyTasksPageProps {
    return {
        today: '2026-09-23',
        filters: EMPTY_MY_TASK_FILTERS,
        tasks: [task(1), task(2)],
        cursor: null,
        next_cursor: null,
        statuses,
        options,
        ...overrides,
    };
}

function lastUrl(): string {
    return String(inertia.get.mock.calls.at(-1)?.[0]);
}

beforeEach(() => {
    inertia.get.mockReset();
    window.localStorage.clear();
    window.history.replaceState({}, '', '/mis-tareas');
});

afterEach(() => {
    vi.useRealTimers();
});

describe('myTaskQuery', () => {
    it('pasa los filtros a la URL en español, sin los vacíos ni el orden por defecto', () => {
        expect(myTaskQuery(EMPTY_MY_TASK_FILTERS)).toEqual({});
        expect(
            myTaskQuery({
                ...EMPTY_MY_TASK_FILTERS,
                q: '  portada ',
                projects: [3, 4],
                clients: [9],
                statuses: [1],
                done: true,
                priority: 'high',
                types: [2],
                due: 'range',
                from: '2026-10-01',
                to: '2026-10-31',
                sort: 'due',
            }),
        ).toEqual({
            q: 'portada',
            proyecto: '3,4',
            cliente: '9',
            estado: '1',
            hechas: '1',
            prioridad: 'high',
            tipo: '2',
            vence: 'rango',
            desde: '2026-10-01',
            hasta: '2026-10-31',
            orden: 'vencimiento',
        });
        // Las fechas solo cuentan con «entre fechas».
        expect(
            myTaskQuery({
                ...EMPTY_MY_TASK_FILTERS,
                due: 'overdue',
                from: '2026-10-01',
            }),
        ).toEqual({ vence: 'vencidas' });
    });
});

describe('MyTaskFilterBar', () => {
    function renderBar(filters: MyTaskFilters = EMPTY_MY_TASK_FILTERS) {
        const onChange = vi.fn();
        render(
            <MyTaskFilterBar
                filters={filters}
                options={options}
                statuses={statuses}
                onChange={onChange}
            />,
        );

        return onChange;
    }

    it('busca al pulsar Intro y al dejar de escribir', async () => {
        const user = userEvent.setup();
        const onChange = renderBar();

        await user.type(screen.getByLabelText('Buscar'), 'portada{Enter}');

        expect(onChange).toHaveBeenLastCalledWith({
            ...EMPTY_MY_TASK_FILTERS,
            q: 'portada',
        });
    });

    it('aplica la búsqueda sola tras una pausa', () => {
        vi.useFakeTimers();
        const onChange = renderBar();
        const input = screen.getByLabelText('Buscar');

        act(() => {
            input.focus();
        });
        // Escribir sin userEvent (los temporizadores son falsos).
        act(() => {
            Object.getOwnPropertyDescriptor(
                HTMLInputElement.prototype,
                'value',
            )?.set?.call(input, 'acme');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });

        expect(onChange).not.toHaveBeenCalled();
        act(() => {
            vi.advanceTimersByTime(SEARCH_DELAY_MS);
        });
        expect(onChange).toHaveBeenCalledWith({
            ...EMPTY_MY_TASK_FILTERS,
            q: 'acme',
        });
    });

    it('elige varios proyectos con el selector múltiple', async () => {
        const user = userEvent.setup();
        const onChange = renderBar();

        await user.click(
            screen.getByRole('combobox', { name: 'Proyecto: Todos' }),
        );
        await user.click(await screen.findByText('HOTEL · Hoteles'));

        expect(onChange).toHaveBeenLastCalledWith({
            ...EMPTY_MY_TASK_FILTERS,
            projects: [4],
        });
    });

    it('ofrece «Incluir hechas» y el rango de fechas solo con «Entre fechas»', async () => {
        const user = userEvent.setup();
        const onChange = renderBar({
            ...EMPTY_MY_TASK_FILTERS,
            due: 'range',
        });

        expect(screen.getByLabelText('Vence desde')).toBeTruthy();
        await user.click(
            screen.getByRole('switch', { name: 'Incluir hechas' }),
        );

        expect(onChange).toHaveBeenLastCalledWith({
            ...EMPTY_MY_TASK_FILTERS,
            due: 'range',
            done: true,
        });
    });

    it('«Limpiar filtros» los quita todos y conserva el orden', async () => {
        const user = userEvent.setup();
        const onChange = renderBar({
            ...EMPTY_MY_TASK_FILTERS,
            q: 'x',
            projects: [3],
            sort: 'priority',
        });

        await user.click(
            screen.getByRole('button', { name: 'Limpiar filtros' }),
        );

        expect(onChange).toHaveBeenCalledWith({
            ...EMPTY_MY_TASK_FILTERS,
            sort: 'priority',
        });
    });

    it('sin filtros no enseña «Limpiar filtros»', () => {
        renderBar();

        expect(
            screen.queryByRole('button', { name: 'Limpiar filtros' }),
        ).toBeNull();
    });
});

describe('MyTaskSortSelect', () => {
    it('ofrece los seis órdenes, con «Imputadas recientemente» primero', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(<MyTaskSortSelect value="logged" onChange={onChange} />);

        const trigger = screen.getByRole('combobox', { name: 'Ordenar por' });
        expect(trigger.textContent).toContain('Imputadas recientemente');

        await user.click(trigger);
        const items = await screen.findAllByRole('option');
        expect(items.map((item) => item.textContent)).toEqual([
            'Imputadas recientemente',
            'Vencimiento',
            'Prioridad',
            'Proyecto',
            'Creación',
            'Actualización',
        ]);

        await user.click(screen.getByRole('option', { name: 'Prioridad' }));
        expect(onChange).toHaveBeenCalledWith('priority');
    });
});

describe('página Mis tareas', () => {
    it('marca las tareas que no tengo asignadas y cuándo imputé', () => {
        render(
            <MyTasks
                {...props({
                    tasks: [
                        task(1, { my_last_logged_on: '2026-09-22' }),
                        task(2, {
                            assigned_to_me: false,
                            assignee: {
                                id: 8,
                                name: 'Pedro Pérez',
                                avatar: null,
                                department_id: null,
                                is_active: true,
                            },
                        }),
                    ],
                })}
            />,
        );

        const rows = screen.getAllByTestId('my-task');
        expect(
            within(rows[0]).getByText('Imputaste el 22/09/2026'),
        ).toBeTruthy();
        expect(within(rows[0]).queryByText('No asignada a ti')).toBeNull();
        expect(within(rows[1]).getByText('No asignada a ti')).toBeTruthy();
        expect(within(rows[1]).getByText(': Pedro Pérez')).toBeTruthy();
    });

    it('agrupa por secciones al ordenar por vencimiento', () => {
        render(
            <MyTasks
                {...props({
                    filters: { ...EMPTY_MY_TASK_FILTERS, sort: 'due' },
                    tasks: [
                        task(1, { section: 'overdue', due_date: '2026-09-20' }),
                        task(2, { section: 'today', due_date: '2026-09-23' }),
                        task(3, { section: 'today', start_date: '2026-09-23' }),
                    ],
                })}
            />,
        );

        expect(
            screen
                .getAllByRole('heading', { level: 2 })
                .map((h) => h.textContent),
        ).toEqual(['Vencidas(1)', 'Hoy(2)']);
    });

    it('cambia el orden por la URL', async () => {
        const user = userEvent.setup();
        render(<MyTasks {...props()} />);

        await user.click(screen.getByRole('combobox', { name: 'Ordenar por' }));
        await user.click(
            await screen.findByRole('option', { name: 'Proyecto' }),
        );

        expect(lastUrl()).toBe('/mis-tareas?orden=proyecto');
    });

    it('«Cargar más» pide la página siguiente y la añade a la lista', async () => {
        const user = userEvent.setup();
        const first = props({ next_cursor: 'abc' });
        const { rerender } = render(<MyTasks {...first} />);

        expect(screen.getByTestId('my-tasks-count').textContent).toBe(
            '2 tareas cargadas; hay más.',
        );
        await user.click(
            screen.getByRole('button', { name: 'Cargar más tareas' }),
        );

        expect(lastUrl()).toBe('/mis-tareas?cursor=abc');
        expect(inertia.get.mock.calls.at(-1)?.[2]).toMatchObject({
            only: ['tasks', 'cursor', 'next_cursor'],
            preserveUrl: true,
        });

        rerender(
            <MyTasks
                {...first}
                tasks={[task(2), task(3)]}
                cursor="abc"
                next_cursor={null}
            />,
        );

        expect(
            screen.getAllByTestId('my-task').map((row) => row.dataset.taskId),
        ).toEqual(['1', '2', '3']);
        expect(
            screen.queryByRole('button', { name: 'Cargar más tareas' }),
        ).toBeNull();
    });

    it('recuerda los filtros y los recupera al volver sin filtros en la URL', async () => {
        const { unmount } = render(
            <MyTasks
                {...props({
                    filters: {
                        ...EMPTY_MY_TASK_FILTERS,
                        projects: [3],
                        sort: 'due',
                    },
                })}
            />,
        );

        expect(
            JSON.parse(
                window.localStorage.getItem('audax.my-tasks.filters.7') ?? '',
            ),
        ).toEqual({ proyecto: '3', orden: 'vencimiento' });
        unmount();

        render(<MyTasks {...props()} />);

        await waitFor(() =>
            expect(lastUrl()).toBe('/mis-tareas?proyecto=3&orden=vencimiento'),
        );
        // Lo guardado no se pisa con la página vacía mientras vuelve.
        expect(window.localStorage.getItem('audax.my-tasks.filters.7')).toBe(
            JSON.stringify({ proyecto: '3', orden: 'vencimiento' }),
        );
    });

    it('con filtros en la URL no recupera los guardados', () => {
        window.localStorage.setItem(
            'audax.my-tasks.filters.7',
            JSON.stringify({ proyecto: '3' }),
        );
        window.history.replaceState({}, '', '/mis-tareas?orden=prioridad');

        render(
            <MyTasks
                {...props({
                    filters: { ...EMPTY_MY_TASK_FILTERS, sort: 'priority' },
                })}
            />,
        );

        expect(inertia.get).not.toHaveBeenCalled();
    });

    it('sigue funcionando sin almacenamiento', () => {
        const spy = vi
            .spyOn(window.localStorage, 'getItem')
            .mockImplementation(() => {
                throw new Error('bloqueado');
            });
        const setSpy = vi
            .spyOn(window.localStorage, 'setItem')
            .mockImplementation(() => {
                throw new Error('bloqueado');
            });

        expect(() => render(<MyTasks {...props()} />)).not.toThrow();
        expect(screen.getAllByTestId('my-task')).toHaveLength(2);

        spy.mockRestore();
        setSpy.mockRestore();
    });
});
