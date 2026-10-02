// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import {
    MyMilestones,
    ProjectMilestonesCard,
    relativeDays,
} from '@/components/planning/milestone-list';
import type {
    HomeMilestone,
    MilestoneItem,
    ProjectMilestones,
} from '@/types/planning';

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

function milestone(
    id: number,
    title: string,
    due: string,
    days: number,
): MilestoneItem {
    return {
        id,
        project_id: 7,
        title,
        due_date: due,
        is_overdue: days < 0,
        days,
    };
}

function projectData(
    overrides: Partial<ProjectMilestones> = {},
): ProjectMilestones {
    return {
        overdue: [milestone(1, 'Maquetas aprobadas', '2026-10-06', -7)],
        overdue_total: 1,
        upcoming: [
            milestone(2, 'Publicación', '2026-10-13', 0),
            milestone(3, 'Formación', '2026-10-14', 1),
            milestone(4, 'Cierre', '2026-11-02', 20),
        ],
        undated_count: 2,
        today: '2026-10-13',
        ...overrides,
    };
}

describe('días hasta la entrega', () => {
    it('en palabras', () => {
        expect(relativeDays(0)).toBe('Hoy');
        expect(relativeDays(1)).toBe('Mañana');
        expect(relativeDays(-1)).toBe('Ayer');
        expect(relativeDays(5)).toBe('En 5 días');
        expect(relativeDays(-7)).toBe('Hace 7 días');
    });
});

describe('«Próximos hitos» del resumen del proyecto', () => {
    it('destaca los vencidos con icono y texto y enlaza cada hito a su tarea', () => {
        render(
            <ProjectMilestonesCard projectId={7} milestones={projectData()} />,
        );

        const overdue = screen.getByRole('list', { name: 'Vencidos (1)' });
        expect(overdue.textContent).toContain('Maquetas aprobadas');
        expect(
            within(overdue)
                .getByText('Vencido', { exact: false })
                .closest('[data-test="milestone-overdue"]'),
        ).toBeTruthy();
        expect(overdue.textContent).toContain('Hace 7 días');
        expect(overdue.textContent).toContain('Entrega el 06/10/2026');

        const upcoming = screen.getByRole('list', { name: 'Siguientes' });
        const items = within(upcoming).getAllByRole('listitem');
        expect(items.map((item) => item.textContent)).toEqual([
            expect.stringContaining('Publicación'),
            expect.stringContaining('Formación'),
            expect.stringContaining('Cierre'),
        ]);
        expect(items[0].textContent).toContain('Hoy');
        expect(items[1].textContent).toContain('Mañana');
        expect(
            within(upcoming).queryByText('Vencido', { exact: false }),
        ).toBeNull();

        expect(
            screen
                .getByRole('link', { name: 'Publicación' })
                .getAttribute('href'),
        ).toBe('/proyectos/7/tareas?tarea=2');
        expect(screen.getByText('Hitos sin fecha: 2.')).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: 'Ver el calendario' })
                .getAttribute('href'),
        ).toBe('/proyectos/7/tareas?vista=calendario');
    });

    it('si hay más vencidos de los que se enseñan, lo dice', () => {
        render(
            <ProjectMilestonesCard
                projectId={7}
                milestones={projectData({ overdue_total: 14 })}
            />,
        );

        expect(
            screen.getByText('Se enseñan los 1 más atrasados de 14.'),
        ).toBeTruthy();
        expect(
            screen.getByRole('list', { name: 'Vencidos (14)' }),
        ).toBeTruthy();
    });

    it('sin hitos, estado vacío (y si solo hay sin fecha, lo dice)', () => {
        const { rerender } = render(
            <ProjectMilestonesCard
                projectId={7}
                milestones={projectData({
                    overdue: [],
                    overdue_total: 0,
                    upcoming: [],
                    undated_count: 0,
                })}
            />,
        );

        expect(screen.getByText('No hay hitos próximos')).toBeTruthy();
        expect(
            screen.getByText(
                'Marca una tarea como hito en su panel para seguirla aquí.',
            ),
        ).toBeTruthy();

        rerender(
            <ProjectMilestonesCard
                projectId={7}
                milestones={projectData({
                    overdue: [],
                    overdue_total: 0,
                    upcoming: [],
                    undated_count: 3,
                })}
            />,
        );

        expect(
            screen.getByText(
                'Hay hitos sin fecha: 3. Ponles fecha para verlos aquí.',
            ),
        ).toBeTruthy();
    });
});

describe('«Mis próximos hitos» de Inicio', () => {
    const mine: HomeMilestone[] = [
        {
            ...milestone(1, 'Entrega de la home', '2026-10-10', -3),
            project: {
                id: 7,
                code: 'ACME-WEB',
                name: 'Web de Acme',
                color: '#0171FF',
            },
        },
        {
            ...milestone(2, 'Lanzamiento', '2026-10-20', 7),
            project_id: 8,
            project: {
                id: 8,
                code: 'BETA',
                name: 'Beta',
                color: '#179FA5',
            },
        },
    ];

    it('lista mis hitos con su proyecto, fecha y enlace a la tarea', () => {
        render(<MyMilestones milestones={mine} />);

        const list = screen.getByRole('list', { name: 'Mis próximos hitos' });
        const items = within(list).getAllByRole('listitem');
        expect(items).toHaveLength(2);
        expect(items[0].textContent).toContain('ACME-WEB');
        expect(items[0].textContent).toContain('Vencido');
        expect(items[1].textContent).toContain('BETA');
        expect(items[1].textContent).toContain('En 7 días');
        expect(
            screen
                .getByRole('link', { name: 'Lanzamiento' })
                .getAttribute('href'),
        ).toBe('/proyectos/8/tareas?tarea=2');
    });

    it('sin hitos, estado vacío', () => {
        render(<MyMilestones milestones={[]} />);

        expect(
            screen.getByText('No tienes hitos en los próximos 30 días'),
        ).toBeTruthy();
    });
});
