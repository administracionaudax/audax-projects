// @vitest-environment jsdom
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ConflictDialog } from '@/components/gantt/conflict-dialog';
import { GanttView } from '@/components/gantt/gantt-view';
import type { GanttViewProps } from '@/components/gantt/gantt-view';
import type { GanttTask, GanttTaskStatus } from '@/components/gantt/types';
import type { ShiftProposal, TaskDependencyItem } from '@/types/schedule';

vi.setConfig({ testTimeout: 20_000 });

type VisitOptions = {
    only?: string[];
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onHttpException?: (response: { status: number }) => boolean | void;
    onCancel?: () => void;
    onFinish?: () => void;
};

type FinishedVisit = {
    completed: boolean;
    async: boolean;
    prefetch: boolean;
    only: string[];
    except: string[];
};

type FinishListener = (event: { detail: { visit: FinishedVisit } }) => void;

const server = vi.hoisted(() => ({
    post: vi.fn<(url: string, data: unknown, options: VisitOptions) => void>(),
    delete: vi.fn<(url: string, options: VisitOptions) => void>(),
    visit: vi.fn<(url: string, options?: VisitOptions) => void>(),
    get: vi.fn(),
    /** Escuchas de router.on('finish'). */
    finish: new Set<FinishListener>(),
}));

/** Lo que emite Inertia al terminar una visita (por defecto, síncrona, completa y terminada). */
function finishVisit(visit: Partial<FinishedVisit> = {}) {
    act(() => {
        // Copia: cada escucha se quita a sí misma al atenderla.
        for (const listener of Array.from(server.finish)) {
            listener({
                detail: {
                    visit: {
                        completed: true,
                        async: false,
                        prefetch: false,
                        only: [],
                        except: [],
                        ...visit,
                    },
                },
            });
        }
    });
}

const toasts = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn() }));

vi.mock('sonner', () => ({ toast: toasts }));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({ url: '/proyectos/1/gantt', props: {} }),
    router: {
        post: (url: string, data: unknown, options: VisitOptions) =>
            server.post(url, data, options),
        delete: (url: string, options: VisitOptions) =>
            server.delete(url, options),
        visit: (url: string, options?: VisitOptions) =>
            server.visit(url, options),
        get: (...args: unknown[]) => server.get(...args),
        on: (type: string, listener: FinishListener) => {
            if (type !== 'finish') {
                return () => {};
            }

            server.finish.add(listener);

            return () => server.finish.delete(listener);
        },
    },
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

const statuses: GanttTaskStatus[] = [
    { id: 1, name: 'Por hacer', color: '#56667A', category: 'todo' },
];

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
        status: statuses[0],
        assignee: null,
        estimated_minutes: null,
        logged_minutes: 0,
        subtasks_count: 0,
        can: { update: true },
        ...overrides,
    };
}

const design = task({
    id: 1,
    title: 'Diseño',
    start_date: '2026-10-05',
    due_date: '2026-10-07',
});
const layout = task({
    id: 2,
    title: 'Maquetación',
    start_date: '2026-10-08',
    due_date: '2026-10-09',
});
const copy = task({ id: 3, title: 'Textos sin fecha' });
const link: TaskDependencyItem = {
    id: 20,
    predecessor_task_id: 1,
    successor_task_id: 2,
    type: 'finish_to_start',
};
const proposal: ShiftProposal = {
    task_id: 2,
    title: 'Maquetación',
    start_date: '2026-10-08',
    due_date: '2026-10-09',
    new_start_date: '2026-10-09',
    new_due_date: '2026-10-10',
    shift_days: 1,
    predecessor_id: 1,
};

const RELOAD = ['tasks', 'dependencies', 'range'];

function renderView(overrides: Partial<GanttViewProps> = {}) {
    return render(
        <GanttView
            label="Diagrama de Gantt de «Web»"
            tasks={[design, layout, copy]}
            dependencies={[link]}
            statuses={statuses}
            range={{ start: '2026-10-01', end: '2026-10-20' }}
            today="2026-10-06"
            preferences={{ scale: 'day', color: 'status' }}
            reload={RELOAD}
            showUnscheduled
            keyboardCommitDelay={0}
            {...overrides}
        />,
    );
}

