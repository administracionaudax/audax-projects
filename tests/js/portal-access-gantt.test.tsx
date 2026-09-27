// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { GanttView } from '@/components/gantt/gantt-view';
import type { GanttViewProps } from '@/components/gantt/gantt-view';
import { barLabel } from '@/components/gantt/labels';
import type { GanttTask, GanttTaskStatus } from '@/components/gantt/types';
import PortalProjectGantt from '@/pages/portal/projects/gantt';
import type { TaskDependencyItem } from '@/types/schedule';

vi.setConfig({ testTimeout: 20_000 });

const server = vi.hoisted(() => ({ visit: vi.fn(), post: vi.fn() }));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ url: '/portal/proyectos/7/gantt', props: {} }),
    router: {
        visit: server.visit,
        post: server.post,
        on: () => () => {},
    },
    Link: ({
        href,
        children,
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
}));

const statuses: GanttTaskStatus[] = [
    { id: 1, name: 'Por hacer', color: '#56667A', category: 'todo' },
    { id: 2, name: 'Hecha', color: '#1E7A4C', category: 'done' },
];

function task(overrides: Partial<GanttTask>): GanttTask {
    return {
        id: 0,
        project_id: 7,
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
        can: { update: false },
        ...overrides,
    };
}

// Aunque llegara un responsable (el portal nunca lo envía), hideAssignees no lo enseña.
const design = task({
    id: 1,
    title: 'Diseño',
    start_date: '2026-10-05',
    due_date: '2026-10-07',
    assignee: { id: 9, name: 'Elena Empleada', avatar: null },
});
const launch = task({
    id: 2,
    title: 'Lanzamiento',
    due_date: '2026-10-09',
    is_milestone: true,
});
const loose = task({ id: 3, title: 'Textos sin fecha' });
const link: TaskDependencyItem = {
    id: 20,
    predecessor_task_id: 1,
    successor_task_id: 2,
    type: 'finish_to_start',
};

function renderView(overrides: Partial<GanttViewProps> = {}) {
    return render(
        <GanttView
            label="Gantt de Web"
            tasks={[design, launch, loose]}
            dependencies={[link]}
            statuses={statuses}
            range={{ start: '2026-10-01', end: '2026-10-20' }}
            today="2026-10-06"
            preferences={{ scale: 'day', color: 'status' }}
            reload={[]}
            readOnly
            hideAssignees
            showUnscheduled
            {...overrides}
        />,
    );
}

function barLabels(): string[] {
    return [
        ...document.querySelectorAll<HTMLElement>('[data-gantt-part="bar"]'),
    ].map((element) => element.getAttribute('aria-label') ?? '');
}

describe('Gantt del portal: solo lectura y sin responsables (hideAssignees)', () => {
    it('no ofrece colorear por responsable ni nombra a nadie en las barras', () => {
        renderView();

        expect(
            screen.queryByRole('radio', { name: 'Por responsable' }),
        ).toBeNull();
        expect(screen.queryByRole('radio', { name: 'Por estado' })).toBeNull();
        expect(screen.getByRole('radio', { name: 'Semana' })).toBeTruthy();

        const labels = barLabels();
        expect(labels.length).toBeGreaterThanOrEqual(2);
        expect(labels.some((label) => label.startsWith('Diseño'))).toBe(true);
        for (const label of labels) {
            expect(label).not.toContain('Responsable');
            expect(label).not.toContain('Sin responsable');
            expect(label).not.toContain('Elena');
            expect(label).toContain('Solo lectura');
        }
        expect(document.body.textContent).not.toContain('Elena');
    });

    it('la tabla accesible no lleva la columna de responsable', async () => {
        const user = userEvent.setup();
        renderView();

        await user.click(screen.getByRole('radio', { name: 'Tabla' }));
        const table = screen.getByRole('table', { name: 'Gantt de Web' });
        const headers = within(table)
            .getAllByRole('columnheader')
            .map((cell) => cell.textContent);

        expect(headers).toEqual([
            'Tarea',
            'Inicio',
            'Entrega',
            'Predecesoras',
            'Estado',
        ]);
        expect(table.textContent).not.toContain('Elena');
        expect(table.textContent).not.toContain('Sin responsable');
    });

    it('sin hideAssignees, el Gantt interno sigue igual', async () => {
        const user = userEvent.setup();
        renderView({ readOnly: false, hideAssignees: false });

        expect(
            screen.getByRole('radio', { name: 'Por responsable' }),
        ).toBeTruthy();
        expect(
            barLabels().some((label) =>
                label.includes('Responsable: Elena Empleada'),
            ),
        ).toBe(true);

        await user.click(screen.getByRole('radio', { name: 'Tabla' }));
        expect(
            screen.getByRole('columnheader', { name: 'Responsable' }),
        ).toBeTruthy();
    });

    it('barLabel omite el responsable con hideAssignee', () => {
        const dates = {
            start_date: design.start_date,
            due_date: design.due_date,
        };

        expect(barLabel(design, dates)).toContain(
            'Responsable: Elena Empleada',
        );
        expect(barLabel(loose, { start_date: null, due_date: null })).toContain(
            'Sin responsable',
        );
        expect(barLabel(design, dates, { hideAssignee: true })).not.toContain(
            'Responsable',
        );
        expect(
            barLabel(
                loose,
                { start_date: null, due_date: null },
                { hideAssignee: true },
            ),
        ).not.toContain('Sin responsable');
    });

    it('abrir una tarea no navega: llama a onOpenTask', async () => {
        const user = userEvent.setup();
        const onOpenTask = vi.fn();
        renderView({ onOpenTask });

        await user.click(
            screen.getByRole('button', { name: 'Textos sin fecha' }),
        );

        expect(onOpenTask).toHaveBeenCalledWith(loose);
        expect(server.visit).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: /Asignar fechas/ }),
        ).toBeNull();
    });
});

