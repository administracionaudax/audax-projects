import type { TooltipRow } from '@/components/charts/chart-config';
import { cn } from '@/lib/utils';

type ChartTooltipCardProps = {
    title?: string;
    rows: ReadonlyArray<TooltipRow>;
    className?: string;
};

/**
 * Tarjeta de tooltip: el valor manda (font-medium, cifras tabulares) y el nombre de la
 * serie va detrás en tinta secundaria. Cada fila lleva una clave de línea con el color de la serie.
 */
export function ChartTooltipCard({
    title,
    rows,
    className,
}: ChartTooltipCardProps) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <div
            className={cn(
                'min-w-36 border bg-popover px-3 py-2 text-sm text-popover-foreground',
                className,
            )}
        >
            {title ? (
                <p className="mb-1.5 text-xs text-muted-foreground">{title}</p>
            ) : null}
            <ul className="grid gap-1">
                {rows.map((row) => (
                    <li key={row.key} className="flex items-center gap-2">
                        {row.pattern === 'hatch' ? (
                            <span
                                aria-hidden="true"
                                className="h-2 w-3 shrink-0 bg-hatch-tentative"
                            />
                        ) : (
                            <span
                                aria-hidden="true"
                                className="h-0.5 w-3 shrink-0"
                                style={{ backgroundColor: row.color }}
                            />
                        )}
                        <span className="tabular font-medium">{row.value}</span>
                        <span className="text-muted-foreground">
                            {row.label}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