function bar(name: RegExp): HTMLElement {
    const found = [
        ...document.querySelectorAll<HTMLElement>(
            '[role="button"][data-gantt-part="bar"]',
        ),
    ].filter((element) => name.test(element.getAttribute('aria-label') ?? ''));

    if (found.length !== 1) {
        throw new Error(`Se esperaba una barra ${name}, hay ${found.length}`);
    }

    return found[0];
}

function respond(status: number, body: unknown) {
    return vi.fn(async () => new Response(JSON.stringify(body), { status }));
}

beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=token%3D123';
});

afterEach(() => {
    // Una visita completa atiende las recargas pendientes tras una interrupción (estado del módulo).
    finishVisit();
    vi.unstubAllGlobals();
    server.post.mockReset();
    server.delete.mockReset();
    server.visit.mockReset();
    toasts.error.mockReset();
});

describe('mover una tarea con sucesoras (D-057)', () => {
    it('pide la propuesta con las fechas nuevas y el token CSRF; sin conflicto, guarda sin desplazar', async () => {
        const fetch = respond(200, { proposals: [] });
        vi.stubGlobal('fetch', fetch);
        const user = userEvent.setup();
        renderView();

        bar(/^Maquetación/).focus();
        await user.keyboard('{ArrowRight}');

        await waitFor(() => expect(server.post).toHaveBeenCalled());
        const [url, init] = fetch.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];
        expect(url).toBe('/tareas/2/reprogramar/propuesta');
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body as string)).toEqual({
            start_date: '2026-10-09',
            due_date: '2026-10-10',
        });
        expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe(
            'token=123',
        );

        const [saveUrl, data, options] = server.post.mock.calls[0];
        expect(saveUrl).toBe('/tareas/2/reprogramar');
        expect(data).toEqual({
            start_date: '2026-10-09',
            due_date: '2026-10-10',
            shift_successors: false,
        });
        expect(options.only).toEqual(RELOAD);
        expect(screen.queryByRole('dialog')).toBeNull();

        // Mientras guarda, la barra ya está en su sitio nuevo y ocupada.
        expect(bar(/^Maquetación/).getAttribute('aria-label')).toContain(
            'Del 09/10/2026 al 10/10/2026',
        );
        expect(bar(/^Maquetación/).getAttribute('aria-busy')).toBe('true');
    });

    it('con sucesoras en conflicto avisa y «Mover también las sucesoras» guarda con shift_successors', async () => {
        vi.stubGlobal('fetch', respond(200, { proposals: [proposal] }));
        const user = userEvent.setup();
        renderView();

        bar(/^Diseño/).focus();
        await user.keyboard('{Shift>}{ArrowRight}{/Shift}');

        const dialog = await screen.findByRole('dialog', {
            name: /Hay tareas que dependen de esta/,
        });
        expect(dialog.textContent).toContain(
            'Con las nuevas fechas de «Diseño» (Del 05/10/2026 al 08/10/2026), una tarea que depende de ella empezaría antes de que acabe.',
        );
        const row = within(dialog).getByRole('row', { name: /Maquetación/ });
        expect(row.textContent).toContain('08/10/2026 – 09/10/2026');
        expect(row.textContent).toContain('09/10/2026 – 10/10/2026');
        expect(row.textContent).toContain('(+1 día)');
        expect(server.post).not.toHaveBeenCalled();

        await user.click(
            within(dialog).getByRole('button', {
                name: 'Mover también las sucesoras',
            }),
        );

        expect(server.post).toHaveBeenCalledWith(
            '/tareas/1/reprogramar',
            {
                start_date: '2026-10-05',
                due_date: '2026-10-08',
                shift_successors: true,
            },
            expect.objectContaining({ only: RELOAD }),
        );

        server.post.mock.calls[0][2].onSuccess?.();
        server.post.mock.calls[0][2].onFinish?.();
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    });

    it('«Solo esta tarea» guarda sin desplazar las sucesoras', async () => {
        vi.stubGlobal('fetch', respond(200, { proposals: [proposal] }));
        const user = userEvent.setup();
        renderView();

        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowRight}');
        await user.click(
            await screen.findByRole('button', { name: 'Solo esta tarea' }),
        );

        expect(server.post).toHaveBeenCalledWith(
            '/tareas/1/reprogramar',
            {
                start_date: '2026-10-06',
                due_date: '2026-10-08',
                shift_successors: false,
            },
            expect.anything(),
        );
    });

    it('«Cancelar» no guarda y la tarea vuelve a su sitio', async () => {
        vi.stubGlobal('fetch', respond(200, { proposals: [proposal] }));
        const user = userEvent.setup();
        renderView();

        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowRight}');
        expect(bar(/^Diseño/).getAttribute('aria-label')).toContain(
            'Del 06/10/2026 al 08/10/2026',
        );

        await user.click(
            await screen.findByRole('button', { name: 'Cancelar' }),
        );

        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(server.post).not.toHaveBeenCalled();
        expect(bar(/^Diseño/).getAttribute('aria-label')).toContain(
            'Del 05/10/2026 al 07/10/2026',
        );
    });

    it('si la propuesta falla, la tarea vuelve y se avisa con el mensaje del servidor', async () => {
        vi.stubGlobal(
            'fetch',
            respond(422, {
                errors: { due_date: ['La entrega no es válida.'] },
            }),
        );
        const user = userEvent.setup();
        renderView();

        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowRight}');

        await waitFor(() =>
            expect(toasts.error).toHaveBeenCalledWith(
                'La entrega no es válida.',
            ),
        );
        expect(bar(/^Diseño/).getAttribute('aria-label')).toContain(
            'Del 05/10/2026 al 07/10/2026',
        );
        expect(server.post).not.toHaveBeenCalled();
    });

    it('si guardar falla, la tarea vuelve y se avisa', async () => {
        vi.stubGlobal('fetch', respond(200, { proposals: [] }));
        const user = userEvent.setup();
        renderView();

        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowRight}');
        await waitFor(() => expect(server.post).toHaveBeenCalled());

        server.post.mock.calls[0][2].onHttpException?.({ status: 500 });

        await waitFor(() =>
            expect(bar(/^Diseño/).getAttribute('aria-label')).toContain(
                'Del 05/10/2026 al 07/10/2026',
            ),
        );
        expect(toasts.error).toHaveBeenCalledWith(
            'Ha fallado el servidor y no se ha guardado el cambio. Vuelve a intentarlo en unos minutos.',
        );
    });
});

