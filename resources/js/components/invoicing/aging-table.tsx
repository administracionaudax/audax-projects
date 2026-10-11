import { Link } from '@inertiajs/react';
import { HelpTip } from '@/components/billing/help-tip';
import { toQueryString } from '@/components/billing/invoice-table';
import type { InvoiceQuery } from '@/components/billing/invoice-table';
import { useIsMobile } from '@/hooks/use-mobile';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { AgingBucket, AgingByClient } from '@/types';

/** Tramos en el orden de la tabla y su valor en la URL (InvoiceList::AGES). */
export const AGES: { bucket: AgingBucket; param: string }[] = [
    { bucket: 'current', param: 'en-plazo' },
    { bucket: 'd1_30', param: '1-30' },
    { bucket: 'd31_60', param: '31-60' },
    { bucket: 'd61_90', param: '61-90' },
    { bucket: 'd90_plus', param: 'mas-90' },
];

const URL = '/facturacion/facturas';

type Selected = { client: number | null; age: string | null };

/** Una celda: el importe que filtra la lista (cliente y tramo); vacía, un guion. */
function AgingCell({
    amount,
    client,
    age,
    label,
    strong,
    href,
    selected,
}: {
    amount: string;
    client: number | null;
    age: string | null;
    label: string;
    strong?: boolean;
    href: (client: number | null, age: string | null) => string;
    selected: Selected;
}) {
    if (Number(amount) === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    const active = selected.client === client && selected.age === age;

    return (
        <Link
            href={href(client, age)}
            preserveScroll
            preserveState
            aria-label={label}
            aria-current={active ? 'true' : undefined}
            className={cn(
                'tabular inline-block rounded-md px-1.5 py-0.5 hover:bg-muted hover:underline',
                active && 'bg-info-soft underline',
                strong && 'font-medium',
                FOCUS_RING,
            )}
            data-test="aging-cell"
        >
            {formatCurrency(amount)}
        </Link>
    );
}

/**
 * «Por cobrar» con la antigüedad por cliente (PLAN-EMISION §3.3, L3; E2, D-437), como la de Xero:
 * lo pendiente (con IVA) de cada cliente por tramos desde el vencimiento. Cada celda filtra la lista
 * de debajo (cliente y tramo); la celda elegida se marca y se quita al volver a pulsarla. En el
 * móvil, una tarjeta por cliente.
 */
export function AgingTable({
    aging,
    query,
    selected,
}: {
    aging: AgingByClient;
    query: InvoiceQuery;
    selected: Selected;
}) {
    const mobile = useIsMobile();

    if (aging.rows.length === 0) {
        return null;
    }

    const href = (client: number | null, age: string | null) => {
        const active = selected.client === client && selected.age === age;

        return (
            URL +
            toQueryString({
                ...query,
                vista: 'por-cobrar',
                cliente: active ? null : client,
                antiguedad: active ? null : age,
                pagina: null,
            })
        );
    };

    const name = (row: AgingByClient['rows'][number]) =>
        row.client?.name ??
        row.contact_name ??
        t('invoicing.collect.aging.no_client');
    const overdueIndex = (bucket: AgingBucket) => bucket !== 'current';

    return (
        <section
            aria-labelledby="aging-title"
            className="grid gap-2"
            data-test="aging-table"
        >
            <div className="flex items-center gap-1">
                <h2
                    id="aging-title"
                    className="text-xs font-medium tracking-[0.12em] text-muted-foreground uppercase"
                >
                    {t('invoicing.collect.aging.title')}
                </h2>
                <HelpTip topic={t('invoicing.collect.aging.title')}>
                    {t('invoicing.collect.aging.help')}
                </HelpTip>
            </div>

            {mobile ? (
                <ul className="grid gap-2">
                    {aging.rows.map((row) => (
                        <li
                            key={row.client?.id ?? row.contact_name}
                            className="grid gap-2 rounded-md border bg-card p-3 text-sm"
                        >
                            <div className="flex items-baseline justify-between gap-3">
                                <span className="min-w-0 truncate">
                                    {name(row)}
                                </span>
                                <AgingCell
                                    amount={row.total}
                                    client={row.client?.id ?? null}
                                    age={null}
                                    href={href}
                                    selected={selected}
                                    label={t(
                                        'invoicing.collect.aging.cell_total',
                                        {
                                            client: name(row),
                                        },
                                    )}
                                    strong
                                />
                            </div>
                            <dl className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                {AGES.filter(
                                    ({ bucket }) =>
                                        Number(row.buckets[bucket]) !== 0,
                                ).map(({ bucket, param }) => (
                                    <div
                                        key={bucket}
                                        className="flex items-center gap-1"
                                    >
                                        <dt
                                            className={cn(
                                                'text-muted-foreground',
                                                overdueIndex(bucket) &&
                                                    'text-danger',
                                            )}
                                        >
                                            {t(
                                                `billing.invoicing.aging.${bucket}`,
                                            )}
                                        </dt>
                                        <dd>
                                            <AgingCell
                                                amount={row.buckets[bucket]}
                                                client={row.client?.id ?? null}
                                                age={param}
                                                href={href}
                                                selected={selected}
                                                label={t(
                                                    'invoicing.collect.aging.cell',
                                                    {
                                                        client: name(row),
                                                        age: t(
                                                            `billing.invoicing.aging.${bucket}`,
                                                        ),
                                                    },
                                                )}
                                            />
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </li>
                    ))}
                </ul>
            ) : (
                <div
                    className={cn(
                        'overflow-x-auto rounded-md border',
                        FOCUS_RING,
                    )}
                    role="region"
                    aria-label={t('invoicing.collect.aging.title')}
                    tabIndex={0}
                >
                    <table className="tabular w-full min-w-[48rem] text-sm">
                        <caption className="sr-only">
                            {t('invoicing.collect.aging.caption')}
                        </caption>
                        <thead>
                            <tr className="border-b">
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-left font-medium"
                                >
                                    {t('billing.invoice.client')}
                                </th>
                                {AGES.map(({ bucket }) => (
                                    <th
                                        key={bucket}
                                        scope="col"
                                        className="px-3 py-2 text-right font-medium whitespace-nowrap"
                                    >
                                        {t(`billing.invoicing.aging.${bucket}`)}
                                    </th>
                                ))}
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right font-medium"
                                >
                                    {t('invoicing.collect.aging.total')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {aging.rows.map((row) => (
                                <tr
                                    key={row.client?.id ?? row.contact_name}
                                    className="border-b last:border-b-0"
                                >
                                    <th
                                        scope="row"
                                        className="max-w-64 truncate px-3 py-1.5 text-left font-normal"
                                    >
                                        {name(row)}
                                        <span className="ml-2 text-xs text-muted-foreground">
                                            {t(
                                                row.count === 1
                                                    ? 'invoicing.collect.aging.invoices_one'
                                                    : 'invoicing.collect.aging.invoices_other',
                                                { count: row.count },
                                            )}
                                        </span>
                                    </th>
                                    {AGES.map(({ bucket, param }) => (
                                        <td
                                            key={bucket}
                                            className="px-1.5 py-1.5 text-right"
                                        >
                                            <AgingCell
                                                amount={row.buckets[bucket]}
                                                client={row.client?.id ?? null}
                                                age={param}
                                                href={href}
                                                selected={selected}
                                                label={t(
                                                    'invoicing.collect.aging.cell',
                                                    {
                                                        client: name(row),
                                                        age: t(
                                                            `billing.invoicing.aging.${bucket}`,
                                                        ),
                                                    },
                                                )}
                                            />
                                        </td>
                                    ))}
                                    <td className="px-1.5 py-1.5 text-right">
                                        <AgingCell
                                            amount={row.total}
                                            client={row.client?.id ?? null}
                                            age={null}
                                            href={href}
                                            selected={selected}
                                            label={t(
                                                'invoicing.collect.aging.cell_total',
                                                { client: name(row) },
                                            )}
                                            strong
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t bg-muted/50">
                                <th
                                    scope="row"
                                    className="px-3 py-2 text-left font-normal"
                                >
                                    {aging.more > 0
                                        ? t(
                                              'invoicing.collect.aging.total_more',
                                              {
                                                  count: aging.more,
                                              },
                                          )
                                        : t('invoicing.collect.aging.total')}
                                </th>
                                {AGES.map(({ bucket, param }) => (
                                    <td
                                        key={bucket}
                                        className="px-1.5 py-2 text-right"
                                    >
                                        <AgingCell
                                            amount={aging.totals[bucket]}
                                            client={null}
                                            age={param}
                                            href={href}
                                            selected={selected}
                                            label={t(
                                                'invoicing.collect.aging.cell_all',
                                                {
                                                    age: t(
                                                        `billing.invoicing.aging.${bucket}`,
                                                    ),
                                                },
                                            )}
                                        />
                                    </td>
                                ))}
                                <td className="px-3 py-2 text-right">
                                    {formatCurrency(aging.total)}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}
        </section>
    );
}
