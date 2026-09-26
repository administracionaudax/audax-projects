// @vitest-environment jsdom
import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement } from 'react';
import { cloneElement } from 'react';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { BillableHoursChart } from '@/components/charts/billable-hours-chart';
import { CalendarHeatmap } from '@/components/charts/calendar-heatmap';
import { ChartLegend } from '@/components/charts/chart-legend';
import { ChartTooltipCard } from '@/components/charts/chart-tooltip';
import { DepartmentHoursChart } from '@/components/charts/department-hours-chart';
import { LoadCell } from '@/components/charts/load-cell';
import { WeeklyHoursChart } from '@/components/charts/weekly-hours-chart';

/**
 * En jsdom no hay layout: el ResponsiveContainer de Recharts mediría 0 × 0 y no pintaría nada.
 * Se sustituye por un contenedor de tamaño fijo para poder inspeccionar el SVG real.
 */
vi.mock('recharts', async (importOriginal) => {
    const actual = await importOriginal<typeof import('recharts')>();

    return {
        ...actual,
        ResponsiveContainer: ({
            children,
        }: {
            children: ReactElement<{ width?: number; height?: number }>;
        }) => cloneElement(children, { width: 640, height: 280 }),
    };
});

beforeAll(() => {
    (
        globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
    ).IS_REACT_ACT_ENVIRONMENT = true;

    if (!('ResizeObserver' in globalThis)) {
        globalThis.ResizeObserver = class {
            observe() {}
            unobserve() {}
            disconnect() {}
        } as unknown as typeof ResizeObserver;
    }
});

afterEach(() => cleanup());

// Intl usa espacios de no separación (U+00A0 / U+202F) antes de %.
const norm = (value: string | null) =>
    (value ?? '').replace(/[\u00a0\u202f]/g, ' ');

const WEEKS = [
    { id: 'w1', label: '07/09', logged: 4560, capacity: 4800 },
    { id: 'w2', label: '14/09', logged: 5130, capacity: 4800 },
    { id: 'w3', label: '21/09', logged: 4320, capacity: 4800 },
];

describe('ChartTooltipCard', () => {
    it('muestra primero el valor en h:mm y después la serie, con clave de línea', () => {
        render(
            <ChartTooltipCard
                title="Semana del 07/09"
                rows={[
                    {
                        key: 'logged',
                        label: 'Horas imputadas',
                        color: 'var(--chart-1)',
                        value: '76:00',
                    },
                    {
                        key: 'capacity',
                        label: 'Capacidad',
                        color: 'var(--chart-2)',
                        value: '80:00',
                    },
                ]}
            />,
        );

        const items = screen.getAllByRole('listitem');
        expect(items.map((li) => li.textContent)).toEqual([
            '76:00Horas imputadas',
            '80:00Capacidad',
        ]);
        expect(
            items[0]
                .querySelector('[aria-hidden="true"]')
                ?.getAttribute('style'),
        ).toContain('var(--chart-1)');
        expect(screen.getByText('Semana del 07/09')).toBeTruthy();
    });

    it('no pinta nada sin filas', () => {
        const { container } = render(<ChartTooltipCard rows={[]} />);
        expect(container.innerHTML).toBe('');
    });
});

describe('ChartLegend', () => {
    it('usa tinta de texto para la etiqueta y el color de la serie solo en la clave', () => {
        render(
            <ChartLegend
                items={[
                    {
                        key: 'a',
                        label: 'Facturable',
                        color: 'var(--chart-1)',
                        shape: 'rect',
                    },
                    {
                        key: 'b',
                        label: 'No facturable',
                        color: 'var(--chart-2)',
                        shape: 'rect',
                    },
                ]}
            />,
        );

        const items = screen.getAllByRole('listitem');
        expect(items.map((li) => li.textContent)).toEqual([
            'Facturable',
            'No facturable',
        ]);
        items.forEach((li, index) => {
            expect(li.getAttribute('style')).toBeNull();
            expect(li.querySelector('span')?.getAttribute('style')).toContain(
                `var(--chart-${index + 1})`,
            );
        });
    });
});

