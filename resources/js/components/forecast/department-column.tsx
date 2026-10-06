import { LAYER_SVG_FILL } from '@/components/forecast/layer-swatch';
import { LAYERS } from '@/lib/forecast';
import type { LayerToggles } from '@/lib/forecast';
import type { LoadCell } from '@/types/forecast';

/** Separación entre segmentos de una columna apilada, en el color de la superficie (D-291). */
export const STACK_GAP = 2;

/**
 * Un periodo de la fila de un departamento (D-290 y D-263): la columna apilada (real abajo, seguro
 * encima y posible arriba, con trama) en **porcentaje de la capacidad de ese periodo**, frente a la
 * línea del 100 %, recta en todos los periodos (el propietario la prefirió así a la capacidad en
 * horas, que se escalonaba con los días laborables). `yMax` es la escala del departamento en
 * proporción (1 = 100 %). Sin capacidad (festivos o ausencias), no hay columna. Presentacional.
 */
export function DepartmentColumn({
    cell,
    yMax,
    width,
    height,
    barWidth,
    layers,
}: {
    cell: LoadCell;
    yMax: number;
    width: number;
    height: number;
    barWidth: number;
    layers: LayerToggles;
}) {
    const top = 4;
    const bottom = height - 1;
    const y = (value: number) =>
        yMax <= 0
            ? bottom
            : top + (bottom - top) * (1 - Math.min(value, yMax) / yMax);
    const x = (width - barWidth) / 2;
    const share = (minutes: number) =>
        cell.capacity > 0 ? minutes / cell.capacity : 0;
    let base = 0;
    const segments = LAYERS.filter(
        (layer) => layers[layer] && cell[layer] > 0 && cell.capacity > 0,
    ).map((layer, index) => {
        const from = y(base);
        const to = y(base + share(cell[layer]));
        base += share(cell[layer]);

        return {
            layer,
            y: to,
            height: Math.max(0, from - to - (index > 0 ? STACK_GAP : 0)),
        };
    });
    const capacityY = y(1);

    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            aria-hidden="true"
            focusable="false"
            className="block overflow-visible"
        >
            {segments.map((segment) =>
                segment.height > 0.2 ? (
                    <rect
                        key={segment.layer}
                        data-layer={segment.layer}
                        x={x}
                        y={segment.y}
                        width={barWidth}
                        height={segment.height}
                        fill={LAYER_SVG_FILL[segment.layer]}
                    />
                ) : null,
            )}
            <line
                x1={0}
                x2={width}
                y1={bottom}
                y2={bottom}
                stroke="var(--muted-foreground)"
                strokeWidth={1}
            />
            <path
                data-test="capacity-line"
                d={`M0,${capacityY} L${width},${capacityY}`}
                fill="none"
                stroke="var(--foreground)"
                strokeOpacity={0.6}
                strokeWidth={1}
                strokeDasharray={cell.capacity > 0 ? undefined : '4 4'}
            />
        </svg>
    );
}
