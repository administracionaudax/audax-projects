import { cn } from '@/lib/utils';

/**
 * Estilos del texto enriquecido (descripción y comentarios), en el editor y al leerlo: párrafos,
 * listas, citas, código, enlaces y menciones (@persona en azul de texto, AA). La «negrita» se pinta
 * con el peso 500 de DM Sans: el tema nunca usa negritas (ni sintéticas).
 */
export const RICH_TEXT_CLASSES = cn(
    'text-sm leading-relaxed break-words',
    '[&_b]:font-medium [&_strong]:font-medium',
    '[&_p]:my-1.5 [&_p:first-child]:mt-0 [&_p:last-child]:mb-0',
    '[&_h3]:mt-3 [&_h3]:mb-1 [&_h3]:text-base [&_h3]:font-medium [&_h4]:mt-2 [&_h4]:mb-1 [&_h4]:font-medium',
    '[&_ol]:my-1.5 [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:my-1.5 [&_ul]:list-disc [&_ul]:pl-5',
    '[&_blockquote]:my-2 [&_blockquote]:border-l-2 [&_blockquote]:pl-3 [&_blockquote]:text-muted-foreground',
    '[&_code]:rounded-md [&_code]:bg-muted [&_code]:px-1 [&_code]:text-[0.85em] [&_pre]:my-2 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-muted [&_pre]:p-2',
    '[&_a]:text-primary-text [&_a]:underline [&_hr]:my-3',
    '[&_[data-type=mention]]:rounded-md [&_[data-type=mention]]:bg-accent [&_[data-type=mention]]:px-0.5 [&_[data-type=mention]]:text-primary-text',
);

/**
 * Pinta HTML que YA viene saneado del servidor (App\Support\RichText::sanitize): nunca se pasa
 * aquí texto que no haya pasado por el servidor.
 */
export function RichTextContent({
    html,
    className,
}: {
    html: string;
    className?: string;
}) {
    return (
        <div
            className={cn(RICH_TEXT_CLASSES, className)}
            // HTML saneado en el servidor con una lista cerrada de etiquetas y atributos.
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}
