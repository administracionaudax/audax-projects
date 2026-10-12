import { Head, Link, router } from '@inertiajs/react';
import { Plus, Repeat, Search } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { BillingHeader } from '@/components/billing/billing-header';
import { ViewTabs } from '@/components/billing/view-tabs';
import { EmptyState } from '@/components/empty-state';
import { invoiceTabs } from '@/components/invoicing/invoice-views';
import type { InvoiceView } from '@/components/invoicing/invoice-views';
import {
    RecurringModeText,
    RecurringStateBadge,
    periodicityText,
} from '@/components/invoicing/recurring-parts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { ROW_CLICK_CLASS, rowClickProps } from '@/lib/row-click';
import { cn } from '@/lib/utils';
import type { RecurringRow } from '@/types';

type StateFilter = 'activas' | 'pausadas' | 'acabadas' | 'todas';

const STATES: StateFilter[] = ['todas', 'activas', 'pausadas', 'acabadas'];

type Props = {
    recurrences: RecurringRow[];
    counts: Record<StateFilter, number>;
    filters: { estado: StateFilter; buscar: string };
    views: Partial<Record<InvoiceView, number>>;
    pending_review: number;
    can_manage: boolean;
    auto_active: boolean;
};

const URL = '/facturacion/recurrentes';

/**
 * Facturas recurrentes (PLAN-EMISION §6.2, «Recurrentes»; E3): una vista más de Facturas, con el
 * nombre, el cliente, la periodicidad, la próxima fecha, la base, qué hace (borrador o emitir y
 * enviar) y el estado. Las importadas de Holded llegan en pausa y se revisan una a una.
 */
export default function RecurringIndex({
    recurrences,
    counts,
    filters,
    views,
    pending_review: pendingReview,
    can_manage: canManage,
    auto_active: autoActive,
}: Props) {
    const id = useId();
    const [search, setSearch] = useState(filters.buscar);

    useEffect(() => {
        if (search.trim() === filters.buscar.trim()) {
            return;
        }
        const timer = window.setTimeout(
            () =>
                router.get(
                    URL,
                    {
                        ...(filters.estado !== 'todas'
                            ? { estado: filters.estado }
                            : {}),
                        ...(search.trim() ? { buscar: search.trim() } : {}),
                    },
                    {
                        preserveState: true,
                        preserveScroll: true,
                        replace: true,
                    },
                ),
            300,
        );

        return () => window.clearTimeout(timer);
    }, [search, filters.buscar, filters.estado]);

    const stateHref = (state: StateFilter) => {
        const query = new URLSearchParams();
        if (state !== 'todas') {
            query.set('estado', state);
        }
        if (filters.buscar) {
            query.set('buscar', filters.buscar);
        }
        const string = query.toString();

        return string ? `${URL}?${string}` : URL;
    };

    return (
        <>
            <Head title={t('invoicing.recurring.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
                <BillingHeader
                    current="facturas"
                    title={t('billing.invoices.title')}
                    description={t('invoicing.recurring.description')}
                    actions={
                        canManage ? (
                            <Button asChild data-test="new-recurring">
                                <Link href={`${URL}/nueva`}>
                                    <Plus aria-hidden="true" />
                                    {t('invoicing.recurring.new')}
                                </Link>
                            </Button>
                        ) : null
                    }
                />

                <ViewTabs
                    label={t('billing.invoices.views_label')}
                    current="recurrentes"
                    dataTest="invoice-views"
                    tabs={invoiceTabs({
                        views,
                        current: 'recurrentes',
                        href: (view) =>
                            view === 'todas'
                                ? '/facturacion/facturas'
                                : `/facturacion/facturas?vista=${view}`,
                        recurring: counts.todas,
                    })}
                />

                {!autoActive ? (
                    <p
                        className="rounded-md bg-info-soft px-3 py-2 text-sm"
                        data-test="recurring-test-mode"
                    >
                        {t('invoicing.recurring.test_mode')}
                    </p>
                ) : null}
                {pendingReview > 0 ? (
                    <p
                        className="rounded-md bg-warning-soft px-3 py-2 text-sm"
                        data-test="recurring-review"
                    >
                        {t('invoicing.recurring.pending_review', {
                            count: pendingReview,
                        })}
                    </p>
                ) : null}

                <div className="flex flex-wrap items-center gap-3">
                    <nav
                        aria-label={t('invoicing.recurring.states_label')}
                        className="flex flex-wrap gap-1"
                    >
                        {STATES.map((state) => (
                            <Link
                                key={state}
                                href={stateHref(state)}
                                preserveScroll
                                aria-current={
                                    filters.estado === state
                                        ? 'page'
                                        : undefined
                                }
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-sm',
                                    filters.estado === state
                                        ? 'border-primary bg-info-soft text-foreground'
                                        : 'text-muted-foreground hover:text-foreground',
                                    FOCUS_RING,
                                )}
                                data-test={`recurring-state-${state}`}
                            >
                                {t(`invoicing.recurring.state_filter.${state}`)}
                                <span className="tabular text-xs">
                                    {counts[state]}
                                </span>
                            </Link>
                        ))}
                    </nav>
                    <div className="relative ml-auto w-full sm:w-72">
                        <Label htmlFor={`${id}-search`} className="sr-only">
                            {t('invoicing.recurring.search')}
                        </Label>
                        <Search
                            aria-hidden="true"
                            className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            id={`${id}-search`}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder={t('invoicing.recurring.search')}
                            className="pl-8"
                        />
                    </div>
                </div>

                {recurrences.length === 0 ? (
                    <EmptyState
                        icon={Repeat}
                        title={t(
                            counts.todas === 0
                                ? 'invoicing.recurring.empty'
                                : 'invoicing.recurring.empty_filtered',
                        )}
                        description={t('invoicing.recurring.empty_description')}
                    >
                        {canManage && counts.todas === 0 ? (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`${URL}/nueva`}>
                                    <Plus aria-hidden="true" />
                                    {t('invoicing.recurring.new')}
                                </Link>
                            </Button>
                        ) : null}
                    </EmptyState>
                ) : (
                    <>
                        <RecurringTable rows={recurrences} />
                        <RecurringCards rows={recurrences} />
                    </>
                )}
            </div>
        </>
    );
}

