import { Link } from '@inertiajs/react';
import { CircleCheck, CornerDownRight, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { deviation, formatOverage } from '@/components/reports/r2-helpers';
import type {
    R2Estimates,
    R2EstimateTask,
    R2TaskType,
} from '@/components/reports/r2-types';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';

/**
 * Desviación de una tarea o un tipo: por encima de lo estimado en rojo con icono de aviso; en lo
 * estimado o por debajo, en verde con icono de hecho. Siempre con signo y texto, nunca solo color.
 */
export function R2Deviation({
    estimated,
    actual,
}: {
    estimated: number | null;
    actual: number;
}) {
    const value = deviation(estimated, actual);

    if (value === null) {
        return (
            <span className="text-muted-foreground">
                {t('reports_r2.estimates.not_estimated')}
            </span>
        );
    }

    const over = value.minutes > 0;
    const Icon = over ? TriangleAlert : CircleCheck;
    const hours = over
        ? formatOverage(value.minutes)
        : formatMinutes(value.minutes);
    const ratio =
        value.ratio === null
            ? ''
            : ` (${value.ratio > 0 ? '+' : ''}${formatPercent(value.ratio, 0)})`;

    return (
        <span
            className={cn(
                'inline-flex items-center justify-end gap-1',
                over ? 'text-danger' : 'text-foreground',
            )}
        >
            <Icon
                aria-hidden="true"
                className={cn(
                    'size-3.5 shrink-0',
                    over ? 'text-danger' : 'text-success',
                )}
            />
            <span className="sr-only">
                {t(
                    over
                        ? 'reports_r2.estimates.over_sr'
                        : 'reports_r2.estimates.within_sr',
                )}{' '}
            </span>
            {hours}
            {ratio}
        </span>
    );
}

function TypeName({ type }: { type: R2TaskType }) {
    if (type === null) {
        return (
            <span className="text-muted-foreground">
                {t('reports_r2.no_type')}
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-2">
            <span
                aria-hidden="true"
                className="size-2.5 shrink-0 rounded-full"
                style={{ backgroundColor: type.color }}
            />
            {type.name}
        </span>
    );
}

/**
 * Estimado frente a real por tarea (SPEC §10.3) con la regla de subtareas del SPEC §6: la tarea
 * principal con subtareas estimadas usa su suma (se indica) y suma las horas de sus subtareas,
 * que van debajo, sangradas. Pie con los totales y las horas de tareas movidas a otro proyecto.
 */
export function R2EstimateTable({
    projectId,
    estimates,
}: {
    projectId: number;
    estimates: R2Estimates;
}) {
    const { tasks, totals } = estimates;

    return (
        <div
            className={cn(
                'max-h-[36rem] overflow-auto rounded-md border',
                FOCUS_RING,
            )}
            role="region"
            aria-label={t('reports_r2.estimates.tasks_caption')}
            tabIndex={0}
        >
            <table className="tabular w-full min-w-[46rem] text-sm">
                <caption className="sr-only">
                    {t('reports_r2.estimates.tasks_caption')}
                </caption>
                <thead className="sticky top-0 bg-card">
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('reports_r2.column.task')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('reports_r2.column.type')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('reports_r2.column.status')}
                        </th>
                        <Th>{t('reports_r2.column.estimated')}</Th>
                        <Th>{t('reports_r2.column.actual')}</Th>
                        <Th>{t('reports_r2.column.deviation')}</Th>
                    </tr>
                </thead>
                <tbody>
                    {tasks.map((task) => (
                        <TaskRow
                            key={task.id}
                            projectId={projectId}
                            task={task}
                        />
                    ))}
                </tbody>
                <tfoot>
                    {totals.other_minutes > 0 ? (
                        <tr className="border-t">
                            <th
                                scope="row"
                                colSpan={4}
                                className="px-3 py-2 text-left font-normal text-muted-foreground"
                            >
                                {t('reports_r2.estimates.other_tasks')}
                            </th>
                            <td className="px-3 py-2 text-right">
                                {formatMinutes(totals.other_minutes)}
                            </td>
                            <td />
                        </tr>
                    ) : null}
                    <tr className="border-t-2">
                        <th
                            scope="row"
                            colSpan={3}
                            className="px-3 py-2 text-left font-medium"
                        >
                            {t('reports_r2.estimates.totals', {
                                tasks: totals.tasks,
                                estimated: totals.estimated_tasks,
                            })}
                        </th>
                        <td className="px-3 py-2 text-right font-medium">
                            {formatMinutes(totals.estimated_minutes)}
                        </td>
                        <td className="px-3 py-2 text-right font-medium">
                            {formatMinutes(totals.actual_minutes)}
                        </td>
                        <td className="px-3 py-2 text-right text-muted-foreground">
                            {t('reports_r2.estimates.over_tasks', {
                                count: totals.over_tasks,
                            })}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

function TaskRow({
    projectId,
    task,
}: {
    projectId: number;
    task: R2EstimateTask;
}) {
    const sub = task.depth === 1;

    return (
        <tr
            className={cn(
                'border-b last:border-0',
                sub ? 'bg-muted/40' : 'even:bg-muted',
            )}
        >
            <th scope="row" className="px-3 py-2 text-left font-normal">
                <span
                    className={cn(
                        'flex min-w-0 items-start gap-1.5',
                        sub && 'pl-5',
                    )}
                >
                    {sub ? (
                        <CornerDownRight
                            aria-hidden="true"
                            className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                        />
                    ) : null}
                    <span className="min-w-0">
                        <Link
                            href={urls.task(projectId, task.id)}
                            aria-label={
                                sub
                                    ? t('reports_r2.estimates.subtask_label', {
                                          title: task.title,
                                      })
                                    : undefined
                            }
                            className={cn(
                                'rounded-md break-words hover:underline',
                                FOCUS_RING,
                            )}
                        >
                            {task.title}
                        </Link>
                        {task.derived ? (
                            <span className="block text-xs text-muted-foreground">
                                {t('reports_r2.estimates.derived')}
                            </span>
                        ) : null}
                    </span>
                </span>
            </th>
            <td className="px-3 py-2">
                <TypeName type={task.type} />
            </td>
            <td className="px-3 py-2">
                <span className="inline-flex items-center gap-2">
                    <span
                        aria-hidden="true"
                        className="size-2.5 shrink-0 rounded-full"
                        style={{ backgroundColor: task.status.color }}
                    />
                    {task.status.name}
                </span>
            </td>
            <td className="px-3 py-2 text-right">
                {task.estimated_minutes === null
                    ? '—'
                    : formatMinutes(task.estimated_minutes)}
            </td>
            <td className="px-3 py-2 text-right">
                {formatMinutes(task.actual_minutes)}
            </td>
            <td className="px-3 py-2 text-right">
                <R2Deviation
                    estimated={task.estimated_minutes}
                    actual={task.actual_minutes}
                />
            </td>
        </tr>
    );
}

/** Estimado frente a real por tipo de tarea. */
export function R2EstimateByType({ rows }: { rows: R2Estimates['by_type'] }) {
    return (
        <div
            className={cn('overflow-x-auto rounded-md border', FOCUS_RING)}
            role="region"
            aria-label={t('reports_r2.estimates.by_type_caption')}
            tabIndex={0}
        >
            <table className="tabular w-full min-w-[30rem] text-sm">
                <caption className="sr-only">
                    {t('reports_r2.estimates.by_type_caption')}
                </caption>
                <thead>
                    <tr className="border-b text-left">
                        <th scope="col" className="px-3 py-2 font-medium">
                            {t('reports_r2.column.type')}
                        </th>
                        <Th>{t('reports_r2.column.estimated')}</Th>
                        <Th>{t('reports_r2.column.actual')}</Th>
                        <Th>{t('reports_r2.column.deviation')}</Th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={row.type?.id ?? 'none'}
                            className="border-b last:border-0 even:bg-muted"
                        >
                            <th
                                scope="row"
                                className="px-3 py-2 text-left font-normal"
                            >
                                <TypeName type={row.type} />
                            </th>
                            <td className="px-3 py-2 text-right">
                                {formatMinutes(row.estimated_minutes)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                {formatMinutes(row.actual_minutes)}
                            </td>
                            <td className="px-3 py-2 text-right">
                                <R2Deviation
                                    estimated={
                                        row.estimated_minutes > 0
                                            ? row.estimated_minutes
                                            : null
                                    }
                                    actual={row.actual_minutes}
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Th({ children }: { children: ReactNode }) {
    return (
        <th scope="col" className="px-3 py-2 text-right font-medium">
            {children}
        </th>
    );
}
