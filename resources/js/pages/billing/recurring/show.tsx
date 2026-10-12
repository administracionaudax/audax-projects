import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarX2,
    CheckCheck,
    FilePlus2,
    MoreHorizontal,
    PencilLine,
    Pause,
    Play,
    RotateCcw,
    Trash2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { BillingHeader } from '@/components/billing/billing-header';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    formatQuantity,
    unitLabel,
} from '@/components/invoicing/invoicing-format';
import {
    RecurringModeText,
    RecurringStateBadge,
    periodicityText,
} from '@/components/invoicing/recurring-parts';
import { PageSection } from '@/components/projects-list/page-section';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatDate, formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { RecurringDetail } from '@/types';

type Props = {
    recurring: RecurringDetail;
    can_manage: boolean;
    auto_active: boolean;
    today: string;
};

const WORDS = ['[mes]', '[mes anterior]', '[año]', '[trimestre]', '[fecha]'];

/**
 * La ficha de una recurrente (PLAN-EMISION §6.2; E3): qué genera (la plantilla con sus palabras
 * dinámicas), cuándo (las próximas 12 fechas, con «Omitir» en cada una) y qué ha generado (el
 * historial, con cada factura). «Generar ahora», pausar y reanudar, editar y, si viene de Holded,
 * «Revisada».
 */
export default function RecurringShow({
    recurring,
    can_manage: canManage,
    auto_active: autoActive,
}: Props) {
    const [processing, setProcessing] = useState<string | null>(null);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const base = `/facturacion/recurrentes/${recurring.id}`;
    const post = (path: string, data: Record<string, string | boolean> = {}) =>
        router.post(`${base}/${path}`, data, {
            preserveScroll: true,
            onStart: () => setProcessing(path),
            onFinish: () => setProcessing(null),
        });
    const generated = recurring.runs.filter(
        (run) => run.status === 'generated',
    ).length;

    return (
        <>
            <Head title={recurring.name} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <BillingHeader
                    current="facturas"
                    kicker={
                        <>
                            {t('invoicing.recurring.kicker')}
                            <span aria-hidden="true">·</span>
                            <Link
                                href={`/clientes/${recurring.client.id}/facturacion`}
                                className={cn(
                                    'rounded-md hover:underline',
                                    FOCUS_RING,
                                )}
                            >
                                {recurring.client.name}
                            </Link>
                        </>
                    }
                    title={recurring.name}
                    description={
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <RecurringStateBadge row={recurring} />
                            <span className="text-muted-foreground">
                                {periodicityText(recurring)}
                            </span>
                            <span
                                aria-hidden="true"
                                className="text-muted-foreground"
                            >
                                ·
                            </span>
                            <RecurringModeText row={recurring} />
                        </div>
                    }
                    actions={
                        canManage ? (
                            <>
                                {recurring.state !== 'finished' ? (
                                    <Button
                                        onClick={() => post('generar')}
                                        disabled={processing !== null}
                                        data-test="recurring-generate"
                                    >
                                        {processing === 'generar' ? (
                                            <Spinner />
                                        ) : (
                                            <FilePlus2 aria-hidden="true" />
                                        )}
                                        {t('invoicing.recurring.generate')}
                                    </Button>
                                ) : null}
                                {recurring.state === 'active' ? (
                                    <Button
                                        variant="outline"
                                        onClick={() => post('pausar')}
                                        disabled={processing !== null}
                                        data-test="recurring-pause"
                                    >
                                        <Pause aria-hidden="true" />
                                        {t('invoicing.recurring.pause')}
                                    </Button>
                                ) : null}
                                {recurring.state === 'paused' ? (
                                    <Button
                                        variant="outline"
                                        onClick={() => post('reanudar')}
                                        disabled={processing !== null}
                                        data-test="recurring-resume"
                                    >
                                        <Play aria-hidden="true" />
                                        {t('invoicing.recurring.resume')}
                                    </Button>
                                ) : null}
                                <Button variant="outline" asChild>
                                    <Link
                                        href={`${base}/editar`}
                                        data-test="recurring-edit"
                                    >
                                        <PencilLine aria-hidden="true" />
                                        {t('invoicing.recurring.edit')}
                                    </Link>
                                </Button>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            aria-label={t(
                                                'invoicing.show.more',
                                            )}
                                            data-test="recurring-more"
                                        >
                                            <MoreHorizontal aria-hidden="true" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        className="min-w-56"
                                    >
                                        {recurring.from_holded &&
                                        !recurring.reviewed ? (
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    post('revisada')
                                                }
                                                data-test="recurring-review"
                                            >
                                                <CheckCheck aria-hidden="true" />
                                                {t(
                                                    'invoicing.recurring.mark_reviewed',
                                                )}
                                            </DropdownMenuItem>
                                        ) : null}
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                setConfirmDelete(true)
                                            }
                                            className="text-danger"
                                            disabled={generated > 0}
                                            data-test="recurring-delete"
                                        >
                                            <Trash2 aria-hidden="true" />
                                            {generated > 0
                                                ? t(
                                                      'invoicing.recurring.delete_blocked',
                                                  )
                                                : t(
                                                      'invoicing.recurring.delete',
                                                  )}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </>
                        ) : null
                    }
                />

                <Notices recurring={recurring} autoActive={autoActive} />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="grid min-w-0 content-start gap-6">
                        <PageSection
                            title={t('invoicing.recurring.template')}
                            description={t(
                                'invoicing.recurring.template_description',
                            )}
                        >
                            <TemplateLines recurring={recurring} />
                            {recurring.body ? (
                                <p className="rounded-md bg-muted px-3 py-2 text-sm whitespace-pre-line">
                                    {recurring.body}
                                </p>
                            ) : null}
                        </PageSection>

                        <PageSection
                            title={t('invoicing.recurring.history')}
                            description={t(
                                'invoicing.recurring.history_description',
                                { count: generated },
                            )}
                        >
                            <History recurring={recurring} />
                        </PageSection>
                    </div>

                    <aside className="grid content-start gap-6">
                        <PageSection title={t('invoicing.recurring.calendar')}>
                            <Calendar
                                recurring={recurring}
                                canManage={canManage}
                                onSkip={(date, skip) =>
                                    post('omitir', { period: date, skip })
                                }
                                busy={processing !== null}
                            />
                        </PageSection>
                        <PageSection title={t('invoicing.recurring.details')}>
                            <Details recurring={recurring} />
                        </PageSection>
                    </aside>
                </div>
            </div>

            <ConfirmDialog
                trigger={<span hidden />}
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title={t('invoicing.recurring.delete_title')}
                description={t('invoicing.recurring.delete_description')}
                confirmLabel={t('invoicing.recurring.delete')}
                processing={processing === 'delete'}
                onConfirm={() =>
                    router.delete(base, {
                        onStart: () => setProcessing('delete'),
                        onFinish: () => setProcessing(null),
                    })
                }
            />
        </>
    );
}