describe('guardado interrumpido por otra visita de Inertia', () => {
    /** Lo que hace Inertia al cancelar una visita síncrona: onCancel y después onFinish. */
    const interrupt = (options: VisitOptions) =>
        act(() => {
            options.onCancel?.();
            options.onFinish?.();
        });

    const view = (tasks: GanttTask[]) => (
        <GanttView
            label="Diagrama de Gantt de «Web»"
            tasks={tasks}
            dependencies={[link]}
            statuses={statuses}
            range={{ start: '2026-10-01', end: '2026-10-20' }}
            today="2026-10-06"
            preferences={{ scale: 'day', color: 'status' }}
            reload={RELOAD}
            showUnscheduled
            keyboardCommitDelay={0}
        />
    );

    it('la tarea deja de estar «guardando» y se puede volver a mover; si la visita que lo interrumpió no trae las tareas, se piden', async () => {
        vi.stubGlobal('fetch', respond(200, { proposals: [] }));
        const user = userEvent.setup();
        const { rerender } = render(view([design, layout, copy]));

        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowRight}');
        await waitFor(() => expect(server.post).toHaveBeenCalledTimes(1));
        expect(bar(/^Diseño/).getAttribute('aria-busy')).toBe('true');

        interrupt(server.post.mock.calls[0][2]);

        // Libre al momento, sin avisos, y donde se dejó (sin saltar atrás).
        expect(bar(/^Diseño/).getAttribute('aria-busy')).toBeNull();
        expect(bar(/^Diseño/).getAttribute('aria-label')).toContain(
            'Del 06/10/2026 al 08/10/2026',
        );
        expect(toasts.error).not.toHaveBeenCalled();

        // Se puede volver a mover (no se queda bloqueada) y también se interrumpe.
        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowRight}');
        await waitFor(() => expect(server.post).toHaveBeenCalledTimes(2));
        expect(server.post.mock.calls[1][1]).toEqual({
            start_date: '2026-10-07',
            due_date: '2026-10-09',
            shift_successors: false,
        });
        interrupt(server.post.mock.calls[1][2]);

        // La visita que lo interrumpió (cambiar la escala) no trae las tareas: se piden, una vez.
        expect(server.visit).not.toHaveBeenCalled();
        finishVisit({ only: ['preferences'] });
        expect(server.visit).toHaveBeenCalledTimes(1);
        const [url, options] = server.visit.mock.calls[0];
        expect(url).toBe(window.location.href);
        expect(options).toMatchObject({
            only: RELOAD,
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });

        // Hasta que llegan, se ve donde se dejó; al llegar, mandan las fechas del servidor.
        rerender(
            view([
                { ...design, start_date: '2026-10-06', due_date: '2026-10-08' },
                layout,
                copy,
            ]),
        );
        expect(bar(/^Diseño/).getAttribute('aria-label')).toContain(
            'Del 07/10/2026 al 09/10/2026',
        );
        act(() => options?.onFinish?.());
        expect(bar(/^Diseño/).getAttribute('aria-label')).toContain(
            'Del 06/10/2026 al 08/10/2026',
        );
    });

    it('si la visita que lo interrumpió ya trae las props del Gantt, no se piden otra vez', async () => {
        vi.stubGlobal('fetch', respond(200, { proposals: [] }));
        const user = userEvent.setup();
        const { rerender } = render(view([design, layout, copy]));

        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowRight}');
        await waitFor(() => expect(server.post).toHaveBeenCalledTimes(1));
        interrupt(server.post.mock.calls[0][2]);

        // Otra tarea guardada (recarga tasks, dependencies y range); el servidor no aplicó el primero.
        rerender(view([{ ...design }, layout, copy]));
        finishVisit({ only: RELOAD });

        expect(server.visit).not.toHaveBeenCalled();
        expect(bar(/^Diseño/).getAttribute('aria-label')).toContain(
            'Del 05/10/2026 al 07/10/2026',
        );
    });

    it('las visitas asíncronas, las precargas y las que también se cancelan no cuentan; si se interrumpe la recarga, se vuelve a pedir', async () => {
        vi.stubGlobal('fetch', respond(200, { proposals: [] }));
        const user = userEvent.setup();
        render(view([design, layout, copy]));

        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowRight}');
        await waitFor(() => expect(server.post).toHaveBeenCalledTimes(1));
        interrupt(server.post.mock.calls[0][2]);

        finishVisit({ only: ['notifications'], async: true });
        finishVisit({ only: ['preferences'], prefetch: true });
        finishVisit({ only: ['preferences'], completed: false });
        expect(server.visit).not.toHaveBeenCalled();

        finishVisit({ only: ['preferences'] });
        expect(server.visit).toHaveBeenCalledTimes(1);

        // La recarga también se interrumpe: se pedirá al terminar la siguiente.
        interrupt(server.visit.mock.calls[0][1] as VisitOptions);
        finishVisit({ only: ['filters'] });
        expect(server.visit).toHaveBeenCalledTimes(2);
        expect(server.visit.mock.calls[1][1]).toMatchObject({ only: RELOAD });
    });

    it('las fechas de las tareas que se siguen guardando no se pisan con las del servidor', async () => {
        vi.stubGlobal('fetch', respond(200, { proposals: [] }));
        const user = userEvent.setup();
        const { rerender } = render(view([design, layout, copy]));

        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowRight}');
        await waitFor(() => expect(server.post).toHaveBeenCalledTimes(1));

        // Llegan tareas (otra recarga) mientras Diseño sigue guardándose.
        rerender(view([{ ...design }, layout, copy]));

        expect(bar(/^Diseño/).getAttribute('aria-label')).toContain(
            'Del 06/10/2026 al 08/10/2026',
        );
        expect(bar(/^Diseño/).getAttribute('aria-busy')).toBe('true');
    });

    it('en el diálogo de conflicto, si se interrumpe, se cierra y la tarea queda libre', async () => {
        vi.stubGlobal('fetch', respond(200, { proposals: [proposal] }));
        const user = userEvent.setup();
        render(view([design, layout, copy]));

        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowRight}');
        await user.click(
            await screen.findByRole('button', {
                name: 'Mover también las sucesoras',
            }),
        );
        expect(server.post).toHaveBeenCalledTimes(1);

        interrupt(server.post.mock.calls[0][2]);

        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(bar(/^Diseño/).getAttribute('aria-busy')).toBeNull();
        expect(toasts.error).not.toHaveBeenCalled();
    });
});

