// @vitest-environment jsdom
import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentProps } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TemplateEditor } from '@/components/templates/template-editor';
import type { EditorRow } from '@/components/templates/template-editor-state';
import {
    mapErrors,
    moveRow,
    rowLabels,
    rowsFromStructure,
    rowsMeta,
    toggleDependency,
    updateRow,
} from '@/components/templates/template-editor-state';
import type { TemplateStructure } from '@/types/templates';

/*
| Editor de plantillas en el límite (500 tareas, D-058). Antes, cada tecla recalculaba en cada fila
| lo de todas las demás (O(n³): unos 1,7 s con 500 tareas) y pintaba un <select> «Subtarea de» con
| todas las tareas en cada fila (250 000 <option>). Ahora:
|   1. lo que cada fila necesita de las demás se calcula una vez por render (rowsMeta), en
|      milisegundos con 500 tareas y 1990 dependencias,
|   2. al escribir en una fila solo se vuelven a pintar sus campos (filas memoizadas y acciones
|      estables): se cuenta con los campos de días de cada fila,
|   3. la lista de «Subtarea de» solo existe mientras está abierta: <option> lineales.
| Las comprobaciones del DOM usan 60 filas (pintar 500 en jsdom lleva demasiado para la suite):
| lo que miden (qué se vuelve a pintar y cuántas opciones hay) no depende del tamaño.
*/

const counts = vi.hoisted(() => ({ integerInputs: 0 }));

// Cada fila pinta dos campos de días (inicio y duración): cuántas veces se pintan dice cuántas
// filas se han vuelto a pintar.
vi.mock('@/components/templates/integer-input', async (importOriginal) => {
    const original =
        await importOriginal<
            typeof import('@/components/templates/integer-input')
        >();

    return {
        IntegerInput: (props: ComponentProps<typeof original.IntegerInput>) => {
            counts.integerInputs++;

            return <original.IntegerInput {...props} />;
        },
    };
});

const MAX_TASKS = 500;

/** Pintar filas en jsdom con la máquina cargada lleva su tiempo. */
const SLOW = { timeout: 60_000 };

/**
 * N tareas de primer nivel (el peor caso para «Subtarea de»), cada una depende de las 4 anteriores
 * y empieza cuando acaba la anterior (sin conflictos).
 */
function bigStructure(count: number): TemplateStructure {
    return {
        tasks: Array.from({ length: count }, (_, i) => ({
            ref: `t${i}`,
            parent_ref: null,
            title: `Tarea ${i + 1}`,
            task_type_id: null,
            priority: 'normal' as const,
            estimated_minutes: 60,
            is_milestone: false,
            start_offset_days: i * 2,
            duration_days: 2,
        })),
        dependencies: Array.from({ length: count }, (_, i) =>
            [1, 2, 3, 4]
                .filter((back) => i - back >= 0)
                .map((back) => ({ from_ref: `t${i - back}`, to_ref: `t${i}` })),
        ).flat(),
    };
}

function Harness({ initial }: { initial: EditorRow[] }) {
    const [rows, setRows] = useState(initial);

    return (
        <TemplateEditor
            rows={rows}
            onChange={setRows}
            errors={mapErrors({}, initial)}
            types={[{ id: 1, name: 'Diseño UI', is_active: true }]}
            priorities={['low', 'normal', 'high', 'urgent']}
            maxTasks={MAX_TASKS}
            maxDays={3650}
        />
    );
}

beforeEach(() => {
    counts.integerInputs = 0;
});

