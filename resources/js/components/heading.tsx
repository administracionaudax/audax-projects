import { KeywordText } from '@/components/keyword-text';

/**
 * Encabezado de página o de sección. El título admite palabras clave con [[…]]
 * (solo en la variante grande: el azul de marca necesita tamaño de texto grande para AA).
 */
export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    return (
        <header className={variant === 'small' ? '' : 'mb-8 space-y-1'}>
            <h2
                className={
                    variant === 'small'
                        ? 'mb-0.5 text-base font-medium'
                        : 'text-2xl font-normal tracking-tight'
                }
            >
                {variant === 'small' ? title : <KeywordText text={title} />}
            </h2>
            {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
        </header>
    );
}
