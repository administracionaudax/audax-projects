// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { WorkloadCellPanel } from '@/components/workload/workload-cell-panel';
import {
    changedValues,
    WorkloadTaskEditor,
} from '@/components/workload/workload-task-editor';
import { PEOPLE, allByTest, panel, task } from './workload-fixtures';

// La matriz y los paneles completos tardan en jsdom: margen para las máquinas cargadas (CI).
vi.setConfig({ testTimeout: 20_000 });

type Options = {
    onError?: (errors: Record<string, string>) => void;
    onSuccess?: () => void;
    onStart?: () => void;
    onFinish?: () => void;
};

const server = vi.hoisted(() => ({
    patch: vi.fn<(url: string, data: unknown, options: Options) => void>(),
}));

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
    router: {
        patch: (url: string, data: unknown, options: Options) =>
            server.patch(url, data, options),
        on: () => () => {},
    },
}));

beforeEach(() => {
    server.patch.mockReset();
});

function renderPanel(cell = panel()) {
    render(
        <WorkloadCellPanel
            cell={cell}
            loading={false}
            byWeek={false}
            people={PEOPLE}
            onClose={() => {}}
        />,
    );

    return screen.getByRole('dialog');
}

describe('panel de una celda', () => {
    it('lista las tareas que forman la carga con sus minutos de ese día, proyecto, bolsa, restante y fechas', () => {
        const dialog = renderPanel();
        const [first, second] = allByTest('workload-cell-task', dialog);

        // Solo la primera letra en mayúscula (no «Semana Del … Al …»).
        expect(within(dialog).getByText('Martes 13/10/2026')).toBeTruthy();
        expect(
            within(dialog).getByText('Tareas que forman esta carga (2)'),
        ).toBeTruthy();
        expect(within(first).getByText('5:00 este día')).toBeTruthy();
        expect(
            within(first)
                .getByRole('link', {
                    name: 'Pantalla de reservas (abrir la tarea)',
                })
                .getAttribute('href'),
        ).toBe('/proyectos/7/tareas?tarea=41');
        expect(
            within(first).getByText('APP · App de citas · Beta'),
        ).toBeTruthy();
        expect(within(first).getByText('Sin bolsa')).toBeTruthy();
        expect(within(first).getByText('Quedan 10:00 de 10:00')).toBeTruthy();
        expect(within(first).getByText('13/10/2026 – 14/10/2026')).toBeTruthy();
        expect(within(second).getByText('Bolsa 2026')).toBeTruthy();
    });

    it('señala las tareas vencidas y avisa de que su restante cuenta hoy', () => {
        const dialog = renderPanel(
            panel({
                overdue: true,
                tasks: [task({ overdue: true, due_date: '2026-10-02' })],
            }),
        );

        expect(within(dialog).getByText('Vencida')).toBeTruthy();
        expect(
            within(dialog).getByText(
                'Incluye tareas vencidas: su restante se cuenta hoy.',
            ),
        ).toBeTruthy();
    });

    it('una celda semanal enseña el detalle de cada día con el motivo de los grises', () => {
        const dialog = renderPanel(
            panel({
                from: '2026-10-12',
                to: '2026-10-18',
                days: [
                    {
                        date: '2026-10-12',
                        planned: 0,
                        capacity: 0,
                        base: 480,
                        reason: {
                            type: 'holiday',
                            label: 'Fiesta Nacional de España',
                        },
                        reduced: null,
                    },
                    {
                        date: '2026-10-13',
                        planned: 600,
                        capacity: 480,
                        base: 480,
                        reason: null,
                        reduced: null,
                    },
                ],
            }),
        );

        const days = within(dialog).getByRole('table', {
            name: 'Carga y capacidad de cada día de la semana',
        });
        const rows = within(days).getAllByRole('row');
        // Cada día: su nombre, el festivo y el texto completo del semáforo para lectores de pantalla.
        expect(within(rows[1]).getByRole('rowheader').textContent).toContain(
            'Lunes 12/10/2026',
        );
        expect(
            within(rows[1]).getByText(
                'Festivo: Fiesta Nacional de España. Sin capacidad, 0:00 planificadas',
            ),
        ).toBeTruthy();
        expect(
            within(rows[2]).getByText(
                '10:00 planificadas de 8:00 de capacidad (125 %): Sobrecarga',
            ),
        ).toBeTruthy();
        expect(
            within(dialog).getAllByText(/este día|esta semana/)[0].textContent,
        ).toBe('5:00 esta semana');
    });

    it('sin tareas, un estado vacío que explica qué suma carga', () => {
        const dialog = renderPanel(panel({ tasks: [], planned: 0 }));

        expect(
            within(dialog).getByText('No hay tareas planificadas este día'),
        ).toBeTruthy();
    });

    it('las tareas que no puede cambiar quedan en solo lectura', () => {
        const dialog = renderPanel(
            panel({ tasks: [task({ can_edit: false, assignee_ids: null })] }),
        );

        expect(
            within(dialog).getByText(
                'Solo lectura: no puedes cambiar esta tarea.',
            ),
        ).toBeTruthy();
        expect(
            within(dialog).queryByRole('button', {
                name: /Reasignar|Replanificar/,
            }),
        ).toBeNull();
    });

    it('reasigna una tarea a alguien con hueco y guarda solo lo que cambia', async () => {
        const user = userEvent.setup();
        const dialog = renderPanel();
        const [first] = allByTest('workload-cell-task', dialog);

        await user.click(
            within(first).getByRole('button', {
                name: 'Reasignar o replanificar',
            }),
        );
        await user.click(
            within(first).getByRole('combobox', { name: 'Responsable' }),
        );

        const options = await screen.findAllByRole('option');
        expect(
            options.map((option) => option.textContent?.replace(/\s/g, ' ')),
        ).toEqual([
            'Sin responsable',
            expect.stringContaining('Elena Empleada'),
            expect.stringContaining('Lucía Martín'),
            expect.stringContaining('Raúl Responsable (tú)'),
        ]);
        expect(options[3].textContent).toContain('0:00 / 32:00');

        await user.click(options[3]);
        await user.click(
            within(first).getByRole('button', { name: 'Guardar' }),
        );

        expect(server.patch).toHaveBeenCalledTimes(1);
        const [url, data] = server.patch.mock.calls[0];
        expect(url).toBe('/carga/tareas/41');
        expect(data).toEqual({ assignee_user_id: 2 });
    });
});

