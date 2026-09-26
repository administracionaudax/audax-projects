import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { KeywordText } from '@/components/keyword-text';
import { Badge } from '@/components/ui/badge';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Fases del SPEC §17 en las que llega cada funcionalidad. */
export type Phase = 1 | 2 | 3 | 4 | 5 | 6 | 7;

export function PhaseBadge({
    phase,
    className,
}: {
    phase: Phase;
    className?: string;
}) {
    return (
        <Badge
            variant="outline"
            className={cn(
                'border-border font-normal text-muted-foreground',
                className,
            )}
        >
            {t('common.coming_in_phase', { phase })}
        </Badge>
    );
}

/** Estado vacío compacto (tarjetas del panel, listas sin datos). */
export function EmptyState({
    icon: Icon,
    title,
    description,
    phase,
    className,
    children,
}: {
    icon?: LucideIcon;
    title: string;
    description?: string;
    phase?: Phase;
    className?: string;
    children?: ReactNode;
}) {
    return (
        <div
            className={cn(
                'flex flex-col items-start gap-3 rounded-md border border-dashed bg-muted/60 p-4',
                className,
            )}
        >
            {Icon && (
                <Icon
                    aria-hidden="true"
                    className="size-5 text-muted-foreground"
                    strokeWidth={1.5}
                />
            )}
            <div className="space-y-1">
                <p className="text-sm font-medium text-foreground">{title}</p>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {phase !== undefined && <PhaseBadge phase={phase} />}
            {children}
        </div>
    );
}

/**
 * Estado vacío grande con el degradado de marca (SPEC §3.1: solo login, cabecera del portal
 * y estados vacíos grandes). El bloque se pinta con los tokens del tema oscuro (clase `dark`)
 * para que el texto y la palabra clave cumplan el contraste sobre el degradado.
 */
export function HeroEmptyState({
    icon: Icon,
    eyebrow,
    title,
    description,
    phase,
    children,
    className,
}: {
    icon?: LucideIcon;
    eyebrow?: string;
    /** Admite palabras clave con [[…]]. */
    title: string;
    description?: string;
    phase?: Phase;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn(
                'dark relative overflow-hidden rounded-md px-6 py-12 text-foreground bg-brand-gradient sm:px-10 sm:py-16',
                className,
            )}
        >
            <div className="relative flex max-w-2xl flex-col items-start gap-5">
                {Icon && (
                    <span className="flex size-12 items-center justify-center rounded-md border border-border bg-muted">
                        <Icon
                            aria-hidden="true"
                            className="size-6 text-foreground"
                            strokeWidth={1.5}
                        />
                    </span>
                )}
                {eyebrow && (
                    <p className="text-sm tracking-wide text-muted-foreground uppercase">
                        {eyebrow}
                    </p>
                )}
                <h1 className="text-3xl leading-tight font-normal text-balance sm:text-4xl">
                    <KeywordText
                        text={title}
                        keywordClassName="text-primary-text"
                    />
                </h1>
                {description && (
                    <p className="max-w-xl text-base text-muted-foreground">
                        {description}
                    </p>
                )}
                {phase !== undefined && (
                    <Badge
                        variant="outline"
                        className="border-border bg-muted font-normal text-foreground"
                    >
                        {t('common.coming_in_phase', { phase })}
                    </Badge>
                )}
                {children}
            </div>
        </section>
    );
}
