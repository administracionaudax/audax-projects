import {
    HOUR_BANK_LEVELS,
    hourBankFigures,
    hourBankLevel,
} from '@/components/charts/thresholds';
import { formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Barra de consumo compacta para tablas (listado de proyectos y vista global de bolsas): color
 * por umbral (verde, ámbar, rojo; D-035) con icono y porcentaje, nunca solo color.
 */
export function HourBankMiniMeter({
    name,
    consumed,
    total,
    className,
}: {
    /** Para el nombre accesible («Consumo de Bolsa Q4»). */
    name: string;
    consumed: number;
    total: number;
    className?: string;
}) {
    const figures = hourBankFigures(consumed, total);
    const level = hourBankLevel(figures.consumed, figures.total);
    const meta = HOUR_BANK_LEVELS[level];
    const Icon = meta.icon;
    const inside = Math.min(figures.consumed, figures.total);
    const width = figures.total > 0 ? (inside / figures.total) * 100 : 0;

    return (
        <div className={cn('flex min-w-32 items-center gap-2', className)}>
            <div
                role="meter"
                aria-label={t('hour_bank.meter_label', { name })}
                aria-valuemin={0}
                aria-valuemax={figures.total}
                aria-valuenow={inside}
                aria-valuetext={t(
                    figures.overage > 0
                        ? 'hour_bank.value_text_overage'
                        : 'hour_bank.value_text',
                    {
                        consumed: formatMinutes(figures.consumed),
                        total: formatMinutes(figures.total),
                        ratio: formatPercent(figures.ratio, 0),
                        overage: formatMinutes(figures.overage),
                    },
                )}
                className="relative h-2 w-20 shrink-0 rounded-[3px] bg-neutral-soft"
            >
                <div
                    className={cn(
                        'absolute inset-y-0 left-0 rounded-[3px]',
                        meta.bar,
                    )}
                    style={{ width: `${width}%` }}
                />
            </div>
            <span className="inline-flex items-center gap-1 text-xs whitespace-nowrap">
                <Icon
                    aria-hidden="true"
                    className={cn('size-3.5', meta.tone)}
                />
                <span className="tabular">
                    {formatPercent(figures.ratio, 0)}
                </span>
            </span>
        </div>
    );
}
