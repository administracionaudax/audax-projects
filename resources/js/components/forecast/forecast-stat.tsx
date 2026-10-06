import type { ReactNode } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

/**
 * Cifra grande de la previsión (D-302): la etiqueta, el valor a 600 y una línea de detalle (con su
 * icono de nivel si toca). Plana, como las tarjetas de la hoja de Audax (D-137).
 */
export function ForecastStat({
    label,
    value,
    detail,
    loading = false,
    className,
}: {
    label: string;
    value: ReactNode;
    detail?: ReactNode;
    loading?: boolean;
    className?: string;
}) {
    return (
        <div className={cn('flex min-w-0 flex-col gap-1 border bg-card p-4', className)} data-test="forecast-stat">
            <p className="text-sm text-muted-foreground">{label}</p>
            {loading ? (
                <Skeleton className="h-8 w-20" />
            ) : (
                <p className="tabular text-2xl font-semibold tracking-tight">{value}</p>
            )}
            {detail ? <div className="flex min-w-0 items-start gap-1 text-xs text-muted-foreground">{detail}</div> : null}
        </div>
    );
}
