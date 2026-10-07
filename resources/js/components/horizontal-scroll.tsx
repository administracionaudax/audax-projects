import { useEffect, useRef, useState } from 'react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type Edges = { left: boolean; right: boolean };

/**
 * Contenedor con scroll horizontal que se nota (D-329): cuando hay contenido oculto a un lado, una
 * sombra suave en ese borde lo indica. Sin nada que desplazar, no se ve ninguna. Las props van al
 * elemento que se desplaza (región, etiqueta, `tabIndex`…).
 */
export function HorizontalScroll({
    className,
    children,
    ...props
}: ComponentProps<'div'>) {
    const ref = useRef<HTMLDivElement>(null);
    const [edges, setEdges] = useState<Edges>({ left: false, right: false });

    useEffect(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        const update = () => {
            const max = element.scrollWidth - element.clientWidth;
            const next = {
                left: element.scrollLeft > 1,
                right: max > 1 && element.scrollLeft < max - 1,
            };

            setEdges((current) =>
                current.left === next.left && current.right === next.right
                    ? current
                    : next,
            );
        };

        update();
        element.addEventListener('scroll', update, { passive: true });
        const observer =
            typeof ResizeObserver === 'undefined'
                ? null
                : new ResizeObserver(update);
        observer?.observe(element);

        if (element.firstElementChild) {
            observer?.observe(element.firstElementChild);
        }

        return () => {
            element.removeEventListener('scroll', update);
            observer?.disconnect();
        };
    }, []);

    return (
        <div className="relative min-w-0">
            <div
                ref={ref}
                className={cn('overflow-x-auto', className)}
                data-scroll-left={edges.left ? 'true' : undefined}
                data-scroll-right={edges.right ? 'true' : undefined}
                {...props}
            >
                {children}
            </div>
            <span
                aria-hidden="true"
                className={cn(
                    'pointer-events-none absolute inset-y-px left-px w-6 rounded-l-md bg-linear-to-r from-foreground/15 to-transparent transition-opacity',
                    edges.left ? 'opacity-100' : 'opacity-0',
                )}
            />
            <span
                aria-hidden="true"
                className={cn(
                    'pointer-events-none absolute inset-y-px right-px w-6 rounded-r-md bg-linear-to-l from-foreground/15 to-transparent transition-opacity',
                    edges.right ? 'opacity-100' : 'opacity-0',
                )}
                data-test="scroll-hint-right"
            />
        </div>
    );
}
