import { LAYER_SVG_FILL } from '@/components/forecast/layer-swatch';
import { LAYERS } from '@/lib/forecast';
import type { LayerToggles } from '@/lib/forecast';
import type { LoadCell } from '@/types/forecast';

/** Separación entre segmentos de una columna apilada, en el color de la superficie (D-291). */
export const STACK_GAP = 2;

/**
 * Un periodo de la fila de un departamento (D-290): la columna apilada (real abajo, seguro encima y
 * posible arriba, con trama) frente a la línea de capacidad, escalonada: un trazo horizontal a la
 * altura de su capacidad y un trazo vertical que la une con la del periodo anterior. La escala es la
 * del propio departamento (`yMax`). Presentacional: el texto va fuera.
 */
export function DepartmentColumn({
    cell,
    previousCapacity,
    yMax,
    width,
    height,
    barWidth,
    layers,
}: {
    cell: LoadCell;
    previousCapacity: number | null;
    yMax: number;
    width: number;
    height: number;
    barWidth: number;
    layers: LayerToggles;
}) {
    const top = 4;
    const bottom = height - 1;
    const y = (value: number) =>
        yMax <= 0 ? bottom : top + (bottom - top) * (1 - Math.min(value, yMax) / yMax);
    const x = (width - barWidth) / 2;
    let base = 0;
    const segments = LAYERS.filter((layer) => layers[layer] && cell[layer] > 0).map(
        (layer, index) => {
            const from = y(base);
            const to = y(base + cell[layer]);
            base += cell[layer];

            return {
                layer,
                y: to,
                height: Math.max(0, from - to - (index > 0 ? STACK_GAP : 0)),
            };
        },
    );
    const capacityY = y(cell.capacity);

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
            <line x1={0} x2={width} y1={bottom} y2={bottom} stroke="var(--muted-foreground)" strokeWidth={1} />
            {cell.capacity > 0 || (previousCapacity ?? 0) > 0 ? (
                <path
                    data-test="capacity-line"
                    d={`${previousCapacity !== null ? `M0,${y(previousCapacity)} L0,${capacityY}` : `M0,${capacityY}`} L${width},${capacityY}`}
                    fill="none"
                    stroke="var(--foreground)"
                    strokeWidth={2}
                />
            ) : null}
        </svg>
    );
}
