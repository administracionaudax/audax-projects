import { Deferred, Head, Link, router } from '@inertiajs/react';
import {
    CalendarClock,
    MoreHorizontal,
    Plus,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { AllocationDialog } from '@/components/forecast/allocation-dialog';
import { AllocationsTable } from '@/components/forecast/allocations-table';
import { AssignGapDialog } from '@/components/forecast/assign-gap-dialog';
import { EstimateVsActualSection } from '@/components/forecast/estimate-vs-actual';
import {
    CreateProjectDialog,
    LinkDialog,
    LoseDialog,
} from '@/components/forecast/forecast-actions';
import { ForecastProjectDialog } from '@/components/forecast/forecast-project-dialog';
import { ImpactGrid, ImpactSentence } from '@/components/forecast/impact';
import { HatchDefs, LayerSwatch } from '@/components/forecast/layer-swatch';
import { KeywordText } from '@/components/keyword-text';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { formatCurrency, formatDate, formatDateTime } from '@/lib/format';
import { dateRange, formatHours } from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { index as boardIndex } from '@/routes/forecast';
import {
    confirm,
    destroy,
    index,
    reopen,
    show,
    unlink,
    update,
} from '@/routes/forecast/projects';
import type {
    Allocation,
    ForecastHistoryEntry,
    ForecastProject,
    ForecastProjectPageProps,
} from '@/types/forecast';

function todayString(): string {
    return new Date().toISOString().slice(0, 10);
}

/** Datos del formulario de un previsto tal como están, para cambiar solo la seguridad. */
function forecastPayload(
    forecast: ForecastProject,
    confidence: ForecastProject['confidence'],
) {
    return {
        name: forecast.name,
        client_id: forecast.client?.id ?? null,
        prospect_name: forecast.client ? null : forecast.prospect_name,
        confidence,
        start_date: forecast.start_date,
        end_date: forecast.end_date,
        estimated_minutes: forecast.estimated_minutes,
        ...(forecast.estimated_amount !== null
            ? { estimated_amount: forecast.estimated_amount }
            : {}),
        description: forecast.description,
    };
}

function historyLine(entry: ForecastHistoryEntry): string {
    const who = entry.causer ?? t('forecast.history.someone');

    if (entry.subject === 'forecast') {
        if (entry.event === 'created') {
            return t('forecast.history.created', { who });
        }

        if (entry.event === 'deleted') {
            return t('forecast.history.deleted', { who });
        }

        return entry.fields.length > 0
            ? t('forecast.history.updated_fields', {
                  who,
                  fields: entry.fields.join(', ').toLowerCase(),
              })
            : t('forecast.history.updated', { who });
    }

    const target = entry.who ?? '';

    if (entry.event === 'created') {
        return t('forecast.history.allocation_created', { who, target });
    }

    if (entry.event === 'deleted') {
        return t('forecast.history.allocation_deleted', { who, target });
    }

    return t('forecast.history.allocation_updated', { who, target });
}

/**
 * Ficha de un proyecto previsto (`/prevision/proyectos/{id}`, D-295 y D-297): la cabecera con su
 * seguridad y sus acciones, sus datos, el impacto «sin / con» (una frase con el peor caso y la
 * rejilla por departamento y persona), sus asignaciones con el minicronograma, la seguridad y el
 * historial. Vinculado, «Estimado frente a real» en lugar del impacto.
 */
export default function ForecastProjectShow({
    forecast,
    allocations,
    months,
    totals,
    impact,
    estimate,
    history,
    options,
}: ForecastProjectPageProps) {
    const [editing, setEditing] = useState(false);
    const [allocationOpen, setAllocationOpen] = useState(false);
    const [editingAllocation, setEditingAllocation] =
        useState<Allocation | null>(null);
    const [assigning, setAssigning] = useState<Allocation | null>(null);
    const [losing, setLosing] = useState(false);
    const [linking, setLinking] = useState(false);
    const [creatingProject, setCreatingProject] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const can = forecast.can;
    const layer = forecast.confidence === 'firm' ? 'firm' : 'tentative';
    const counts =
        forecast.status === 'open' || forecast.status === 'confirmed';
    const canEdit = can.update && counts;
    const over =
        totals.difference_minutes !== null && totals.difference_minutes > 0;

    const setConfidence = (confidence: ForecastProject['confidence']) =>
        router.put(
            update.url(forecast.id),
            forecastPayload(forecast, confidence),
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={forecast.name} />
            <HatchDefs />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <p className="flex flex-wrap gap-2 text-xs">
                            <span className="inline-flex items-center gap-1.5 border px-2 py-0.5">
                                <LayerSwatch layer={layer} />
                                {t(
                                    `forecast.confidence.${forecast.confidence}`,
                                )}
                            </span>
                            {forecast.client === null &&
                            forecast.prospect_name ? (
                                <span className="border px-2 py-0.5">
                                    {t('forecast.badge.new_client')}
                                </span>
                            ) : null}
                            <span className="border px-2 py-0.5">
                                {t('forecast.show.status', {
                                    status: t(
                                        `forecast.status.${forecast.status}`,
                                    ).toLowerCase(),
                                })}
                            </span>
                        </p>
                        <h1 className="text-2xl font-normal tracking-tight break-words">
                            <KeywordText
                                text={
                                    forecast.client_name
                                        ? `${forecast.client_name} · [[${forecast.name}]]`
                                        : `[[${forecast.name}]]`
                                }
                            />
                        </h1>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {canEdit && forecast.confidence === 'tentative' ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setConfidence('firm')}
                            >
                                {t('forecast.actions.make_firm')}
                            </Button>
                        ) : null}
                        {can.create_project ? (
                            <Button
                                type="button"
                                onClick={() => setCreatingProject(true)}
                                data-test="create-project"
                            >
                                {t('forecast.actions.create_project')}
                            </Button>
                        ) : null}
                        {forecast.project ? (
                            <Button asChild variant="outline">
                                <Link
                                    href={`/proyectos/${forecast.project.id}/planificacion`}
                                >
                                    {t('forecast.show.open_project', {
                                        code: forecast.project.code,
                                    })}
                                </Link>
                            </Button>
                        ) : null}
                        {can.update ||
                        can.confirm ||
                        can.lose ||
                        can.reopen ||
                        can.link ||
                        can.unlink ||
                        can.delete ? (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('forecast.actions.more')}
                                    >
                                        <MoreHorizontal aria-hidden="true" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {canEdit ? (
                                        <DropdownMenuItem
                                            onSelect={() => setEditing(true)}
                                        >
                                            {t('forecast.actions.edit')}
                                        </DropdownMenuItem>
                                    ) : null}
                                    {can.confirm ? (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                router.post(
                                                    confirm.url(forecast.id),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('forecast.actions.confirm')}
                                        </DropdownMenuItem>
                                    ) : null}
                                    {can.link ? (
                                        <DropdownMenuItem
                                            onSelect={() => setLinking(true)}
                                        >
                                            {t('forecast.actions.link_title')}
                                        </DropdownMenuItem>
                                    ) : null}
                                    {can.lose ? (
                                        <DropdownMenuItem
                                            onSelect={() => setLosing(true)}
                                        >
                                            {t('forecast.actions.lose')}
                                        </DropdownMenuItem>
                                    ) : null}
                                    {can.reopen ? (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                router.post(
                                                    reopen.url(forecast.id),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('forecast.actions.reopen')}
                                        </DropdownMenuItem>
                                    ) : null}
                                    {can.unlink ? (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                router.delete(
                                                    unlink.url(forecast.id),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('forecast.actions.unlink')}
                                        </DropdownMenuItem>
                                    ) : null}
                                    {can.delete ? (
                                        <>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                variant="destructive"
                                                onSelect={() =>
                                                    setDeleting(true)
                                                }
                                            >
                                                {t('forecast.actions.delete')}
                                            </DropdownMenuItem>
                                        </>
                                    ) : null}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        ) : null}
                    </div>
                </header>

                {forecast.starts_in_past ? (
                    <p
                        className="flex items-start gap-2 border-l-2 border-warning bg-warning-soft px-3 py-2 text-sm"
                        role="status"
                    >
                        <CalendarClock
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-warning"
                        />
                        {t('forecast.show.starts_in_past')}
                    </p>
                ) : null}

                <dl
                    className="grid grid-cols-2 gap-x-6 gap-y-3 border bg-card p-4 text-sm sm:flex sm:flex-wrap"
                    data-test="forecast-facts"
                >
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            {t('forecast.form.client')}
                        </dt>
                        <dd>
                            {forecast.client_name ?? '—'}
                            {forecast.client === null &&
                            forecast.prospect_name ? (
                                <span className="text-muted-foreground">
                                    {' '}
                                    {t('forecast.show.not_yet_client')}
                                </span>
                            ) : null}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            {t('forecast.show.owner')}
                        </dt>
                        <dd>{forecast.owner.name}</dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            {t('forecast.show.dates')}
                        </dt>
                        <dd className="tabular">
                            {forecast.start_date
                                ? dateRange(
                                      forecast.start_date,
                                      forecast.end_date,
                                  )
                                : t('forecast.dates.none')}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            {t('forecast.show.estimate_total')}
                        </dt>
                        <dd className="tabular">
                            {forecast.estimated_minutes === null
                                ? '—'
                                : formatHours(forecast.estimated_minutes)}
                        </dd>
                    </div>
                    {forecast.estimated_amount !== null ? (
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                {t('forecast.form.amount')}
                            </dt>
                            <dd className="tabular">
                                {formatCurrency(forecast.estimated_amount)}
                            </dd>
                        </div>
                    ) : null}
                    {forecast.status === 'lost' ? (
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                {t('forecast.status.lost')}
                            </dt>
                            <dd>
                                {forecast.lost_at
                                    ? formatDate(forecast.lost_at)
                                    : ''}
                                {forecast.lost_reason
                                    ? ` · ${forecast.lost_reason}`
                                    : ''}
                            </dd>
                        </div>
                    ) : null}
                </dl>

                {forecast.status === 'linked' ? (
                    <section
                        aria-labelledby="estimate-title"
                        className="grid gap-3"
                    >
                        <div>
                            <h2 id="estimate-title" className="text-lg">
                                <KeywordText
                                    text={t('forecast.estimate.title')}
                                />
                            </h2>
                            {forecast.linked_at ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('forecast.estimate.description', {
                                        date: formatDate(forecast.linked_at),
                                    })}
                                </p>
                            ) : null}
                        </div>
                        <Deferred
                            data="estimate"
                            fallback={<Skeleton className="h-64" />}
                        >
                            {estimate ? (
                                <EstimateVsActualSection
                                    estimate={estimate}
                                    today={todayString()}
                                />
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {t('forecast.estimate.not_linked')}
                                </p>
                            )}
                        </Deferred>
                    </section>
                ) : counts ? (
                    <section
                        aria-labelledby="impact-title"
                        className="grid gap-4 border bg-card p-4"
                        data-test="forecast-impact"
                    >
                        <div>
                            <h2
                                id="impact-title"
                                className="text-base font-medium"
                            >
                                {t('forecast.show.impact')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('forecast.show.impact_help')}
                            </p>
                        </div>
                        <Deferred
                            data="impact"
                            fallback={<Skeleton className="h-48" />}
                        >
                            {impact && impact.buckets.length > 0 ? (
                                <>
                                    <ImpactSentence impact={impact} />
                                    <ul
                                        className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                                        aria-label={t('forecast.legend.label')}
                                    >
                                        <li className="inline-flex items-center gap-1.5">
                                            <span
                                                aria-hidden="true"
                                                className="size-2.5 bg-[var(--context-mark)]"
                                            />
                                            {t('forecast.show.without_this')}
                                        </li>
                                        <li className="inline-flex items-center gap-1.5">
                                            <LayerSwatch layer={impact.layer} />
                                            {t('forecast.show.adds_this')}
                                        </li>
                                        <li className="inline-flex items-center gap-1.5">
                                            <span
                                                aria-hidden="true"
                                                className="h-0.5 w-4 bg-foreground"
                                            />
                                            {t('forecast.legend.capacity')}
                                        </li>
                                    </ul>
                                    <ImpactGrid impact={impact} />
                                </>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {t('forecast.show.no_impact')}
                                </p>
                            )}
                        </Deferred>
                    </section>
                ) : (
                    <p className="border bg-card p-4 text-sm text-muted-foreground">
                        {t('forecast.show.not_counting')}
                    </p>
                )}

                <section
                    aria-labelledby="allocations-title"
                    className="grid gap-3 border bg-card p-4"
                >
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h2
                                id="allocations-title"
                                className="text-base font-medium"
                            >
                                {t('forecast.show.allocations')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('forecast.show.allocations_help')}
                            </p>
                        </div>
                        {canEdit ? (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setAllocationOpen(true)}
                                data-test="add-allocation"
                            >
                                <Plus aria-hidden="true" />
                                {t('forecast.allocation.add')}
                            </Button>
                        ) : null}
                    </div>
                    {allocations.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('forecast.show.no_allocations')}
                        </p>
                    ) : (
                        <AllocationsTable
                            allocations={allocations}
                            months={months}
                            layer={layer}
                            onEdit={canEdit ? setEditingAllocation : undefined}
                            onAssign={setAssigning}
                            footer={
                                <>
                                    <th
                                        scope="row"
                                        colSpan={3}
                                        className="px-3 py-2.5 text-left font-medium"
                                    >
                                        {totals.estimated_minutes === null
                                            ? t('forecast.show.allocated_total')
                                            : t(
                                                  'forecast.show.allocated_vs_estimate',
                                                  {
                                                      hours: formatHours(
                                                          totals.estimated_minutes,
                                                      ),
                                                  },
                                              )}
                                    </th>
                                    <td className="tabular px-3 py-2.5 text-right font-medium">
                                        {formatHours(totals.allocated_minutes)}
                                    </td>
                                    <td colSpan={2} className="px-3 py-2.5">
                                        {totals.difference_minutes !== null &&
                                        totals.difference_minutes !== 0 ? (
                                            <span className="inline-flex items-center gap-1.5">
                                                {over ? (
                                                    <TriangleAlert
                                                        aria-hidden="true"
                                                        className="size-4 text-warning"
                                                    />
                                                ) : null}
                                                {t(
                                                    over
                                                        ? 'forecast.estimate.more'
                                                        : 'forecast.estimate.less',
                                                    {
                                                        hours: formatHours(
                                                            Math.abs(
                                                                totals.difference_minutes,
                                                            ),
                                                        ),
                                                    },
                                                )}
                                            </span>
                                        ) : null}
                                    </td>
                                </>
                            }
                        />
                    )}
                </section>

                <div className="grid gap-4 lg:grid-cols-2">
                    <section
                        aria-labelledby="confidence-title"
                        className="grid content-start gap-3 border bg-card p-4"
                    >
                        <h2
                            id="confidence-title"
                            className="text-base font-medium"
                        >
                            {t('forecast.form.confidence')}
                        </h2>
                        <div
                            role="radiogroup"
                            aria-labelledby="confidence-title"
                            className="inline-flex w-fit border"
                        >
                            {(['firm', 'tentative'] as const).map(
                                (confidence) => {
                                    const checked =
                                        forecast.confidence === confidence;
                                    const disabled =
                                        !canEdit ||
                                        (confidence === 'tentative' &&
                                            forecast.status === 'confirmed');

                                    return (
                                        <button
                                            key={confidence}
                                            type="button"
                                            role="radio"
                                            aria-checked={checked}
                                            disabled={disabled}
                                            onClick={() =>
                                                checked
                                                    ? null
                                                    : setConfidence(confidence)
                                            }
                                            className="inline-flex items-center gap-2 px-4 py-2 text-sm disabled:opacity-60 aria-checked:bg-accent"
                                        >
                                            <LayerSwatch layer={confidence} />
                                            {t(
                                                `forecast.confidence.${confidence}`,
                                            )}
                                        </button>
                                    );
                                },
                            )}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {t('forecast.form.confidence_help')}
                        </p>
                    </section>
                    <section
                        aria-labelledby="history-title"
                        className="grid content-start gap-3 border bg-card p-4"
                    >
                        <h2
                            id="history-title"
                            className="text-base font-medium"
                        >
                            {t('forecast.show.history')}
                        </h2>
                        <Deferred
                            data="history"
                            fallback={<Skeleton className="h-24" />}
                        >
                            {history && history.length > 0 ? (
                                <ol
                                    className="grid gap-2 text-sm"
                                    data-test="forecast-history"
                                >
                                    {history.map((entry) => (
                                        <li
                                            key={entry.id}
                                            className="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-3"
                                        >
                                            <span className="tabular text-muted-foreground">
                                                {entry.at
                                                    ? formatDateTime(
                                                          entry.at,
                                                      ).slice(0, 5)
                                                    : ''}
                                            </span>
                                            <span>{historyLine(entry)}</span>
                                        </li>
                                    ))}
                                </ol>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {t('forecast.history.empty')}
                                </p>
                            )}
                        </Deferred>
                    </section>
                </div>
            </div>

            {canEdit ? (
                <ForecastProjectDialog
                    forecast={forecast}
                    clients={options?.clients}
                    open={editing}
                    onOpenChange={setEditing}
                />
            ) : null}
            {canEdit && options ? (
                <AllocationDialog
                    key={editingAllocation?.id ?? 'new'}
                    container={{ kind: 'forecast', id: forecast.id }}
                    allocation={editingAllocation ?? undefined}
                    people={options.people}
                    departments={options.departments}
                    defaults={{
                        start: forecast.start_date,
                        end: forecast.end_date,
                    }}
                    open={allocationOpen || editingAllocation !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setAllocationOpen(false);
                            setEditingAllocation(null);
                        }
                    }}
                />
            ) : null}
            {assigning ? (
                <AssignGapDialog
                    allocation={assigning}
                    departmentName={assigning.department?.name ?? ''}
                    open
                    onOpenChange={(open) => (open ? null : setAssigning(null))}
                />
            ) : null}
            <LoseDialog
                forecast={forecast}
                open={losing}
                onOpenChange={setLosing}
            />
            <LinkDialog
                forecast={forecast}
                candidates={options?.link_candidates}
                open={linking}
                onOpenChange={setLinking}
            />
            {can.create_project ? (
                <CreateProjectDialog
                    forecast={forecast}
                    clients={options?.clients}
                    open={creatingProject}
                    onOpenChange={setCreatingProject}
                />
            ) : null}
            <ConfirmDialog
                trigger={<span hidden />}
                open={deleting}
                onOpenChange={setDeleting}
                title={t('forecast.actions.delete_title')}
                description={t('forecast.actions.delete_description')}
                confirmLabel={t('forecast.actions.delete')}
                onConfirm={() => router.delete(destroy.url(forecast.id))}
            />
        </>
    );
}

ForecastProjectShow.layout = (props: ForecastProjectPageProps) => ({
    breadcrumbs: [
        { title: t('forecast.title'), href: boardIndex() },
        { title: t('forecast.nav.projects'), href: index() },
        { title: props.forecast.name, href: show(props.forecast.id) },
    ],
});
