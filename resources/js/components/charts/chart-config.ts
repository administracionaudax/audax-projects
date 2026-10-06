/**
 * Configuración común de las gráficas (SPEC §3.1, docs/DECISIONES.md D-007 y D-012).
 *
 * - Los colores salen SOLO de los tokens del tema (var(--chart-n) y compañía): el modo
 *   oscuro los cambia por CSS, sin lógica en JavaScript.
 * - La paleta categórica se asigna en ORDEN FIJO y nunca se cicla: una 7.ª serie es un
 *   error de diseño (se agrupa en "Otros" o se divide en varias gráficas).
 * - Las horas se reciben en minutos enteros y se muestran como h:mm (formatMinutes).
 */
import { formatMinutes, formatNumber } from '@/lib/format';

export const CHART_COLORS = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
    'var(--chart-6)',
] as const;

export type ChartColor = (typeof CHART_COLORS)[number];

/** Tinta de la gráfica: ejes y rejilla recesivos; los textos nunca llevan el color de la serie. */
export const CHART_INK = {
    axis: 'var(--muted-foreground)',
    grid: 'var(--border)',
    label: 'var(--foreground)',
    surface: 'var(--card)',
    empty: 'var(--neutral-soft)',
} as const;

/** Grosor de las líneas y separación entre marcas (skill de visualización: marks-and-anatomy). */
export const LINE_WIDTH = 2;
export const SURFACE_GAP = 2;
export const MAX_BAR_SIZE = 24;
/** Estilo plano (D-137 y D-307): las columnas, sin esquinas redondeadas. */
export const BAR_RADIUS = 0;

/**
 * Capas de la previsión (D-291): el color dice de qué tipo es la hora (real, previsto e imputado) y
 * la trama dice «puede no salir» (lo posible usa el mismo violeta que lo seguro, con trama).
 */
export const LAYER_COLORS = {
    real: 'var(--chart-1)',
    firm: 'var(--chart-3)',
    tentative: 'var(--chart-3)',
    logged: 'var(--chart-2)',
} as const;

/** Línea de capacidad (D-291): 2 px en tinta, escalonada. */
export const CAPACITY_STROKE = 'var(--foreground)';

/** Color de la serie en la posición `index` (0 → var(--chart-1)). Fuera de rango, error: nunca se cicla. */
export function seriesColor(index: number): ChartColor {
    if (!Number.isInteger(index) || index < 0 || index >= CHART_COLORS.length) {
        throw new RangeError(
            `Serie ${index + 1}: la paleta tiene ${CHART_COLORS.length} colores y no se cicla. Agrupa el resto en "Otros".`,
        );
    }

    return CHART_COLORS[index];
}

export type SeriesInput<K extends string = string> = {
    key: K;
    label: string;
};

export type SeriesDef<K extends string = string> = SeriesInput<K> & {
    color: ChartColor;
};

/**
 * Asigna los colores por orden de declaración. El color sigue a la entidad (la clave),
 * no a su posición tras filtrar: filtra DESPUÉS de definir las series, nunca antes.
 */
export function defineSeries<K extends string>(
    series: ReadonlyArray<SeriesInput<K>>,
): SeriesDef<K>[] {
    const keys = new Set<string>();

    return series.map((item, index) => {
        if (keys.has(item.key)) {
            throw new Error(`Serie duplicada: ${item.key}`);
        }

        keys.add(item.key);

        return { ...item, color: seriesColor(index) };
    });
}

/** Marca de eje de horas: 450 → "7,5 h". Los ejes usan horas redondas; el detalle va en el tooltip. */
export function formatHoursTick(minutes: number): string {
    if (!Number.isFinite(minutes)) {
        return '';
    }

    return `${formatNumber(minutes / 60, 1)} h`;
}

