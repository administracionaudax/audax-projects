import { describe, expect, it } from 'vitest';
import { BILLABLE_SERIES } from '@/components/charts/billable-hours-chart';
import {
    buildCalendarWeeks,
    scaleLabels,
    weekdayIndex,
} from '@/components/charts/calendar-heatmap';
import {
    buildTooltipRows,
    CHART_COLORS,
    CHART_INK,
    defineSeries,
    formatHoursTick,
    hourTicks,
    legendItems,
    sequentialColor,
    sequentialStep,
    seriesColor,
} from '@/components/charts/chart-config';
import { DEPARTMENT_HOURS_SERIES } from '@/components/charts/department-hours-chart';
import {
    formatLoadPercent,
    hourBankFigures,
    hourBankLevel,
    loadLevel,
    loadPercent,
} from '@/components/charts/thresholds';
import { WEEKLY_HOURS_SERIES } from '@/components/charts/weekly-hours-chart';

describe('paleta categórica', () => {
    it('usa var(--chart-1…6) en orden fijo', () => {
        expect(CHART_COLORS).toEqual([
            'var(--chart-1)',
            'var(--chart-2)',
            'var(--chart-3)',
            'var(--chart-4)',
            'var(--chart-5)',
            'var(--chart-6)',
        ]);

        CHART_COLORS.forEach((color, index) => {
            expect(seriesColor(index)).toBe(`var(--chart-${index + 1})`);
            expect(color).toBe(seriesColor(index));
        });
    });

    it.each([6, 7, 12, -1, 1.5, Number.NaN])(
        'nunca cicla: la posición %s es un error',
        (index) => {
            expect(() => seriesColor(index)).toThrow(RangeError);
        },
    );

    it('defineSeries asigna por orden de declaración', () => {
        const series = defineSeries([
            { key: 'design', label: 'Diseño' },
            { key: 'development', label: 'Desarrollo' },
            { key: 'marketing', label: 'Marketing' },
        ]);

        expect(series.map((s) => s.color)).toEqual([
            'var(--chart-1)',
            'var(--chart-2)',
            'var(--chart-3)',
        ]);
    });

    it('defineSeries rechaza una 7.ª serie y las claves duplicadas', () => {
        const seven = Array.from({ length: 7 }, (_, i) => ({
            key: `s${i}`,
            label: `Serie ${i}`,
        }));

        expect(() => defineSeries(seven)).toThrow(/no se cicla/);
        expect(() =>
            defineSeries([
                { key: 'a', label: 'A' },
                { key: 'a', label: 'A bis' },
            ]),
        ).toThrow(/duplicada/);
    });

    it('el color sigue a la entidad: filtrar después no repinta', () => {
        const series = defineSeries([
            { key: 'design', label: 'Diseño' },
            { key: 'development', label: 'Desarrollo' },
            { key: 'marketing', label: 'Marketing' },
        ]);
        const visible = series.filter((s) => s.key !== 'development');

        expect(visible.find((s) => s.key === 'marketing')?.color).toBe(
            'var(--chart-3)',
        );
    });

    it('las gráficas de ejemplo usan la paleta en orden', () => {
        expect(WEEKLY_HOURS_SERIES.map((s) => [s.key, s.color])).toEqual([
            ['logged', 'var(--chart-1)'],
            ['capacity', 'var(--chart-2)'],
        ]);
        expect(BILLABLE_SERIES.map((s) => [s.key, s.color])).toEqual([
            ['billable', 'var(--chart-1)'],
            ['nonBillable', 'var(--chart-2)'],
        ]);
        expect(DEPARTMENT_HOURS_SERIES).toHaveLength(1);
        expect(DEPARTMENT_HOURS_SERIES[0].color).toBe('var(--chart-1)');
    });

    it('la tinta de ejes, rejilla y textos sale de tokens de texto y superficie', () => {
        expect(
            Object.values(CHART_INK).every((v) => v.startsWith('var(--')),
        ).toBe(true);
        expect(CHART_INK.label).toBe('var(--foreground)');
        expect(CHART_INK.axis).toBe('var(--muted-foreground)');
    });
});

