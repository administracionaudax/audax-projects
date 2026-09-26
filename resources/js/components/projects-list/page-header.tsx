import type { ReactNode } from 'react';
import { KeywordText } from '@/components/keyword-text';

/**
 * Cabecera de página de los listados del área: el único h1 de la página (admite palabras clave
 * de marca con [[…]]), una descripción y las acciones a la derecha.
 */
export function PageHeader({
    title,
    description,
    actions,
}: {
    title: string;
    description?: string;
    actions?: ReactNode;
}) {
    return (
        <header className="flex flex-wrap items-start justify-between gap-4">
            <div className="min-w-0 space-y-1">
                <h1 className="text-2xl font-normal tracking-tight">
                    <KeywordText text={title} />
                </h1>
                {description ? (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </div>
            {actions ? (
                <div className="flex flex-wrap gap-2">{actions}</div>
            ) : null}
        </header>
    );
}
