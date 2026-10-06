import { cn } from '@/lib/utils';
import type { LoadLayer } from '@/types/forecast';

/** Fondo de cada capa (D-291): real en --chart-1, previsto en --chart-3 y lo posible con trama. */
export const LAYER_FILL: Record<LoadLayer, string> = {
    real: 'bg-chart-1',
    firm: 'bg-chart-3',
    tentative: 'bg-hatch-tentative',
};

/** Relleno SVG de cada capa: la trama sale del patrón de HatchDefs. */
export const LAYER_SVG_FILL: Record<LoadLayer, string> = {
    real: 'var(--chart-1)',
    firm: 'var(--chart-3)',
    tentative: 'url(#forecast-hatch)',
};

/** Muestra de una capa (leyenda, casillas, tablas). */
export function LayerSwatch({
    layer,
    className,
}: {
    layer: LoadLayer;
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            data-layer={layer}
            className={cn('inline-block size-2.5 shrink-0', LAYER_FILL[layer], className)}
        />
    );
}

/**
 * El patrón SVG de la trama de lo posible (rayas de 2 px a 45° de --chart-3 sobre --posible-bg),
 * una vez por página: las gráficas lo usan con `fill="url(#forecast-hatch)"`.
 */
export function HatchDefs() {
    return (
        <svg
            width="0"
            height="0"
            aria-hidden="true"
            focusable="false"
            className="absolute"
            style={{ forcedColorAdjust: 'none' }}
        >
            <defs>
                <pattern
                    id="forecast-hatch"
                    width="5"
                    height="5"
                    patternUnits="userSpaceOnUse"
                    patternTransform="rotate(45)"
                >
                    <rect width="5" height="5" fill="var(--posible-bg)" />
                    <rect width="2" height="5" fill="var(--chart-3)" />
                </pattern>
            </defs>
        </svg>
    );
}
