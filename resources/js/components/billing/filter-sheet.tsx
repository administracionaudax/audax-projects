import { SlidersHorizontal } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Filtros de las pantallas de Facturación en el móvil (I7, D-415): por debajo de 768 px van en una
 * hoja inferior tras un botón «Filtros (2)», para que no se coman el primer pliegue; en escritorio
 * se pintan en su sitio, igual que siempre. Se pintan una sola vez (en la hoja o en la página), así
 * que los controles no se duplican.
 */
export function FilterSheet({
    count,
    children,
    className,
}: {
    /** Filtros elegidos (sin contar el periodo por defecto). */
    count: number;
    children: ReactNode;
    /** Clases del contenedor en escritorio. */
    className?: string;
}) {
    const mobile = useIsMobile();

    if (!mobile) {
        return className ? (
            <div className={className}>{children}</div>
        ) : (
            children
        );
    }

    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className={cn('self-start', count > 0 && 'border-primary')}
                    data-test="filter-sheet-trigger"
                >
                    <SlidersHorizontal aria-hidden="true" />
                    {count > 0
                        ? t('billing.filters.sheet_count', { count })
                        : t('billing.filters.sheet')}
                </Button>
            </SheetTrigger>
            <SheetContent
                side="bottom"
                className="max-h-[85svh] overflow-y-auto"
                data-test="filter-sheet"
            >
                <SheetHeader>
                    <SheetTitle>{t('billing.filters.sheet')}</SheetTitle>
                    <SheetDescription>
                        {t('billing.filters.sheet_description')}
                    </SheetDescription>
                </SheetHeader>
                <div className="grid gap-3 px-4 pb-6 [&_[role=combobox]]:max-w-none [&>div]:min-w-0">
                    {children}
                </div>
            </SheetContent>
        </Sheet>
    );
}

/** Cuántos de esos filtros lleva la query de la URL (una lista vacía no cuenta). */
export function countQueryFilters(
    query: Record<string, unknown>,
    keys: ReadonlyArray<string>,
): number {
    return keys.filter((key) => {
        const value = query[key];

        return Array.isArray(value)
            ? value.length > 0
            : value !== undefined && value !== null && value !== '';
    }).length;
}
