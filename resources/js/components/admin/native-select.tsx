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
    bare = false,
    children,
    ...props
}: ComponentProps<'select'> & {
    /**
     * Sin borde, fondo ni alto propios: para meterlo en un recuadro que ya los tiene (los «chips»
     * de filtro con etiqueta). Ocupa todo el alto del recuadro.
     */
    bare?: boolean;
}) {
    return (
        <div className={cn('relative', bare && 'h-full', className)}>
            <select
                {...props}
                className={cn(
                    // Sin fondo propio, como <Input>: sobre una tarjeta blanca no se ve gris. Las
                    // opciones sí llevan el fondo del desplegable (en Windows heredan el del select).
                    'h-9 w-full min-w-0 appearance-none rounded-md border border-input bg-transparent py-1 pr-8 pl-3 text-base text-foreground disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive md:text-sm [&_option]:bg-popover [&_option]:text-popover-foreground',
                    FOCUS_RING,
                    'focus-visible:border-ring',
                    bare && 'h-full border-0',
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