describe('diálogo de conflicto', () => {
    it('ofrece las tres opciones y cerrarlo es cancelar', async () => {
        const user = userEvent.setup();
        const onChoose = vi.fn();
        const conflict = {
            task: design,
            dates: { start_date: '2026-10-05', due_date: '2026-10-12' },
            proposals: [
                proposal,
                { ...proposal, task_id: 3, title: 'Pruebas', shift_days: 4 },
            ],
        };

        const { rerender } = render(
            <ConflictDialog
                conflict={conflict}
                resolving={null}
                onChoose={onChoose}
            />,
        );

        const dialog = screen.getByRole('dialog');
        expect(dialog.textContent).toContain('2 tareas que dependen de ella');
        expect(within(dialog).getAllByRole('row')).toHaveLength(3);
        expect(dialog.textContent).toContain('(+4 días)');

        await user.click(
            screen.getByRole('button', { name: 'Mover también las sucesoras' }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Solo esta tarea' }),
        );
        await user.click(screen.getByRole('button', { name: 'Cancelar' }));
        await user.keyboard('{Escape}');

        expect(onChoose.mock.calls.map(([choice]) => choice)).toEqual([
            'shift',
            'only',
            'cancel',
            'cancel',
        ]);

        // Mientras guarda, los botones se desactivan y no se puede cerrar.
        onChoose.mockReset();
        rerender(
            <ConflictDialog
                conflict={conflict}
                resolving="shift"
                onChoose={onChoose}
            />,
        );
        expect(
            (
                screen.getByRole('button', {
                    name: 'Solo esta tarea',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        await user.keyboard('{Escape}');
        expect(onChoose).not.toHaveBeenCalled();
    });
});

describe('dependencias y fechas desde el Gantt', () => {
    it('«Añadir dependencia…» enlaza con la tarea elegida y enseña el error del servidor (ciclo)', async () => {
        const user = userEvent.setup();
        renderView();

        bar(/^Maquetación/).focus();
        await user.keyboard('{Shift>}{F10}{/Shift}');
        await user.click(
            await screen.findByRole('menuitem', {
                name: 'Añadir dependencia…',
            }),
        );

        const dialog = await screen.findByRole('dialog', {
            name: 'Añadir dependencia',
        });
        await user.click(
            within(dialog).getByRole('radio', {
                name: '«Maquetación» va antes de la tarea que elijas',
            }),
        );
        await user.click(
            within(dialog).getByRole('option', { name: /Diseño/ }),
        );
        expect(dialog.textContent).toContain(
            '«Diseño» empezará cuando acabe «Maquetación».',
        );
        await user.click(
            within(dialog).getByRole('button', { name: 'Añadir dependencia' }),
        );

        const [url, data, options] = server.post.mock.calls[0];
        expect(url).toBe('/proyectos/1/dependencias');
        expect(data).toEqual({ predecessor_task_id: 2, successor_task_id: 1 });
        expect(options.only).toEqual(RELOAD);

        options.onError?.({
            successor_task_id:
                'Esa dependencia crearía un ciclo: la tarea ya depende, directa o indirectamente, de la otra.',
        });
        options.onFinish?.();

        expect(
            (await within(dialog).findByRole('alert')).textContent,
        ).toContain('Esa dependencia crearía un ciclo');
        expect(toasts.error).not.toHaveBeenCalled();
    });

    it('«Quitar fechas» lleva el foco tras la tarea, a su entrada de la lista «Sin fechas»', async () => {
        vi.stubGlobal('fetch', respond(200, { proposals: [] }));
        const user = userEvent.setup();
        renderView();

        bar(/^Maquetación/).focus();
        await user.keyboard('{Shift>}{F10}{/Shift}');
        await user.click(
            await screen.findByRole('menuitem', { name: 'Quitar fechas' }),
        );

        const list = screen.getByRole('region', { name: 'Sin fechas (2)' });
        const assign = within(list).getByRole('button', {
            name: 'Asignar fechas a «Maquetación»',
        });
        await waitFor(() => expect(document.activeElement).toBe(assign));

        // El cierre del menú (Radix, en un setTimeout) no se lo lleva.
        await act(() => new Promise((resolve) => setTimeout(resolve, 20)));
        expect(document.activeElement).toBe(assign);
        await waitFor(() =>
            expect(server.post.mock.calls[0]?.[1]).toEqual({
                start_date: null,
                due_date: null,
                shift_successors: false,
            }),
        );
    });

    it('si falla guardar «Quitar fechas», la tarea vuelve al diagrama y el foco con ella', async () => {
        vi.stubGlobal('fetch', respond(200, { proposals: [] }));
        const user = userEvent.setup();
        renderView();

        bar(/^Maquetación/).focus();
        await user.keyboard('{Shift>}{F10}{/Shift}');
        await user.click(
            await screen.findByRole('menuitem', { name: 'Quitar fechas' }),
        );
        const assign = within(
            screen.getByRole('region', { name: 'Sin fechas (2)' }),
        ).getByRole('button', { name: 'Asignar fechas a «Maquetación»' });
        await waitFor(() => expect(document.activeElement).toBe(assign));
        await waitFor(() => expect(server.post).toHaveBeenCalledTimes(1));

        act(() => {
            server.post.mock.calls[0][2].onHttpException?.({ status: 500 });
            server.post.mock.calls[0][2].onFinish?.();
        });

        await waitFor(() =>
            expect(document.activeElement).toBe(bar(/^Maquetación/)),
        );
        expect(bar(/^Maquetación/).getAttribute('aria-label')).toContain(
            'Del 08/10/2026 al 09/10/2026',
        );
        expect(toasts.error).toHaveBeenCalled();
    });

    it('si era la última barra y el diagrama desaparece, el foco también va a «Sin fechas»', async () => {
        vi.stubGlobal('fetch', respond(200, { proposals: [] }));
        const user = userEvent.setup();
        renderView({ tasks: [design, copy], dependencies: [] });

        bar(/^Diseño/).focus();
        await user.keyboard('{Shift>}{F10}{/Shift}');
        await user.click(
            await screen.findByRole('menuitem', { name: 'Quitar fechas' }),
        );

        expect(document.querySelector('[data-test="gantt-scroll"]')).toBeNull();
        const assign = within(
            screen.getByRole('region', { name: 'Sin fechas (2)' }),
        ).getByRole('button', { name: 'Asignar fechas a «Diseño»' });
        await waitFor(() => expect(document.activeElement).toBe(assign));
        await act(() => new Promise((resolve) => setTimeout(resolve, 20)));
        expect(document.activeElement).toBe(assign);
    });

    it('«Asignar fechas» en la lista «Sin fechas» reprograma la tarea', async () => {
        vi.stubGlobal('fetch', respond(200, { proposals: [] }));
        const user = userEvent.setup();
        renderView();

        const list = screen.getByRole('region', { name: 'Sin fechas (1)' });
        await user.click(
            within(list).getByRole('button', {
                name: 'Asignar fechas a «Textos sin fecha»',
            }),
        );

        const dialog = await screen.findByRole('dialog', {
            name: 'Asignar fechas',
        });
        expect(within(dialog).getByLabelText('Inicio')).toBeTruthy();
        expect(within(dialog).getByLabelText('Entrega')).toBeTruthy();
    });

    it('en solo lectura no hay edición ni diálogos', async () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        const user = userEvent.setup();
        renderView({ readOnly: true });

        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowRight}');

        expect(fetch).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: /Asignar fechas a/ }),
        ).toBeNull();
    });
});