/** Marcas de eje "limpias" (múltiplos de 1, 2, 2,5, 5, 10… horas) desde 0 hasta cubrir `maxMinutes`. */
export function hourTicks(maxMinutes: number, targetCount = 5): number[] {
    if (!Number.isFinite(maxMinutes) || maxMinutes <= 0) {
        return [0];
    }

    const rawStep = maxMinutes / 60 / Math.max(targetCount - 1, 1);
    const magnitude = 10 ** Math.floor(Math.log10(rawStep));
    const stepHours =
        [1, 2, 2.5, 5, 10]
            .map((m) => m * magnitude)
            .find((s) => s >= rawStep) ?? 10 * magnitude;
    const step = stepHours * 60;
    const ticks: number[] = [];

    for (let value = 0; value < maxMinutes + step; value += step) {
        ticks.push(Math.round(value));

        if (value >= maxMinutes) {
            break;
        }
    }

    return ticks;
}

export type TooltipRow = {
    key: string;
    label: string;
    color: string;
    value: string;
    /** «hatch»: la clave lleva la trama de lo posible (D-291). */
    pattern?: 'hatch';
};

type PayloadItem = {
    dataKey?: unknown;
    value?: unknown;
};

/**
 * Filas del tooltip en el ORDEN DE LAS SERIES (no en el que llegan de Recharts),
 * con los valores ya formateados (h:mm por defecto).
 */
export function buildTooltipRows(
    payload: ReadonlyArray<PayloadItem> | undefined,
    series: ReadonlyArray<SeriesDef>,
    format: (value: number) => string = formatMinutes,
): TooltipRow[] {
    if (!payload || payload.length === 0) {
        return [];
    }

    return series.flatMap((serie) => {
        const item = payload.find((p) => String(p.dataKey) === serie.key);
        const value = typeof item?.value === 'number' ? item.value : NaN;

        if (!Number.isFinite(value)) {
            return [];
        }

        return [
            {
                key: serie.key,
                label: serie.label,
                color: serie.color,
                value: format(value),
            },
        ];
    });
}

export type LegendItem = {
    key: string;
    label: string;
    color: string;
    /** «hatch»: rectángulo con la trama de lo posible; «dashed»: rectángulo con borde discontinuo. */
    shape: 'line' | 'rect' | 'hatch' | 'dashed';
};

/** Leyenda: solo con 2 o más series (una sola serie ya la nombra el título). */
export function legendItems(
    series: ReadonlyArray<SeriesDef>,
    shape: LegendItem['shape'],
): LegendItem[] {
    if (series.length < 2) {
        return [];
    }

    return series.map(({ key, label, color }) => ({
        key,
        label,
        color,
        shape,
    }));
}

/**
 * Rampa secuencial de una sola tonalidad (azul de datos) para magnitudes: heatmap diario.
 * Paso 0 = sin horas (gris neutro); 1…5 = mezcla creciente de --chart-1 sobre la tarjeta.
 */
export const SEQUENTIAL_MIX = [35, 52, 68, 84, 100] as const;

export function sequentialColor(step: number): string {
    if (step <= 0) {
        return CHART_INK.empty;
    }

    const index = Math.min(Math.round(step), SEQUENTIAL_MIX.length) - 1;
    const mix = SEQUENTIAL_MIX[index];

    return mix === 100
        ? CHART_COLORS[0]
        : `color-mix(in oklab, ${CHART_COLORS[0]} ${mix}%, ${CHART_INK.surface})`;
}

/** Límites (en minutos) de los pasos del heatmap diario: < 2 h, < 4 h, < 6 h, < 8 h, ≥ 8 h. */
export const DAILY_HOURS_THRESHOLDS = [120, 240, 360, 480] as const;

export function sequentialStep(
    minutes: number,
    thresholds: ReadonlyArray<number> = DAILY_HOURS_THRESHOLDS,
): number {
    if (!Number.isFinite(minutes) || minutes <= 0) {
        return 0;
    }

    const index = thresholds.findIndex((limit) => minutes < limit);

    return index === -1 ? thresholds.length + 1 : index + 1;
}
