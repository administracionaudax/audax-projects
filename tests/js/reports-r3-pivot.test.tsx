// @vitest-environment jsdom
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import type { DetailPageProps } from '@/components/reports/r3-types';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { PivotResult } from '@/types';

const get = vi.fn();
const on = vi.fn(
    (_event: string, _handler: (event: unknown) => void) => () => {},
);
const reload = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: {
        get: (...args: unknown[]) => get(...args),
        on: (event: string, handler: (event: unknown) => void) =>
            on(event, handler),
        reload: (...args: unknown[]) => reload(...args),
    },
}));

import { PivotControls } from '@/components/reports/r3-pivot-controls';
import {
    PivotTable,
    pivotHeaderLabel,
    pivotTruncation,
} from '@/components/reports/r3-pivot-table';
import ReportDetail from '@/pages/reports/detail';

/** Persona × semana: Luis 12:40 y Ana 11:00 (el escenario calculado a mano de los tests PHP). */
const pivot: PivotResult = {
    rows: [
        { key: '2', name: 'Luis' },
        { key: '1', name: 'Ana' },
    ],
    columns: [
        { key: '2026-09-21', name: '2026-09-21' },
        { key: '2026-09-28', name: '2026-09-28' },
    ],
    cells: {
        '2': { '2026-09-21': 760 },
        '1': { '2026-09-21': 600, '2026-09-28': 60 },
    },
    row_totals: { '2': 760, '1': 660 },
    column_totals: { '2026-09-21': 1360, '2026-09-28': 60 },
    total: 1420,
    truncated: false,
};

const rowNames = () =>
    screen
        .getAllByRole('row')
        .slice(1, -1)
        .map(
            (row) =>
                within(row).getAllByRole('rowheader')[0]?.textContent ?? '',
        );

beforeAll(() => {
    // Radix Select usa la captura del puntero, que jsdom no tiene.
    if (!('hasPointerCapture' in Element.prototype)) {
        Object.assign(Element.prototype, {
            hasPointerCapture: () => false,
            releasePointerCapture: () => {},
        });
    }
});

describe('PivotTable', () => {
    it('pinta horas en h:mm con subtotales por fila y por columna', () => {
        render(
            <PivotTable
                pivot={pivot}
                rowsDimension="persona"
                columnsDimension="semana"
                caption="Horas imputadas por Persona y Semana"
            />,
        );

        const table = screen.getByRole('table', {
            name: 'Horas imputadas por Persona y Semana',
        });
        expect(
            screen.getByRole('region', {
                name: 'Horas imputadas por Persona y Semana',
            }),
        ).toBeTruthy();

        const [header, luis, ana, totals] = within(table).getAllByRole('row');
        expect(header?.textContent).toContain('Persona / Semana');
        expect(header?.textContent).toContain('Sem. 21/09');
        expect(header?.textContent).toContain('Total');

        expect(
            within(luis!)
                .getAllByRole('cell')
                .map((cell) => cell.textContent),
        ).toEqual(['12:40', '–Sin horas', '12:40']);
        expect(
            within(ana!)
                .getAllByRole('cell')
                .map((cell) => cell.textContent),
        ).toEqual(['10:00', '1:00', '11:00']);
        expect(
            within(totals!)
                .getAllByRole('cell')
                .map((cell) => cell.textContent),
        ).toEqual(['22:40', '1:00', '23:40']);
        expect(within(totals!).getByRole('rowheader').textContent).toBe(
            'Total',
        );
    });

    it('ordena por nombre, por total y por cualquier columna, y lo anuncia', async () => {
        const user = userEvent.setup();
        render(
            <PivotTable
                pivot={pivot}
                rowsDimension="persona"
                columnsDimension="semana"
                caption="Tabla"
            />,
        );

        // Por defecto, el orden del servidor (de más a menos horas).
        expect(rowNames()).toEqual(['Luis', 'Ana']);

        await user.click(
            screen.getByRole('button', { name: /Ordenar por Persona$/ }),
        );
        expect(rowNames()).toEqual(['Ana', 'Luis']);
        expect(
            screen
                .getByRole('columnheader', { name: /Persona \/ Semana/ })
                .getAttribute('aria-sort'),
        ).toBe('ascending');

        await user.click(
            screen.getByRole('button', {
                name: /Ordenar por Semana del 28\/09\/2026/,
            }),
        );
        expect(rowNames()).toEqual(['Ana', 'Luis']);

        const total = screen.getByRole('button', { name: /Ordenar por Total/ });
        await user.click(total);
        expect(rowNames()).toEqual(['Luis', 'Ana']);
        await user.click(total);
        expect(rowNames()).toEqual(['Ana', 'Luis']);
        expect(
            screen
                .getByRole('columnheader', { name: /^Total/ })
                .getAttribute('aria-sort'),
        ).toBe('ascending');
    });

    it('avisa con icono y texto si la tabla está recortada', () => {
        render(
            <PivotTable
                pivot={{
                    ...pivot,
                    column_totals: totals(61, (n) => `tarea-${n}`),
                    truncated: true,
                }}
                rowsDimension="persona"
                columnsDimension="tarea"
                caption="Tabla"
            />,
        );

        const alert = screen.getByRole('alert');
        expect(alert.querySelector('svg')).toBeTruthy();
        expect(alert.textContent).toBe(
            'La tabla está recortada: se ven las 60 columnas con más horas, pero los totales incluyen todas las horas. Acota el periodo o los filtros para verlo todo.',
        );
    });
});

