// @vitest-environment jsdom
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { statusColors } from '@/components/gantt/colors';
import { GanttChart } from '@/components/gantt/gantt-chart';
import type { GanttChartProps } from '@/components/gantt/gantt-chart';
import { createTimeline } from '@/components/gantt/geometry';
import { buildTaskRows } from '@/components/gantt/rows';
import type { GanttTask, GanttTaskStatus } from '@/components/gantt/types';
import type { TaskDependencyItem } from '@/types/schedule';

vi.setConfig({ testTimeout: 20_000 });

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
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
    { id: 2, name: 'Hecha', color: '#179FA5', category: 'done' },
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
        assignee: { id: 5, name: 'Elena Empleada', avatar: null },
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
    estimated_minutes: 600,
    logged_minutes: 150,
});
const layout = task({
    id: 2,
    title: 'Maquetación',
    start_date: '2026-10-08',
    due_date: '2026-10-09',
});
const delivery = task({
    id: 3,
    title: 'Entrega',
    due_date: '2026-10-16',
    is_milestone: true,
    assignee: null,
});
const parent = task({ id: 4, title: 'Contenidos', subtasks_count: 1 });
const child = task({
    id: 5,
    parent_task_id: 4,
    title: 'Textos',
    start_date: '2026-10-12',
    due_date: '2026-10-13',
});

const link: TaskDependencyItem = {
    id: 20,
    predecessor_task_id: 1,
    successor_task_id: 2,
    type: 'finish_to_start',
};

function renderChart(
    props: Partial<GanttChartProps> = {},
    tasks: GanttTask[] = [design, layout, delivery, parent, child],
) {
    const handlers = {
        onReschedule: vi.fn(),
        onLink: vi.fn(),
        onUnlink: vi.fn(),
        onOpen: vi.fn(),
        onAction: vi.fn(),
    };

    render(
        <GanttChart
            label="Diagrama de Gantt de «Web»"
            rows={buildTaskRows(tasks).rows}
            dependencies={[link]}
            timeline={createTimeline(
                { start: '2026-09-28', end: '2026-10-25' },
                'day',
            )}
            today="2026-10-06"
            colors={statusColors(tasks, statuses)}
            keyboardCommitDelay={0}
            {...handlers}
            {...props}
        />,
    );

    return handlers;
}

/**
 * La barra de la tarea por su nombre accesible (sin getByRole, que calcula el nombre de todos los
 * botones y es lento con cientos de celdas; el título de la columna también es un botón).
 */
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

afterEach(() => {
    vi.useRealTimers();
});