describe('editor de plantillas en el límite', () => {
    it('con 500 tareas y 1990 dependencias, lo de todas las filas se calcula en una pasada', () => {
        const rows = rowsFromStructure(bigStructure(MAX_TASKS));
        expect(rows.flatMap((row) => row.depends_on)).toHaveLength(1990);

        const started = performance.now();
        // Lo que el editor calcula en cada render, cinco veces, y las operaciones de una tecla.
        for (let i = 0; i < 5; i++) {
            rowLabels(rows);
            const meta = rowsMeta(rows);
            expect(meta.size).toBe(MAX_TASKS);
        }
        updateRow(rows, 't250', { title: 'Otra' });
        moveRow(rows, 't250', 'up');
        toggleDependency(rows, 't0', 't499');
        const elapsed = performance.now() - started;

        // Unos milisegundos (antes, 1,7 s por render): el margen es para una máquina muy cargada.
        expect(elapsed).toBeLessThan(1_500);
    });

    it('rowsMeta da lo mismo que calcular fila a fila', () => {
        const tasks = bigStructure(5).tasks;
        const rows = rowsFromStructure({
            tasks: [
                tasks[0],
                // Empieza el día 1, cuando t0 (días 0 y 1) aún no ha acabado.
                { ...tasks[1], start_offset_days: 1 },
                tasks[2],
                { ...tasks[3], parent_ref: 't0' },
                { ...tasks[4], parent_ref: 't0' },
            ],
            dependencies: [
                { from_ref: 't0', to_ref: 't1' },
                { from_ref: 't1', to_ref: 't2' },
            ],
        });
        const meta = rowsMeta(rows);

        // Orden de lectura: t0 con sus subtareas t3 y t4, y después t1 y t2.
        expect(rows.map((row) => row.ref)).toEqual([
            't0',
            't3',
            't4',
            't1',
            't2',
        ]);
        expect(meta.get('t0')).toMatchObject({
            label: '1',
            canMoveUp: false,
            canMoveDown: true,
            children: 2,
            parent: null,
            conflict: null,
        });
        expect(meta.get('t3')).toMatchObject({
            label: '1.1',
            canMoveUp: false,
            canMoveDown: true,
            children: 0,
            parent: 't0',
        });
        expect(meta.get('t4')).toMatchObject({
            label: '1.2',
            canMoveUp: true,
            canMoveDown: false,
        });
        // Conflicto de t1 con t0 (D-057); t2 empieza cuando acaba t1.
        expect(meta.get('t1')?.conflict?.ref).toBe('t0');
        expect(meta.get('t2')?.conflict).toBeNull();
        expect(meta.get('t2')).toMatchObject({
            label: '3',
            canMoveUp: true,
            canMoveDown: false,
        });
    });
});

describe('tabla del editor con muchas filas', SLOW, () => {
    const ROWS = 60;

    it('al escribir en una fila solo se vuelve a pintar esa fila, y no hay <option> por cada tarea', () => {
        render(<Harness initial={rowsFromStructure(bigStructure(ROWS))} />);

        expect(
            document.querySelectorAll('[data-test="template-row"]'),
        ).toHaveLength(ROWS);
        expect(counts.integerInputs).toBe(ROWS * 2);
        // Solo los selectores de tipo (2 opciones) y de prioridad (4) de cada fila: lineal.
        expect(document.querySelectorAll('option')).toHaveLength(ROWS * 6);
        expect(screen.queryByRole('listbox')).toBeNull();

        counts.integerInputs = 0;
        const input = screen.getByLabelText(
            'Título de la tarea 30',
        ) as HTMLInputElement;
        fireEvent.change(input, { target: { value: 'Maquetación' } });

        expect(input.value).toBe('Maquetación');
        // Sus dos campos de días (llevan su nombre en la etiqueta); las otras 59 filas, nada.
        expect(counts.integerInputs).toBe(2);
        expect(
            screen.getByLabelText('Día en que empieza «30. Maquetación»'),
        ).toBeTruthy();
    });

    it('«Subtarea de» lista las tareas de primer nivel al abrirlo y la mueve bajo la elegida', async () => {
        const user = userEvent.setup();
        render(<Harness initial={rowsFromStructure(bigStructure(ROWS))} />);

        await user.click(
            screen.getByRole('combobox', {
                name: `De qué tarea es subtarea «${ROWS}. Tarea ${ROWS}»: Primer nivel`,
            }),
        );
        const list = screen.getByRole('listbox');
        // «Primer nivel» y las otras tareas de primer nivel.
        expect(within(list).getAllByRole('option')).toHaveLength(ROWS);

        await user.click(
            within(list).getByRole('option', { name: '1. Tarea 1' }),
        );

        expect(screen.queryByRole('listbox')).toBeNull();
        expect(
            screen.getByRole('combobox', {
                name: `De qué tarea es subtarea «1.1. Tarea ${ROWS}»: 1. Tarea 1`,
            }),
        ).toBeTruthy();
    });
});
