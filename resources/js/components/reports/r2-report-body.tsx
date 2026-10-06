import { router } from '@inertiajs/react';
import { Info, RotateCw, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { isReportPageVisit } from '@/components/reports/r1-report-state';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Estado de las visitas de Inertia mientras se mira un informe: cargando (al cambiar un filtro o
 * de periodo, el informe se vuelve a pedir al servidor) o con un error de red.
 */
export function useReportVisitState(): { loading: boolean; failed: boolean } {
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        // Solo las visitas que vuelven a pedir este informe (no precargas del menú, envíos de
        // diálogos ni el temporizador): antes cualquiera atenuaba el informe (D-310).
        let pending: string | null = null;
        const offStart = router.on('start', (event) => {
            if (isReportPageVisit(event.detail.visit)) {
                pending = event.detail.visit.id;
                setLoading(true);
                setFailed(false);
            }
        });
        const offFinish = router.on('finish', (event) => {
            if (event.detail.visit.id === pending) {
                pending = null;
                setLoading(false);
            }
        });
        const offError = router.on('networkError', () => {
            if (pending !== null) {
                setLoading(false);
                setFailed(true);
            }
        });

        return () => {
            offStart();
            offFinish();
            offError();
        };
    }, []);

    return { loading, failed };
}

/**
 * Cuerpo de un informe con sus estados (SPEC §3): mientras se actualiza, un aviso con el
 * indicador de carga y el contenido atenuado (aria-busy); si falla la red, un aviso con icono,
 * texto y el botón de reintentar.
 */
export function R2ReportBody({ children }: { children: ReactNode }) {
    const { loading, failed } = useReportVisitState();

    return (
        <div className="grid min-w-0">
            <div aria-live="polite">
                {loading ? (
                    <p className="mb-4 flex items-center gap-2 text-sm text-muted-foreground">
                        <Spinner />
                        {t('reports_r2.loading')}
                    </p>
                ) : null}
            </div>

            {failed ? (
                <Alert variant="destructive" role="alert" className="mb-4">
                    <TriangleAlert aria-hidden="true" />
                    <AlertDescription className="flex flex-wrap items-center gap-3">
                        {t('reports_r2.error')}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => router.reload()}
                        >
                            <RotateCw aria-hidden="true" />
                            {t('reports_r2.retry')}
                        </Button>
                    </AlertDescription>
                </Alert>
            ) : null}

            <div
                aria-busy={loading || undefined}
                className={cn(
                    'grid min-w-0 gap-8 transition-opacity',
                    loading && 'opacity-60',
                )}
            >
                {children}
            </div>
        </div>
    );
}

/** Aviso del alcance del informe (qué horas se ven, D-044), con icono y texto. */
export function R2ScopeNote({ children }: { children: ReactNode }) {
    return (
        <p className="flex items-start gap-2 rounded-md bg-info-soft px-3 py-2 text-sm text-foreground">
            <Info
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-info"
            />
            {children}
        </p>
    );
}
