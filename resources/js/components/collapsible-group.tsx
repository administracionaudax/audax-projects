import {
    ChevronDown,
    ChevronRight,
    ChevronsDownUp,
    ChevronsUpDown,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Encabezado de un grupo plegable (D-320): el título es un botón con chevron, `aria-expanded` y
 * `aria-controls` (el contenido que pliega), con el número de elementos al lado. Se maneja con el
 * teclado como cualquier botón (Intro o espacio).
 */
export function CollapsibleGroupHeading({
    id,
    contentId,
    expanded,
    onToggle,
    label,
    count,
    marker,
    as: Heading = 'h2',
    className,
    'data-test': dataTest,
}: {
    /** Id del encabezado (la sección lo usa en aria-labelledby). */
    id: string;
    /** Id del contenido plegable. */
    contentId: string;
    expanded: boolean;
    onToggle: () => void;
    label: ReactNode;
    /** Texto del número de elementos, p. ej. «(12)». */
    count?: ReactNode;
    /** Punto de color o icono delante del nombre. */
    marker?: ReactNode;
    as?: 'h2' | 'h3';
    className?: string;
    'data-test'?: string;
}) {
    const Chevron = expanded ? ChevronDown : ChevronRight;

    return (
        <Heading id={id} className={cn('text-base font-medium', className)}>
            <button
                type="button"
                aria-expanded={expanded}
                aria-controls={contentId}
                onClick={onToggle}
                className={cn(
                    '-mx-1 inline-flex max-w-full min-w-0 items-center gap-2 rounded-md px-1 py-0.5 text-left hover:bg-accent',
                    FOCUS_RING,
                )}
                data-test={dataTest}
            >
                <Chevron
                    aria-hidden="true"
                    className="size-4 shrink-0 text-muted-foreground"
                />
                {marker}
                <span className="min-w-0 truncate">{label}</span>
                {count !== undefined ? (
                    <span className="shrink-0 text-sm font-normal text-muted-foreground">
                        {count}
                    </span>
                ) : null}
            </button>
        </Heading>
    );
}

/** «Desplegar todo» y «Plegar todo», discretos, para una lista de grupos plegables. */
export function GroupFoldControls({
    onExpandAll,
    onCollapseAll,
    allExpanded,
    allCollapsed,
    className,
}: {
    onExpandAll: () => void;
    onCollapseAll: () => void;
    allExpanded: boolean;
    allCollapsed: boolean;
    className?: string;
}) {
    return (
        <div
            className={cn('flex flex-wrap items-center gap-1', className)}
            data-test="group-fold-controls"
        >
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-7 px-2 text-xs font-normal text-muted-foreground"
                onClick={onExpandAll}
                disabled={allExpanded}
            >
                <ChevronsUpDown aria-hidden="true" className="size-3.5" />
                {t('groups.expand_all')}
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-7 px-2 text-xs font-normal text-muted-foreground"
                onClick={onCollapseAll}
                disabled={allCollapsed}
            >
                <ChevronsDownUp aria-hidden="true" className="size-3.5" />
                {t('groups.collapse_all')}
            </Button>
        </div>
    );
}
