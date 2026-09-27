import { TriangleAlert } from 'lucide-react';
import {
    HOUR_BANK_LEVELS,
    hourBankAlerts,
    hourBankFigures,
    hourBankLevel,
} from '@/components/charts/thresholds';
import { formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type HourBankMeterProps = {
    name: string;
    /** Minutos consumidos (todas las entradas, sea cual sea su estado: SPEC §8.4). */
    consumed: number;
    /** Minutos contratados. */
    total: number;
    /**
     * Exceso calculado por el servidor (overage_minutes). Con él, el saldo y el color coinciden
     * con remaining_minutes y el estado de la bolsa. Sin él, se deduce de consumido − total.
     */
    overage?: number | null;
    /** Estimación restante de las tareas abiertas de la bolsa, en minutos. */
    committed?: number;
    /**
     * Enseña las horas comprometidas y su aviso (por defecto). El portal de cliente no los enseña:
     * son planificación interna (SPEC §11).
     */
    showCommitted?: boolean;
    /**
     * Umbrales de alerta configurados, en % (config.hour_bank_thresholds). Marcan la barra y el
     * paso a ámbar (desde el primero). Por defecto, 75, 90 y 100.
     */
    thresholds?: readonly number[];
    className?: string;
};

/**
 * Consumo de una bolsa (SPEC §8, UI): barra con color por umbral, marcas de alerta
 * (75 % y 90 %, o los umbrales configurados) y del total, exceso en rojo con icono y aviso de
 * horas comprometidas.
 */
export function HourBankMeter({
    name,
    consumed,
    total,
    overage,
    committed = 0,
    showCommitted = true,
    thresholds,
    className,
}: HourBankMeterProps) {
    const f = hourBankFigures(consumed, total, committed, overage);
    const alerts = hourBankAlerts(thresholds);
    const level = hourBankLevel(f.inBank, f.total, alerts[0]);
    const meta = HOUR_BANK_LEVELS[level];
    const Icon = meta.icon;
    // El exceso se pinta siempre a partir del total (fuera de lo contratado).
    const scale = Math.max(f.total + f.overage, f.inBank, 1);
    const inside = Math.min(f.inBank, f.total);
    const pct = (minutes: number) => `${(minutes / scale) * 100}%`;

    const valueText = t(
        f.overage > 0 ? 'hour_bank.value_text_overage' : 'hour_bank.value_text',
        {
            consumed: formatMinutes(f.consumed),
            total: formatMinutes(f.total),
            ratio: formatPercent(f.ratio, 0),
            overage: formatMinutes(f.overage),
        },
    );

    return (
        <div className={cn('@container grid gap-3', className)}>
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <p className="flex flex-wrap items-baseline gap-x-2">
                    <span className="text-2xl">
                        {formatMinutes(f.consumed)}
                        <span className="text-muted-foreground">
                            {' '}
                            / {formatMinutes(f.total)}
                        </span>
                    </span>
                    {f.overage > 0 ? (
                        <span className="inline-flex items-center gap-1 text-sm font-medium text-danger">
                            <span aria-hidden="true">·</span>
                            <TriangleAlert
                                aria-hidden="true"
                                className="size-4 self-center"
                            />
                            {t('hour_bank.overage_badge', {
                                minutes: formatMinutes(f.overage),
                            })}
                        </span>
                    ) : null}
                </p>
                <span
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-[3px] px-2 py-0.5 text-xs font-medium text-foreground',
                        meta.surface,
                    )}
                >
                    <Icon
                        aria-hidden="true"
                        className={cn('size-3.5', meta.tone)}
                    />
                    {meta.label} · {formatPercent(f.ratio, 0)}
                </span>
            </div>

            <div
                role="meter"
                aria-label={t('hour_bank.meter_label', { name })}
                aria-valuemin={0}
                aria-valuemax={f.total}
                aria-valuenow={inside}
                aria-valuetext={valueText}
                className="relative h-2.5 w-full rounded-[3px] bg-neutral-soft"
            >
                <div
                    className={cn(
                        'absolute inset-y-0 left-0 rounded-l-[3px]',
                        meta.bar,
                        (f.overage === 0 || inside < f.total) &&
                            'rounded-r-[3px]',
                    )}
                    style={{ width: pct(inside) }}
                />
                {f.overage > 0 ? (
                    <div
                        className="absolute inset-y-0 rounded-r-[3px] border-l-2 border-card bg-danger"
                        style={{ left: pct(f.total), width: pct(f.overage) }}
                    />
                ) : null}
                {alerts.map((alert) => (
                    <span
                        key={alert}
                        aria-hidden="true"
                        className={cn(
                            'absolute -inset-y-1 w-px',
                            alert === 1 ? 'bg-foreground' : 'bg-foreground/40',
                        )}
                        style={{ left: pct(f.total * alert) }}
                    />
                ))}
            </div>

            <dl
                className={cn(
                    'grid grid-cols-2 gap-x-4 gap-y-2 text-sm',
                    showCommitted ? '@md:grid-cols-4' : '@md:grid-cols-3',
                )}
            >
                <Figure
                    label={t('hour_bank.consumed')}
                    value={formatMinutes(f.consumed)}
                />
                <Figure
                    label={t('hour_bank.remaining')}
                    value={formatMinutes(f.remaining)}
                />
                <Figure
                    label={t('hour_bank.overage')}
                    value={
                        f.overage > 0 ? `+${formatMinutes(f.overage)}` : '0:00'
                    }
                    danger={f.overage > 0}
                />
                {showCommitted ? (
                    <Figure
                        label={t('hour_bank.committed')}
                        value={formatMinutes(f.committed)}
                    />
                ) : null}
            </dl>

            {showCommitted &&
            f.shortfall > 0 &&
            (f.overage === 0 || f.remaining > 0) ? (
                <p className="flex items-start gap-2 rounded-[3px] bg-warning-soft px-3 py-2 text-sm text-foreground">
                    <TriangleAlert
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-warning"
                    />
                    {t('hour_bank.shortfall', {
                        minutes: formatMinutes(f.shortfall),
                    })}
                </p>
            ) : null}
        </div>
    );
}

function Figure({
    label,
    value,
    danger = false,
}: {
    label: string;
    value: string;
    danger?: boolean;
}) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className={cn('tabular', danger && 'font-medium text-danger')}>
                {value}
            </dd>
        </div>
    );
}
