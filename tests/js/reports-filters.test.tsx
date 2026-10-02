// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { ReportFiltersProps } from '@/types';

const get = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { get: (...args: unknown[]) => get(...args) },
}));

import { KpiCard } from '@/components/reports/kpi-card';
import {
    ReportFilterBar,
    resetReportOptionsCache,
} from '@/components/reports/report-filter-bar';

const filters: ReportFiltersProps = {
    query: { periodo: 'mes', fecha: '2026-09-01', persona: [3] },
    period: 'mes',
    from: '2026-09-01',
    to: '2026-09-30',
    compare: false,
    previous: { periodo: 'mes', fecha: '2026-08-01', persona: [3] },
    next: { periodo: 'mes', fecha: '2026-10-01', persona: [3] },
    comparison: null,
    can_see_financials: false,
};

describe('ReportFilterBar', () => {
    beforeEach(() => {
        get.mockReset();
        resetReportOptionsCache();
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    people: [{ id: 3, name: 'Ana' }],
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

    it('va al periodo anterior y al siguiente conservando los filtros', async () => {
        const user = userEvent.setup();
        render(<ReportFilterBar filters={filters} url="/informes/direccion" />);

        await user.click(
            screen.getByRole('button', { name: 'Periodo anterior' }),
        );
        expect(get).toHaveBeenLastCalledWith(
            '/informes/direccion',
            filters.previous,
            expect.objectContaining({ preserveState: true }),
        );

        await user.click(
            screen.getByRole('button', { name: 'Periodo siguiente' }),
        );
        expect(get).toHaveBeenLastCalledWith(
            '/informes/direccion',
            filters.next,
            expect.anything(),
        );
    });

    it('activa la comparación y quita los filtros sin tocar el periodo', async () => {
        const user = userEvent.setup();
        render(<ReportFilterBar filters={filters} url="/informes/direccion" />);

        await user.click(
            screen.getByRole('switch', {
                name: 'Comparar con el periodo anterior',
            }),
        );
        expect(get).toHaveBeenLastCalledWith(
            '/informes/direccion',
            {
                periodo: 'mes',
                fecha: '2026-09-01',
                persona: [3],
                comparar: '1',
            },
            expect.anything(),
        );

        await user.click(
            screen.getByRole('button', { name: 'Quitar filtros' }),
        );
        expect(get).toHaveBeenLastCalledWith(
            '/informes/direccion',
            { periodo: 'mes', fecha: '2026-09-01' },
            expect.anything(),
        );
    });

    it('sin comparación (compare=false) no ofrece el interruptor ni el tramo comparado', () => {
        render(
            <ReportFilterBar
                filters={{
                    ...filters,
                    compare: true,
                    comparison: { from: '2026-08-01', to: '2026-08-31' },
                }}
                show={['facturable']}
                compare={false}
                url="/informes/facturacion"
            />,
        );

        expect(
            screen.queryByRole('switch', {
                name: 'Comparar con el periodo anterior',
            }),
        ).toBeNull();
        expect(screen.queryByText(/Comparado con/)).toBeNull();
    });

    it('muestra el filtro de personas con la selección actual y oculta los no pedidos', async () => {
        render(
            <ReportFilterBar filters={filters} show={['persona']} url="/x" />,
        );

        expect(
            await screen.findByRole('combobox', { name: 'Personas: Ana' }),
        ).toBeTruthy();
        expect(screen.queryByRole('combobox', { name: /Clientes/ })).toBeNull();
    });
});

describe('KpiCard', () => {
    it('explica la métrica y la variación con texto, no solo con color', () => {
        render(
            <TooltipProvider>
                <KpiCard
                    label="Ocupación"
                    definition="Horas imputadas / capacidad."
                    value="39 %"
                    delta={{ current: 0.39, previous: 0.3 }}
                />
            </TooltipProvider>,
        );

        expect(
            screen.getByRole('button', { name: 'Qué significa «Ocupación»' }),
        ).toBeTruthy();
        expect(
            screen.getByText(/30 % más que en el periodo anterior/),
        ).toBeTruthy();
    });

    it('sin datos lo dice en lugar de pintar 0', () => {
        render(
            <TooltipProvider>
                <KpiCard label="Ocupación" definition="x" value={null} />
            </TooltipProvider>,
        );

        expect(screen.getByText('Sin datos')).toBeTruthy();
    });
});
