// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PortalProjectsCard } from '@/components/portal/projects/portal-projects-card';
import {
    filterTasks,
    isOverdue,
    PortalTaskList,
} from '@/components/portal/projects/portal-task-list';
import type {
    PortalProjectListItem,
    PortalProjectShowProps,
    PortalTask,
    PortalTaskStatus,
} from '@/components/portal/projects/types';
import PortalProjectsIndex from '@/pages/portal/projects/index';
import PortalProjectShow from '@/pages/portal/projects/show';

const page = vi.hoisted(() => ({
    url: '/portal/proyectos/7',
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => page,
    router: { get: vi.fn(), post: vi.fn(), on: () => () => {} },
    Link: ({
        href,
        children,
        prefetch: _prefetch,
        ...rest
    }: {
        href: string | { url: string };
        children?: ReactNode;
        prefetch?: boolean;
        [key: string]: unknown;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
}));

const statuses: PortalTaskStatus[] = [
    { id: 1, name: 'Por hacer', color: '#56667A', category: 'todo' },
    { id: 2, name: 'En curso', color: '#0171FF', category: 'in_progress' },
    { id: 3, name: 'Hecha', color: '#1E7A4C', category: 'done' },
];

function task(overrides: Partial<PortalTask>): PortalTask {
    return {
        id: 0,
        parent_task_id: null,
        depth: 0,
        title: `Tarea ${overrides.id}`,
        status: statuses[0],
        start_date: null,
        due_date: null,
        is_milestone: false,
        is_completed: false,
        subtasks_count: 0,
        ...overrides,
    };
}

const briefing = task({
    id: 1,
    title: 'Briefing',
    status: statuses[2],
    is_completed: true,
    due_date: '2026-09-05',
});
const design = task({
    id: 2,
    title: 'Diseño de la home',
    status: statuses[1],
    due_date: '2026-09-18',
    subtasks_count: 1,
});
const mobile = task({
    id: 3,
    title: 'Maqueta móvil',
    parent_task_id: 2,
    depth: 1,
    due_date: '2026-10-30',
});
const launch = task({
    id: 4,
    title: 'Lanzamiento',
    is_milestone: true,
    due_date: '2026-10-30',
});
const tasks = [briefing, design, mobile, launch];

const totals = { tasks: 4, done: 1, open: 3, milestones: 1, minutes: null };

beforeEach(() => {
    page.url = '/portal/proyectos/7';
    page.props = {};
});

describe('tareas del proyecto en el portal', () => {
    it('lista tareas con estado, entrega e hitos, con las subtareas sangradas y sin horas si no se enseñan', () => {
        render(
            <PortalTaskList
                projectName="Web corporativa"
                tasks={tasks}
                statuses={statuses}
                showHours={false}
                totals={totals}
                today="2026-09-27"
            />,
        );

        const table = screen.getByRole('table', {
            name: 'Tareas de Web corporativa',
        });
        const headers = within(table)
            .getAllByRole('columnheader')
            .map((cell) => cell.textContent);
        expect(headers).toEqual(['Tarea', 'Estado', 'Entrega']);

        const rows = within(table).getAllByRole('row').slice(1);
        expect(rows).toHaveLength(4);
        expect(rows[0].textContent).toContain('Briefing');
        expect(rows[0].textContent).toContain('Hecha');
        expect(rows[1].textContent).toContain('1 subtarea');
        // Vencida: sin completar y con la entrega antes de hoy, con icono y texto.
        expect(rows[1].textContent).toContain('18/09/2026');
        expect(rows[1].textContent).toContain('Vencida');
        expect(rows[0].textContent).not.toContain('Vencida');
        expect(
            within(rows[2]).getByText('Subtarea de «Diseño de la home»')
                .className,
        ).toContain('sr-only');
        expect(rows[3].textContent).toContain('Hito');
        expect(table.textContent).not.toContain('0:00');

        const byStatus = screen.getByRole('list', {
            name: 'Tareas por estado',
        });
        expect(
            within(byStatus)
                .getAllByRole('listitem')
                .map((item) => item.textContent),
        ).toEqual(['Por hacer2', 'En curso1', 'Hecha1']);
    });

    it('con las horas por tarea, su columna y el total', () => {
        render(
            <PortalTaskList
                projectName="Web corporativa"
                tasks={[
                    { ...briefing, minutes: 0 },
                    { ...design, minutes: 210 },
                    { ...mobile, minutes: 30 },
                    { ...launch, minutes: 0 },
                ]}
                statuses={statuses}
                showHours
                totals={{ ...totals, minutes: 210 }}
                today="2026-09-27"
            />,
        );

        const table = screen.getByRole('table', {
            name: 'Tareas de Web corporativa',
        });
        expect(
            within(table).getByRole('columnheader', { name: 'Horas' }),
        ).toBeTruthy();
        expect(within(table).getAllByRole('row')[2].textContent).toContain(
            '3:30',
        );
        const total = within(table)
            .getByRole('rowheader', { name: 'Total' })
            .closest('tr');
        expect(total?.textContent).toContain('3:30');
        expect(
            screen.getByText(
                'Las horas de una tarea incluyen las de sus subtareas.',
            ),
        ).toBeTruthy();
    });

    it('filtra pendientes y completadas; una subtarea sin su tarea dice de cuál es', async () => {
        const user = userEvent.setup();
        render(
            <PortalTaskList
                projectName="Web"
                tasks={[
                    briefing,
                    { ...design, is_completed: true, status: statuses[2] },
                    mobile,
                    launch,
                ]}
                statuses={statuses}
                showHours={false}
                totals={{ ...totals, done: 2, open: 2 }}
                today="2026-09-27"
            />,
        );

        expect(
            screen.getByRole('radio', { name: 'Pendientes 2' }),
        ).toBeTruthy();
        await user.click(screen.getByRole('radio', { name: 'Pendientes 2' }));

        const rows = within(screen.getByRole('table'))
            .getAllByRole('row')
            .slice(1);
        expect(rows.map((row) => row.querySelector('th')?.textContent)).toEqual(
            ['Maqueta móvilSubtarea de «Diseño de la home»', 'LanzamientoHito'],
        );
        expect(
            within(rows[0]).getByText('Subtarea de «Diseño de la home»')
                .className,
        ).not.toContain('sr-only');

        await user.click(screen.getByRole('radio', { name: 'Completadas 2' }));
        expect(
            within(screen.getByRole('table')).getAllByRole('row'),
        ).toHaveLength(3);
    });

    it('estados vacíos: sin tareas y sin tareas en el filtro', async () => {
        const user = userEvent.setup();
        const { unmount } = render(
            <PortalTaskList
                projectName="Web"
                tasks={[]}
                statuses={statuses}
                showHours={false}
                totals={{
                    ...totals,
                    tasks: 0,
                    done: 0,
                    open: 0,
                    milestones: 0,
                }}
                today="2026-09-27"
            />,
        );
        expect(
            screen.getByText('Este proyecto todavía no tiene tareas'),
        ).toBeTruthy();
        unmount();

        render(
            <PortalTaskList
                projectName="Web"
                tasks={[briefing]}
                statuses={statuses}
                showHours={false}
                totals={{ ...totals, tasks: 1, done: 1, open: 0 }}
                today="2026-09-27"
            />,
        );
        await user.click(screen.getByRole('radio', { name: 'Pendientes 0' }));
        expect(screen.getByText('No hay tareas pendientes')).toBeTruthy();
    });

    it('filterTasks e isOverdue', () => {
        expect(filterTasks(tasks, 'done').map((item) => item.id)).toEqual([1]);
        expect(filterTasks(tasks, 'open').map((item) => item.id)).toEqual([
            2, 3, 4,
        ]);
        expect(isOverdue(design, '2026-09-19')).toBe(true);
        expect(isOverdue(design, '2026-09-18')).toBe(false);
        expect(isOverdue(briefing, '2026-12-01')).toBe(false);
    });
});

describe('página del proyecto en el portal', () => {
    const props: PortalProjectShowProps = {
        project: {
            id: 7,
            code: 'LUR-WEB',
            name: 'Web corporativa',
            status: 'active',
            start_date: '2026-09-01',
            due_date: '2026-12-18',
        },
        tasks,
        statuses,
        showHours: false,
        totals,
        gantt: true,
    };

    it('un solo h1, el resumen y la pestaña del Gantt si está abierto', () => {
        const { container } = render(<PortalProjectShow {...props} />);

        expect(container.querySelectorAll('h1')).toHaveLength(1);
        expect(screen.getByText('Del 01/09/2026 al 18/12/2026')).toBeTruthy();
        expect(screen.getByText('Activo')).toBeTruthy();
        expect(screen.getByText('1 de 4')).toBeTruthy();
        const tabs = screen.getByRole('navigation', {
            name: 'Secciones del proyecto',
        });
        expect(
            within(tabs)
                .getByRole('link', { name: 'Tareas' })
                .getAttribute('aria-current'),
        ).toBe('page');
        expect(
            within(tabs)
                .getByRole('link', { name: 'Gantt' })
                .getAttribute('href'),
        ).toBe('/portal/proyectos/7/gantt');
        expect(container.textContent).not.toContain('Responsable');
    });

    it('sin el Gantt abierto no hay pestañas; con horas, el resumen las enseña', () => {
        render(
            <PortalProjectShow
                {...props}
                gantt={false}
                showHours
                totals={{ ...totals, minutes: 125 }}
                tasks={tasks.map((item) => ({ ...item, minutes: 0 }))}
            />,
        );

        expect(
            screen.queryByRole('navigation', {
                name: 'Secciones del proyecto',
            }),
        ).toBeNull();
        expect(
            screen.getAllByRole('term').map((term) => term.textContent),
        ).toContain('Horas');
        expect(screen.getAllByText('2:05').length).toBeGreaterThan(0);
    });
});

describe('lista de proyectos del portal', () => {
    const project = (
        overrides: Partial<PortalProjectListItem>,
    ): PortalProjectListItem => ({
        id: 7,
        code: 'LUR-WEB',
        name: 'Web corporativa',
        status: 'active',
        start_date: null,
        due_date: '2026-12-18',
        view: true,
        gantt: true,
        progress: { done: 1, total: 4 },
        ...overrides,
    });

    it('cada proyecto con su avance y enlaces a lo que está abierto', () => {
        render(
            <PortalProjectsIndex
                projects={[
                    project({}),
                    project({
                        id: 8,
                        code: 'LUR-INT',
                        name: 'Intranet',
                        view: false,
                        progress: null,
                    }),
                ]}
            />,
        );

        const cards = screen.getAllByRole('listitem');
        expect(
            within(cards[0])
                .getByRole('link', { name: 'Web corporativa' })
                .getAttribute('href'),
        ).toBe('/portal/proyectos/7');
        expect(
            within(cards[0]).getByText('1 de 4 tareas completadas'),
        ).toBeTruthy();
        expect(
            within(cards[0]).getByRole('progressbar', {
                name: 'Avance de Web corporativa',
            }),
        ).toBeTruthy();
        expect(
            within(cards[0])
                .getByRole('link', { name: 'Ver el Gantt' })
                .getAttribute('href'),
        ).toBe('/portal/proyectos/7/gantt');
        // Solo el Gantt abierto: el nombre lleva al Gantt y no hay avance ni enlace a las tareas.
        expect(
            within(cards[1])
                .getByRole('link', { name: 'Intranet' })
                .getAttribute('href'),
        ).toBe('/portal/proyectos/8/gantt');
        expect(
            within(cards[1]).queryByRole('link', { name: 'Ver las tareas' }),
        ).toBeNull();
        expect(within(cards[1]).queryByRole('progressbar')).toBeNull();
    });

    it('sin proyectos abiertos, un estado vacío', () => {
        render(<PortalProjectsIndex projects={[]} />);

        expect(
            screen.getByText('Todavía no hay proyectos abiertos al portal'),
        ).toBeTruthy();
    });

    it('la tarjeta «Tus proyectos» del Inicio usa la prop compartida del portal', () => {
        page.props = {
            portal: {
                company: { name: 'Audax Studio', logo: null },
                projects: [
                    {
                        id: 7,
                        code: 'LUR-WEB',
                        name: 'Web corporativa',
                        view: true,
                        gantt: true,
                    },
                    {
                        id: 8,
                        code: 'LUR-INT',
                        name: 'Intranet',
                        view: false,
                        gantt: true,
                    },
                ],
            },
        };
        render(<PortalProjectsCard />);

        const card = screen
            .getByRole('heading', { name: 'Tus proyectos' })
            .closest('[data-test="portal-projects-card"]') as HTMLElement;
        expect(
            within(card)
                .getByRole('link', { name: /Web corporativa/ })
                .getAttribute('href'),
        ).toBe('/portal/proyectos/7');
        expect(
            within(card)
                .getByRole('link', { name: /Intranet/ })
                .getAttribute('href'),
        ).toBe('/portal/proyectos/8/gantt');
        expect(
            within(card)
                .getByRole('link', { name: 'Ver todos tus proyectos' })
                .getAttribute('href'),
        ).toBe('/portal/proyectos');
    });
});