/** Totales de `count` grupos (claves generadas), como los que llegan de PivotReport. */
function totals(count: number, key: (n: number) => string) {
    return Object.fromEntries(
        Array.from({ length: count }, (_, n) => [key(n), 30]),
    );
}

describe('pivotTruncation', () => {
    it('no avisa si la tabla está completa', () => {
        expect(pivotTruncation(pivot, 'semana')).toBeNull();
    });

    it('con semanas, meses o días en columnas dice que se ven las primeras del periodo', () => {
        const cut = {
            ...pivot,
            column_totals: totals(61, (n) => `2025-01-${n}`),
            truncated: true,
        };

        expect(pivotTruncation(cut, 'semana')).toContain(
            'se ven las 60 primeras semanas del periodo, pero',
        );
        expect(pivotTruncation(cut, 'mes')).toContain(
            'se ven los 60 primeros meses del periodo, pero',
        );
        expect(pivotTruncation(cut, 'dia')).toContain(
            'se ven los 60 primeros días del periodo, pero',
        );
        expect(pivotTruncation(cut, 'proyecto')).toContain(
            'se ven las 60 columnas con más horas, pero',
        );
    });

    it('dice qué se recortó: las filas, las columnas o ambas', () => {
        const rows = totals(201, (n) => String(n));

        expect(
            pivotTruncation(
                { ...pivot, row_totals: rows, truncated: true },
                'semana',
            ),
        ).toContain('se ven las 200 filas con más horas, pero');
        expect(
            pivotTruncation(
                {
                    ...pivot,
                    row_totals: rows,
                    column_totals: totals(61, (n) => `2025-01-${n}`),
                    truncated: true,
                },
                'semana',
            ),
        ).toContain(
            'se ven las 200 filas con más horas y las 60 primeras semanas del periodo, pero',
        );
    });
});

describe('pivotHeaderLabel', () => {
    it('nombra semanas por su lunes y meses por su nombre', () => {
        expect(
            pivotHeaderLabel('semana', { key: '2026-09-21', name: 'x' }),
        ).toEqual({
            short: 'Sem. 21/09',
            full: 'Semana del 21/09/2026',
        });
        expect(
            pivotHeaderLabel('mes', { key: '2026-09-01', name: 'x' }).full,
        ).toBe('septiembre de 2026');
        expect(
            pivotHeaderLabel('dia', { key: '2026-09-01', name: 'x' }).short,
        ).toBe('01/09/2026');
        expect(
            pivotHeaderLabel('bolsa', { key: null, name: 'Sin bolsa' }),
        ).toEqual({ short: 'Sin bolsa', full: 'Sin bolsa' });
    });
});

describe('PivotControls', () => {
    it('intercambia filas y columnas, y nunca deja la misma dimensión en ambas', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(
            <PivotControls
                layout={{
                    filas: 'proyecto',
                    columnas: 'semana',
                    medida: 'imputadas',
                }}
                dimensions={['persona', 'proyecto', 'semana', 'mes']}
                measures={['imputadas', 'facturables', 'dentro', 'exceso']}
                onChange={onChange}
            />,
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Intercambiar filas y columnas',
            }),
        );
        expect(onChange).toHaveBeenLastCalledWith({
            filas: 'semana',
            columnas: 'proyecto',
            medida: 'imputadas',
        });

        await user.click(screen.getByRole('combobox', { name: 'Filas' }));
        await user.click(await screen.findByRole('option', { name: 'Semana' }));
        expect(onChange).toHaveBeenLastCalledWith({
            filas: 'semana',
            columnas: 'proyecto',
            medida: 'imputadas',
        });

        await user.click(screen.getByRole('combobox', { name: 'Medida' }));
        await user.click(
            await screen.findByRole('option', { name: 'Exceso de bolsa' }),
        );
        expect(onChange).toHaveBeenLastCalledWith({
            filas: 'proyecto',
            columnas: 'semana',
            medida: 'exceso',
        });
    });
});

