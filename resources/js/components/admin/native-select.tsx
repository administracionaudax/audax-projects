import { ChevronDown } from 'lucide-react';
import type { ComponentProps } from 'react';
import { FOCUS_RING } from '@/lib/focus-ring';
import { cn } from '@/lib/utils';

/**
 * Selector nativo con el aspecto de <Input>: accesible por teclado y lector de pantalla sin
 * esfuerzo y cómodo en el móvil (abre el selector del sistema). Para filtros y formularios de
 * administración y clientes.
 */
export function NativeSelect({
    className,
    children,
    ...props
}: ComponentProps<'select'>) {
    return (
        <div className={cn('relative', className)}>
            <select
                {...props}
                className={cn(
                    'h-9 w-full min-w-0 appearance-none rounded-md border border-input bg-background py-1 pr-8 pl-3 text-base text-foreground disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive md:text-sm',
                    FOCUS_RING,
                    'focus-visible:border-ring',
                )}
            >
                {children}
            </select>
            <ChevronDown
                aria-hidden="true"
                className="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-muted-foreground"
            />
        </div>
    );
}
