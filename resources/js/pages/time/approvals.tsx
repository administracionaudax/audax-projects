import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarCheck,
    ChevronDown,
    CircleCheck,
    ExternalLink,
    RotateCcw,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    TimeEntryStatusBadge,
    TimesheetStatusBadge,
} from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { CapacityCell } from '@/components/time/capacity-cell';
import { ReturnWeekDialog } from '@/components/time/return-week-dialog';
import {
    dayMonthLabel,
    weekRangeLabel,
    weekdayShortLabel,
} from '@/components/time/week-days';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as timeIndex } from '@/routes/time';
import {
    approve,
    approveMany,
    index as approvalsIndex,
} from '@/routes/time/approvals';
import { reopen } from '@/routes/time/week';
import type { ApprovalsPageProps, PendingWeek } from '@/types';

function sheetUrl(week: string, personId: number) {
    return timeIndex.url({ query: { semana: week, persona: personId } });
}

const VISIT = {
    preserveScroll: true,
    errorBag: 'review',
    onError: (errors: Record<string, string>) =>
        Object.values(errors).forEach((message) => toast.error(message)),
};

/**
 * Aprobaciones de horas (SPEC §7, D-020, D-024): las semanas enviadas de las personas que
 * supervisa quien revisa (un admin, todas), con totales por día y detalle; aprobar, devolver con
 * comentario, aprobar varias y reabrir desde el histórico.
 */
