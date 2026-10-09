import { Link, router } from '@inertiajs/react';
import { Ban, Check, RotateCcw, Search } from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import { SearchableSelect } from '@/components/domain/searchable-select';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency } from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import { cn } from '@/lib/utils';
import type { BillingClientOption, HoldedContactRow } from '@/types';

type View = 'todos' | 'descartados';

const PAGE = 50;

const fold = (value: string | null | undefined) =>
    (value ?? '')
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();

/** Cómo está casado un contacto: con su cliente y el método, descartado o sin casar. */
function ContactStatus({ row }: { row: HoldedContactRow }) {
    if (row.client) {
        return (
            <span className="grid gap-0.5">
                <span className="inline-flex min-w-0 items-center gap-1.5">
                    <Check
                        aria-hidden="true"
                        className="size-4 shrink-0 text-success"
                    />
                    <Link
                        href={`/clientes/${row.client.id}/facturacion`}
                        className={cn(
                            'truncate rounded-md hover:underline',
                            FOCUS_RING,
                        )}
                    >
                        {row.client.name}
                    </Link>
                </span>
                <span className="text-xs text-muted-foreground">
                    {t(
                        `billing.contacts.match.${row.match_method ?? 'manual'}`,
                    )}
                </span>
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1.5 text-muted-foreground">
            <Ban aria-hidden="true" className="size-4 shrink-0" />
            {row.ignored
                ? t('billing.contacts.ignored')
                : t('billing.directory.unmatched')}
        </span>
    );
}

function DirectoryActions({
    row,
    clients,
}: {
    row: HoldedContactRow;
    clients: BillingClientOption[];
}) {
    const [processing, setProcessing] = useState(false);
    const send = (action: 'assign' | 'ignore' | 'auto', clientId?: number) =>
        router.put(
            `/facturacion/contactos/${row.id}`,
            { action, client_id: clientId ?? null },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    return (
        <div className="flex flex-wrap items-center justify-end gap-1 lg:flex-nowrap">
            <SearchableSelect
                value={null}
                placeholder={t(
                    row.client
                        ? 'billing.directory.change'
                        : 'billing.directory.choose',
                )}
                search={t('billing.contacts.search_client')}
                empty={t('billing.contacts.no_client_found')}
                aria-label={t('billing.review.choose_client_for', {
                    contact: row.name,
                })}
                className="h-8 w-full min-w-0 sm:w-52"
                dataTest="directory-client"
                disabled={processing}
                groups={[
                    {
                        label: null,
                        options: clients.map((client) => ({
                            value: String(client.id),
                            label: client.is_active
                                ? client.name
                                : t('billing.contacts.inactive_client', {
                                      name: client.name,
                                  }),
                            hint: client.tax_id,
                        })),
                    },
                ]}
                onChange={(value) => send('assign', Number(value))}
            />
            {row.ignored || row.match_method === 'manual' ? (
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={processing}
                    onClick={() => send('auto')}
                    title={t('billing.directory.auto_hint')}
                >
                    <RotateCcw aria-hidden="true" />
                    {t('billing.contacts.auto')}
                </Button>
            ) : (
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={processing}
                    onClick={() => send('ignore')}
                    aria-label={t('billing.review.ignore_contact', {
                        contact: row.name,
                    })}
                >
                    <Ban aria-hidden="true" />
                    {t('billing.contacts.ignore')}
                </Button>
            )}
        </div>
    );
}

/**
 * Contactos de Holded en Ajustes (I5, D-413): el directorio con todos y los descartados (antes,
 * vistas de «Por revisar»), con búsqueda y la misma tabla de la bandeja en modo directorio: el
 * cliente de cada uno y cómo se casó, y cambiarlo, descartarlo o que se vuelva a casar solo. Lo que
 * queda por resolver está en «Por revisar».
 */
export function HoldedContactsDirectory({
    view: initialView,
    rows,
    clients,
}: {
    view: View;
    rows: HoldedContactRow[];
    clients: BillingClientOption[];
}) {
    const id = useId();
    const [view, setView] = useState<View>(initialView);
    const [search, setSearch] = useState('');
    const [limit, setLimit] = useState(PAGE);
    const query = fold(search.trim());
    const ignored = rows.filter((row) => row.ignored).length;

    const shown = useMemo(
        () =>
            rows
                .filter((row) => (view === 'descartados' ? row.ignored : true))
                .filter(
                    (row) =>
                        query === '' ||
                        [row.name, row.trade_name, row.tax_id, row.client?.name]
                            .map(fold)
                            .some((value) => value.includes(query)),
                ),
        [rows, view, query],
    );
    const visible = shown.slice(0, limit);
    const caption = t('billing.directory.caption');

    return (
        <section
            id="contactos-holded"
            aria-labelledby={`${id}-title`}
            className="grid scroll-mt-20 content-start gap-4"
            data-test="holded-contacts-directory"
        >
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="min-w-0 space-y-1">
                    <h2 id={`${id}-title`} className="text-lg font-normal">
                        {t('billing.directory.title')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('billing.directory.description')}{' '}
                        <Link
                            href="/facturacion/por-revisar?tipo=contactos"
                            className={cn(
                                'rounded-md text-primary-text underline underline-offset-2',
                                FOCUS_RING,
                            )}
                        >
                            {t('billing.directory.to_review')}
                        </Link>
                    </p>
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <div
                    role="group"
                    aria-label={t('billing.contacts.views')}
                    className="inline-flex rounded-md border p-0.5"
                >
                    {(['todos', 'descartados'] as const).map((item) => (
                        <Button
                            key={item}
                            type="button"
                            size="sm"
                            variant={view === item ? 'secondary' : 'ghost'}
                            className="h-7"
                            aria-pressed={view === item}
                            onClick={() => {
                                setView(item);
                                setLimit(PAGE);
                            }}
                            data-test={`directory-view-${item}`}
                        >
                            {t(`billing.contacts.view.${item}`)}
                            <span className="tabular text-xs text-muted-foreground">
                                {item === 'todos' ? rows.length : ignored}
                            </span>
                        </Button>
                    ))}
                </div>
                <div className="relative w-full sm:w-72">
                    <Label htmlFor={`${id}-search`} className="sr-only">
                        {t('billing.review.search')}
                    </Label>
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        id={`${id}-search`}
                        type="search"
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setLimit(PAGE);
                        }}
                        placeholder={t('billing.directory.search')}
                        className="h-8 pl-8"
                        autoComplete="off"
                    />
                </div>
            </div>

            <p className="sr-only" aria-live="polite">
                {tCount('billing.directory.results', shown.length)}
            </p>

            {shown.length === 0 ? (
                <EmptyState
                    icon={Search}
                    title={t(
                        rows.length === 0
                            ? 'billing.contacts.none'
                            : 'billing.review.no_results',
                    )}
                    description={t(
                        rows.length === 0
                            ? 'billing.contacts.none_description'
                            : 'billing.directory.no_results_description',
                    )}
                />
            ) : (
                <>
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
                            data-test="directory-table"
                        >
                            <caption className="sr-only">{caption}</caption>
                            <thead>
                                <tr className="border-b">
                                    <th
                                        scope="col"
                                        className="w-[32%] px-3 py-2 text-left"
                                    >
                                        {t('billing.review.col.contact')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right"
                                    >
                                        {t('billing.review.col.invoiced')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="w-[26%] px-3 py-2 text-left"
                                    >
                                        {t('billing.contacts.client')}
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right"
                                    >
                                        {t('billing.review.col.actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-b last:border-0 even:bg-muted"
                                    >
                                        <th
                                            scope="row"
                                            className="max-w-0 px-3 py-2 text-left font-normal"
                                        >
                                            <span
                                                className="block truncate"
                                                title={row.name}
                                            >
                                                {row.name}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {row.tax_id
                                                    ? t(
                                                          'billing.contacts.tax_id',
                                                          {
                                                              tax_id: row.tax_id,
                                                          },
                                                      )
                                                    : t(
                                                          'billing.contacts.no_tax_id',
                                                      )}
                                                {row.city
                                                    ? ` · ${row.city}`
                                                    : ''}
                                            </span>
                                        </th>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                            {formatCurrency(row.invoiced)}
                                            <span className="block text-xs text-muted-foreground">
                                                {tCount(
                                                    'billing.invoices',
                                                    row.invoices,
                                                )}
                                            </span>
                                        </td>
                                        <td className="max-w-0 px-3 py-2">
                                            <ContactStatus row={row} />
                                        </td>
                                        <td className="px-3 py-2">
                                            <DirectoryActions
                                                row={row}
                                                clients={clients}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <ul
                        className="grid gap-2 md:hidden"
                        data-test="directory-cards"
                    >
                        {visible.map((row) => (
                            <li
                                key={row.id}
                                className="grid gap-2 rounded-md border bg-card p-3 text-sm"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <span className="min-w-0 truncate">
                                        {row.name}
                                    </span>
                                    <span className="tabular shrink-0">
                                        {formatCurrency(row.invoiced)}
                                    </span>
                                </div>
                                <ContactStatus row={row} />
                                <DirectoryActions row={row} clients={clients} />
                            </li>
                        ))}
                    </ul>
                    {shown.length > limit ? (
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setLimit(limit + PAGE)}
                            >
                                {t('billing.directory.more', {
                                    shown: limit,
                                    total: shown.length,
                                })}
                            </Button>
                        </div>
                    ) : null}
                </>
            )}
        </section>
    );
}
