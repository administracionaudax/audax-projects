import { Equal, TrendingDown, TrendingUp } from 'lucide-react';
import { deviationKind, deviationLabel } from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Por encima de ±10 % la desviación lleva aviso (D-296). */
export const DEVIATION_WARNING = 10;

/**
 * Desviación con flecha y signo (D-296 y D-297): ↗ si va por encima, ↘ si va por debajo y = por
 * debajo de medio punto («Igual que lo estimado»). En ámbar por encima de ±10 %. No usa el semáforo
 * de la carga.
 */
export function DeviationBadge({
    percent,
    className,
}: {
    percent: number | null;
    className?: string;
}) {
    const kind = deviationKind(percent);

    if (kind === 'none') {
        return (
            <span className={cn('text-muted-foreground', className)}>
                {deviationLabel(percent)}
            </span>
        );
    }

    const warning = percent !== null && Math.abs(percent) > DEVIATION_WARNING;
    const Icon =
        kind === 'same' ? Equal : kind === 'over' ? TrendingUp : TrendingDown;

    return (
        <span
            className={cn(
                'tabular inline-flex items-center gap-1 whitespace-nowrap',
                className,
            )}
            data-test="deviation"
        >
            <Icon
                aria-hidden="true"
                className={cn(
                    'size-3.5 shrink-0',
                    warning ? 'text-warning' : 'text-muted-foreground',
                )}
            />
            {deviationLabel(percent)}
            {warning ? (
                <span className="sr-only">
                    {' '}
                    ({t('forecast.deviation.high')})
                </span>
            ) : null}
        </span>
    );
}

/**
 * Barra de bala (D-296 y D-297): lo real en turquesa frente a la marca del objetivo (el plan hasta
 * hoy, en azul, o lo estimado, en violeta) y, si se pasa, la previsión como prolongación con borde
 * discontinuo. `max` fija la escala común de una tabla.
 */
export function BulletBar({
    actual,
    target,
    projected,
    max,
    targetTone = 'plan',
    className,
}: {
    actual: number;
    target: number;
    projected?: number;
    max: number;
    targetTone?: 'plan' | 'estimate';
    className?: string;
}) {
    const scale = (value: number) =>
        `${Math.min(Math.max(value / Math.max(max, 1), 0), 1) * 100}%`;

    return (
        <span
            aria-hidden="true"
            className={cn(
                'relative block h-3 w-full bg-neutral-soft',
                className,
            )}
            data-test="bullet-bar"
        >
            {projected !== undefined && projected > actual ? (
                <span
                    className="absolute inset-y-0 border border-dashed border-chart-2 bg-chart-2/15"
                    style={{
                        left: scale(actual),
                        width: `calc(${scale(projected)} - ${scale(actual)})`,
                    }}
                />
            ) : null}
            <span
                className="absolute inset-y-0 left-0 bg-chart-2"
                style={{ width: scale(actual) }}
            />
            <span
                className={cn(
                    'absolute -inset-y-1 w-0.5',
                    targetTone === 'plan' ? 'bg-chart-1' : 'bg-chart-3',
                )}
                style={{ left: scale(target) }}
            />
        </span>
    );
}
