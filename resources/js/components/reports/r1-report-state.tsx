import { router } from '@inertiajs/react';
import { CircleAlert, LoaderCircle, RotateCw } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type ReportVisitState = {
    /** Hay una visita en curso a esta misma página (cambio de filtros o de periodo). */
    loading: boolean;
    /** La última visita a esta página ha fallado (error del servidor o de red). */
    failed: boolean;
    retry: () => void;
};

/**
 * Estado de las visitas de un dashboard: al cambiar filtros o periodo, la página se vuelve a pedir
 * a sí misma (ReportFilterBar). Mientras llega, se marca como «cargando» (sin perder lo que se ve);
 * si falla, se queda en la página con un aviso y un botón para reintentar, en lugar de salir a la
 * página de error o al modal de Inertia.
 */
export function useReportVisit(): ReportVisitState {
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const pending = useRef<string | null>(null);
    const lastFailed = useRef<string | null>(null);

    useEffect(() => {
        const offStart = router.on('start', (event) => {
            const { visit } = event.detail;

            if (
                visit.method === 'get' &&
                visit.url.pathname === window.location.pathname
            ) {
                pending.current = visit.url.href;
                setLoading(true);
                setFailed(false);
            }
        });

        const fail = (event: Event) => {
            if (pending.current === null) {
                return;
            }

            lastFailed.current = pending.current;
            setFailed(true);
            event.preventDefault();
        };

        const offHttp = router.on('httpException', fail);
        const offNetwork = router.on('networkError', fail);
        const offFinish = router.on('finish', () => {
            if (pending.current !== null) {
                pending.current = null;
                setLoading(false);
            }
        });

        return () => {
            offStart();
            offHttp();
            offNetwork();
            offFinish();
        };
    }, []);

    const retry = () => {
        const href = lastFailed.current ?? window.location.href;

        router.get(href, undefined, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return { loading, failed, retry };
}

/**
 * Contenedor del contenido de un dashboard con sus estados: «Actualizando…» mientras llegan los
 * datos (aria-busy, el contenido atenuado y un aviso flotante que no desplaza nada) y un aviso con
 * icono y texto si la carga falla.
 */
export function ReportContent({
    state,
    children,
    className,
}: {
    state: ReportVisitState;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('relative grid gap-8', className)}>
            <p className="sr-only" aria-live="polite">
                {state.loading ? t('reports_r1.state.loading') : ''}
            </p>

            {state.loading ? (
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-x-0 top-2 z-10 flex justify-center"
                >
                    <span className="inline-flex items-center gap-2 rounded-md border bg-card px-3 py-1.5 text-sm text-foreground shadow-sm">
                        <LoaderCircle className="size-4 animate-spin text-muted-foreground" />
                        {t('reports_r1.state.loading')}
                    </span>
                </div>
            ) : null}

            {state.failed ? (
                <div
                    role="alert"
                    className="flex flex-wrap items-center gap-3 rounded-md border border-danger bg-danger-soft px-3 py-2 text-sm text-foreground"
                >
                    <CircleAlert
                        aria-hidden="true"
                        className="size-4 shrink-0 text-danger"
                    />
                    <span className="min-w-0 flex-1">
                        {t('reports_r1.state.error')}
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={state.retry}
                    >
                        <RotateCw aria-hidden="true" />
                        {t('reports_r1.state.retry')}
                    </Button>
                </div>
            ) : null}

            <div
                aria-busy={state.loading || undefined}
                className={cn(
                    'grid min-w-0 gap-8 transition-opacity',
                    state.loading && 'opacity-60',
                )}
            >
                {children}
            </div>
        </div>
    );
}