describe('controles', () => {
    it('cambia de escala y de colores y avisa para llevarlo a la URL; la tabla es la alternativa accesible', async () => {
        const user = userEvent.setup();
        const onPreferencesChange = vi.fn();
        renderView({ onPreferencesChange });

        await user.click(screen.getByRole('radio', { name: 'Mes' }));
        expect(onPreferencesChange).toHaveBeenLastCalledWith({
            scale: 'month',
            color: 'status',
        });

        await user.click(
            screen.getByRole('radio', { name: 'Por responsable' }),
        );
        expect(onPreferencesChange).toHaveBeenLastCalledWith({
            scale: 'month',
            color: 'assignee',
        });
        expect(
            screen.getByRole('list', {
                name: 'Leyenda de colores por responsable',
            }).textContent,
        ).toContain('Sin responsable');

        await user.click(screen.getByRole('radio', { name: 'Tabla' }));
        const table = screen.getByRole('table', {
            name: 'Diagrama de Gantt de «Web»',
        });
        const rows = within(table).getAllByRole('row');
        expect(rows).toHaveLength(3);
        expect(rows[2].textContent).toContain('Maquetación');
        expect(rows[2].textContent).toContain('08/10/2026');
        expect(rows[2].textContent).toContain('Diseño');
    });
});
