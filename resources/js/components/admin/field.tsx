import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/**
 * Campo de formulario: etiqueta visible, control, ayuda opcional y error junto al campo.
 * El control recibe `id` (para la etiqueta) y, si hay ayuda o error, `aria-describedby`.
 */
export function Field({
    id,
    label,
    help,
    error,
    optional,
    className,
    children,
}: {
    id: string;
    label: string;
    help?: ReactNode;
    error?: string;
    /** Texto «(opcional)» junto a la etiqueta. */
    optional?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('grid content-start gap-2', className)}>
            <Label htmlFor={id}>
                {label}
                {optional ? (
                    <span className="ml-1 font-normal text-muted-foreground">
                        {optional}
                    </span>
                ) : null}
            </Label>
            {children}
            {help ? (
                <p id={`${id}-help`} className="text-sm text-muted-foreground">
                    {help}
                </p>
            ) : null}
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

/** Ids para `aria-describedby` de un campo con ayuda y/o error. */
export function describedBy(
    id: string,
    { help, error }: { help?: boolean; error?: string },
): string | undefined {
    const ids = [help ? `${id}-help` : null, error ? `${id}-error` : null]
        .filter(Boolean)
        .join(' ');

    return ids === '' ? undefined : ids;
}
