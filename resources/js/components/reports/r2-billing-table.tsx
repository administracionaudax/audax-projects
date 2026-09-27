import { Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import type { ReactNode } from 'react';
import { HourBankStatusBadge } from '@/components/domain/badges';
import { R2OverageValue } from '@/components/reports/r2-breakdown-table';
import type { R2BillingSummary } from '@/components/reports/r2-types';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';

/** Horas pendientes de aprobar (borrador o enviadas): aviso con icono, pueden cambiar. */
function Pending({ minutes }: { minutes: number }) {
    if (minutes <= 0) {
        return <>{formatMinutes(0)}</>;
    }

    return (
        <span className="inline-flex items-center justify-end gap-1">
            <Clock aria-hidden="true" className="size-3.5 text-warning" />
            {formatMinutes(minutes)}
        </span>
    );
}

/**
 * Resumen para facturar (SPEC §10 «Exportación», D-045): una fila por proyecto y bolsa con lo
 * imputado, lo que va dentro de la bolsa y el exceso por separado, facturables, no facturables y
 * pendientes de aprobar; con view-financials, cómo se valora, la tarifa, el precio y el importe
 * estimado (D-043). Totales al pie.
 */
export function R2BillingTable({
    summary,
    financials,
}: {
    summary: R2BillingSummary;
    financials: boolean;
}) {
    const { rows, totals } = summary;

    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={t('reports_r2.billing.table_caption')}
            tabIndex={0}
        >
            <table
                className={cn(
                    'tabular w-full text-sm',
                    financials ? 'min-w-[68rem]' : 'min-w-[52rem]',
                )}
            >
                <caption className="sr-only">
                    {t('reports_r2.billing.table_caption')}
                </caption>
                <thead>
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('reports_r2.column.project')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('reports_r2.column.bank')}
                        </th>
                        <Th>{t('reports_r2.column.logged')}</Th>
                        <Th>{t('reports_r2.column.in_bank')}</Th>
                        <Th>{t('reports_r2.column.overage')}</Th>
                        <Th>{t('reports_r2.column.billable')}</Th>
                        <Th>{t('reports_r2.column.non_billable')}</Th>
                        <Th>{t('reports_r2.column.pending')}</Th>
                        {financials ? (
                            <>
                                <th
                                    scope="col"
                                    className="px-3 py-2 font-medium"
                                >
                                    {t('reports_r2.column.pricing')}
                                </th>
                                <Th>{t('reports_r2.column.rate')}</Th>
                                <Th>{t('reports_r2.column.price')}</Th>
                                <Th>{t('reports_r2.column.amount')}</Th>
                            </>
                        ) : null}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={`${row.project.id}-${row.bank?.id ?? 'none'}`}
                            className="border-b last:border-0 even:bg-muted"
                        >
                            <th
                                scope="row"
                                className="px-3 py-2 text-left font-normal"
                            >
                                <Link
                                    href={urls.project(row.project.id)}
                                    className={cn(
                                        'rounded-[3px] hover:underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    <span className="text-muted-foreground">
                                        {row.project.code}
                                    </span>{' '}
                                    {row.project.name}
                                </Link>
                                <span className="block text-xs text-muted-foreground">
                                    {t(
                                        `project.billing_type.${row.project.billing_type}`,
                                    )}
                                </span>
                            </th>
                            <td className="px-3 py-2">
                                {row.bank ? (
                                    <span className="grid justify-items-start gap-1">
                                        <Link
                                            href={urls.hourBank(
                                                row.project.id,
                                                row.bank.id,
                                            )}
                                            className={cn(
                                                'rounded-[3px] text-primary-text hover:underline',
                                                FOCUS_RING,
                                            )}
                                        >
                                            {row.bank.name}
                                        </Link>
                                        <HourBankStatusBadge
                                            status={row.bank.status}
                                        />
                                    </span>
                                ) : (
                                    <span className="text-muted-foreground">
                                        {t('reports_r2.billing.no_bank')}
                                    </span>
                                )}
                            </td>
                            <Td>{formatMinutes(row.logged_minutes)}</Td>
                            <Td>
                                {row.bank
                                    ? formatMinutes(row.in_bank_minutes)
                                    : '—'}
                            </Td>
                            <Td>
                                <R2OverageValue minutes={row.overage_minutes} />
                            </Td>
                            <Td>{formatMinutes(row.billable_minutes)}</Td>
                            <Td>{formatMinutes(row.non_billable_minutes)}</Td>
                            <Td>
                                <Pending minutes={row.pending_minutes} />
                            </Td>
                            {financials ? (
                                <>
                                    <td className="px-3 py-2">
                                        {row.pricing
                                            ? t(
                                                  `reports_r2.billing.pricing.${row.pricing}`,
                                              )
                                            : '—'}
                                    </td>
                                    <Td>
                                        {row.rate
                                            ? t('reports_r2.billing.per_hour', {
                                                  amount: formatCurrency(
                                                      row.rate,
                                                  ),
                                              })
                                            : '—'}
                                    </Td>
                                    <Td>
                                        {row.price_amount
                                            ? formatCurrency(row.price_amount)
                                            : '—'}
                                    </Td>
                                    <Td>{formatCurrency(row.income ?? '0')}</Td>
                                </>
                            ) : null}
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr className="border-t-2">
                        <th
                            scope="row"
                            colSpan={2}
                            className="px-3 py-2 text-left font-medium"
                        >
                            {t('reports_r2.total')}
                        </th>
                        <Td strong>{formatMinutes(totals.logged_minutes)}</Td>
                        <Td strong>{formatMinutes(totals.in_bank_minutes)}</Td>
                        <Td strong>
                            <R2OverageValue minutes={totals.overage_minutes} />
                        </Td>
                        <Td strong>{formatMinutes(totals.billable_minutes)}</Td>
                        <Td strong>
                            {formatMinutes(totals.non_billable_minutes)}
                        </Td>
                        <Td strong>
                            <Pending minutes={totals.pending_minutes} />
                        </Td>
                        {financials ? (
                            <>
                                <td colSpan={3} />
                                <Td strong>
                                    {formatCurrency(totals.income ?? '0')}
                                </Td>
                            </>
                        ) : null}
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

function Th({ children }: { children: ReactNode }) {
    return (
        <th scope="col" className="px-3 py-2 text-right font-medium">
            {children}
        </th>
    );
}

function Td({
    children,
    strong = false,
}: {
    children: ReactNode;
    strong?: boolean;
}) {
    return (
        <td className={cn('px-3 py-2 text-right', strong && 'font-medium')}>
            {children}
        </td>
    );
}
