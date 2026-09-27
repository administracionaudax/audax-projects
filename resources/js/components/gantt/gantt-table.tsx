import { CircleCheck, Diamond, TriangleAlert } from 'lucide-react';
import { isConflict } from '@/components/gantt/conflicts';
import type {
    GanttProjectRow,
    GanttRow,
    GanttTaskRow,
} from '@/components/gantt/rows';
import type { GanttTask } from '@/components/gantt/types';
import { TaskStatusBadge } from '@/components/domain/badges';
import {
    Table,
    TableBody,
    TableCaption,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TaskDependencyItem } from '@/types/schedule';

type Group = {
    key: string;
    project: GanttProjectRow | null;
    rows: GanttTaskRow[];
};

/** Agrupa las filas por proyecto (Gantt multiproyecto): cada proyecto, su propio <tbody>. */
function groupRows(rows: ReadonlyArray<GanttRow>): Group[] {
    const groups: Group[] = [];

    for (const row of rows) {
        if (row.kind === 'project') {
            groups.push({ key: row.key, project: row, rows: [] });
            continue;
        }

        if (groups.length === 0) {
            groups.push({ key: 'tasks', project: null, rows: [] });
        }

        groups[groups.length - 1].rows.push(row);
    }

    return groups;
}

/**
 * Vista de tabla del Gantt (alternativa accesible al diagrama, D-060): título, responsable,
 * inicio, entrega, predecesoras (con el conflicto en texto e icono) y estado. Mismas filas que el
 * diagrama (las tareas sin fechas están en su lista aparte). En el Gantt multiproyecto, cada
 * proyecto es un grupo de filas (<tbody>) con su cabecera (scope="rowgroup").
 */
export function GanttTable({
    caption,
    rows,
    tasks,
    dependencies,
    onOpen,
}: {
    caption: string;
    rows: ReadonlyArray<GanttRow>;
    /** Todas las tareas (para nombrar predecesoras que no tienen fila). */
    tasks: ReadonlyArray<GanttTask>;
    dependencies: ReadonlyArray<TaskDependencyItem>;
    onOpen: (task: GanttTask) => void;
}) {
    const byId = new Map(tasks.map((task) => [task.id, task]));
    const predecessors = new Map<number, TaskDependencyItem[]>();

    for (const dependency of dependencies) {
        const list = predecessors.get(dependency.successor_task_id) ?? [];
        list.push(dependency);
        predecessors.set(dependency.successor_task_id, list);
    }

    return (
        <div
            className="overflow-x-auto rounded-md border"
            data-test="gantt-table"
        >
            <Table>
                <TableCaption className="sr-only">{caption}</TableCaption>
                <TableHeader>
                    <TableRow>
                        <TableHead scope="col">
                            {t('gantt.column.task')}
                        </TableHead>
                        <TableHead scope="col">
                            {t('gantt.column.assignee')}
                        </TableHead>
                        <TableHead scope="col">
                            {t('gantt.column.start')}
                        </TableHead>
                        <TableHead scope="col">
                            {t('gantt.column.due')}
                        </TableHead>
                        <TableHead scope="col">
                            {t('gantt.column.predecessors')}
                        </TableHead>
                        <TableHead scope="col">
                            {t('gantt.column.status')}
                        </TableHead>
                    </TableRow>
                </TableHeader>
                {groupRows(rows).map((group) => (
                    <TableBody key={group.key}>
                        {group.project ? (
                            <TableRow className="bg-muted/60">
                                <TableHead
                                    scope="rowgroup"
                                    colSpan={6}
                                    className="text-foreground"
                                >
                                    {group.project.project.code} ·{' '}
                                    {group.project.project.name}
                                </TableHead>
                            </TableRow>
                        ) : null}
                        {group.rows.map((row) => {
                            const task = row.task;
                            const links = predecessors.get(task.id) ?? [];

                            return (
                                <TableRow
                                    key={row.key}
                                    data-test="gantt-table-row"
                                >
                                    <TableHead
                                        scope="row"
                                        className="font-normal whitespace-normal"
                                    >
                                        <span
                                            className="flex items-center gap-1.5"
                                            style={{
                                                paddingLeft:
                                                    row.depth === 1 ? 20 : 0,
                                            }}
                                        >
                                            {task.is_milestone ? (
                                                <Diamond
                                                    aria-hidden="true"
                                                    className="size-3.5 shrink-0 text-muted-foreground"
                                                />
                                            ) : null}
                                            <button
                                                type="button"
                                                onClick={() => onOpen(task)}
                                                className="rounded-[3px] text-left text-foreground underline-offset-2 hover:underline"
                                                data-gantt-focus={task.id}
                                            >
                                                {task.title}
                                            </button>
                                            {task.is_milestone ? (
                                                <span className="sr-only">
                                                    {t(
                                                        'gantt.bar.is_milestone',
                                                    )}
                                                </span>
                                            ) : null}
                                        </span>
                                    </TableHead>
                                    <TableCell>
                                        {task.assignee?.name ?? (
                                            <span className="text-muted-foreground">
                                                {t('gantt.legend.unassigned')}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {task.start_date
                                            ? formatDate(task.start_date)
                                            : '—'}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {task.due_date
                                            ? formatDate(task.due_date)
                                            : '—'}
                                    </TableCell>
                                    <TableCell className="whitespace-normal">
                                        {links.length === 0 ? (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        ) : (
                                            <ul className="grid gap-0.5">
                                                {links.map((link) => {
                                                    const predecessor =
                                                        byId.get(
                                                            link.predecessor_task_id,
                                                        );
                                                    const conflict =
                                                        predecessor !==
                                                            undefined &&
                                                        isConflict(
                                                            predecessor,
                                                            task,
                                                        );

                                                    return (
                                                        <li
                                                            key={link.id}
                                                            className="flex items-center gap-1"
                                                        >
                                                            {predecessor?.title ??
                                                                `#${link.predecessor_task_id}`}
                                                            {conflict ? (
                                                                <span className="flex items-center gap-1 text-destructive-foreground">
                                                                    <TriangleAlert
                                                                        aria-hidden="true"
                                                                        className="size-3.5"
                                                                    />
                                                                    {t(
                                                                        'gantt.table.conflict',
                                                                    )}
                                                                </span>
                                                            ) : null}
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {task.status ? (
                                            <TaskStatusBadge
                                                name={task.status.name}
                                                color={task.status.color}
                                                done={
                                                    task.status.category ===
                                                    'done'
                                                }
                                            />
                                        ) : task.is_completed ? (
                                            <CircleCheck
                                                aria-hidden="true"
                                                className="size-4 text-success"
                                            />
                                        ) : null}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                ))}
            </Table>
        </div>
    );
}