describe('foco tras guardar', () => {
    it('va al título de la lista de tareas del panel (la tarea puede haber salido de la celda)', async () => {
        const user = userEvent.setup();
        server.patch.mockImplementation((_url, _data, options) =>
            options.onSuccess?.(),
        );
        const dialog = renderPanel();
        const [first] = allByTest('workload-cell-task', dialog);

        await user.click(
            within(first).getByRole('button', {
                name: 'Reasignar o replanificar',
            }),
        );
        const estimate = within(first).getByRole('textbox', {
            name: 'Estimación',
        });
        await user.clear(estimate);
        await user.type(estimate, '4');
        await user.click(
            within(first).getByRole('button', { name: 'Guardar' }),
        );

        expect(document.activeElement).toBe(
            within(dialog).getByRole('heading', {
                name: 'Tareas que forman esta carga (2)',
            }),
        );
    });
});

describe('editor de la tarea', () => {
    it('sin permiso para repartir, cambia fechas y estimación pero no el responsable', async () => {
        const user = userEvent.setup();
        render(
            <WorkloadTaskEditor
                task={task({ assignee_ids: null })}
                people={PEOPLE}
            />,
        );

        expect(
            screen.queryByRole('combobox', { name: 'Responsable' }),
        ).toBeNull();
        expect(
            screen.getByText(
                /El responsable lo cambia quien reparte el trabajo/,
            ),
        ).toBeTruthy();

        const save = screen.getByRole('button', { name: 'Guardar' });
        expect(save).toHaveProperty('disabled', true);

        const estimate = screen.getByRole('textbox', { name: 'Estimación' });
        await user.clear(estimate);
        await user.type(estimate, '6');
        await user.click(save);

        const [url, data] = server.patch.mock.calls[0];
        expect(url).toBe('/carga/tareas/41');
        expect(data).toEqual({ estimated_minutes: 360 });
    });

    it('enseña los errores de validación del servidor junto a su campo', async () => {
        const user = userEvent.setup();
        server.patch.mockImplementation((_url, _data, options) =>
            options.onError?.({
                due_date: 'La entrega no puede ser anterior al inicio.',
            }),
        );
        render(<WorkloadTaskEditor task={task()} people={PEOPLE} />);

        const estimate = screen.getByRole('textbox', { name: 'Estimación' });
        await user.clear(estimate);
        await user.type(estimate, '2');
        await user.click(screen.getByRole('button', { name: 'Guardar' }));

        expect(screen.getByRole('alert').textContent).toBe(
            'La entrega no puede ser anterior al inicio.',
        );
    });

    it('Deshacer vuelve a los valores de la tarea', async () => {
        const user = userEvent.setup();
        render(<WorkloadTaskEditor task={task()} people={PEOPLE} />);

        const estimate = screen.getByRole('textbox', { name: 'Estimación' });
        await user.clear(estimate);
        await user.type(estimate, '2');
        await user.click(screen.getByRole('button', { name: 'Deshacer' }));

        expect(screen.getByRole('button', { name: 'Guardar' })).toHaveProperty(
            'disabled',
            true,
        );
    });

    it('calcula solo los cambios (PATCH parcial)', () => {
        expect(
            changedValues(task(), {
                assignee_user_id: 3,
                start_date: '2026-10-13',
                due_date: '2026-10-20',
                estimated_minutes: 600,
            }),
        ).toEqual({ due_date: '2026-10-20' });
    });
});