function Notices({
    recurring,
    autoActive,
}: {
    recurring: RecurringDetail;
    autoActive: boolean;
}) {
    const notes: { key: string; tone: string; text: string; role?: 'alert' }[] =
        [];

    if (recurring.last_error && recurring.state === 'active') {
        notes.push({
            key: 'error',
            tone: 'bg-danger-soft',
            role: 'alert',
            text: t('invoicing.recurring.last_error', {
                date: recurring.last_error_at
                    ? formatDateTime(recurring.last_error_at)
                    : '—',
                reason: recurring.last_error,
            }),
        });
    }
    if (recurring.from_holded && !recurring.reviewed) {
        notes.push({
            key: 'holded',
            tone: 'bg-warning-soft',
            text: t('invoicing.recurring.notice_holded'),
        });
    } else if (recurring.paused) {
        notes.push({
            key: 'paused',
            tone: 'bg-muted',
            text: t('invoicing.recurring.notice_paused', {
                date: formatDateTime(recurring.paused.at),
                person: recurring.paused.by ?? '—',
            }),
        });
    }
    if (!autoActive && recurring.state === 'active') {
        notes.push({
            key: 'test',
            tone: 'bg-info-soft',
            text: t('invoicing.recurring.test_mode'),
        });
    }

    if (notes.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-1" data-test="recurring-notices">
            {notes.map((note) => (
                <p
                    key={note.key}
                    role={note.role}
                    className={cn('rounded-md px-3 py-2 text-sm', note.tone)}
                >
                    {note.text}
                </p>
            ))}
        </div>
    );
}

