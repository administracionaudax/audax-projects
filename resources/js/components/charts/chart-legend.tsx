import type { LegendItem } from '@/components/charts/chart-config';
import { cn } from '@/lib/utils';

/**
 * Muestra de la leyenda con la forma de la marca: línea, rectángulo, la trama de lo posible
 * («hatch», D-291) o un rectángulo de borde discontinuo («dashed»: huecos y previsiones).
 */
export function LegendSwatch({
    shape,
    color,
    className,
}: {
    shape: LegendItem['shape'];
    color: string;
    className?: string;
}) {
    if (shape === 'hatch') {
        return (
            <span
                aria-hidden="true"
                className={cn(
                    'size-2.5 shrink-0 bg-hatch-tentative',
                    className,
                )}
            />
        );
    }

    if (shape === 'dashed') {
        return (
            <span
                aria-hidden="true"
                className={cn(
                    'size-2.5 shrink-0 border border-dashed',
                    className,
                )}
                style={{ borderColor: color }}
            />
        );
    }

    return (
        <span
            aria-hidden="true"
            className={cn(
                'shrink-0',
                shape === 'line' ? 'h-0.5 w-4' : 'size-2.5',
                className,
            )}
            style={{ backgroundColor: color }}
        />
    );
}

/**
 * Leyenda en HTML (no la de Recharts): el texto va en tinta de texto y la identidad
 * la da la clave de color al lado, con la forma de la marca (línea o rectángulo).
 */
export function ChartLegend({
    items,
    className,
}: {
    items: ReadonlyArray<LegendItem>;
    className?: string;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <ul
            className={cn(
                'flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground',
                className,
            )}
        >
            {items.map((item) => (
                <li key={item.key} className="flex items-center gap-1.5">
                    <LegendSwatch shape={item.shape} color={item.color} />
                    {item.label}
                </li>
            ))}
        </ul>
    );
}
