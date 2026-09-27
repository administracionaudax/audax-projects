import { Link } from '@inertiajs/react';
import {
    CalendarClock,
    CalendarMinus,
    ChevronDown,
    ClipboardList,
    ExternalLink,
    Lock,
} from 'lucide-react';
import { useState } from 'react';
import { LoadCell } from '@/components/charts/load-cell';
import { EmptyState } from '@/components/empty-state';
import type {
    WorkloadCellPanelData,
    WorkloadCellTask,
    WorkloadPerson,
} from '@/components/workload/types';
import {
    loadSummary,
    periodLabel,
    reasonLong,
    reasonShort,
    reducedLong,
} from '@/components/workload/workload-labels';
import {
    editorKey,
    WorkloadTaskEditor,
} from '@/components/workload/workload-task-editor';
import { WorkloadTaskMeta } from '@/components/workload/workload-task-meta';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';

/**
 * Panel de una celda de la vista Carga (D-052): las tareas que forman esa carga, con los minutos
 * que ponen en ese día (o semana), su proyecto, bolsa, restante, fechas y si están vencidas; y,
 * si se puede, reasignarlas y replanificarlas ahí mismo. Se abre con ?celda=persona:fecha (una
 * recarga parcial que solo trae la prop `cell`); mientras llega, un esqueleto.
 */
export function WorkloadCellPanel({
    cell,
    loading,
    byWeek,
    people,
    onClose,
}: {
    cell: WorkloadCellPanelData | null;
    loading: boolean;
    byWeek: boolean;
    people: WorkloadPerson[];
    onClose: () => void;
}) {
    const open = cell !== null || loading;
    const period = cell ? periodLabel(cell, byWeek) : '';

    return (
        <Sheet
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
        >
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto sm:max-w-xl"
                data-test="workload-cell-panel"
            >
                <SheetHeader className="gap-1 pr-12">
                    {cell && !loading ? (
                        <>
                            <SheetTitle className="text-lg font-normal">
                                {cell.person.name}
                            </SheetTitle>
                            <SheetDescription>
                                <span className="capitalize">{period}</span>
                                {cell.person.department ? (
                                    <> · {cell.person.department}</>
                                ) : null}
                            </SheetDescription>
                        </>
                    ) : (
                        <>
                            <SheetTitle className="sr-only">
                                {t('workload_panel.loading')}
                            </SheetTitle>
                            <SheetDescription className="sr-only">
                                {t('workload_panel.loading')}
                            </SheetDescription>
                        </>
                    )}
                </SheetHeader>

                {cell && !loading ? (
                    <PanelBody
                        key={cell.key}
                        cell={cell}
                        byWeek={byWeek}
                        people={people}
                    />
                ) : (
                    <PanelSkeleton />
                )}
            </SheetContent>
        </Sheet>
    );
}

function PanelSkeleton() {
    return (
        <div
            className="grid gap-4 p-4"
            role="status"
            aria-label={t('workload_panel.loading')}
        >
            <Skeleton className="h-14 rounded-[3px]" />
            {[0, 1, 2].map((item) => (
                <Skeleton key={item} className="h-28 rounded-[3px]" />
            ))}
        </div>
    );
}

