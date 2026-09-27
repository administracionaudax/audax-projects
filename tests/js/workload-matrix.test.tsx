// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { cellKey, WorkloadMatrix } from '@/components/workload/workload-matrix';
import { COLUMNS, pageProps } from './workload-fixtures';

// La matriz y los paneles completos tardan en jsdom: margen para las máquinas cargadas (CI).
vi.setConfig({ testTimeout: 20_000 });

function renderMatrix(
    overrides: Partial<Parameters<typeof WorkloadMatrix>[0]> = {},
) {
    const onOpen = vi.fn();
    const props = pageProps();

    render(
        <WorkloadMatrix
            matrix={props.matrix}
            byWeek={false}
            from="2026-10-12"
            to="2026-10-18"
            openKey={null}
            onOpen={onOpen}
            {...overrides}
        />,
    );

    return { onOpen, props };
}

/** Nombre accesible exacto (Intl separa «125 %» con un espacio duro). */
function named(expected: string) {
    return (name: string) => name.replace(/\s/g, ' ') === expected;
}

function cells() {
    return screen
        .getAllByRole('button')
        .filter((button) => button.hasAttribute('data-cell'));
}

describe('WorkloadMatrix: estructura y totales', () => {
    it('agrupa por departamento, con una fila por persona y los totales del departamento y de la vista', () => {
        renderMatrix();

        const grid = screen.getByRole('grid', {
            name: 'Horas planificadas frente a la capacidad por persona y día, del 12/10/2026 al 18/10/2026',
        });
        const headers = within(grid).getAllByRole('rowheader');

        expect(headers.map((header) => header.textContent?.trim())).toEqual([
            'Pablo Ruiz',
            'Total de Desarrollo',
            'Elena Empleada',
            'Lucía Martín',
            'Raúl Responsable Tú',
            'Total de Diseño',
            'Total de la vista',
        ]);
        expect(
            within(grid).getByRole('columnheader', {
                name: 'Desarrollo Personas: 1',
            }),
        ).toBeTruthy();
        // 4 personas × 5 días.
        expect(cells()).toHaveLength(20);
        expect(
            screen.getByText(
                (_, element) =>
                    element?.className === 'sr-only' &&
                    named(
                        'Total de Diseño en el horizonte: 38:00 planificadas de 80:00 de capacidad (48 %): Holgada',
                    )(element.textContent ?? ''),
            ),
        ).toBeTruthy();
    });

    it('cada columna de día lleva el día de la semana y la fecha para los lectores de pantalla', () => {
        renderMatrix();

        expect(
            screen.getByRole('columnheader', { name: /martes 13\/10\/2026/ }),
        ).toBeTruthy();
    });

    it('en el horizonte de 3 meses las columnas son semanas', () => {
        const { props } = renderMatrix({ byWeek: true });

        expect(
            screen.getAllByRole('columnheader', {
                name: /semana del 12\/10 al 12\/10\/2026/,
            }),
        ).toHaveLength(1);
        expect(props.matrix.columns).toHaveLength(5);
    });
});

describe('WorkloadMatrix: texto accesible de las celdas', () => {
    it('una celda sobrecargada dice quién, cuándo, las cifras, el nivel y que lleva vencidas', () => {
        renderMatrix();

        expect(
            screen.getByRole('button', {
                name: named(
                    'Elena Empleada, martes 13/10/2026. 10:00 planificadas de 8:00 de capacidad (125 %): Sobrecarga. Incluye tareas vencidas.',
                ),
            }),
        ).toBeTruthy();
    });

    it('una celda gris explica el motivo: festivo con su nombre o ausencia con su tipo', () => {
        renderMatrix();

        expect(
            screen.getByRole('button', {
                name: 'Elena Empleada, lunes 12/10/2026. Sin capacidad, 0:00 planificadas. Festivo: Fiesta Nacional de España.',
            }),
        ).toBeTruthy();
        expect(
            screen.getByRole('button', {
                name: 'Lucía Martín, martes 13/10/2026. Sin capacidad, 0:00 planificadas. Vacaciones.',
            }),
        ).toBeTruthy();
    });

    it('una ausencia parcial se explica como capacidad reducida', () => {
        renderMatrix();

        expect(
            screen.getByRole('button', {
                name: named(
                    'Pablo Ruiz, jueves 15/10/2026. 4:00 planificadas de 4:00 de capacidad (100 %): Equilibrada. Capacidad reducida: ausencia parcial: 4:00 (Formación externa).',
                ),
            }),
        ).toBeTruthy();
    });

    it('el color nunca va solo: la celda gris enseña el motivo en texto', () => {
        renderMatrix();

        const holiday = screen.getByRole('button', {
            name: /Elena Empleada, lunes 12\/10\/2026/,
        });

        expect(holiday.textContent).toContain('Festivo');
    });
});

