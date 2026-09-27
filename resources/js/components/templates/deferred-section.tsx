import { router, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { t } from '@/lib/i18n';

/** Estado de carga de una sección diferida. */
export function SectionLoading({ label }: { label: string }) {
    return (
        <div role="status" aria-live="polite" className="grid gap-2">
            <span className="sr-only">{label}</span>
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-3/4" />
        </div>
    );
}

/** Error al cargar una sección diferida: se explica y se ofrece reintentar. */
export function SectionError({ prop }: { prop: string }) {
    return (
        <Alert variant="destructive" role="alert">
            <TriangleAlert aria-hidden="true" />
            <AlertDescription className="flex flex-wrap items-center gap-3">
                {t('templates.settings.load_error')}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => router.reload({ only: [prop] })}
                >
                    {t('templates.settings.retry')}
                </Button>
            </AlertDescription>
        </Alert>
    );
}

/**
 * Sección con una prop diferida de Inertia (`Inertia::defer(…, rescue: true)`): mientras llega,
 * el estado de carga; si falló en el servidor, el error con «Reintentar»; si no, `children` con
 * sus datos. Inertia pide las props diferidas sola tras pintar la página.
 */
export function DeferredSection<T>({
    prop,
    loadingLabel,
    children,
}: {
    prop: string;
    loadingLabel: string;
    children: (data: T) => ReactNode;
}) {
    const page = usePage();
    const rescued = (page as { rescuedProps?: string[] }).rescuedProps ?? [];
    const data = (page.props as Record<string, unknown>)[prop] as T | undefined;

    if (rescued.includes(prop)) {
        return <SectionError prop={prop} />;
    }

    if (data === undefined || data === null) {
        return <SectionLoading label={loadingLabel} />;
    }

    return children(data);
}