describe('tooltips y leyendas', () => {
    it('formatea los valores del tooltip en h:mm', () => {
        const rows = buildTooltipRows(
            [
                { dataKey: 'logged', value: 4560 },
                { dataKey: 'capacity', value: 4800 },
            ],
            WEEKLY_HOURS_SERIES,
        );

        expect(rows).toEqual([
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
        ]);
    });

    it('ordena las filas como las series, no como llegan de Recharts', () => {
        const rows = buildTooltipRows(
            [
                { dataKey: 'nonBillable', value: 90 },
                { dataKey: 'billable', value: 150 },
            ],
            BILLABLE_SERIES,
        );

        expect(rows.map((r) => `${r.value} ${r.label}`)).toEqual([
            '2:30 Facturable',
            '1:30 No facturable',
        ]);
    });

    it('omite series sin valor numérico y acepta otro formateador', () => {
        expect(
            buildTooltipRows(
                [
                    { dataKey: 'logged', value: undefined },
                    { dataKey: 'capacity', value: 'x' },
                ],
                WEEKLY_HOURS_SERIES,
            ),
        ).toEqual([]);
        expect(buildTooltipRows(undefined, WEEKLY_HOURS_SERIES)).toEqual([]);
        expect(
            buildTooltipRows(
                [{ dataKey: 'logged', value: 3 }],
                WEEKLY_HOURS_SERIES,
                (v) => `${v} u`,
            )[0].value,
        ).toBe('3 u');
    });

    it('la leyenda solo aparece con dos o más series', () => {
        expect(legendItems(DEPARTMENT_HOURS_SERIES, 'rect')).toEqual([]);
        expect(legendItems(WEEKLY_HOURS_SERIES, 'line')).toEqual([
            {
                key: 'logged',
                label: 'Horas imputadas',
                color: 'var(--chart-1)',
                shape: 'line',
            },
            {
                key: 'capacity',
                label: 'Capacidad',
                color: 'var(--chart-2)',
                shape: 'line',
            },
        ]);
    });

    it.each([
        [0, '0 h'],
        [60, '1 h'],
        [450, '7,5 h'],
        [60_000, '1.000 h'],
    ])('marca de eje %i min → %s', (minutes, label) => {
        expect(formatHoursTick(minutes)).toBe(label);
    });

    it('elige marcas de eje limpias que cubren el máximo', () => {
        expect(hourTicks(0)).toEqual([0]);
        expect(hourTicks(9600)).toEqual([0, 3000, 6000, 9000, 12000]);
        expect(hourTicks(37_080, 4)).toEqual([0, 15000, 30000, 45000]);

        for (const max of [59, 480, 2460, 65_000]) {
            const ticks = hourTicks(max);
            expect(ticks[0]).toBe(0);
            expect(ticks.at(-1)).toBeGreaterThanOrEqual(max);
        }
    });
});

describe('rampa secuencial (heatmap)', () => {
    it('sin horas → gris neutro; el paso máximo es var(--chart-1)', () => {
        expect(sequentialColor(0)).toBe('var(--neutral-soft)');
        expect(sequentialColor(5)).toBe('var(--chart-1)');
    });

    it('los pasos intermedios mezclan solo --chart-1 y la tarjeta, de menos a más', () => {
        const mixes = [1, 2, 3, 4].map((step) => {
            const color = sequentialColor(step);
            expect(color).toMatch(
                /^color-mix\(in oklab, var\(--chart-1\) \d+%, var\(--card\)\)$/,
            );

            return Number(/(\d+)%/.exec(color)?.[1]);
        });

        expect([...mixes].sort((a, b) => a - b)).toEqual(mixes);
    });

    it.each([
        [0, 0],
        [-30, 0],
        [1, 1],
        [119, 1],
        [120, 2],
        [239, 2],
        [240, 3],
        [360, 4],
        [479, 4],
        [480, 5],
        [720, 5],
    ])('%i min → paso %i', (minutes, step) => {
        expect(sequentialStep(minutes)).toBe(step);
    });

    it('etiqueta la escala en horas', () => {
        expect(scaleLabels()).toEqual([
            'Sin horas',
            '< 2 h',
            '2–4 h',
            '4–6 h',
            '6–8 h',
            '≥ 8 h',
        ]);
    });
});