const pageProps = (
    overrides: Partial<DetailPageProps> = {},
): DetailPageProps => ({
    filters: {
        query: {
            periodo: 'semana',
            fecha: '2026-09-21',
            proyecto: [4],
            filas: 'persona',
            columnas: 'semana',
            medida: 'imputadas',
        },
        period: 'semana',
        from: '2026-09-21',
        to: '2026-09-27',
        compare: false,
        previous: { periodo: 'semana', fecha: '2026-09-14' },
        next: { periodo: 'semana', fecha: '2026-09-28' },
        comparison: null,
        can_see_financials: false,
    },
    layout: { filas: 'persona', columnas: 'semana', medida: 'imputadas' },
    dimensions: ['persona', 'proyecto', 'semana', 'mes'],
    measures: ['imputadas', 'facturables', 'dentro', 'exceso'],
    filterKeys: [
        'persona',
        'departamento',
        'cliente',
        'proyecto',
        'bolsa',
        'tipo',
        'facturable',
    ],
    pivot,
    summary: {
        logged_minutes: 1420,
        billable_minutes: 1360,
        in_bank_minutes: 1320,
        overage_minutes: 100,
        billability: 0.9577,
    },
    comparison: null,
    ...overrides,
});

describe('página del informe detallado', () => {
    beforeEach(() => {
        get.mockReset();
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    people: [],
                    departments: [],
                    clients: [],
                    projects: [],
                    hour_banks: [],
                    task_types: [],
                }),
                { status: 200 },
            ),
        );
    });

    it('muestra los KPI de horas y exporta la tabla y las entradas con los mismos filtros', async () => {
        const user = userEvent.setup();
        render(
            <TooltipProvider>
                <ReportDetail {...pageProps()} />
            </TooltipProvider>,
        );

        const summary = screen.getByRole('region', {
            name: 'Resumen del periodo',
        });
        expect(within(summary).getByText('23:40')).toBeTruthy();
        expect(within(summary).getByText('22:40')).toBeTruthy();
        expect(
            within(summary).getByText('Dentro de bolsa: 22:00'),
        ).toBeTruthy();
        expect(within(summary).getByText('95,8 %')).toBeTruthy();
        expect(screen.getByRole('combobox', { name: /Personas/ })).toBeTruthy();

        await user.click(
            screen.getByRole('button', { name: 'Exportar la tabla' }),
        );
        const table = screen.getByRole('menuitem', { name: 'Excel (.xlsx)' });
        expect(table.getAttribute('href')).toBe(
            '/informes/detalle?periodo=semana&fecha=2026-09-21&proyecto%5B%5D=4&filas=persona&columnas=semana&medida=imputadas&formato=xlsx',
        );
        await user.keyboard('{Escape}');

        await user.click(
            screen.getByRole('button', { name: 'Exportar las entradas' }),
        );
        expect(
            screen
                .getByRole('menuitem', { name: 'CSV (.csv)' })
                .getAttribute('href'),
        ).toContain(
            '/informes/horas/exportar?periodo=semana&fecha=2026-09-21&proyecto%5B%5D=4',
        );
    });

    it('lleva a la URL el cambio de la tabla conservando los filtros', async () => {
        const user = userEvent.setup();
        render(
            <TooltipProvider>
                <ReportDetail {...pageProps()} />
            </TooltipProvider>,
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Intercambiar filas y columnas',
            }),
        );

        expect(get).toHaveBeenLastCalledWith(
            '/informes/detalle',
            {
                periodo: 'semana',
                fecha: '2026-09-21',
                proyecto: [4],
                filas: 'semana',
                columnas: 'persona',
                medida: 'imputadas',
            },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('sin horas, un estado vacío; a una empleada le explica que ve solo las suyas', () => {
        render(
            <TooltipProvider>
                <ReportDetail
                    {...pageProps({
                        dimensions: ['proyecto', 'semana', 'mes'],
                        filterKeys: [
                            'cliente',
                            'proyecto',
                            'bolsa',
                            'tipo',
                            'facturable',
                        ],
                        layout: {
                            filas: 'proyecto',
                            columnas: 'semana',
                            medida: 'imputadas',
                        },
                        pivot: {
                            ...pivot,
                            rows: [],
                            columns: [],
                            cells: {},
                            row_totals: {},
                            column_totals: {},
                            total: 0,
                        },
                    })}
                />
            </TooltipProvider>,
        );

        expect(screen.getByText('No hay horas con estos filtros')).toBeTruthy();
        expect(screen.getByText('Ves solo tus propias horas.')).toBeTruthy();
        expect(screen.queryByRole('table')).toBeNull();
        // Sin equipo, la barra no ofrece filtrar por persona ni por departamento.
        expect(screen.queryByRole('combobox', { name: /Personas/ })).toBeNull();
        expect(
            screen.queryByRole('combobox', { name: /Departamentos/ }),
        ).toBeNull();
        expect(screen.getByRole('combobox', { name: /Clientes/ })).toBeTruthy();
    });

    it('con otra medida sin horas, el estado vacío la nombra y sugiere cambiarla', () => {
        render(
            <TooltipProvider>
                <ReportDetail
                    {...pageProps({
                        layout: {
                            filas: 'persona',
                            columnas: 'semana',
                            medida: 'exceso',
                        },
                        pivot: {
                            ...pivot,
                            rows: [],
                            columns: [],
                            cells: {},
                            row_totals: {},
                            column_totals: {},
                            total: 0,
                        },
                    })}
                />
            </TooltipProvider>,
        );

        expect(
            screen.getByText('No hay exceso de bolsa con estos filtros'),
        ).toBeTruthy();
        expect(
            screen.getByText(
                'Prueba con otro periodo, otra medida o quita algún filtro.',
            ),
        ).toBeTruthy();
        expect(screen.queryByRole('table')).toBeNull();
    });

    it('con comparar=1, cada KPI dice cuánto varía frente al periodo anterior', () => {
        render(
            <TooltipProvider>
                <ReportDetail
                    {...pageProps({
                        // Imputadas 1420 frente a 1000 (+42 %); exceso 100 frente a 200 (−50 %, mejor).
                        comparison: {
                            logged_minutes: 1000,
                            billable_minutes: 1360,
                            in_bank_minutes: 800,
                            overage_minutes: 200,
                            billability: 0.9577,
                        },
                    })}
                />
            </TooltipProvider>,
        );

        const summary = screen.getByRole('region', {
            name: 'Resumen del periodo',
        });
        expect(
            within(summary).getByText('42 % más que en el periodo anterior'),
        ).toBeTruthy();
        expect(
            within(summary).getByText('50 % menos que en el periodo anterior'),
        ).toBeTruthy();
        expect(
            within(summary).getAllByText('Igual que en el periodo anterior'),
        ).toHaveLength(2);
    });

    it('mientras carga lo anuncia y bloquea la tabla; si falla la red, ofrece reintentar', async () => {
        const handlers: Record<string, (event: unknown) => void> = {};
        on.mockImplementation((event, handler) => {
            handlers[event] = handler;

            return () => {};
        });
        const user = userEvent.setup();
        render(
            <TooltipProvider>
                <ReportDetail {...pageProps()} />
            </TooltipProvider>,
        );
        const visit = (path: string) => ({
            detail: { visit: { url: new URL(`https://app.test${path}`) } },
        });

        // Una visita a otra página no cambia nada.
        act(() => handlers.start(visit('/proyectos')));
        expect(screen.queryByRole('status')).toBeNull();

        act(() => handlers.start(visit('/informes/detalle')));
        expect(screen.getByRole('status').textContent).toContain(
            'Actualizando el informe…',
        );
        expect(
            screen
                .getByRole('button', { name: 'Intercambiar filas y columnas' })
                .hasAttribute('disabled'),
        ).toBe(true);

        act(() => handlers.networkError({}));
        act(() => handlers.finish(visit('/informes/detalle')));
        expect(screen.queryByText('Actualizando el informe…')).toBeNull();
        expect(
            screen.getByText(/No se ha podido actualizar el informe/),
        ).toBeTruthy();

        await user.click(screen.getByRole('button', { name: 'Reintentar' }));
        expect(reload).toHaveBeenCalledTimes(1);
        expect(
            screen.queryByText(/No se ha podido actualizar el informe/),
        ).toBeNull();

        on.mockImplementation(() => () => {});
    });
});
