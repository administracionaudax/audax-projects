import type { Ref } from 'react';
import { KeywordText } from '@/components/keyword-text';

/**
 * Encabezado de página o de sección. El título admite palabras clave con [[…]]
 * (solo en la variante grande: el azul de marca necesita tamaño de texto grande para AA).
 *
 * `as` fija el nivel (UI-10): un solo h1 por página, que es el título de la página
 * (as="h1"); las secciones van en h2 (valor por defecto).
 */
export default function Heading({
    title,
    description,
    variant = 'default',
    as: Tag = 'h2',
    ref,
    tabIndex,
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    as?: 'h1' | 'h2' | 'h3';
    /** Destino estable de foco (con tabIndex={-1}) tras acciones que quitan elementos. */
    ref?: Ref<HTMLHeadingElement>;
    tabIndex?: number;
}) {
    return (
        <header className={variant === 'small' ? '' : 'mb-8 space-y-1'}>
            <Tag
                ref={ref}
                tabIndex={tabIndex}
                className={
                    variant === 'small'
                        ? 'mb-0.5 text-base font-medium focus:outline-none'
                        : 'text-2xl font-normal tracking-tight focus:outline-none'
                }
            >
                {variant === 'small' ? title : <KeywordText text={title} />}
            </Tag>
            {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
        </header>
    );
}
