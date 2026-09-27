import { TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId } from 'react';
import { formatMinutes, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { formatMonth } from './format';
import type { PortalHomeSummary } from './types';

/**
 * Resumen del inicio del portal: horas de este mes en las bolsas del cliente (con su exceso), bolsas
 * activas y las que están cerca del límite desde el primer umbral configurado (D-035). Todo con las
 * horas que ve el cliente (D-064).
 */
export function PortalBanksSummary({
    summary,
}: {
    summary: PortalHomeSummary;
}) {
    const titleId = useId();
    const month = formatMonth(summary.month);

    return (
        <section aria-labelledby={titleId}>
            <h2 id={titleId} className="sr-only">
                {t('portal_banks.summary.title')}
            </h2>
            <dl
                className="grid gap-3 sm:grid-cols-3"
                data-test="portal-banks-summary"
            >
                <Tile
                    label={t('portal_banks.summary.month', {
                        month: month.toLowerCase(),
                    })}
                    value={formatMinutes(summary.month_minutes)}
                    detail={
                        summary.month_overage_minutes > 0 ? (
                            <span className="inline-flex items-center gap-1 font-medium text-danger">
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="size-3.5"
                                />
                                {t('portal_banks.summary.month_overage', {
                                    minutes: formatMinutes(
                                        summary.month_overage_minutes,
                                    ),
                                })}
                            </span>
                        ) : summary.month_minutes === 0 ? (
                            t('portal_banks.summary.month_none')
                        ) : undefined
                    }
                />
                <Tile
                    label={t('portal_banks.summary.open')}
                    value={formatNumber(summary.open_count, 0)}
                />
                <Tile
                    label={t('portal_banks.summary.near_limit')}
                    value={
                        <span className="inline-flex items-center gap-2">
                            {summary.near_limit_count > 0 ? (
                                <TriangleAlert
                                    aria-hidden="true"
                                    className="size-5 text-warning"
                                />
                            ) : null}
                            {formatNumber(summary.near_limit_count, 0)}
                        </span>
                    }
                    detail={t('portal_banks.summary.near_limit_detail', {
                        threshold: summary.first_threshold,
                    })}
                />
            </dl>
        </section>
    );
}

function Tile({
    label,
    value,
    detail,
}: {
    label: string;
    value: ReactNode;
    detail?: ReactNode;
}) {
    return (
        <div className="grid content-start gap-1 rounded-md border bg-card px-4 py-3">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="tabular text-2xl">{value}</dd>
            {detail ? (
                <dd className="text-xs text-muted-foreground">{detail}</dd>
            ) : null}
        </div>
    );
}