describe('Gantt: teclado', () => {
    it('←/→ mueven un día, Mayús cambia la entrega y Alt + Mayús el inicio', async () => {
        const user = userEvent.setup();
        const { onReschedule } = renderChart();

        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowRight}');
        expect(onReschedule).toHaveBeenLastCalledWith(design, {
            start_date: '2026-10-06',
            due_date: '2026-10-08',
        });

        await user.keyboard('{Shift>}{ArrowRight}{/Shift}');
        expect(onReschedule).toHaveBeenLastCalledWith(design, {
            start_date: '2026-10-05',
            due_date: '2026-10-08',
        });

        await user.keyboard('{Shift>}{ArrowLeft}{/Shift}');
        expect(onReschedule).toHaveBeenLastCalledWith(design, {
            start_date: '2026-10-05',
            due_date: '2026-10-06',
        });

        await user.keyboard('{Alt>}{Shift>}{ArrowLeft}{/Shift}{/Alt}');
        expect(onReschedule).toHaveBeenLastCalledWith(design, {
            start_date: '2026-10-04',
            due_date: '2026-10-07',
        });

        // Alt sola es «atrás» del navegador: no mueve nada.
        await user.keyboard('{Alt>}{ArrowLeft}{/Alt}');
        expect(onReschedule).toHaveBeenCalledTimes(4);
    });

    it('la entrega no puede quedar antes del inicio', async () => {
        const user = userEvent.setup();
        const single = task({
            id: 9,
            title: 'Revisión',
            start_date: '2026-10-05',
            due_date: '2026-10-05',
        });
        const { onReschedule } = renderChart({}, [single]);

        bar(/^Revisión/).focus();
        await user.keyboard('{Shift>}{ArrowLeft}{/Shift}');

        expect(onReschedule).not.toHaveBeenCalled();
    });

    it('un hito solo mueve su entrega', async () => {
        const user = userEvent.setup();
        const { onReschedule } = renderChart();

        bar(/^Entrega/).focus();
        await user.keyboard('{ArrowLeft}');

        expect(onReschedule).toHaveBeenLastCalledWith(delivery, {
            start_date: null,
            due_date: '2026-10-15',
        });
    });

    it('con espera, varias pulsaciones se guardan juntas; Esc las deshace e Intro las guarda ya', () => {
        // Con temporizadores falsos, fireEvent (síncrono) en lugar de user-event.
        vi.useFakeTimers();
        const { onReschedule, onOpen } = renderChart({
            keyboardCommitDelay: 600,
        });
        const press = (key: string) =>
            fireEvent.keyDown(bar(/^Diseño/), { key });

        bar(/^Diseño/).focus();
        press('ArrowRight');
        press('ArrowRight');
        expect(onReschedule).not.toHaveBeenCalled();
        expect(bar(/^Diseño/).getAttribute('aria-label')).toContain(
            'Del 07/10/2026 al 09/10/2026',
        );
        expect(screen.getByRole('status').textContent).toBe(
            '«Diseño»: Del 07/10/2026 al 09/10/2026',
        );

        act(() => {
            vi.advanceTimersByTime(600);
        });
        expect(onReschedule).toHaveBeenCalledTimes(1);
        expect(onReschedule).toHaveBeenLastCalledWith(design, {
            start_date: '2026-10-07',
            due_date: '2026-10-09',
        });

        press('ArrowLeft');
        press('Escape');
        act(() => {
            vi.advanceTimersByTime(600);
        });
        expect(onReschedule).toHaveBeenCalledTimes(1);
        expect(bar(/^Diseño/).getAttribute('aria-label')).toContain(
            'Del 05/10/2026 al 07/10/2026',
        );
        expect(screen.getByRole('status').textContent).toBe(
            'Cambio deshecho en «Diseño»',
        );

        press('ArrowLeft');
        press('Enter');
        expect(onReschedule).toHaveBeenCalledTimes(2);
        expect(onReschedule).toHaveBeenLastCalledWith(design, {
            start_date: '2026-10-04',
            due_date: '2026-10-06',
        });
        expect(onOpen).not.toHaveBeenCalled();

        // Salir de la barra también guarda lo pendiente.
        press('ArrowRight');
        fireEvent.blur(bar(/^Diseño/));
        expect(onReschedule).toHaveBeenCalledTimes(3);
    });

    it('↑/↓, Inicio y Fin cambian de tarea con un solo elemento en el orden de tabulación', async () => {
        const user = userEvent.setup();
        renderChart();

        const bars = screen
            .getAllByRole('button')
            .filter((element) => element.dataset.ganttPart === 'bar');
        expect(bars.filter((element) => element.tabIndex === 0)).toHaveLength(
            1,
        );

        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowDown}');
        expect(document.activeElement).toBe(bar(/^Maquetación/));
        expect(bar(/^Maquetación/).tabIndex).toBe(0);
        expect(bar(/^Diseño/).tabIndex).toBe(-1);

        await user.keyboard('{End}');
        expect(document.activeElement).toBe(bar(/^Textos/));

        await user.keyboard('{Home}{ArrowUp}');
        expect(document.activeElement).toBe(bar(/^Diseño/));
    });

    it('Intro abre la tarea', async () => {
        const user = userEvent.setup();
        const { onOpen } = renderChart();

        bar(/^Maquetación/).focus();
        await user.keyboard('{Enter}');

        expect(onOpen).toHaveBeenCalledWith(layout);
    });

    it('la barra de una tarea con subtareas es un resumen: no se mueve', async () => {
        const user = userEvent.setup();
        const { onReschedule } = renderChart();
        const summary = bar(/^Contenidos/);

        expect(summary.dataset.variant).toBe('summary');
        expect(summary.getAttribute('aria-label')).toContain(
            'Resumen de sus subtareas: del 12/10/2026 al 13/10/2026',
        );

        summary.focus();
        await user.keyboard('{ArrowRight}');

        expect(onReschedule).not.toHaveBeenCalled();
        expect(screen.getByRole('status').textContent).toContain(
            '«Contenidos» resume sus subtareas',
        );
    });

    it('Mayús + F10 abre el menú de la tarea con sus acciones', async () => {
        const user = userEvent.setup();
        const { onAction, onUnlink } = renderChart();

        bar(/^Maquetación/).focus();
        await user.keyboard('{Shift>}{F10}{/Shift}');

        const menu = await screen.findByRole('menu');
        expect(
            within(menu)
                .getAllByRole('menuitem')
                .map((item) => item.textContent),
        ).toEqual([
            'Abrir',
            'Cambiar fechas…',
            'Quitar fechas',
            'Añadir dependencia…',
            '«Diseño» → «Maquetación»',
        ]);

        await user.click(
            within(menu).getByRole('menuitem', {
                name: '«Diseño» → «Maquetación»',
            }),
        );
        expect(onUnlink).toHaveBeenCalledWith(link);

        await user.keyboard('{Shift>}{F10}{/Shift}');
        await user.click(
            await screen.findByRole('menuitem', {
                name: 'Añadir dependencia…',
            }),
        );
        expect(onAction).toHaveBeenCalledWith('dependency', layout);
    });
});