describe('página del Gantt en el portal', () => {
    const props = {
        project: {
            id: 7,
            code: 'LUR-WEB',
            name: 'Web corporativa',
            status: 'active' as const,
            start_date: '2026-10-01',
            due_date: '2026-10-30',
        },
        tasks: [{ ...design, assignee: null }, launch, loose],
        dependencies: [link],
        statuses,
        range: { start: '2026-10-01', end: '2026-10-20' },
        preferences: { scale: 'day' as const, color: 'status' as const },
        today: '2026-10-06',
        view: true,
    };

    it('enseña la cabecera con las pestañas abiertas y el Gantt en solo lectura', () => {
        const { container } = render(<PortalProjectGantt {...props} />);

        expect(container.querySelectorAll('h1')).toHaveLength(1);
        expect(
            screen.getByRole('heading', { level: 1, name: 'Web corporativa' }),
        ).toBeTruthy();
        const tabs = screen.getByRole('navigation', {
            name: 'Secciones del proyecto',
        });
        expect(
            within(tabs)
                .getByRole('link', { name: 'Tareas' })
                .getAttribute('href'),
        ).toBe('/portal/proyectos/7');
        expect(
            within(tabs)
                .getByRole('link', { name: 'Gantt' })
                .getAttribute('aria-current'),
        ).toBe('page');
        expect(
            screen.getByRole('region', { name: 'Gantt de Web corporativa' }),
        ).toBeTruthy();
        expect(
            screen.queryByRole('radio', { name: 'Por responsable' }),
        ).toBeNull();
    });

    it('sin la vista del proyecto abierta, no hay pestañas', () => {
        render(<PortalProjectGantt {...props} view={false} />);

        expect(
            screen.queryByRole('navigation', {
                name: 'Secciones del proyecto',
            }),
        ).toBeNull();
    });

    it('una tarea se abre en un diálogo de solo lectura con sus dependencias', async () => {
        const user = userEvent.setup();
        render(<PortalProjectGantt {...props} />);

        await user.click(screen.getByRole('radio', { name: 'Tabla' }));
        await user.click(screen.getByRole('button', { name: 'Lanzamiento' }));

        const dialog = await screen.findByRole('dialog', {
            name: 'Lanzamiento',
        });
        expect(within(dialog).getByText('Hito el 09/10/2026')).toBeTruthy();
        expect(within(dialog).getByText('Diseño')).toBeTruthy();
        expect(within(dialog).queryByRole('textbox')).toBeNull();
        expect(dialog.textContent).not.toContain('Responsable');

        await user.click(
            within(dialog).getByRole('button', { name: 'Cerrar' }),
        );
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('sin tareas, un estado vacío', () => {
        render(<PortalProjectGantt {...props} tasks={[]} dependencies={[]} />);

        expect(
            screen.getByText('Este proyecto todavía no tiene tareas'),
        ).toBeTruthy();
    });
});