function NextDate({ row }: { row: RecurringRow }) {
    if (row.next_run_on === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span
            className={
                row.state === 'paused' ? 'text-muted-foreground' : undefined
            }
        >
            {formatDate(row.next_run_on)}
        </span>
    );
}

function RecurringTable({ rows }: { rows: RecurringRow[] }) {
    const caption = t('invoicing.recurring.caption');

    return (
        <div
            className={cn(
                'hidden overflow-x-auto rounded-md border md:block',
                FOCUS_RING,
            )}
            role="region"
            aria-label={caption}
            tabIndex={0}
        >
            <table
                className="tabular w-full text-sm"
                data-test="recurring-table"
            >
                <caption className="sr-only">{caption}</caption>
                <thead>
                    <tr className="border-b text-left text-xs tracking-[0.12em] text-muted-foreground uppercase">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('invoicing.recurring.col.name')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('invoicing.recurring.col.periodicity')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('invoicing.recurring.col.next')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('invoicing.recurring.col.base')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('invoicing.recurring.col.mode')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('invoicing.recurring.col.state')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('invoicing.recurring.col.last')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={row.id}
                            className={cn(
                                'h-12 border-b last:border-0 even:bg-muted',
                                ROW_CLICK_CLASS,
                            )}
                            {...rowClickProps}
                        >
                            <th
                                scope="row"
                                className="px-3 py-1.5 text-left font-normal"
                            >
                                <Link
                                    href={row.url}
                                    data-row-primary
                                    className={cn(
                                        'rounded-md text-primary-text hover:underline',
                                        FOCUS_RING,
                                    )}
                                    data-test="recurring-name"
                                >
                                    {row.name}
                                </Link>
                                <span className="block text-xs text-muted-foreground">
                                    {row.client.name}
                                    {row.project
                                        ? ` · ${row.project.code}`
                                        : ''}
                                </span>
                            </th>
                            <td className="px-3 py-1.5 whitespace-nowrap">
                                {periodicityText(row)}
                            </td>
                            <td className="px-3 py-1.5 whitespace-nowrap">
                                <NextDate row={row} />
                            </td>
                            <td className="px-3 py-1.5 text-right whitespace-nowrap">
                                {formatCurrency(row.subtotal)}
                            </td>
                            <td className="px-3 py-1.5">
                                <RecurringModeText row={row} />
                            </td>
                            <td className="px-3 py-1.5 whitespace-nowrap">
                                <RecurringStateBadge row={row} />
                            </td>
                            <td className="px-3 py-1.5 whitespace-nowrap">
                                {row.last_document ? (
                                    <Link
                                        href={row.last_document.url}
                                        className={cn(
                                            'rounded-md hover:underline',
                                            FOCUS_RING,
                                        )}
                                    >
                                        {row.last_document.number ??
                                            t('invoicing.recurring.draft')}
                                        <span className="block text-xs text-muted-foreground">
                                            {formatDate(
                                                row.last_document.issue_date,
                                            )}
                                        </span>
                                    </Link>
                                ) : (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** En el móvil, una tarjeta por recurrente: nombre y base; cliente, periodicidad y próxima fecha. */
function RecurringCards({ rows }: { rows: RecurringRow[] }) {
    return (
        <ul className="grid gap-2 md:hidden" data-test="recurring-cards">
            {rows.map((row) => (
                <li key={row.id}>
                    <Link
                        href={row.url}
                        className={cn(
                            'grid gap-1.5 rounded-md border bg-card p-3 text-sm hover:bg-accent/60',
                            FOCUS_RING,
                        )}
                    >
                        <span className="flex items-baseline justify-between gap-3">
                            <span className="min-w-0 truncate text-primary-text">
                                {row.name}
                            </span>
                            <span className="tabular shrink-0">
                                {formatCurrency(row.subtotal)}
                            </span>
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {row.client.name} · {periodicityText(row)}
                        </span>
                        <span className="flex items-center justify-between gap-3 text-xs">
                            <RecurringStateBadge row={row} />
                            <span className="tabular text-muted-foreground">
                                {row.next_run_on
                                    ? t('invoicing.recurring.next_short', {
                                          date: formatDate(row.next_run_on),
                                      })
                                    : '—'}
                            </span>
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

RecurringIndex.layout = {
    breadcrumbs: [
        { title: t('billing.section'), href: '/facturacion' },
        { title: t('billing.invoices.title'), href: '/facturacion/facturas' },
        { title: t('invoicing.recurring.tab'), href: URL },
    ],
};
