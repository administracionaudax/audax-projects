import type { ReactNode } from 'react';
import { useId } from 'react';
import { cn } from '@/lib/utils';

/**
 * Grupo de cifras con la misma base (D-410): el rótulo va una vez por grupo («Facturación (sin
 * IVA)», «Cobros (con IVA)», «Horas»), en mayúsculas pequeñas y texto secundario, y no en cada
 * tarjeta.
 */
export function KpiGroup({
    title,
    className,
    columns,
    children,
}: {
    title: string;
    className?: string;
    /** Clases de la rejilla de tarjetas. */
    columns?: string;
    children: ReactNode;
}) {
    const id = useId();

    return (
        <section aria-labelledby={id} className={cn('grid gap-2', className)}>
            <h2
                id={id}
                className="text-xs font-medium tracking-[0.12em] text-muted-foreground uppercase"
            >
                {title}
            </h2>
            <div className={cn('grid gap-3', columns)}>{children}</div>
        </section>
    );
}