function TemplateLines({ recurring }: { recurring: RecurringDetail }) {
    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={t('invoicing.recurring.template')}
            tabIndex={0}
        >
            <table
                className="tabular w-full min-w-[34rem] text-sm"
                data-test="recurring-lines"
            >
                <thead>
                    <tr className="border-b text-left text-xs tracking-[0.12em] text-muted-foreground uppercase">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('invoicing.show.concept')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('invoicing.show.quantity')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('invoicing.show.price')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('invoicing.show.discount')}
                        </th>
                        <th
                            scope="col"
                            className="px-3 py-2 text-right font-medium"
                        >
                            {t('invoicing.show.tax')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {recurring.lines.map((line) =>
                        line.kind === 'text' ? (
                            <tr
                                key={line.id}
                                className="border-b last:border-0"
                            >
                                <td
                                    colSpan={5}
                                    className="px-3 py-2 whitespace-pre-line text-muted-foreground"
                                >
                                    {line.description}
                                </td>
                            </tr>
                        ) : (
                            <tr
                                key={line.id}
                                className="border-b last:border-0 even:bg-muted"
                            >
                                <th
                                    scope="row"
                                    className="px-3 py-2 text-left font-normal"
                                >
                                    {line.service?.name ?? line.description}
                                    {line.service && line.description ? (
                                        <span className="block text-xs whitespace-pre-line text-muted-foreground">
                                            {line.description}
                                        </span>
                                    ) : null}
                                </th>
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    {formatQuantity(line.quantity)}{' '}
                                    {unitLabel(line.unit, line.quantity)}
                                </td>
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    {formatCurrency(line.unit_price)}
                                </td>
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    {Number(line.discount_pct) === 0
                                        ? '—'
                                        : `${formatQuantity(line.discount_pct)} %`}
                                </td>
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    {line.tax ?? '—'}
                                </td>
                            </tr>
                        ),
                    )}
                </tbody>
                <tfoot>
                    <tr className="border-t bg-muted">
                        <th
                            scope="row"
                            colSpan={4}
                            className="px-3 py-2 text-left font-medium"
                        >
                            {t('invoicing.recurring.base_each')}
                        </th>
                        <td className="px-3 py-2 text-right font-medium whitespace-nowrap">
                            {formatCurrency(recurring.subtotal)}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

function Calendar({
    recurring,
    canManage,
    onSkip,
    busy,
}: {
    recurring: RecurringDetail;
    canManage: boolean;
    onSkip: (date: string, skip: boolean) => void;
    busy: boolean;
}) {
    if (recurring.upcoming.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('invoicing.recurring.no_upcoming')}
            </p>
        );
    }

    return (
        <ol
            className="divide-y rounded-md border text-sm"
            data-test="recurring-calendar"
        >
            {recurring.upcoming.map((one, index) => (
                <li
                    key={one.date}
                    className={cn(
                        'flex items-center justify-between gap-3 px-3 py-2',
                        one.skipped && 'text-muted-foreground',
                    )}
                >
                    <span className="tabular">
                        <span
                            className={one.skipped ? 'line-through' : undefined}
                        >
                            {formatDate(one.date)}
                        </span>
                        {index === 0 && !one.skipped ? (
                            <span className="ml-2 rounded-md bg-info-soft px-1.5 py-0.5 text-xs text-foreground">
                                {t('invoicing.recurring.next')}
                            </span>
                        ) : null}
                        {one.skipped ? (
                            <span className="ml-2 text-xs">
                                {t('invoicing.recurring.skipped')}
                            </span>
                        ) : null}
                    </span>
                    {canManage && recurring.state !== 'finished' ? (
                        <Button
                            variant="ghost"
                            size="sm"
                            disabled={busy}
                            onClick={() => onSkip(one.date, !one.skipped)}
                            data-test={
                                one.skipped
                                    ? 'recurring-unskip'
                                    : 'recurring-skip'
                            }
                            aria-label={t(
                                one.skipped
                                    ? 'invoicing.recurring.unskip_label'
                                    : 'invoicing.recurring.skip_label',
                                { date: formatDate(one.date) },
                            )}
                        >
                            {one.skipped ? (
                                <RotateCcw aria-hidden="true" />
                            ) : (
                                <CalendarX2 aria-hidden="true" />
                            )}
                            {t(
                                one.skipped
                                    ? 'invoicing.recurring.unskip'
                                    : 'invoicing.recurring.skip',
                            )}
                        </Button>
                    ) : null}
                </li>
            ))}
        </ol>
    );
}

