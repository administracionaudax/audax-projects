import { CircleHelp } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Ayuda en contexto de Facturación (R7, D-415): un «?» discreto junto a un título o una cifra que
 * abre una explicación breve. Se abre al pulsar (también con el teclado y en el móvil, donde no hay
 * hover), no al pasar el ratón.
 */
export function HelpTip({
    topic,
    children,
    className,
    dataTest,
}: {
    /** De qué es la ayuda («Por cobrar»): el nombre accesible del botón. */
    topic: string;
    children: ReactNode;
    className?: string;
    dataTest?: string;
}) {
    return (
        <Popover>
            <PopoverTrigger
                type="button"
                aria-label={t('billing.help.about', { topic })}
                className={cn(
                    'inline-flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:text-foreground',
                    FOCUS_RING,
                    className,
                )}
                data-test={dataTest ?? 'help-tip'}
            >
                <CircleHelp aria-hidden="true" className="size-4" />
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-80 max-w-[calc(100vw-2rem)] text-sm leading-relaxed"
            >
                {children}
            </PopoverContent>
        </Popover>
    );
}

/** Título de sección (h2) con su «?» al lado, para las secciones de Facturación. */
export function TitleWithHelp({
    id,
    title,
    help,
    as: Tag = 'h2',
    className,
}: {
    id?: string;
    title: string;
    help: ReactNode;
    as?: 'h2' | 'h3';
    className?: string;
}) {
    return (
        <div className={cn('flex items-center gap-1', className)}>
            <Tag
                id={id}
                className={Tag === 'h2' ? 'text-lg font-normal' : 'text-base'}
            >
                {title}
            </Tag>
            <HelpTip topic={title}>{help}</HelpTip>
        </div>
    );
}
