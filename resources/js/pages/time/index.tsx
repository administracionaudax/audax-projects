import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CalendarCheck,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    ClipboardCopy,
    Clock,
    Info,
    Lock,
    Plus,
    RotateCcw,
    Send,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { TimesheetStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { CapacityCell } from '@/components/time/capacity-cell';
import { CellEntriesDialog } from '@/components/time/cell-entries-dialog';
import { ReturnWeekDialog } from '@/components/time/return-week-dialog';
import { TaskPicker } from '@/components/time/task-picker';
import { TimeEntryDialog } from '@/components/time/time-entry-dialog';
import { TimesheetGrid } from '@/components/time/timesheet-grid';
import type { GridRow } from '@/components/time/timesheet-grid';
import { useExtraRows } from '@/components/time/use-extra-rows';
import { weekRangeLabel } from '@/components/time/week-days';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAbilities, useRequiredUser } from '@/hooks/use-auth';
import { formatDate, formatDateTime, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { index as timeIndex } from '@/routes/time';
import { index as approvalsIndex } from '@/routes/time/approvals';
import { approve } from '@/routes/time/approvals';
import { index as locksIndex } from '@/routes/time/locks';
import { reopen, submit, withdraw } from '@/routes/time/week';
import type { TimeEntry, TimesheetPageProps } from '@/types';

type DialogState =
    | { kind: 'none' }
    | { kind: 'cell'; row: GridRow; dayIndex: number }
    | { kind: 'entry'; entry: TimeEntry }
    | {
          kind: 'new';
          task: { id: number; title: string; project_id: number } | null;
          date: string;
      };

function sheetUrl(week: string, personId: number | null) {
    return timeIndex.url({
        query:
            personId !== null
                ? { semana: week, persona: personId }
                : { semana: week },
    });
}

/**
 * Hoja semanal (SPEC §7, D-020, D-036): /horas?semana=2026-W39[&persona=12].
 */
export default function TimesheetPage({
    week,
    person,
    is_own: isOwn,
    people,
    scope,
    period,
    rows,
    totals,
    capacity,
    previous_week_tasks: previousWeekTasks,
    can,
    settings,
}: TimesheetPageProps) {
    const user = useRequiredUser();
    const timer = usePage().props.timer ?? null;
    const abilities = useAbilities();
    const personParam = isOwn ? null : person.id;
    const extras = useExtraRows(person.id, week.iso);
    const [dialog, setDialog] = useState<DialogState>({ kind: 'none' });
    const [processing, setProcessing] = useState(false);
    const [confirm, setConfirm] = useState<
        'submit' | 'withdraw' | 'reopen' | null
    >(null);

    const serverTaskIds = new Set(rows.map((row) => row.task.id));
    const gridRows: GridRow[] = [
        ...rows,
        ...extras.rows
            .filter((task) => !serverTaskIds.has(task.id))
            .map((task) => ({
                task,
                cells: week.days.map(() => []),
                total: 0,
                extra: true,
            })),
    ];

    const defaultDate =
        settings.today >= week.start && settings.today <= week.end
            ? settings.today
            : week.start;

    const visit = {
        preserveScroll: true,
        errorBag: 'week',
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setConfirm(null);
        },
        onError: (errors: Record<string, string>) =>
            Object.values(errors).forEach((message) => toast.error(message)),
    };

    const copyPrevious = () => {
        const added = extras.add(previousWeekTasks);

        if (added === 0) {
            toast.info(t('hours.sheet.copy_none'));
        } else {
            toast.success(t('hours.sheet.copy_done', { count: added }));
        }
    };

    const weekLabel = weekRangeLabel(week.start, week.end);
    const submitDescription = [
        t('hours.sheet.submit_description', {
            minutes: formatMinutes(totals.week),
            week: weekLabel,
        }),
        timer && week.days.includes(settings.today)
            ? t('hours.sheet.submit_timer_warning')
            : '',
    ]
        .filter(Boolean)
        .join(' ');
    const openCell =
        dialog.kind === 'cell'
            ? {
                  row: dialog.row,
                  day: week.days[dialog.dayIndex],
                  entries:
                      gridRows.find((row) => row.task.id === dialog.row.task.id)
                          ?.cells[dialog.dayIndex] ?? [],
              }
            : null;

    return (
        <>
            <Head title={t('hours.sheet.page_title')} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-x-4 [&>header]:mb-0">
                    <Heading
                        as="h1"
                        title={
                            isOwn
                                ? t('hours.sheet.title_own')
                                : t('hours.sheet.title_other', {
                                      name: person.name,
                                  })
                        }
                        description={t('hours.sheet.description')}
                    />
                    <div className="flex flex-wrap gap-2 empty:hidden">
                        {abilities.approveTime ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={approvalsIndex()}>
                                    <CalendarCheck aria-hidden="true" />
                                    {t('hours.nav.approvals')}
                                </Link>
                            </Button>
                        ) : null}
                        {abilities.lockTime ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={locksIndex()}>
                                    <Lock aria-hidden="true" />
                                    {t('hours.nav.locks')}
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                </div>

                <section
                    aria-label={t('hours.sheet.toolbar')}
                    className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
                >
                    <nav
                        aria-label={t('hours.sheet.week_nav')}
                        className="flex flex-wrap items-center gap-2 sm:flex-nowrap"
                    >
                        <Button asChild variant="outline" size="icon">
                            <Link
                                href={sheetUrl(week.previous, personParam)}
                                aria-label={t('hours.sheet.previous_week')}
                                title={t('hours.sheet.previous_week')}
                                preserveScroll
                            >
                                <ChevronLeft aria-hidden="true" />
                            </Link>
                        </Button>
                        <p
                            className="order-first w-full min-w-0 text-base sm:order-none sm:w-auto"
                            aria-live="polite"
                        >
                            <span className="font-medium">{weekLabel}</span>{' '}
                            <span className="text-sm text-muted-foreground">
                                {t('hours.sheet.week_number', {
                                    week: week.iso.slice(-2),
                                })}
                            </span>
                        </p>
                        <Button asChild variant="outline" size="icon">
                            <Link
                                href={sheetUrl(week.next, personParam)}
                                aria-label={t('hours.sheet.next_week')}
                                title={t('hours.sheet.next_week')}
                                preserveScroll
                            >
                                <ChevronRight aria-hidden="true" />
                            </Link>
                        </Button>
                        {week.iso !== week.current ? (
                            <Button asChild variant="ghost" size="sm">
                                <Link
                                    href={sheetUrl(week.current, personParam)}
                                >
                                    {t('hours.sheet.this_week')}
                                </Link>
                            </Button>
                        ) : null}
                    </nav>

                    {people.length > 0 ? (
                        <div className="flex items-center gap-2">
                            <span
                                id="timesheet-person-label"
                                className="text-sm text-muted-foreground"
                            >
                                {t('hours.sheet.person')}
                            </span>
                            <Select
                                value={String(person.id)}
                                onValueChange={(value) => {
                                    const id = Number(value);
                                    router.get(
                                        sheetUrl(
                                            week.iso,
                                            id === user.id ? null : id,
                                        ),
                                    );
                                }}
                            >
                                <SelectTrigger
                                    className="w-56"
                                    aria-labelledby="timesheet-person-label"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={String(user.id)}>
                                        {t('hours.dialog.me', {
                                            name: user.name,
                                        })}
                                    </SelectItem>
                                    {people.map((other) => (
                                        <SelectItem
                                            key={other.id}
                                            value={String(other.id)}
                                        >
                                            {other.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    ) : null}
                </section>

                <WeekStatus
                    period={period}
                    isOwn={isOwn}
                    personName={person.name}
                />

                {scope === 'managed_projects' ? (
                    <Alert>
                        <Info aria-hidden="true" />
                        <AlertDescription>
                            {t('hours.sheet.scope_managed')}
                        </AlertDescription>
                    </Alert>
                ) : null}

                <div className="flex flex-wrap items-center gap-2">
                    {can.edit ? (
                        <>
                            <Button
                                type="button"
                                onClick={() =>
                                    setDialog({
                                        kind: 'new',
                                        task: null,
                                        date: defaultDate,
                                    })
                                }
                            >
                                <Plus aria-hidden="true" />
                                {t('hours.sheet.add_entry')}
                            </Button>
                            <div className="w-full sm:w-72">
                                <TaskPicker
                                    value={null}
                                    onChange={(task) => {
                                        if (
                                            extras.add([task]) === 0 &&
                                            serverTaskIds.has(task.id)
                                        ) {
                                            toast.info(
                                                t('hours.sheet.row_exists'),
                                            );
                                        }
                                    }}
                                    userId={isOwn ? undefined : person.id}
                                    placeholder={t('hours.sheet.add_row')}
                                    aria-label={t('hours.sheet.add_row')}
                                />
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={copyPrevious}
                                disabled={previousWeekTasks.length === 0}
                                title={
                                    previousWeekTasks.length === 0
                                        ? t('hours.sheet.copy_none')
                                        : undefined
                                }
                            >
                                <ClipboardCopy aria-hidden="true" />
                                {t('hours.sheet.copy_previous')}
                            </Button>
                        </>
                    ) : null}

                    <div className="flex flex-wrap gap-2 sm:ml-auto">
                        {can.submit ? (
                            <ConfirmDialog
                                open={confirm === 'submit'}
                                onOpenChange={(open) =>
                                    setConfirm(open ? 'submit' : null)
                                }
                                trigger={
                                    <Button
                                        type="button"
                                        data-test="submit-week"
                                    >
                                        <Send aria-hidden="true" />
                                        {t('hours.sheet.submit')}
                                    </Button>
                                }
                                title={t('hours.sheet.submit_title')}
                                description={submitDescription}
                                confirmLabel={t('hours.sheet.submit')}
                                destructive={false}
                                processing={processing}
                                onConfirm={() =>
                                    router.post(
                                        submit.url(),
                                        { week: week.iso },
                                        visit,
                                    )
                                }
                            />
                        ) : null}
                        {can.withdraw ? (
                            <ConfirmDialog
                                open={confirm === 'withdraw'}
                                onOpenChange={(open) =>
                                    setConfirm(open ? 'withdraw' : null)
                                }
                                trigger={
                                    <Button type="button" variant="outline">
                                        <Undo2 aria-hidden="true" />
                                        {t('hours.sheet.withdraw')}
                                    </Button>
                                }
                                title={t('hours.sheet.withdraw_title')}
                                description={t(
                                    'hours.sheet.withdraw_description',
                                )}
                                confirmLabel={t('hours.sheet.withdraw')}
                                destructive={false}
                                processing={processing}
                                onConfirm={() =>
                                    router.post(
                                        withdraw.url(),
                                        { week: week.iso },
                                        visit,
                                    )
                                }
                            />
                        ) : null}
                        {can.review && period.id !== null ? (
                            <>
                                <Button
                                    type="button"
                                    disabled={processing}
                                    onClick={() =>
                                        router.post(
                                            approve.url(period.id as number),
                                            {},
                                            visit,
                                        )
                                    }
                                >
                                    <CircleCheck aria-hidden="true" />
                                    {t('hours.review.approve')}
                                </Button>
                                <ReturnWeekDialog
                                    periodId={period.id}
                                    personName={person.name}
                                    weekLabel={weekLabel}
                                />
                            </>
                        ) : null}
                        {can.reopen && period.id !== null ? (
                            <ConfirmDialog
                                open={confirm === 'reopen'}
                                onOpenChange={(open) =>
                                    setConfirm(open ? 'reopen' : null)
                                }
                                trigger={
                                    <Button type="button" variant="outline">
                                        <RotateCcw aria-hidden="true" />
                                        {t('hours.review.reopen')}
                                    </Button>
                                }
                                title={t('hours.review.reopen_title')}
                                description={t(
                                    'hours.review.reopen_description',
                                )}
                                confirmLabel={t('hours.review.reopen')}
                                destructive={false}
                                processing={processing}
                                onConfirm={() =>
                                    router.post(
                                        reopen.url(period.id as number),
                                        {},
                                        visit,
                                    )
                                }
                            />
                        ) : null}
                    </div>
                </div>

                {gridRows.length === 0 ? (
                    <EmptyState
                        icon={Clock}
                        title={t('hours.sheet.empty_title')}
                        description={
                            can.edit
                                ? t('hours.sheet.empty_description')
                                : t('hours.sheet.empty_readonly')
                        }
                    >
                        <p className="text-sm">
                            <CapacityCell logged={0} capacity={capacity.week} />
                        </p>
                    </EmptyState>
                ) : (
                    <TimesheetGrid
                        rows={gridRows}
                        days={week.days}
                        totals={totals}
                        capacity={capacity}
                        editable={can.edit}
                        today={settings.today}
                        allowFuture={settings.allow_future}
                        personId={person.id}
                        onOpenCell={(row, dayIndex) =>
                            setDialog({ kind: 'cell', row, dayIndex })
                        }
                        onRemoveRow={extras.remove}
                    />
                )}
            </div>

            {openCell ? (
                <CellEntriesDialog
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setDialog({ kind: 'none' });
                        }
                    }}
                    taskTitle={openCell.row.task.title}
                    day={openCell.day}
                    entries={openCell.entries}
                    editable={can.edit}
                    canEditEntry={(entry) =>
                        entry.status === 'locked'
                            ? user.roles.includes('admin')
                            : can.edit && entry.status === 'draft'
                    }
                    onEdit={(entry) => setDialog({ kind: 'entry', entry })}
                    onAdd={() =>
                        setDialog({
                            kind: 'new',
                            task: {
                                id: openCell.row.task.id,
                                title: openCell.row.task.title,
                                project_id: openCell.row.task.project_id,
                            },
                            date: openCell.day,
                        })
                    }
                />
            ) : null}

            <TimeEntryDialog
                open={dialog.kind === 'entry' || dialog.kind === 'new'}
                onOpenChange={(open) => {
                    if (!open) {
                        setDialog({ kind: 'none' });
                    }
                }}
                entry={dialog.kind === 'entry' ? dialog.entry : null}
                task={dialog.kind === 'new' ? dialog.task : null}
                date={dialog.kind === 'new' ? dialog.date : undefined}
                userId={isOwn ? undefined : person.id}
            />
        </>
    );
}

function WeekStatus({
    period,
    isOwn,
    personName,
}: {
    period: TimesheetPageProps['period'];
    isOwn: boolean;
    personName: string;
}) {
    if (period.status === 'returned') {
        return (
            <Alert
                className="border-warning bg-warning-soft"
                data-test="returned-comment"
            >
                <Undo2 aria-hidden="true" className="text-warning" />
                <AlertTitle className="flex flex-wrap items-center gap-2">
                    <TimesheetStatusBadge status={period.status} />
                    {isOwn
                        ? t('hours.status.returned_own')
                        : t('hours.status.returned_other', {
                              name: personName,
                          })}
                </AlertTitle>
                <AlertDescription className="text-foreground">
                    <p>
                        {period.reviewer
                            ? t('hours.status.comment_by', {
                                  name: period.reviewer.name,
                              })
                            : null}{' '}
                        «{period.review_comment}»
                    </p>
                </AlertDescription>
            </Alert>
        );
    }

    const detail = (() => {
        switch (period.status) {
            case 'submitted':
                return t('hours.status.submitted', {
                    date: formatDateTime(period.submitted_at),
                });
            case 'approved':
                return period.auto_approved
                    ? t('hours.status.auto_approved')
                    : t('hours.status.approved', {
                          name: period.reviewer?.name ?? '',
                          date: formatDate(period.reviewed_at),
                      });
            case 'locked':
                return t('hours.status.locked');
            default:
                return isOwn
                    ? t('hours.status.open_own')
                    : t('hours.status.open');
        }
    })();

    return (
        <div
            className="flex flex-wrap items-center gap-2 rounded-md border px-3 py-2 text-sm"
            data-test="week-status"
        >
            <TimesheetStatusBadge status={period.status} />
            <span className="text-muted-foreground">{detail}</span>
        </div>
    );
}

TimesheetPage.layout = {
    breadcrumbs: [{ title: t('nav.time'), href: timeIndex() }],
};
