import { Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import type { ReactNode } from 'react';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatMinutes, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { SoldVsActualTotals, SoldVsActualUnit } from '@/types';
import { SaleStatusBadge } from './billing-badges';
import { signedMinutes } from './sold-vs-actual-lib';

/** Ficha de la unidad: la bolsa o la pestaña Facturación del proyecto. */
export function unitHref(unit: SoldVsActualUnit): string {
    return unit.bank
        ? urls.hourBank(unit.project.id, unit.bank.id)
        : `/proyectos/${unit.project.id}/facturacion`;
}

function Th({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <th
            scope="col"
            className={cn(
                'px-3 py-2 text-right font-medium whitespace-nowrap',
                className,
            )}
        >
            {children}
        </th>
    );
}

function Money({ value }: { value: string | null | undefined }) {
    return value === null || value === undefined ? (
        <span className="text-muted-foreground">—</span>
    ) : (
        <>{formatCurrency(value)}</>
    );
}

/**
 * Tabla de «Vendido frente a real» (D-390): una fila por unidad de venta con lo vendido, lo real,
 * lo pendiente de aprobar, la desviación y su semáforo; con view-financials, además, lo vendido en
 * euros, lo facturado, lo cobrado, lo pendiente de cobro y el margen. Totales al pie.
 */
export function SoldVsActualTable({
    units,
    totals,
    financials,
    caption,
    showClient = true,
}: {
    units: ReadonlyArray<SoldVsActualUnit>;
    totals: SoldVsActualTotals;
    financials: boolean;
    caption: string;
    showClient?: boolean;
}) {
    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={caption}
            tabIndex={0}
        >
            <table
                className={cn(
                    'tabular w-full text-sm',
                    financials ? 'min-w-[86rem]' : 'min-w-[56rem]',
                )}
                data-test="sold-vs-actual-table"
            >
                <caption className="sr-only">{caption}</caption>
                <thead>
                    <tr className="border-b text-left">
                        <th
                            scope="col"
                            className="min-w-60 px-3 py-2 font-medium"
                        >
                            {t('billing.columns.unit')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('billing.columns.manager')}
                        </th>
                        <Th>{t('billing.columns.sold_hours')}</Th>
                        <Th>{t('billing.columns.real_hours')}</Th>
                        <Th>{t('billing.columns.pending')}</Th>
                        <Th>{t('billing.columns.deviation')}</Th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('billing.columns.status')}
                        </th>
                        {financials ? (
                            <>
                                <Th>{t('billing.columns.sold_amount')}</Th>
                                <Th>{t('billing.columns.invoiced')}</Th>
                                <Th>{t('billing.columns.collected')}</Th>
                                <Th>{t('billing.columns.outstanding')}</Th>
                                <Th>{t('billing.columns.margin')}</Th>
                            </>
                        ) : null}
                    </tr>
                </thead>
                <tbody>
                    {units.map((unit) => (
                        <tr
                            key={unit.key}
                            className="border-b last:border-0 even:bg-muted"
                        >
                            <th
                                scope="row"
                                className="max-w-80 px-3 py-2 text-left font-normal"
                            >
                                <Link
                                    href={unitHref(unit)}
                                    className={cn(
                                        'rounded-md hover:underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    <span className="text-muted-foreground">
                                        {unit.project.code}
                                    </span>{' '}
                                    {unit.name}
                                </Link>
                                <span className="block text-xs text-muted-foreground">
                                    {t(`billing.kind.${unit.kind}`)}
                                    {unit.months !== null
                                        ? ` · ${t(unit.months === 1 ? 'billing.months_one' : 'billing.months_other', { count: unit.months })}`
                                        : ''}
                                    {showClient && unit.client
                                        ? ` · ${unit.client.name}`
                                        : ''}
                                </span>
                            </th>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {unit.manager?.name ?? '—'}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {unit.sold_minutes === null
                                    ? '—'
                                    : formatMinutes(unit.sold_minutes)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatMinutes(unit.real_minutes)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {unit.pending_minutes > 0 ? (
                                    <span className="inline-flex items-center justify-end gap-1">
                                        <Clock
                                            aria-hidden="true"
                                            className="size-3.5 text-warning"
                                        />
                                        {formatMinutes(unit.pending_minutes)}
                                    </span>
                                ) : (
                                    formatMinutes(0)
                                )}
                            </td>
                            <td
                                className={cn(
                                    'px-3 py-2 text-right whitespace-nowrap',
                                )}
                            >
                                {signedMinutes(
                                    unit.deviation_minutes,
                                    formatMinutes,
                                )}
                                {unit.consumption_pct !== null ? (
                                    <span className="block text-xs text-muted-foreground">
                                        {t('billing.consumption', {
                                            pct: formatNumber(
                                                unit.consumption_pct,
                                                1,
                                            ),
                                        })}
                                    </span>
                                ) : null}
                            </td>
                            <td className="px-3 py-2">
                                <SaleStatusBadge status={unit.status} />
                            </td>
                            {financials ? (
                                <>
                                    <td className="px-3 py-2 text-right">
                                        <Money
                                            value={
                                                unit.sold_amount ??
                                                unit.hours_value
                                            }
                                        />
                                        {unit.sold_source === 'holded' ? (
                                            <span className="block text-xs text-muted-foreground">
                                                {t('billing.sold_from_holded')}
                                            </span>
                                        ) : null}
                                        {unit.kind === 'horas' ? (
                                            <span className="block text-xs text-muted-foreground">
                                                {t('billing.hours_value')}
                                            </span>
                                        ) : null}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Money value={unit.invoiced} />
                                        {Number(unit.planned ?? 0) !== 0 ? (
                                            <span className="block text-xs text-muted-foreground">
                                                {t('billing.planned_amount', {
                                                    amount: formatCurrency(
                                                        unit.planned ?? '0',
                                                    ),
                                                })}
                                            </span>
                                        ) : null}
                                        <span className="block text-xs text-muted-foreground">
                                            {t(
                                                unit.invoices_count === 1
                                                    ? 'billing.invoices_one'
                                                    : 'billing.invoices_other',
                                                { count: unit.invoices_count },
                                            )}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Money value={unit.collected} />
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Money value={unit.outstanding} />
                                        {Number(unit.overdue ?? 0) > 0 ? (
                                            <span className="block text-xs text-danger">
                                                {t('billing.overdue_amount', {
                                                    amount: formatCurrency(
                                                        unit.overdue ?? '0',
                                                    ),
                                                })}
                                            </span>
                                        ) : null}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Money value={unit.margin} />
                                        {unit.margin_pct !== null &&
                                        unit.margin_pct !== undefined ? (
                                            <span className="block text-xs text-muted-foreground">
                                                {formatNumber(
                                                    unit.margin_pct,
                                                    1,
                                                )}{' '}
                                                %
                                            </span>
                                        ) : null}
                                    </td>
                                </>
                            ) : null}
                        </tr>
                    ))}
                </tbody>
                {units.length > 1 ? (
                    <tfoot>
                        <tr className="border-t bg-muted font-medium">
                            <th
                                scope="row"
                                className="px-3 py-2 text-left font-medium"
                                colSpan={2}
                            >
                                {t('billing.total', { count: totals.units })}
                            </th>
                            <td className="px-3 py-2 text-right">
                                {formatMinutes(totals.sold_minutes)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatMinutes(totals.real_minutes)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatMinutes(totals.pending_minutes)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {signedMinutes(
                                    totals.deviation_minutes,
                                    formatMinutes,
                                )}
                            </td>
                            <td className="px-3 py-2" />
                            {financials ? (
                                <>
                                    <td className="px-3 py-2 text-right">
                                        <Money value={totals.income} />
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Money value={totals.invoiced} />
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Money value={totals.collected} />
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Money value={totals.outstanding} />
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Money value={totals.margin} />
                                    </td>
                                </>
                            ) : null}
                        </tr>
                    </tfoot>
                ) : null}
            </table>
        </div>
    );
}