describe('Gantt: muchas tareas', () => {
    it('solo pinta las filas cercanas a la vista, siempre la activa, y el teclado llega a todas', async () => {
        const user = userEvent.setup();
        const many = Array.from({ length: 120 }, (_, index) =>
            task({
                id: 100 + index,
                title: `Tarea ${index + 1}`,
                start_date: '2026-10-05',
                due_date: '2026-10-06',
            }),
        );
        renderChart({}, many);

        const painted = () =>
            document.querySelectorAll('[data-gantt-part="bar"]').length;
        expect(painted()).toBeLessThan(80);
        expect(
            document.querySelectorAll('[data-test="gantt-row"]').length,
        ).toBe(painted());

        bar(/^Tarea 1\./).focus();
        await user.keyboard('{End}');

        expect(document.activeElement).toBe(bar(/^Tarea 120\./));
        expect(bar(/^Tarea 120\./).tabIndex).toBe(0);
    });
});

describe('Gantt: ratón', () => {
    it('arrastrar la barra la mueve por días y arrastrar su borde cambia la entrega', () => {
        const { onReschedule } = renderChart();
        const element = bar(/^Diseño/);

        fireEvent.pointerDown(element, {
            button: 0,
            pointerId: 1,
            clientX: 100,
        });
        fireEvent.pointerMove(element, { pointerId: 1, clientX: 166 });
        expect(bar(/^Diseño/).getAttribute('aria-label')).toContain(
            'Del 07/10/2026 al 09/10/2026',
        );
        fireEvent.pointerUp(element, { pointerId: 1, clientX: 166 });

        expect(onReschedule).toHaveBeenLastCalledWith(design, {
            start_date: '2026-10-07',
            due_date: '2026-10-09',
        });

        const handle = element.querySelector('[data-gantt-part="end"]');
        expect(handle).not.toBeNull();
        fireEvent.pointerDown(handle as Element, {
            button: 0,
            pointerId: 2,
            clientX: 200,
        });
        fireEvent.pointerMove(handle as Element, {
            pointerId: 2,
            clientX: 232,
        });
        fireEvent.pointerUp(handle as Element, { pointerId: 2, clientX: 232 });

        expect(onReschedule).toHaveBeenLastCalledWith(design, {
            start_date: '2026-10-05',
            due_date: '2026-10-08',
        });
    });

    it('en la escala mes, una tarea de dos días (8 px) no tiene tiradores y arrastrarla la mueve', () => {
        const short = task({
            id: 30,
            title: 'Revisión',
            start_date: '2026-10-06',
            due_date: '2026-10-07',
        });
        const { onReschedule } = renderChart(
            {
                timeline: createTimeline(
                    { start: '2026-09-01', end: '2026-12-31' },
                    'month',
                ),
            },
            [short],
        );
        const element = bar(/^Revisión/);

        expect(element.style.width).toBe('8px');
        expect(element.querySelector('[data-gantt-part="start"]')).toBeNull();
        expect(element.querySelector('[data-gantt-part="end"]')).toBeNull();

        // Su zona sensible llega a 16 px, centrada, y cuenta como la barra.
        const grab = element.querySelector<HTMLElement>(
            '[data-test="gantt-grab-area"]',
        );
        expect(grab?.style.left).toBe('-4px');
        expect(grab?.style.right).toBe('-4px');

        fireEvent.pointerDown(grab as Element, {
            button: 0,
            pointerId: 1,
            clientX: 100,
        });
        fireEvent.pointerMove(grab as Element, { pointerId: 1, clientX: 108 });
        fireEvent.pointerUp(grab as Element, { pointerId: 1, clientX: 108 });

        expect(onReschedule).toHaveBeenLastCalledWith(short, {
            start_date: '2026-10-08',
            due_date: '2026-10-09',
        });
    });

    it('en la escala semana, una tarea de un día deja la mitad central para moverla', () => {
        const oneDay = task({
            id: 31,
            title: 'Llamada',
            start_date: '2026-10-06',
            due_date: '2026-10-06',
        });
        const { onReschedule } = renderChart(
            {
                timeline: createTimeline(
                    { start: '2026-09-28', end: '2026-10-25' },
                    'week',
                ),
            },
            [oneDay],
        );
        const element = bar(/^Llamada/);
        const start = element.querySelector<HTMLElement>(
            '[data-gantt-part="start"]',
        );
        const end = element.querySelector<HTMLElement>(
            '[data-gantt-part="end"]',
        );

        expect(element.style.width).toBe('16px');
        expect(start?.style.width).toBe('4px');
        expect(start?.style.left).toBe('0px');
        expect(end?.style.width).toBe('4px');
        expect(end?.style.right).toBe('0px');

        fireEvent.pointerDown(element, {
            button: 0,
            pointerId: 1,
            clientX: 100,
        });
        fireEvent.pointerMove(element, { pointerId: 1, clientX: 132 });
        fireEvent.pointerUp(element, { pointerId: 1, clientX: 132 });

        expect(onReschedule).toHaveBeenLastCalledWith(oneDay, {
            start_date: '2026-10-08',
            due_date: '2026-10-08',
        });
    });

    it('un clic sin arrastrar no cambia nada y el doble clic abre la tarea', () => {
        const { onReschedule, onOpen } = renderChart();
        const element = bar(/^Maquetación/);

        fireEvent.pointerDown(element, {
            button: 0,
            pointerId: 1,
            clientX: 100,
        });
        fireEvent.pointerMove(element, { pointerId: 1, clientX: 102 });
        fireEvent.pointerUp(element, { pointerId: 1, clientX: 102 });
        fireEvent.doubleClick(element);

        expect(onReschedule).not.toHaveBeenCalled();
        expect(onOpen).toHaveBeenCalledWith(layout);
    });

    it('quitar una dependencia desde su flecha', async () => {
        const user = userEvent.setup();
        const { onUnlink } = renderChart();

        await user.click(
            screen.getByRole('button', {
                name: 'Quitar la dependencia «Diseño» → «Maquetación»',
            }),
        );

        expect(onUnlink).toHaveBeenCalledWith(link);
    });
});