describe('WorkloadMatrix: teclado', () => {
    it('tiene una sola parada de tabulación y las flechas mueven el foco entre las celdas', async () => {
        const user = userEvent.setup();
        renderMatrix();

        const focusable = cells().filter((cell) => cell.tabIndex === 0);
        expect(focusable).toHaveLength(1);
        expect(focusable[0].getAttribute('aria-label')).toMatch(
            /^Pablo Ruiz, lunes 12\/10\/2026/,
        );

        await user.tab();
        expect(document.activeElement).toBe(focusable[0]);

        await user.keyboard('{ArrowRight}');
        expect(document.activeElement?.getAttribute('aria-label')).toMatch(
            /^Pablo Ruiz, martes 13\/10\/2026/,
        );

        // Abajo cruza al siguiente departamento en la misma columna.
        await user.keyboard('{ArrowDown}');
        expect(document.activeElement?.getAttribute('aria-label')).toMatch(
            /^Elena Empleada, martes 13\/10\/2026/,
        );

        await user.keyboard('{End}');
        expect(document.activeElement?.getAttribute('aria-label')).toMatch(
            /^Elena Empleada, viernes 16\/10\/2026/,
        );

        await user.keyboard('{Home}');
        expect(document.activeElement?.getAttribute('aria-label')).toMatch(
            /^Elena Empleada, lunes 12\/10\/2026/,
        );

        await user.keyboard('{Control>}{End}{/Control}');
        expect(document.activeElement?.getAttribute('aria-label')).toMatch(
            /^Raúl Responsable, viernes 16\/10\/2026/,
        );

        // En los bordes no se sale de la matriz.
        await user.keyboard('{ArrowDown}{ArrowRight}');
        expect(document.activeElement?.getAttribute('aria-label')).toMatch(
            /^Raúl Responsable, viernes 16\/10\/2026/,
        );

        // Solo la celda activa sigue en el orden de tabulación.
        expect(cells().filter((cell) => cell.tabIndex === 0)).toEqual([
            document.activeElement,
        ]);
    });

    it('Intro, Espacio o un clic abren el panel de la celda', async () => {
        const user = userEvent.setup();
        const { onOpen, props } = renderMatrix();
        const elena = props.matrix.groups[1].people[0];

        const target = screen.getByRole('button', {
            name: /^Elena Empleada, martes 13\/10\/2026/,
        });
        target.focus();
        await user.keyboard('{Enter}');

        expect(onOpen).toHaveBeenLastCalledWith(elena, COLUMNS[1]);

        await user.keyboard(' ');
        expect(onOpen).toHaveBeenCalledTimes(2);

        await user.click(
            screen.getByRole('button', {
                name: /^Lucía Martín, jueves 15\/10\/2026/,
            }),
        );
        expect(onOpen).toHaveBeenLastCalledWith(
            props.matrix.groups[1].people[1],
            COLUMNS[3],
        );
    });

    it('marca la celda con el panel abierto y empieza el recorrido en ella', () => {
        renderMatrix({ openKey: cellKey(3, COLUMNS[2]) });

        const open = screen.getByRole('button', {
            name: /^Elena Empleada, miércoles 14\/10\/2026/,
        });

        expect(open.getAttribute('aria-expanded')).toBe('true');
        expect(open.tabIndex).toBe(0);
        expect(
            cells().filter(
                (cell) => cell.getAttribute('aria-expanded') === 'true',
            ),
        ).toHaveLength(1);
    });
});
