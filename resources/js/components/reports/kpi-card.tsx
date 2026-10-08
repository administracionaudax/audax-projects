import { ArrowDown, ArrowUp, Info, Minus } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type KpiDelta = {
    /** Valor del periodo actual y del de comparación, en la misma unidad (minutos, euros, ratio). */
    current: number | null;
    previous: number | null;
    /** true si subir es bueno (ocupación, ingreso); false si es malo (exceso, coste). */
    higherIsBetter?: boolean;
    /** Frente a qué se compara (por defecto, el periodo anterior; el informe de facturación, el año anterior, D-400). */
    versus?: 'previous_period' | 'previous_year';
};

/**
 * Tarjeta de KPI de los informes (SPEC §10): valor, definición accesible (SPEC: «documéntalas
 * también en la UI con tooltips») y variación frente al periodo anterior con icono y texto,
 * nunca solo color.
 */
export function KpiCard({
    label,
    definition,
    value,
    detail,
    delta,
    loading = false,
    className,
    children,
}: {
    label: string;
    definition: string;
    /** Valor ya formateado (h:mm, %, €, o un nodo con icono, D-400); null = sin datos. */
    value: ReactNode;
    /** Línea secundaria opcional (p. ej. «de 120:00 de capacidad»). */
    detail?: string;
    delta?: KpiDelta;
    loading?: boolean;
    className?: string;
    /** Debajo de las cifras (p. ej. un medidor, Fase 12). */
    children?: ReactNode;
}) {
    return (
        <Card className={cn('gap-2 py-4', className)}>
            <CardHeader className="flex flex-row items-start justify-between gap-2 px-4">
                <h3 className="text-sm text-muted-foreground">{label}</h3>
                <Tooltip>
                    <TooltipTrigger
                        type="button"
                        aria-label={t('reports.kpi.definition', { label })}
                        className={cn(
                            '-m-1 rounded-md p-1 text-muted-foreground hover:text-foreground',
                            FOCUS_RING,
                        )}
                    >
                        <Info aria-hidden="true" className="size-4" />
                    </TooltipTrigger>
                    <TooltipContent className="max-w-72">
                        {definition}
                    </TooltipContent>
                </Tooltip>
            </CardHeader>
            <CardContent className="grid gap-1 px-4">
                {loading ? (
                    <Skeleton className="h-8 w-24" />
                ) : (
                    <p className="text-2xl">
                        {value ?? (
                            <span className="text-base text-muted-foreground">
                                {t('reports.kpi.no_data')}
                            </span>
                        )}
                    </p>
                )}
                {detail ? (
                    <p className="text-xs text-muted-foreground">{detail}</p>
                ) : null}
                {delta && !loading ? <KpiDeltaLine delta={delta} /> : null}
                {children}
            </CardContent>
        </Card>
    );
}

export function KpiDeltaLine({ delta }: { delta: KpiDelta }) {
    const {
        current,
        previous,
        higherIsBetter = true,
        versus = 'previous_period',
    } = delta;
    const year = versus === 'previous_year';

    if (current === null || previous === null) {
        return null;
    }

    if (previous === 0 && current !== previous) {
        return null;
    }

    const change =
        previous === 0 ? 0 : (current - previous) / Math.abs(previous);

    // Un cambio que se mostraría como «0 %» se lee como «igual» (menos de medio punto).
    if (Math.abs(change) < 0.005) {
        return (
            <p className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                <Minus aria-hidden="true" className="size-3.5" />
                {t(
                    year
                        ? 'reports.kpi.delta_same_year'
                        : 'reports.kpi.delta_same',
                )}
            </p>
        );
    }

    const up = change > 0;
    const good = up === higherIsBetter;
    const Icon = up ? ArrowUp : ArrowDown;

    return (
        <p className="inline-flex items-center gap-1 text-xs text-foreground">
            <Icon
                aria-hidden="true"
                className={cn(
                    'size-3.5',
                    good ? 'text-success' : 'text-danger',
                )}
            />
            {t(
                year
                    ? up
                        ? 'reports.kpi.delta_up_year'
                        : 'reports.kpi.delta_down_year'
                    : up
                      ? 'reports.kpi.delta_up'
                      : 'reports.kpi.delta_down',
                {
                    delta: formatPercent(Math.abs(change), 0),
                },
            )}
        </p>
    );
}
