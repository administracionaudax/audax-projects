import { Link, router } from '@inertiajs/react';
import {
    CalendarCog,
    ChevronRight,
    FileText,
    Headphones,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { TeamStatusStrip } from '@/components/weeklies/team-status-strip';
import { DeadlineDialog } from '@/components/weeklies/weekly-dialogs';
import {
    CycleProgressBadge,
    Participation,
    WeekLabel,
} from '@/components/weeklies/weekly-ui';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { destroy as destroyCycle, show as showCycle } from '@/routes/weeklies';
import type {
    WeekliesIndexPageProps,
    WeeklyHighlightedCycle,
    WeeklyHistoryRow,
} from '@/types/weeklies';

/** ¿El informe tiene menos envíos de los que hay ahora? («Hay nuevos reportes», F-072.) */
function isStale(row: WeeklyHistoryRow): boolean {
    return (
        row.has_report &&
        row.submission_count_at_generation !== null &&
        row.participation.submitted > row.submission_count_at_generation
    );
}

function ReportState({ row }: { row: WeeklyHistoryRow }) {
    const audio = row.audio_state === 'done';

    return (
        <ul className="grid gap-1 text-xs">
            <li className="flex items-center gap-1.5">
                <FileText
                    aria-hidden="true"
                    className="size-3.5 text-muted-foreground"
                />
                {t('weeklies.history.text')}:{' '}
                <span
                    className={cn(!row.has_report && 'text-muted-foreground')}
                >
                    {row.has_report
                        ? isStale(row)
                            ? t('weeklies.cycle.report_stale')
                            : t('weeklies.history.generated')
                        : t('weeklies.history.pending')}
                </span>
            </li>
            <li className="flex items-center gap-1.5">
                <Headphones
                    aria-hidden="true"
                    className="size-3.5 text-muted-foreground"
                />
                {t('weeklies.history.audio')}:{' '}
                <span className={cn(!audio && 'text-muted-foreground')}>
                    {audio
                        ? t('weeklies.history.generated')
                        : t('weeklies.history.pending')}
                </span>
            </li>
        </ul>
    );
}

function DeleteCycle({
    row,
    compact = false,
}: {
    row: WeeklyHistoryRow;
    compact?: boolean;
}) {
    const [processing, setProcessing] = useState(false);

    return (
        <ConfirmDialog
            trigger={
                <Button
                    type="button"
                    variant="ghost"
                    size={compact ? 'icon' : 'sm'}
                    aria-label={t('weeklies.history.delete_for', {
                        label: row.label,
                    })}
                    title={t('weeklies.history.delete')}
                    data-test="weekly-delete"
                >
                    <Trash2 aria-hidden="true" />
                    {compact ? null : t('weeklies.history.delete')}
                </Button>
            }
            title={t('weeklies.history.delete_title', { label: row.label })}
            description={t('weeklies.history.delete_description')}
            confirmLabel={t('weeklies.history.delete')}
            processing={processing}
            onConfirm={() =>
                router.delete(destroyCycle.url(row.id), {
                    onStart: () => setProcessing(true),
                    onFinish: () => setProcessing(false),
                })
            }
        />
    );
}

/** Semana activa o última cerrada, destacada con su equipo (F-065 y F-067). */
function HighlightedCard({
    cycle,
    title,
    hint,
    can,
}: {
    cycle: WeeklyHighlightedCycle;
    title: string;
    hint?: string;
    can: WeekliesIndexPageProps['can'];
}) {
    const active = cycle.status === 'active';

    return (
        <section
            aria-label={title}
            className="grid gap-4 border bg-card p-4 md:p-5"
            data-test={active ? 'weekly-active-card' : 'weekly-latest-card'}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="grid min-w-0 gap-1">
                    <p className="text-xs tracking-wide text-muted-foreground uppercase">
                        {title}
                    </p>
                    <h3 className="text-lg font-normal">
                        <Link
                            href={showCycle.url(cycle.id)}
                            className={cn('hover:underline', FOCUS_RING)}
                        >
                            <WeekLabel cycle={cycle} />
                        </Link>
                    </h3>
                    <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        {t('weeklies.cycle.deadline', {
                            date: formatDate(cycle.deadline_date),
                        })}
                        {cycle.deadline_date !== cycle.end_date ? (
                            <span className="text-xs">
                                {t('weeklies.history.base_friday', {
                                    date: formatDate(cycle.end_date),
                                })}
                            </span>
                        ) : null}
                        <CycleProgressBadge progress={cycle.progress} />
                    </p>
                    {hint ? (
                        <p className="text-xs text-muted-foreground">{hint}</p>
                    ) : null}
                </div>
                <div className="flex flex-wrap items-center gap-1">
                    {active && can.extendDeadline ? (
                        <DeadlineDialog
                            cycle={cycle}
                            trigger={
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    data-test="weekly-deadline-open"
                                >
                                    <CalendarCog aria-hidden="true" />
                                    {t('weeklies.deadline.open')}
                                </Button>
                            }
                        />
                    ) : null}
                    {can.delete ? <DeleteCycle row={cycle} /> : null}
                </div>
            </div>
            <div className="grid gap-4 md:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
                <div className="grid content-start gap-3">
                    <Participation
                        submitted={cycle.participation.submitted}
                        expected={cycle.participation.expected}
                        exempt={cycle.participation.exempt}
                    />
                    <TeamStatusStrip team={cycle.team} showCounter={false} />
                </div>
                <div className="grid content-start gap-2 border-t pt-3 md:border-t-0 md:border-l md:pt-0 md:pl-4">
                    <p className="text-xs tracking-wide text-muted-foreground uppercase">
                        {t('weeklies.history.report_state')}
                    </p>
                    <ReportState row={cycle} />
                </div>
            </div>
        </section>
    );
}

/**
 * Pestaña «Histórico» de /weeklies (F-064 a F-069; WeeklysList de WeeklySync): la semana activa y
 * la última cerrada destacadas (esta sigue así hasta que se cierra la siguiente), y la tabla del
 * resto con fecha límite, participación, estado e informe. Quien gestiona cambia el plazo de la
 * activa y borra semanas. En el móvil, la tabla pasa a tarjetas.
 */
export function WeeklyHistory({
    active,
    latest_closed: latestClosed,
    cycles,
    can,
}: WeekliesIndexPageProps) {
    const highlighted = new Set(
        [active?.id, latestClosed?.id].filter(
            (id): id is number => id !== undefined,
        ),
    );
    const rest = cycles.filter((row) => !highlighted.has(row.id));

    return (
        <div className="grid min-w-0 gap-8">
            {active ? (
                <HighlightedCard
                    cycle={active}
                    title={t('weeklies.history.current')}
                    can={can}
                />
            ) : null}

            {latestClosed ? (
                <HighlightedCard
                    cycle={latestClosed}
                    title={t('weeklies.history.latest_closed')}
                    hint={t('weeklies.history.latest_closed_hint')}
                    can={can}
                />
            ) : null}

            <section
                aria-labelledby="weekly-history-title"
                className="grid min-w-0 gap-3"
            >
                <div>
                    <h2
                        id="weekly-history-title"
                        className="text-lg font-normal"
                    >
                        {t('weeklies.history.title')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {t('weeklies.history.description')}
                    </p>
                </div>

                {rest.length === 0 ? (
                    <p className="border border-dashed bg-muted/60 p-4 text-sm text-muted-foreground">
                        {t('weeklies.history.empty')}
                    </p>
                ) : (
                    <>
                        <ul
                            className="divide-y border bg-card md:hidden"
                            data-test="weekly-history-cards"
                        >
                            {rest.map((row) => (
                                <li
                                    key={row.id}
                                    className="flex items-center gap-2 p-3"
                                >
                                    <Link
                                        href={showCycle.url(row.id)}
                                        className={cn(
                                            'grid min-w-0 flex-1 gap-1.5',
                                            FOCUS_RING,
                                        )}
                                    >
                                        <span className="flex flex-wrap items-center gap-2">
                                            <WeekLabel
                                                cycle={row}
                                                className="font-medium"
                                            />
                                            <CycleProgressBadge
                                                progress={row.progress}
                                            />
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {t('weeklies.cycle.deadline', {
                                                date: formatDate(
                                                    row.deadline_date,
                                                ),
                                            })}
                                        </span>
                                        <Participation
                                            submitted={
                                                row.participation.submitted
                                            }
                                            expected={
                                                row.participation.expected
                                            }
                                            exempt={row.participation.exempt}
                                        />
                                    </Link>
                                    {can.delete ? (
                                        <DeleteCycle row={row} compact />
                                    ) : null}
                                </li>
                            ))}
                        </ul>

                        <div className="hidden border bg-card md:block">
                            <Table data-test="weekly-history-table">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('weeklies.history.col_weekly')}
                                        </TableHead>
                                        <TableHead>
                                            {t('weeklies.history.col_deadline')}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'weeklies.history.col_participation',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t('weeklies.history.col_status')}
                                        </TableHead>
                                        <TableHead>
                                            {t('weeklies.history.col_report')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            <span className="sr-only">
                                                {t(
                                                    'weeklies.history.col_actions',
                                                )}
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rest.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell>
                                                <Link
                                                    href={showCycle.url(row.id)}
                                                    className={cn(
                                                        'hover:underline',
                                                        FOCUS_RING,
                                                    )}
                                                >
                                                    {row.label}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="tabular">
                                                {formatDate(row.deadline_date)}
                                            </TableCell>
                                            <TableCell className="min-w-40">
                                                <Participation
                                                    submitted={
                                                        row.participation
                                                            .submitted
                                                    }
                                                    expected={
                                                        row.participation
                                                            .expected
                                                    }
                                                    exempt={
                                                        row.participation.exempt
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <CycleProgressBadge
                                                    progress={row.progress}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <ReportState row={row} />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <span className="inline-flex items-center gap-1">
                                                    {can.delete ? (
                                                        <DeleteCycle
                                                            row={row}
                                                            compact
                                                        />
                                                    ) : null}
                                                    <Link
                                                        href={showCycle.url(
                                                            row.id,
                                                        )}
                                                        aria-label={t(
                                                            'weeklies.history.open_for',
                                                            {
                                                                label: row.label,
                                                            },
                                                        )}
                                                        className={cn(
                                                            'inline-flex p-1 text-muted-foreground hover:text-foreground',
                                                            FOCUS_RING,
                                                        )}
                                                    >
                                                        <ChevronRight
                                                            aria-hidden="true"
                                                            className="size-4"
                                                        />
                                                    </Link>
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}
            </section>
        </div>
    );
}