function History({ recurring }: { recurring: RecurringDetail }) {
    if (recurring.runs.length === 0) {
        return (
            <p className="rounded-md border border-dashed px-3 py-4 text-sm text-muted-foreground">
                {t('invoicing.recurring.no_history')}
            </p>
        );
    }

    return (
        <ol
            className="divide-y rounded-md border text-sm"
            data-test="recurring-history"
        >
            {recurring.runs.map((run) => (
                <li key={run.id} className="grid gap-0.5 px-3 py-2">
                    <span className="flex flex-wrap items-baseline justify-between gap-3">
                        <span>
                            {t('invoicing.recurring.period', {
                                date: formatDate(run.period_on),
                            })}
                            {' · '}
                            {run.status === 'skipped' ? (
                                <span className="text-muted-foreground">
                                    {t('invoicing.recurring.skipped')}
                                </span>
                            ) : run.document ? (
                                <Link
                                    href={run.document.url}
                                    className={cn(
                                        'rounded-md text-primary-text hover:underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    {run.document.number ??
                                        t('invoicing.recurring.draft')}
                                </Link>
                            ) : (
                                <span className="text-muted-foreground">
                                    {t('invoicing.recurring.deleted_document')}
                                </span>
                            )}
                        </span>
                        {run.document ? (
                            <span className="tabular">
                                {formatCurrency(run.document.total)}
                            </span>
                        ) : null}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {t(
                            run.trigger === 'manual'
                                ? 'invoicing.recurring.trigger_manual'
                                : 'invoicing.recurring.trigger_schedule',
                            {
                                person: run.created_by ?? '—',
                                date: run.created_at
                                    ? formatDateTime(run.created_at)
                                    : '—',
                            },
                        )}
                    </span>
                    {run.error ? (
                        <span className="text-xs text-danger">{run.error}</span>
                    ) : null}
                </li>
            ))}
        </ol>
    );
}

function Details({ recurring }: { recurring: RecurringDetail }) {
    const rows: [string, ReactNode][] = [
        [t('invoicing.recurring.field.client'), recurring.client.name],
        [
            t('invoicing.recurring.field.project'),
            recurring.project
                ? `${recurring.project.code} · ${recurring.project.name}${recurring.bank ? ` · ${recurring.bank.name}` : ''}`
                : '—',
        ],
        [
            t('invoicing.recurring.field.periodicity'),
            periodicityText(recurring),
        ],
        [
            t('invoicing.recurring.field.start'),
            formatDate(recurring.start_date),
        ],
        [
            t('invoicing.recurring.field.end'),
            recurring.end_date
                ? formatDate(recurring.end_date)
                : recurring.max_occurrences
                  ? t('invoicing.recurring.after_times', {
                        done: recurring.occurrences_count,
                        count: recurring.max_occurrences,
                    })
                  : t('invoicing.recurring.never'),
        ],
        [
            t('invoicing.recurring.field.mode'),
            recurring.mode === 'issue'
                ? t('invoicing.recurring.issue_as', {
                      person: recurring.issue_as ?? '—',
                      send: recurring.auto_send
                          ? t('invoicing.recurring.and_send')
                          : '',
                  })
                : t('invoicing.recurring.mode.draft'),
        ],
        [
            t('invoicing.recurring.field.series'),
            recurring.series
                ? `${recurring.series.code} · ${recurring.series.name}`
                : t('invoicing.recurring.default_series'),
        ],
        [
            t('invoicing.recurring.field.payment'),
            recurring.payment_method ?? '—',
        ],
        [
            t('invoicing.recurring.field.due'),
            recurring.due_days === null
                ? t('invoicing.recurring.due_default')
                : t('invoicing.recurring.due_days', {
                      count: recurring.due_days,
                  }),
        ],
        [
            t('invoicing.recurring.field.reference'),
            recurring.customer_reference ?? '—',
        ],
    ];

    return (
        <div className="grid gap-3">
            <dl className="grid gap-2 text-sm" data-test="recurring-details">
                {rows.map(([label, value]) => (
                    <div
                        key={label}
                        className="grid grid-cols-[8rem_minmax(0,1fr)] gap-3"
                    >
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd>{value}</dd>
                    </div>
                ))}
            </dl>
            {recurring.internal_note ? (
                <p className="rounded-md bg-muted px-3 py-2 text-sm whitespace-pre-line">
                    {recurring.internal_note}
                </p>
            ) : null}
            <p className="text-xs text-muted-foreground">
                {t('invoicing.recurring.words_hint', {
                    words: WORDS.join(', '),
                })}
            </p>
        </div>
    );
}

RecurringShow.layout = {
    breadcrumbs: [
        { title: t('billing.section'), href: '/facturacion' },
        { title: t('billing.invoices.title'), href: '/facturacion/facturas' },
        {
            title: t('invoicing.recurring.tab'),
            href: '/facturacion/recurrentes',
        },
    ],
};
