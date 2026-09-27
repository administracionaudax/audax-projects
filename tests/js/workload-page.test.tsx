// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { WorkloadTrays } from '@/components/workload/workload-trays';
import WorkloadIndex from '@/pages/workload';
import {
    ELENA,
    LUCIA,
    RAUL,
    allByTest,
    byTest,
    pageProps,
    panel,
} from './workload-fixtures';

// La matriz y los paneles completos tardan en jsdom: margen para las máquinas cargadas (CI).
vi.setConfig({ testTimeout: 20_000 });

type Options = {
    only?: string[];
    preserveState?: boolean;
    preserveScroll?: boolean;
    onFinish?: () => void;
};

const server = vi.hoisted(() => ({
    visit: vi.fn<(url: string, options: Options) => void>(),
    patch: vi.fn<(url: string, data: unknown, options: Options) => void>(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
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
    router: {
        visit: (url: string, options: Options) => server.visit(url, options),
        patch: (url: string, data: unknown, options: Options) =>
            server.patch(url, data, options),
        on: () => () => {},
    },
}));

beforeEach(() => {
    server.visit.mockReset();
    server.patch.mockReset();
});

describe('vista Carga', () => {
    it('quien ve a su equipo tiene la matriz, los filtros de persona y departamento y las dos bandejas', () => {
        render(<WorkloadIndex {...pageProps()} />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'Carga del equipo' }),
        ).toBeTruthy();
        expect(screen.getByRole('grid')).toBeTruthy();
        expect(
            screen.getByRole('combobox', { name: 'Departamento: Todos' }),
        ).toBeTruthy();
        expect(
            screen.getByRole('combobox', { name: 'Persona: Todos' }),
        ).toBeTruthy();
        expect(
            screen.getByRole('heading', { level: 2, name: /Sin planificar/ }),
        ).toBeTruthy();
        expect(
            screen.getByRole('heading', { level: 2, name: /Sin asignar/ }),
        ).toBeTruthy();
        expect(byTest('workload-range').textContent).toBe(
            'Del 12/10/2026 al 18/10/2026',
        );
    });

    it('quien solo ve su fila no tiene filtros de persona ni de departamento ni la bandeja «Sin asignar»', () => {
        const props = pageProps();

        render(
            <WorkloadIndex
                {...props}
                filters={{
                    ...props.filters,
                    sees_team: false,
                    sees_unassigned: false,
                }}
                matrix={{
                    ...props.matrix,
                    groups: [{ ...props.matrix.groups[1], people: [ELENA] }],
                }}
                trays={{
                    ...props.trays,
                    unassigned: { visible: false, total: 0, groups: [] },
                }}
            />,
        );

        expect(
            screen.getByRole('heading', { level: 1, name: 'Tu carga' }),
        ).toBeTruthy();
        expect(
            screen.queryByRole('combobox', { name: /Departamento/ }),
        ).toBeNull();
        expect(screen.queryByRole('combobox', { name: /Persona/ })).toBeNull();
        expect(screen.getByRole('combobox', { name: /Cliente/ })).toBeTruthy();
        expect(
            screen.queryByRole('heading', { level: 2, name: /Sin asignar/ }),
        ).toBeNull();
    });

    it('avisa de quién supera su capacidad, con icono y texto', () => {
        const props = pageProps();
        const over = { ...ELENA, total: { planned: 2400, capacity: 1920 } };

        render(
            <WorkloadIndex
                {...props}
                matrix={{
                    ...props.matrix,
                    groups: [{ ...props.matrix.groups[1], people: [over] }],
                }}
            />,
        );

        expect(byTest('workload-alerts').textContent?.replace(/\s/g, ' ')).toBe(
            'Sobrecarga (más del 120 %): 1. Elena Empleada, 125 % (40:00 de 32:00)',
        );
    });

    it('aunque el total cuadre, avisa de quién tiene días sobrecargados', () => {
        render(<WorkloadIndex {...pageProps()} />);

        expect(byTest('workload-alerts').textContent?.replace(/\s/g, ' ')).toBe(
            'Con días sobrecargados: 1. Elena Empleada (días o semanas: 2)',
        );
    });

    it('la carga sin capacidad (vencidas un día sin jornada) cuenta como sobrecarga', () => {
        const props = pageProps();
        const noCapacity = {
            ...LUCIA,
            cells: LUCIA.cells.map((cell) => ({ ...cell, planned: 0 })),
            total: { planned: 1800, capacity: 0 },
        };

        render(
            <WorkloadIndex
                {...props}
                matrix={{
                    ...props.matrix,
                    groups: [
                        {
                            ...props.matrix.groups[1],
                            people: [noCapacity, RAUL],
                        },
                    ],
                }}
            />,
        );

        expect(byTest('workload-alerts').textContent?.replace(/\s/g, ' ')).toBe(
            'Sobrecarga (más del 120 %): 1. Lucía Martín, 30:00 sin capacidad',
        );
    });

    it('si nadie va sobrecargado, lo dice', () => {
        const props = pageProps();

        render(
            <WorkloadIndex
                {...props}
                matrix={{
                    ...props.matrix,
                    groups: [{ ...props.matrix.groups[1], people: [RAUL] }],
                }}
            />,
        );

        expect(byTest('workload-alerts').textContent).toBe(
            'Nadie supera su capacidad en este horizonte.',
        );
    });

    it('cambiar el horizonte es una visita a la misma página con el horizonte en la URL', async () => {
        const user = userEvent.setup();
        render(<WorkloadIndex {...pageProps()} />);

        await user.click(
            screen.getByRole('radio', { name: 'Próximas 4 semanas' }),
        );

        const [url, options] = server.visit.mock.calls[0];
        expect(url).toBe('/carga?horizonte=4-semanas');
        expect(options).toMatchObject({
            preserveState: true,
            preserveScroll: true,
        });
    });

    it('abrir una celda trae solo el panel con una recarga parcial (?celda=persona:fecha)', async () => {
        const user = userEvent.setup();
        render(<WorkloadIndex {...pageProps()} />);

        await user.click(
            screen.getByRole('button', {
                name: /^Elena Empleada, martes 13\/10\/2026/,
            }),
        );

        const [url, options] = server.visit.mock.calls[0];
        expect(decodeURIComponent(url)).toBe(
            '/carga?horizonte=semana-que-viene&celda=3:2026-10-13',
        );
        expect(options).toMatchObject({ only: ['cell'], preserveState: true });
        // Mientras llega, el panel ya está abierto con su esqueleto.
        expect(
            screen.getByRole('status', {
                name: 'Cargando las tareas de la celda…',
            }),
        ).toBeTruthy();
    });

    it('con la celda abierta, el panel enseña sus tareas; al cerrarlo, se quita ?celda= de la URL', async () => {
        const user = userEvent.setup();
        render(<WorkloadIndex {...pageProps({ cell: panel() })} />);

        const dialog = screen.getByRole('dialog', { name: 'Elena Empleada' });
        expect(allByTest('workload-cell-task', dialog)).toHaveLength(2);
        // Con el panel (modal) abierto, el resto de la página queda oculto a los lectores.
        expect(
            screen
                .getByRole('button', {
                    name: /^Elena Empleada, martes 13\/10\/2026/,
                    hidden: true,
                })
                .getAttribute('aria-expanded'),
        ).toBe('true');

        await user.keyboard('{Escape}');

        const [url, options] = server.visit.mock.calls.at(-1) ?? [];
        expect(url).toBe('/carga?horizonte=semana-que-viene');
        expect(options).toMatchObject({ only: ['cell'] });
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('sin nadie con los filtros, un estado vacío con la opción de quitarlos', async () => {
        const user = userEvent.setup();
        const props = pageProps();

        render(
            <WorkloadIndex
                {...props}
                filters={{
                    ...props.filters,
                    query: { horizonte: 'semana-que-viene', persona: [99] },
                }}
                matrix={{ ...props.matrix, groups: [], totals: [] }}
            />,
        );

        expect(screen.getByText('No hay nadie que mostrar')).toBeTruthy();
        expect(screen.queryByRole('grid')).toBeNull();

        await user.click(
            within(
                screen.getByText('No hay nadie que mostrar').closest('div')!
                    .parentElement!,
            ).getByRole('button', {
                name: 'Quitar filtros',
            }),
        );

        expect(server.visit.mock.calls[0][0]).toBe(
            '/carga?horizonte=semana-que-viene',
        );
    });

    it('sin carga en el horizonte explica cuándo suma carga una tarea', () => {
        const props = pageProps();

        render(
            <WorkloadIndex
                {...props}
                matrix={{
                    ...props.matrix,
                    total: { planned: 0, capacity: 6480 },
                }}
            />,
        );

        expect(
            screen.getByText(/Una tarea suma carga cuando tiene responsable/),
        ).toBeTruthy();
    });
});

describe('bandejas', () => {
    it('«Sin planificar» dice qué le falta a cada tarea y «Sin asignar» señala las vencidas', () => {
        render(<WorkloadIndex {...pageProps()} />);

        const unplanned = byTest('workload-unplanned');
        expect(
            within(unplanned).getByText('Sin estimación', { selector: 'span' }),
        ).toBeTruthy();
        expect(within(unplanned).getByText('Elena Empleada')).toBeTruthy();

        const unassigned = byTest('workload-unassigned');
        expect(within(unassigned).getByText('Vencida')).toBeTruthy();
        expect(
            within(unassigned).getByText('Tareas: 1 · restante: 4:00'),
        ).toBeTruthy();
    });

    it('asignar una tarea de «Sin asignar» a alguien del equipo (eligiendo por su carga)', async () => {
        const user = userEvent.setup();
        const props = pageProps();
        render(
            <WorkloadTrays
                trays={props.trays}
                people={props.people}
                seesTeam
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Asignar: Banner de campaña' }),
        );
        await user.click(screen.getByRole('combobox', { name: 'Responsable' }));

        const lucia = await screen.findByRole('option', {
            name: /Lucía Martín/,
        });
        expect(lucia.textContent?.replace(/\s/g, ' ')).toContain(
            '8:00 / 16:00',
        );

        await user.click(lucia);
        await user.click(screen.getByRole('button', { name: 'Asignar' }));

        const [url, data] = server.patch.mock.calls[0];
        expect(url).toBe('/carga/tareas/60');
        expect(data).toEqual({ assignee_user_id: 4 });
    }, 15_000);
});