describe('gráficas Recharts', () => {
    it('la línea semanal pinta imputadas con --chart-1 y capacidad con --chart-2, a 2 px', () => {
        const { container } = render(<WeeklyHoursChart data={WEEKS} />);
        const curves = [
            ...container.querySelectorAll('path.recharts-line-curve'),
        ];

        expect(curves.map((c) => c.getAttribute('stroke'))).toEqual([
            'var(--chart-1)',
            'var(--chart-2)',
        ]);
        curves.forEach((c) => expect(c.getAttribute('stroke-width')).toBe('2'));

        const svgText = container.querySelector('svg')?.innerHTML ?? '';
        expect(svgText).not.toMatch(/#[0-9a-f]{3,8}\b/i);
        expect(screen.getByText('Imputadas')).toBeTruthy();
        expect(
            screen.getByText('Capacidad', { selector: 'text' }),
        ).toBeTruthy();
    });

    it('las barras por departamento usan un único color y etiquetan en h:mm', () => {
        const { container } = render(
            <DepartmentHoursChart
                data={[
                    { id: 'd', label: 'Diseño', minutes: 750 },
                    { id: 'v', label: 'Desarrollo', minutes: 1110 },
                ]}
            />,
        );
        const fills = new Set(
            [...container.querySelectorAll('.recharts-bar-rectangle path')].map(
                (p) => p.getAttribute('fill'),
            ),
        );

        expect([...fills]).toEqual(['var(--chart-1)']);
        expect(container.textContent).toContain('12:30');
        expect(container.textContent).toContain('18:30');
        // Una sola serie: sin leyenda.
        expect(container.querySelector('ul')).toBeNull();
    });

    it('las barras apiladas siguen el orden de la paleta y separan con la superficie', () => {
        const { container } = render(
            <BillableHoursChart
                data={[
                    { id: 'm1', label: 'jul', billable: 600, nonBillable: 120 },
                    { id: 'm2', label: 'ago', billable: 480, nonBillable: 60 },
                ]}
            />,
        );
        const layers = [...container.querySelectorAll('.recharts-bar')];

        expect(
            layers.map((layer) =>
                layer
                    .querySelector('.recharts-bar-rectangle path')
                    ?.getAttribute('fill'),
            ),
        ).toEqual(['var(--chart-1)', 'var(--chart-2)']);
        layers.forEach((layer) => {
            const path = layer.querySelector('.recharts-bar-rectangle path');
            expect(path?.getAttribute('stroke')).toBe('var(--card)');
            expect(path?.getAttribute('stroke-width')).toBe('2');
        });
        expect(container.textContent).toContain('12:00');
    });

    it('«Ver como tabla» muestra los mismos datos en h:mm', async () => {
        const user = userEvent.setup();
        render(<WeeklyHoursChart data={WEEKS} />);

        const toggle = screen.getByRole('button', { name: 'Ver como tabla' });
        expect(toggle.getAttribute('aria-pressed')).toBe('false');
        await user.click(toggle);

        const table = screen.getByRole('table');
        const rows = within(table).getAllByRole('row').slice(1);
        expect(rows.map((r) => norm(r.textContent))).toEqual([
            '07/0976:0080:0095 %',
            '14/0985:3080:00106,9 %',
            '21/0972:0080:0090 %',
        ]);
        expect(
            screen
                .getByRole('button', { name: 'Ver como gráfica' })
                .getAttribute('aria-pressed'),
        ).toBe('true');
    });
});

describe('CalendarHeatmap', () => {
    const days = [
        { date: '2026-09-21', minutes: 480 },
        { date: '2026-09-22', minutes: 0 },
        { date: '2026-09-23', minutes: 150 },
    ];

    it('se recorre con el teclado y anuncia el día en h:mm', async () => {
        const user = userEvent.setup();
        render(<CalendarHeatmap days={days} />);

        const grid = screen.getByRole('group', { name: /usa las flechas/ });
        grid.focus();
        await user.keyboard('{Home}');
        expect(
            screen.getByText('lunes, 21/09/2026: 8:00 imputadas'),
        ).toBeTruthy();
        await user.keyboard('{ArrowDown}{ArrowDown}');
        expect(
            screen.getByText('miércoles, 23/09/2026: 2:30 imputadas'),
        ).toBeTruthy();
    });

    it('la tabla alternativa resume por semana', async () => {
        const user = userEvent.setup();
        render(<CalendarHeatmap days={days} />);

        await user.click(
            screen.getByRole('button', { name: 'Ver como tabla' }),
        );
        const rows = within(screen.getByRole('table'))
            .getAllByRole('row')
            .slice(1);
        expect(rows.map((r) => r.textContent)).toEqual([
            'Semana del 21/09/202610:302',
        ]);
    });
});

describe('LoadCell', () => {
    it('nunca depende solo del color: icono, cifras y nivel en texto', () => {
        const { container } = render(<LoadCell planned={600} capacity={480} />);

        expect(norm(container.textContent)).toContain('125 %');
        expect(container.textContent).toContain('10:00 / 8:00');
        expect(container.textContent).toContain('Sobrecarga');
        expect(container.querySelector('svg')).not.toBeNull();
    });

    it('sin capacidad muestra el motivo', () => {
        const { container } = render(
            <LoadCell planned={0} capacity={0} reason="Festivo" />,
        );

        expect(container.textContent).toContain('Festivo');
        expect(container.textContent).toContain('0:00 planificadas');
    });
});