describe('calendario (semanas de lunes a domingo)', () => {
    it('calcula el día de la semana empezando en lunes', () => {
        expect(weekdayIndex('2026-09-28')).toBe(0);
        expect(weekdayIndex('2026-09-27')).toBe(6);
    });

    it('agrupa en semanas con huecos fuera de rango', () => {
        const weeks = buildCalendarWeeks([
            { date: '2026-09-30', minutes: 480 },
            { date: '2026-10-01', minutes: 90 },
            { date: '2026-10-02', minutes: 0 },
            { date: '2026-10-05', minutes: 300 },
        ]);

        expect(weeks.map((w) => w.id)).toEqual(['2026-09-28', '2026-10-05']);
        expect(weeks[0].days.map((d) => d?.date ?? null)).toEqual([
            null,
            null,
            '2026-09-30',
            '2026-10-01',
            '2026-10-02',
            '2026-10-03',
            '2026-10-04',
        ]);
        expect(weeks[0].days[5]?.minutes).toBe(0);
        expect(weeks[0].days[2]?.step).toBe(5);
        expect(weeks[1].days.slice(1).every((d) => d === null)).toBe(true);
        expect(weeks[1].days[0]?.index).toBe(5);
    });

    it('marca el mes donde empieza (y no solapa la primera etiqueta)', () => {
        const days = Array.from({ length: 70 }, (_, i) => ({
            date: new Date(Date.UTC(2026, 8, 21 + i))
                .toISOString()
                .slice(0, 10),
            minutes: 60,
        }));
        const labels = buildCalendarWeeks(days).map((w) => w.monthLabel);

        expect(labels[0]).toBeNull();
        expect(labels.filter(Boolean)).toEqual(['oct', 'nov']);
    });
});

describe('semáforo de carga (SPEC §9)', () => {
    // El nivel sale del porcentaje redondeado que se enseña: la cifra y el color no se contradicen.
    it.each([
        [480, 0, 'none'],
        [0, -60, 'none'],
        [0, 480, 'under'],
        [333, 480, 'under'], // 69,4 % → «69 %»
        [334, 480, 'balanced'], // 69,6 % → «70 %»
        [335, 480, 'balanced'], // 69,8 % → «70 %»: verde, como dice la leyenda (del 70 %)
        [480, 480, 'balanced'],
        [481, 480, 'balanced'], // 100,2 % → «100 %»: verde, no ámbar
        [482, 480, 'balanced'], // 100,4 % → «100 %»
        [483, 480, 'high'], // 100,6 % → «101 %»
        [576, 480, 'high'], // «120 %»
        [577, 480, 'high'], // 120,2 % → «120 %»: ámbar, no rojo
        [578, 480, 'high'], // 120,4 % → «120 %»
        [579, 480, 'over'], // 120,6 % → «121 %»
    ])('%i / %i min → %s', (planned, capacity, level) => {
        expect(loadLevel(planned, capacity)).toBe(level);
    });

    it.each([
        [481, 480, 100],
        [480, 480, 100],
        [335, 480, 70],
        [576, 480, 120],
        [577, 480, 120],
    ])('%i / %i min se enseña como el %i %%', (planned, capacity, percent) => {
        expect(loadPercent(planned, capacity)).toBe(percent);
        expect(
            formatLoadPercent(planned, capacity).replace(
                /[\u00a0\u202f]/g,
                ' ',
            ),
        ).toBe(`${percent} %`);
    });

    it('sin capacidad no hay porcentaje', () => {
        expect(loadPercent(120, 0)).toBeNull();
        expect(formatLoadPercent(120, 0)).toBe('');
    });
});

describe('bolsas de horas (SPEC §8)', () => {
    it.each([
        [0, 3000, 'ok'],
        [2249, 3000, 'ok'],
        [2250, 3000, 'warning'],
        [2999, 3000, 'warning'],
        [3000, 3000, 'exhausted'],
        [3150, 3000, 'exhausted'],
    ])('%i de %i min → %s', (consumed, total, level) => {
        expect(hourBankLevel(consumed, total)).toBe(level);
    });

    it('calcula exceso, restantes y horas comprometidas', () => {
        expect(hourBankFigures(3150, 3000, 360)).toMatchObject({
            consumed: 3150,
            total: 3000,
            inBank: 3000,
            remaining: 0,
            overage: 150,
            committed: 360,
            // Lo que va dentro (3000) + comprometido (360) − total.
            shortfall: 360,
            ratio: 1.05,
        });
        expect(hourBankFigures(2460, 3000, 840)).toMatchObject({
            remaining: 540,
            overage: 0,
            shortfall: 300,
        });
        expect(hourBankFigures(1875, 3000, 600).shortfall).toBe(0);
    });

    it('con el exceso del servidor, el saldo es el total menos lo que va dentro (D-019)', () => {
        // Bloqueada en exceso (1 h) y el total ampliado a 2 h después: queda 1 h libre.
        expect(hourBankFigures(120, 120, 30, 60)).toMatchObject({
            consumed: 120,
            inBank: 60,
            remaining: 60,
            overage: 60,
            shortfall: 0,
        });
        // Sin el dato del servidor, se deduce de consumido − total.
        expect(hourBankFigures(150, 120, 0).overage).toBe(30);
        // Nunca más exceso que consumo.
        expect(hourBankFigures(30, 120, 0, 90).overage).toBe(30);
    });
});
