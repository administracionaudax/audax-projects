import { useLayoutEffect, useRef, useState } from 'react';

/**
 * Ancho de un contenedor, para dibujar las gráficas SVG a su tamaño real (el texto no se deforma).
 * Sin ResizeObserver (pruebas, navegadores antiguos), `fallback`.
 */
export function useWidth<T extends HTMLElement>(fallback = 720) {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(fallback);

    useLayoutEffect(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        const measure = () => {
            const value = element.getBoundingClientRect().width;

            if (value > 0) {
                setWidth(Math.round(value));
            }
        };

        measure();

        if (typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(measure);
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return [ref, width] as const;
}