export default function Approvals({
    pending,
    history,
    limit,
}: ApprovalsPageProps) {
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [processing, setProcessing] = useState(false);
    const [confirmMany, setConfirmMany] = useState(false);
    const ids = pending
        .map((week) => week.period.id)
        .filter((id): id is number => id !== null);
    const selectedIds = ids.filter((id) => selected.has(id));
    const allSelected = ids.length > 0 && selectedIds.length === ids.length;

    const toggle = (id: number, checked: boolean) => {
        const next = new Set(selected);

        if (checked) {
            next.add(id);
        } else {
            next.delete(id);
        }

        setSelected(next);
    };

    const approveSelected = () => {
        router.post(
            approveMany.url(),
            { periods: selectedIds },
            {
                ...VISIT,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setConfirmMany(false);
                },
                onSuccess: () => setSelected(new Set()),
            },
        );
    };

    return (
        <>
            <Head title={t('hours.approvals.title')} />

            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('hours.approvals.heading')}
                    description={t('hours.approvals.description')}
                />

                <section
                    aria-labelledby="pending-heading"
                    className="grid gap-4"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="pending-heading" className="text-lg">
                            {t('hours.approvals.pending', {
                                count: pending.length,
                            })}
                        </h2>
                        {pending.length > 0 ? (
                            <div className="flex flex-wrap items-center gap-3">
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={allSelected}
                                        onCheckedChange={(checked) =>
                                            setSelected(
                                                checked === true
                                                    ? new Set(ids)
                                                    : new Set(),
                                            )
                                        }
                                    />
                                    {t('hours.approvals.select_all')}
                                </label>
                                <ConfirmDialog
                                    open={confirmMany}
                                    onOpenChange={setConfirmMany}
                                    trigger={
                                        <Button
                                            type="button"
                                            disabled={selectedIds.length === 0}
                                        >
                                            <CircleCheck aria-hidden="true" />
                                            {t(
                                                'hours.approvals.approve_selected',
                                                {
                                                    count: selectedIds.length,
                                                },
                                            )}
                                        </Button>
                                    }
                                    title={t(
                                        'hours.approvals.approve_many_title',
                                    )}
                                    description={t(
                                        'hours.approvals.approve_many_description',
                                        { count: selectedIds.length },
                                    )}
                                    confirmLabel={t('hours.review.approve')}
                                    destructive={false}
                                    processing={processing}
                                    onConfirm={approveSelected}
                                />
                            </div>
                        ) : null}
                    </div>

                    {pending.length === 0 ? (
                        <EmptyState
                            icon={CalendarCheck}
                            title={t('hours.approvals.empty')}
                            description={t('hours.approvals.empty_description')}
                        />
                    ) : (
                        <ul className="grid gap-3">
                            {pending.map((week) => (
                                <PendingCard
                                    key={week.period.id ?? week.period.week}
                                    week={week}
                                    selected={
                                        week.period.id !== null &&
                                        selected.has(week.period.id)
                                    }
                                    onSelect={(checked) =>
                                        week.period.id !== null &&
                                        toggle(week.period.id, checked)
                                    }
                                />
                            ))}
                        </ul>
                    )}
                    {pending.length >= limit ? (
                        <p className="text-sm text-muted-foreground">
                            {t('hours.approvals.limit', { count: limit })}
                        </p>
                    ) : null}
                </section>

                <section
                    aria-labelledby="history-heading"
                    className="grid gap-3"
                >
                    <h2 id="history-heading" className="text-lg">
                        {t('hours.approvals.history')}
                    </h2>
                    {history.length === 0 ? (
                        <EmptyState
                            title={t('hours.approvals.history_empty')}
                        />
                    ) : (
                        <div
                            className={cn(
                                'overflow-x-auto rounded-md border',
                                FOCUS_RING,
                            )}
                            role="region"
                            aria-labelledby="history-heading"
                            tabIndex={0}
                        >
                            <table className="w-full min-w-[40rem] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted text-left">
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('hours.approvals.person')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('hours.approvals.week')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('hours.approvals.status')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('hours.approvals.reviewed')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 text-right font-medium"
                                        >
                                            {t('hours.approvals.hours')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 text-right font-medium"
                                        >
                                            <span className="sr-only">
                                                {t('common.actions')}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {history.map(
                                        ({
                                            period,
                                            total,
                                            can_reopen: canReopen,
                                        }) => (
                                            <tr
                                                key={period.id ?? period.week}
                                                className="border-b last:border-b-0"
                                            >
                                                <td className="px-3 py-2">
                                                    <Link
                                                        href={sheetUrl(
                                                            period.week,
                                                            period.user_id,
                                                        )}
                                                        className={cn(
                                                            'rounded-sm hover:underline',
                                                            FOCUS_RING,
                                                        )}
                                                    >
                                                        {period.user?.name}
                                                    </Link>
                                                </td>
                                                <td className="px-3 py-2 whitespace-nowrap">
                                                    {weekRangeLabel(
                                                        period.week_start,
                                                        period.week_end,
                                                    )}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <TimesheetStatusBadge
                                                        status={period.status}
                                                    />
                                                </td>
                                                <td className="px-3 py-2 whitespace-nowrap text-muted-foreground">
                                                    {formatDate(
                                                        period.reviewed_at,
                                                    )}
                                                    {period.reviewer
                                                        ? ` · ${period.reviewer.name}`
                                                        : ''}
                                                </td>
                                                <td className="tabular px-3 py-2 text-right">
                                                    {formatMinutes(total)}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    {canReopen &&
                                                    period.id !== null ? (
                                                        <ReopenButton
                                                            periodId={period.id}
                                                            personName={
                                                                period.user
                                                                    ?.name ?? ''
                                                            }
                                                        />
                                                    ) : null}
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

function ReopenButton({
    periodId,
    personName,
}: {
    periodId: number;
    personName: string;
}) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={setOpen}
            trigger={
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    aria-label={t('hours.review.reopen_for', {
                        name: personName,
                    })}
                >
                    <RotateCcw aria-hidden="true" />
                    {t('hours.review.reopen')}
                </Button>
            }
            title={t('hours.review.reopen_title')}
            description={t('hours.review.reopen_description')}
            confirmLabel={t('hours.review.reopen')}
            destructive={false}
            processing={processing}
            onConfirm={() =>
                router.post(
                    reopen.url(periodId),
                    {},
                    {
                        ...VISIT,
                        onStart: () => setProcessing(true),
                        onFinish: () => {
                            setProcessing(false);
                            setOpen(false);
                        },
                    },
                )
            }
        />
    );
}

function PendingCard({
    week,
    selected,
    onSelect,
}: {
    week: PendingWeek;
    selected: boolean;
    onSelect: (checked: boolean) => void;
}) {
    const { period } = week;
    const [processing, setProcessing] = useState(false);
    const [open, setOpen] = useState(false);
    const name = period.user?.name ?? '';
    const range = weekRangeLabel(period.week_start, period.week_end);
    const days = Object.keys(week.days);

    return (
        <li className="rounded-md border" data-test="pending-week">
            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
                <div className="flex min-w-0 items-start gap-3 lg:w-64">
                    <Checkbox
                        checked={selected}
                        onCheckedChange={(checked) =>
                            onSelect(checked === true)
                        }
                        aria-label={t('hours.approvals.select', {
                            name,
                            week: range,
                        })}
                        className="mt-1"
                    />
                    <div className="min-w-0">
                        <p className="truncate font-medium">{name}</p>
                        <p className="text-sm text-muted-foreground">
                            {range}
                            {week.department ? ` · ${week.department}` : ''}
                        </p>
                    </div>
                </div>

                <div
                    className={cn('min-w-0 flex-1 overflow-x-auto', FOCUS_RING)}
                    role="region"
                    aria-label={t('hours.approvals.days_label', { name })}
                    tabIndex={0}
                >
                    <table className="w-full min-w-[30rem] text-center text-xs">
                        <thead>
                            <tr>
                                {days.map((day) => (
                                    <th
                                        key={day}
                                        scope="col"
                                        className="px-1 font-normal text-muted-foreground"
                                    >
                                        <span className="capitalize">
                                            {weekdayShortLabel(day)}
                                        </span>{' '}
                                        {dayMonthLabel(day)}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                {days.map((day) => (
                                    <td key={day} className="px-1 py-1">
                                        <CapacityCell
                                            logged={week.days[day]}
                                            capacity={
                                                week.capacity_days[day] ?? 0
                                            }
                                            compact
                                            className="justify-center"
                                        />
                                    </td>
                                ))}
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                    <CapacityCell
                        logged={week.total}
                        capacity={week.capacity}
                    />
                    {week.overage > 0 ? (
                        <span className="inline-flex items-center gap-1 text-xs">
                            <TriangleAlert
                                aria-hidden="true"
                                className="size-3.5 text-danger"
                            />
                            {t('hours.approvals.overage', {
                                minutes: formatMinutes(week.overage),
                            })}
                        </span>
                    ) : null}
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2 border-t px-4 py-3">
                <Button
                    type="button"
                    size="sm"
                    disabled={processing || period.id === null}
                    onClick={() =>
                        period.id !== null &&
                        router.post(
                            approve.url(period.id),
                            {},
                            {
                                ...VISIT,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                            },
                        )
                    }
                    aria-label={t('hours.approvals.approve_for', {
                        name,
                        week: range,
                    })}
                >
                    <CircleCheck aria-hidden="true" />
                    {t('hours.review.approve')}
                </Button>
                {period.id !== null ? (
                    <ReturnWeekDialog
                        periodId={period.id}
                        personName={name}
                        weekLabel={range}
                        size="sm"
                    />
                ) : null}
                <Button asChild variant="ghost" size="sm">
                    <Link href={sheetUrl(period.week, period.user_id)}>
                        <ExternalLink aria-hidden="true" />
                        {t('hours.approvals.open_sheet')}
                    </Link>
                </Button>
                <Collapsible
                    open={open}
                    onOpenChange={setOpen}
                    className="w-full"
                >
                    <CollapsibleTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-expanded={open}
                        >
                            <ChevronDown
                                aria-hidden="true"
                                className={cn(
                                    'transition-transform',
                                    open && 'rotate-180',
                                )}
                            />
                            {t('hours.approvals.detail', {
                                count: week.entries.length,
                            })}
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        {week.entries.length === 0 ? (
                            <p className="py-2 text-sm text-muted-foreground">
                                {t('hours.approvals.no_entries')}
                            </p>
                        ) : (
                            <div className="mt-2 overflow-x-auto">
                                <table className="w-full min-w-[36rem] text-sm">
                                    <thead>
                                        <tr className="border-b text-left">
                                            <th
                                                scope="col"
                                                className="px-2 py-1.5 font-medium"
                                            >
                                                {t('hours.table.date')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-2 py-1.5 font-medium"
                                            >
                                                {t('hours.table.task')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-2 py-1.5 font-medium"
                                            >
                                                {t('hours.table.description')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-2 py-1.5 text-right font-medium"
                                            >
                                                {t('hours.table.hours')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-2 py-1.5 font-medium"
                                            >
                                                {t('hours.table.status')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {week.entries.map((entry) => (
                                            <tr
                                                key={entry.id}
                                                className="border-b last:border-b-0"
                                            >
                                                <td className="tabular px-2 py-1.5 whitespace-nowrap">
                                                    {formatDate(entry.date)}
                                                </td>
                                                <td className="px-2 py-1.5">
                                                    <span className="block">
                                                        {entry.task?.title}
                                                    </span>
                                                    <span className="block text-xs text-muted-foreground">
                                                        {entry.project?.code}
                                                    </span>
                                                </td>
                                                <td className="px-2 py-1.5 text-muted-foreground">
                                                    {entry.description ?? '—'}
                                                </td>
                                                <td className="tabular px-2 py-1.5 text-right">
                                                    {formatMinutes(
                                                        entry.minutes,
                                                    )}
                                                    {entry.overage_minutes >
                                                    0 ? (
                                                        <span className="ml-1 text-xs">
                                                            {t(
                                                                'hours.cell.overage',
                                                                {
                                                                    minutes:
                                                                        formatMinutes(
                                                                            entry.overage_minutes,
                                                                        ),
                                                                },
                                                            )}
                                                        </span>
                                                    ) : null}
                                                </td>
                                                <td className="px-2 py-1.5">
                                                    <TimeEntryStatusBadge
                                                        status={entry.status}
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CollapsibleContent>
                </Collapsible>
            </div>
        </li>
    );
}

Approvals.layout = {
    breadcrumbs: [
        { title: t('nav.time'), href: timeIndex() },
        { title: t('hours.approvals.title'), href: approvalsIndex() },
    ],
};
