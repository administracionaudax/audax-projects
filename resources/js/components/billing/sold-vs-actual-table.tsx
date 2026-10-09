import { Link } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import type { ReactNode } from 'react';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatMinutes, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
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
                'px-3 py-2 text-right align-bottom font-medium',
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

/** Línea secundaria de una celda (en pequeño y en gris). */
function Sub({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <span className={cn('block text-xs text-muted-foreground', className)}>
            {children}
        </span>
    );
}

function PendingHours({ minutes }: { minutes: number }) {
    return (
        <span className="inline-flex items-center justify-end gap-1">
            <Clock aria-hidden="true" className="size-3.5 text-warning" />
            {formatMinutes(minutes)}
        </span>
    );
}

/**
 * Tabla de «Vendido frente a real» (D-390): una fila por unidad de venta con lo vendido, lo real,
 * lo pendiente de aprobar, la desviación y su semáforo; con view-financials, además, lo vendido en
 * euros, lo facturado (sin IVA), lo cobrado (con IVA, con lo pendiente de cobro debajo) y el
 * margen. Para que quepa a 1440 px sin desplazarse (D-410), con importes el responsable va debajo de
 * la unidad y las horas sin aprobar debajo de las reales. Totales al pie.
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
    // Sin importes hay sitio: el responsable y las horas sin aprobar llevan su columna.
    const wide = !financials;

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
                    financials ? 'min-w-[64rem]' : 'min-w-[54rem]',
                )}
                data-test="sold-vs-actual-table"
            >
                <caption className="sr-only">{caption}</caption>
                <thead>
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('billing.columns.unit')}
                        </th>
                        {wide ? (
                            <th scope="col" className="px-3 py-2 font-medium">
                                {t('billing.columns.manager')}
                            </th>
                        ) : null}
                        <Th>{t('billing.columns.sold_hours')}</Th>
                        <Th>{t('billing.columns.real_hours')}</Th>
                        {wide ? <Th>{t('billing.columns.pending')}</Th> : null}
                        <Th>{t('billing.columns.deviation')}</Th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('billing.columns.status')}
                        </th>
                        {financials ? (
                            <>
                                <Th>{t('billing.columns.sold_amount')}</Th>
                                <Th>{t('billing.columns.invoiced')}</Th>
                                <Th>{t('billing.columns.collected')}</Th>
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
                                className="max-w-64 min-w-48 px-3 py-2 text-left font-normal"
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
                                    {[
                                        t(`billing.kind.${unit.kind}`),
                                        unit.months !== null
                                            ? tCount(
                                                  'billing.months',
                                                  unit.months,
                                              )
                                            : null,
                                        showClient && unit.client
                                            ? unit.client.name
                                            : null,
                                        !wide && unit.manager
                                            ? unit.manager.name
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            </th>
                            {wide ? (
                                <td className="px-3 py-2 whitespace-nowrap">
                                    {unit.manager?.name ?? '—'}
                                </td>
                            ) : null}
                            <td className="px-3 py-2 text-right">
                                {unit.sold_minutes === null
                                    ? '—'
                                    : formatMinutes(unit.sold_minutes)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatMinutes(unit.real_minutes)}
                                {!wide && unit.pending_minutes > 0 ? (
                                    <Sub>
                                        {t('billing.pending_hours', {
                                            hours: formatMinutes(
                                                unit.pending_minutes,
                                            ),
                                        })}
                                    </Sub>
                                ) : null}
                            </td>
                            {wide ? (
                                <td className="px-3 py-2 text-right">
                                    {unit.pending_minutes > 0 ? (
                                        <PendingHours
                                            minutes={unit.pending_minutes}
                                        />
                                    ) : (
                                        formatMinutes(0)
                                    )}
                                </td>
                            ) : null}
                            <td className="px-3 py-2 text-right">
                                {signedMinutes(
                                    unit.deviation_minutes,
                                    formatMinutes,
                                )}
                                {unit.consumption_pct !== null ? (
                                    <Sub>
                                        {t('billing.consumption', {
                                            pct: formatNumber(
                                                unit.consumption_pct,
                                                1,
                                            ),
                                        })}
                                    </Sub>
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
                                            <Sub>
                                                {t('billing.sold_from_holded')}
                                            </Sub>
                                        ) : null}
                                        {unit.kind === 'horas' ? (
                                            <Sub>
                                                {t('billing.hours_value')}
                                            </Sub>
                                        ) : null}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Money value={unit.invoiced} />
                                        {Number(unit.planned ?? 0) !== 0 ? (
                                            <Sub>
                                                {t('billing.planned_amount', {
                                                    amount: formatCurrency(
                                                        unit.planned ?? '0',
                                                    ),
                                                })}
                                            </Sub>
                                        ) : null}
                                        <Sub>
                                            {tCount(
                                                'billing.invoices',
                                                unit.invoices_count,
                                            )}
                                        </Sub>
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Money value={unit.collected} />
                                        {Number(unit.outstanding ?? 0) > 0 ? (
                                            <Sub>
                                                {t(
                                                    'billing.outstanding_amount',
                                                    {
                                                        amount: formatCurrency(
                                                            unit.outstanding ??
                                                                '0',
                                                        ),
                                                    },
                                                )}
                                            </Sub>
                                        ) : null}
                                        {Number(unit.overdue ?? 0) > 0 ? (
                                            <Sub className="text-danger">
                                                {t('billing.overdue_amount', {
                                                    amount: formatCurrency(
                                                        unit.overdue ?? '0',
                                                    ),
                                                })}
                                            </Sub>
                                        ) : null}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        <Money value={unit.margin} />
                                        {unit.margin_pct !== null &&
                                        unit.margin_pct !== undefined ? (
                                            <Sub>
                                                {formatNumber(
                                                    unit.margin_pct,
                                                    1,
                                                )}{' '}
                                                %
                                            </Sub>
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
                                colSpan={wide ? 2 : 1}
                            >
                                {t('billing.total', { count: totals.units })}
                            </th>
                            <td className="px-3 py-2 text-right">
                                {formatMinutes(totals.sold_minutes)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatMinutes(totals.real_minutes)}
                                {!wide && totals.pending_minutes > 0 ? (
                                    <Sub>
                                        {t('billing.pending_hours', {
                                            hours: formatMinutes(
                                                totals.pending_minutes,
                                            ),
                                        })}
                                    </Sub>
                                ) : null}
                            </td>
                            {wide ? (
                                <td className="px-3 py-2 text-right">
                                    {formatMinutes(totals.pending_minutes)}
                                </td>
                            ) : null}
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
                                        {Number(totals.outstanding ?? 0) > 0 ? (
                                            <Sub>
                                                {t(
                                                    'billing.outstanding_amount',
                                                    {
                                                        amount: formatCurrency(
                                                            totals.outstanding ??
                                                                '0',
                                                        ),
                                                    },
                                                )}
                                            </Sub>
                                        ) : null}
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
