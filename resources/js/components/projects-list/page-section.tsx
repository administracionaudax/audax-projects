import type { ReactNode } from 'react';
import { useId } from 'react';
import { cn } from '@/lib/utils';

/**
 * Sección de una página del área con su h2 (el h1 es el del proyecto o el de la página), una
 * descripción opcional y una acción a la derecha.
 */
export function PageSection({
    title,
    description,
    action,
    children,
    className,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    const id = useId();

    return (
        <section
            aria-labelledby={id}
            className={cn('grid content-start gap-4', className)}
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <h2 id={id} className="text-lg font-normal">
                        {title}
                    </h2>
                    {description ? (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    ) : null}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}