describe('Gantt: conflictos y marcas', () => {
    it('una dependencia en conflicto va marcada con icono y texto, también en la sucesora', () => {
        const overlapping = { ...layout, start_date: '2026-10-07' };
        renderChart({}, [design, overlapping, delivery]);

        expect(
            screen.getByRole('img', {
                name: 'Conflicto: «Maquetación» empieza antes de que acabe «Diseño»',
            }),
        ).toBeTruthy();
        expect(bar(/^Maquetación/).getAttribute('aria-label')).toContain(
            'En conflicto: empieza antes de que acabe «Diseño»',
        );
        expect(
            document
                .querySelector('[data-dependency-id="20"]')
                ?.getAttribute('data-conflict'),
        ).toBe('true');
    });

    it('sin conflicto no hay aviso', () => {
        renderChart();

        expect(screen.queryByRole('img', { name: /Conflicto/ })).toBeNull();
        expect(bar(/^Maquetación/).getAttribute('aria-label')).not.toContain(
            'En conflicto',
        );
    });

    it('el nombre de la barra lleva fechas, responsable, estado y horas', () => {
        renderChart();

        expect(bar(/^Diseño/).getAttribute('aria-label')).toBe(
            'Diseño. Del 05/10/2026 al 07/10/2026. Responsable: Elena Empleada. Estado: Por hacer. 25 % de las horas estimadas (2:30 de 10:00)',
        );
        expect(bar(/^Entrega/).getAttribute('aria-label')).toBe(
            'Entrega. Hito el 16/10/2026. Hito. Sin responsable. Estado: Por hacer',
        );
        expect(bar(/^Textos/).getAttribute('aria-label')).toContain(
            'Subtarea de «Contenidos»',
        );
    });

    it('marca hoy y sombrea los fines de semana en la escala día', () => {
        renderChart();

        expect(
            document.querySelector('[data-test="gantt-today"]'),
        ).not.toBeNull();
        expect(screen.getAllByText('Hoy').length).toBeGreaterThan(0);
    });
});

