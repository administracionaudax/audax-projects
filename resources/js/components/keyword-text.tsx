import { Fragment } from 'react';
import { cn } from '@/lib/utils';

/**
 * Recurso de marca (SPEC §3.1): palabras clave en azul dentro de un titular navy.
 * Las traducciones marcan la palabra clave con dobles corchetes:
 * "Carga de la [[semana que viene]]" → "Carga de la <span class="text-brand">semana que viene</span>".
 */
export function splitKeywords(
    text: string,
): { text: string; keyword: boolean }[] {
    return text
        .split(/(\[\[[^\]]+\]\])/u)
        .filter((part) => part !== '')
        .map((part) =>
            part.startsWith('[[') && part.endsWith(']]')
                ? { text: part.slice(2, -2), keyword: true }
                : { text: part, keyword: false },
        );
}

/** Quita las marcas de palabra clave (para <title>, aria-label…). */
export function stripKeywords(text: string): string {
    return splitKeywords(text)
        .map((part) => part.text)
        .join('');
}

export function KeywordText({
    text,
    keywordClassName = 'text-brand',
}: {
    text: string;
    /** Sobre fondos oscuros usa un azul claro accesible (p. ej. dentro de `.dark`). */
    keywordClassName?: string;
}) {
    return (
        <>
            {splitKeywords(text).map((part, index) =>
                part.keyword ? (
                    <span key={index} className={cn(keywordClassName)}>
                        {part.text}
                    </span>
                ) : (
                    <Fragment key={index}>{part.text}</Fragment>
                ),
            )}
        </>
    );
}
