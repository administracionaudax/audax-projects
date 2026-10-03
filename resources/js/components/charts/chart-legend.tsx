import type { LegendItem } from '@/components/charts/chart-config';
import { cn } from '@/lib/utils';

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
                    <span
                        aria-hidden="true"
                        className={cn(
                            'shrink-0',
                            item.shape === 'line'
                                ? 'h-0.5 w-4 rounded-full'
                                : 'size-2.5 rounded-sm',
                        )}
                        style={{ backgroundColor: item.color }}
                    />
                    {item.label}
                </li>
            ))}
        </ul>
    );
}