function PanelBody({
    cell,
    byWeek,
    people,
}: {
    cell: WorkloadCellPanelData;
    byWeek: boolean;
    people: WorkloadPerson[];
}) {
    const isWeek = cell.days.length > 1;

    return (
        <div className="grid gap-6 p-4">
            <section
                aria-label={t('workload_panel.summary')}
                className="grid gap-2"
                data-test="workload-cell-summary"
            >
                <div className="grid grid-cols-[8rem_minmax(0,1fr)] items-center gap-3">
                    <div aria-hidden="true">
                        <LoadCell
                            planned={cell.planned}
                            capacity={cell.capacity}
                            reason={
                                cell.reason
                                    ? reasonShort(cell.reason)
                                    : undefined
                            }
                        />
                    </div>
                    <p className="text-sm">{loadSummary(cell)}</p>
                </div>
                {cell.reason ? (
                    <p className="flex items-start gap-1.5 text-sm text-muted-foreground">
                        <CalendarMinus
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0"
                        />
                        {reasonLong(cell.reason)}
                    </p>
                ) : null}
                {cell.reduced ? (
                    <p className="flex items-start gap-1.5 text-sm text-muted-foreground">
                        <CalendarMinus
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0"
                        />
                        {reducedLong(cell.reduced)}
                    </p>
                ) : null}
                {cell.overdue ? (
                    <p className="flex items-start gap-1.5 text-sm">
                        <CalendarClock
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-danger"
                        />
                        {t('workload_panel.overdue_note')}
                    </p>
                ) : null}
            </section>

            {isWeek ? (
                <section aria-labelledby="workload-panel-days">
                    <h3
                        id="workload-panel-days"
                        className="mb-2 text-sm font-medium"
                    >
                        {t('workload_panel.days')}
                    </h3>
                    <table className="w-full text-sm">
                        <caption className="sr-only">
                            {t('workload_panel.days_caption')}
                        </caption>
                        <thead className="sr-only">
                            <tr>
                                <th scope="col">{t('workload_panel.day')}</th>
                                <th scope="col">{t('workload_panel.load')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {cell.days.map((day) => (
                                <tr key={day.date}>
                                    <th
                                        scope="row"
                                        className="py-1.5 pr-2 text-left font-normal capitalize"
                                    >
                                        {periodLabel(
                                            { from: day.date, to: day.date },
                                            false,
                                        )}
                                    </th>
                                    <td className="py-1.5 text-right text-muted-foreground">
                                        {day.reason
                                            ? `${reasonLong(day.reason)} · `
                                            : ''}
                                        <span className="tabular text-foreground">
                                            {loadSummary(day)}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            ) : null}

            <section
                aria-labelledby="workload-panel-tasks"
                className="grid gap-3"
            >
                <h3 id="workload-panel-tasks" className="text-sm font-medium">
                    {t('workload_panel.tasks', { count: cell.tasks.length })}
                </h3>

                {cell.tasks.length === 0 ? (
                    <EmptyState
                        icon={ClipboardList}
                        title={t(
                            isWeek || byWeek
                                ? 'workload_panel.empty_week'
                                : 'workload_panel.empty_day',
                        )}
                        description={t('workload_panel.empty_description')}
                    />
                ) : (
                    <ul className="grid gap-3">
                        {cell.tasks.map((task) => (
                            <TaskCard
                                key={task.id}
                                task={task}
                                week={isWeek}
                                people={people}
                                cell={cell}
                                expandedByDefault={cell.tasks.length === 1}
                            />
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}

function TaskCard({
    task,
    week,
    people,
    cell,
    expandedByDefault,
}: {
    task: WorkloadCellTask;
    week: boolean;
    people: WorkloadPerson[];
    cell: WorkloadCellPanelData;
    expandedByDefault: boolean;
}) {
    const [open, setOpen] = useState(expandedByDefault && task.can_edit);

    return (
        <li
            className="grid gap-2 rounded-md border p-3"
            data-test="workload-cell-task"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    {task.parent_title ? (
                        <p className="truncate text-xs text-muted-foreground">
                            {t('workload_task.subtask_of', {
                                task: task.parent_title,
                            })}
                        </p>
                    ) : null}
                    <Link
                        href={urls.task(task.project.id, task.id)}
                        className={cn(
                            'inline-flex items-center gap-1 rounded-[3px] hover:underline',
                            FOCUS_RING,
                        )}
                    >
                        <span className="break-words">{task.title}</span>
                        <ExternalLink
                            aria-hidden="true"
                            className="size-3.5 shrink-0 text-muted-foreground"
                        />{' '}
                        <span className="sr-only">
                            {t('workload_task.open')}
                        </span>
                    </Link>
                </div>
                <p className="tabular shrink-0 rounded-[3px] bg-muted px-1.5 py-0.5 text-sm">
                    {t(
                        week
                            ? 'workload_panel.minutes_week'
                            : 'workload_panel.minutes_day',
                        { minutes: formatMinutes(task.minutes) },
                    )}
                </p>
            </div>

            <WorkloadTaskMeta task={task} />

            {task.can_edit ? (
                <Collapsible open={open} onOpenChange={setOpen}>
                    <CollapsibleTrigger asChild>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="justify-self-start"
                        >
                            <ChevronDown
                                aria-hidden="true"
                                className={cn(
                                    'transition-transform',
                                    open && 'rotate-180',
                                )}
                            />
                            {task.assignee_ids !== null
                                ? t('workload_panel.edit_reassign')
                                : t('workload_panel.edit_plan')}
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent className="pt-3">
                        <WorkloadTaskEditor
                            key={editorKey(task)}
                            task={task}
                            people={people}
                            extraPeople={cell.extra_people}
                        />
                    </CollapsibleContent>
                </Collapsible>
            ) : (
                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Lock aria-hidden="true" className="size-3.5 shrink-0" />
                    {t('workload_panel.read_only')}
                </p>
            )}
        </li>
    );
}