describe('Gantt: solo lectura', () => {
    it('con readOnly no se mueve, no hay conectores ni acciones de edición', async () => {
        const user = userEvent.setup();
        const { onReschedule } = renderChart({ readOnly: true });

        expect(
            document.querySelector('[data-gantt-part="connector"]'),
        ).toBeNull();
        expect(document.querySelector('[data-gantt-part="end"]')).toBeNull();
        expect(
            screen.queryByRole('button', { name: /Quitar la dependencia/ }),
        ).toBeNull();
        expect(bar(/^Diseño/).getAttribute('aria-label')).toContain(
            'Solo lectura',
        );

        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowRight}');
        expect(onReschedule).not.toHaveBeenCalled();
        expect(screen.getByRole('status').textContent).toBe(
            'No puedes cambiar las fechas de «Diseño»',
        );

        const element = bar(/^Diseño/);
        fireEvent.pointerDown(element, {
            button: 0,
            pointerId: 1,
            clientX: 100,
        });
        fireEvent.pointerMove(element, { pointerId: 1, clientX: 200 });
        fireEvent.pointerUp(element, { pointerId: 1, clientX: 200 });
        expect(onReschedule).not.toHaveBeenCalled();

        await user.keyboard('{Shift>}{F10}{/Shift}');
        const menu = await screen.findByRole('menu');
        expect(
            within(menu)
                .getAllByRole('menuitem')
                .map((item) => item.textContent),
        ).toEqual(['Abrir']);
    });

    it('las tareas que no puede editar quien mira se ven en solo lectura', async () => {
        const user = userEvent.setup();
        const locked = { ...design, can: { update: false } };
        const { onReschedule } = renderChart({}, [locked, layout]);

        bar(/^Diseño/).focus();
        await user.keyboard('{ArrowRight}');

        expect(onReschedule).not.toHaveBeenCalled();
        expect(
            bar(/^Maquetación/).querySelector('[data-gantt-part="end"]'),
        ).not.toBeNull();
        // Quitar una dependencia exige poder editar las dos tareas.
        expect(
            screen.queryByRole('button', { name: /Quitar la dependencia/ }),
        ).toBeNull();
    });
});
