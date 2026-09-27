import { Link } from '@inertiajs/react';
import {
    CalendarX,
    ChevronDown,
    CircleCheck,
    ExternalLink,
    Hourglass,
    Inbox,
    ListTodo,
    Lock,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/components/empty-state';
import type {
    WorkloadPerson,
    WorkloadTask,
    WorkloadTrays as WorkloadTraysData,
    WorkloadUnplannedTask,
} from '@/components/workload/types';
import {
    editorKey,
    WorkloadTaskEditor,
} from '@/components/workload/workload-task-editor';
import type { EditorField } from '@/components/workload/workload-task-editor';
import { WorkloadTaskMeta } from '@/components/workload/workload-task-meta';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';

/**
 * Bandejas de la vista Carga (SPEC §9, D-051): «Sin planificar» (tareas de las personas visibles
 * sin estimación o sin entrega: no suman carga hasta completarlas) y, para quien reparte trabajo,
 * «Sin asignar» por departamento. Las vencidas van primero y señaladas. Acciones rápidas con las
 * reglas de Tareas (PATCH /carga/tareas/{id}).
 */
export function WorkloadTrays({
    trays,
    people,
    seesTeam,
}: {
    trays: WorkloadTraysData;
    people: WorkloadPerson[];
    seesTeam: boolean;
}) {
    return (
        <div
            className={cn(
                'grid min-w-0 gap-6',
                trays.unassigned.visible && 'xl:grid-cols-2',
            )}
        >
            <TraySection
                id="workload-unplanned"
                icon={ListTodo}
                title={t('workload_trays.unplanned_title')}
                count={trays.unplanned.total}
                description={t(
                    seesTeam
                        ? 'workload_trays.unplanned_description_team'
                        : 'workload_trays.unplanned_description_own',
                )}
            >
                {trays.unplanned.total === 0 ? (
                    <EmptyState
                        icon={CircleCheck}
                        title={t('workload_trays.unplanned_empty')}
                        description={t(
                            'workload_trays.unplanned_empty_description',
                        )}
                    />
                ) : (
                    <>
                        <ul className="grid gap-2">
                            {trays.unplanned.tasks.map((task) => (
                                <UnplannedItem
                                    key={task.id}
                                    task={task}
                                    people={people}
                                    trays={trays}
                                    showAssignee={seesTeam}
                                />
                            ))}
                        </ul>
                        <Truncated
                            shown={trays.unplanned.tasks.length}
                            total={trays.unplanned.total}
                        />
                    </>
                )}
            </TraySection>

            {trays.unassigned.visible ? (
                <TraySection
                    id="workload-unassigned"
                    icon={Inbox}
                    title={t('workload_trays.unassigned_title')}
                    count={trays.unassigned.total}
                    description={t('workload_trays.unassigned_description')}
                >
                    {trays.unassigned.total === 0 ? (
                        <EmptyState
                            icon={CircleCheck}
                            title={t('workload_trays.unassigned_empty')}
                            description={t(
                                'workload_trays.unassigned_empty_description',
                            )}
                        />
                    ) : (
                        <div className="grid gap-4">
                            {trays.unassigned.groups.map((group) => {
                                const name =
                                    group.department.name ??
                                    t('workload_matrix.no_department');

                                return (
                                    <section
                                        key={group.department.id ?? 'none'}
                                        aria-label={name}
                                        className="grid gap-2"
                                        data-test="workload-unassigned-group"
                                    >
                                        <h3 className="flex flex-wrap items-center gap-x-2 text-sm font-medium">
                                            <span
                                                aria-hidden="true"
                                                className="size-2.5 rounded-full bg-neutral-soft"
                                                style={
                                                    group.department.color
                                                        ? {
                                                              backgroundColor:
                                                                  group
                                                                      .department
                                                                      .color,
                                                          }
                                                        : undefined
                                                }
                                            />
                                            {name}
                                            <span className="text-xs font-normal text-muted-foreground">
                                                {t(
                                                    'workload_trays.group_summary',
                                                    {
                                                        count: group.total,
                                                        minutes: formatMinutes(
                                                            group.remaining_minutes,
                                                        ),
                                                    },
                                                )}
                                            </span>
                                        </h3>
                                        <ul className="grid gap-2">
                                            {group.tasks.map((task) => (
                                                <TrayItem
                                                    key={task.id}
                                                    task={task}
                                                    people={people}
                                                    trays={trays}
                                                    fields={['assignee']}
                                                    actionLabel={t(
                                                        'workload_trays.assign',
                                                    )}
                                                    submitLabel={t(
                                                        'workload_trays.assign_submit',
                                                    )}
                                                />
                                            ))}
                                        </ul>
                                        <Truncated
                                            shown={group.tasks.length}
                                            total={group.total}
                                        />
                                    </section>
                                );
                            })}
                        </div>
                    )}
                </TraySection>
            ) : null}
        </div>
    );
}

function TraySection({
    id,
    icon: Icon,
    title,
    count,
    description,
    children,
}: {
    id: string;
    icon: typeof Inbox;
    title: string;
    count: number;
    description: string;
    children: ReactNode;
}) {
    return (
        <section
            aria-labelledby={`${id}-title`}
            className="grid min-w-0 content-start gap-3 rounded-md border bg-card p-4"
            data-test={id}
        >
            <header className="grid gap-1">
                <h2
                    id={`${id}-title`}
                    className="flex items-center gap-2 text-base"
                >
                    <Icon
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                        strokeWidth={1.5}
                    />
                    {title}
                    <span className="tabular rounded-[3px] bg-muted px-1.5 text-xs text-muted-foreground">
                        {count}
                    </span>
                </h2>
                <p className="text-sm text-muted-foreground">{description}</p>
            </header>
            {children}
        </section>
    );
}

function Truncated({ shown, total }: { shown: number; total: number }) {
    if (shown >= total) {
        return null;
    }

    return (
        <p className="text-xs text-muted-foreground">
            {t('workload_trays.showing', { shown, total })}
        </p>
    );
}

function UnplannedItem({
    task,
    people,
    trays,
    showAssignee,
}: {
    task: WorkloadUnplannedTask;
    people: WorkloadPerson[];
    trays: WorkloadTraysData;
    showAssignee: boolean;
}) {
    return (
        <TrayItem
            task={task}
            people={people}
            trays={trays}
            fields={['assignee', 'dates', 'estimate']}
            actionLabel={t('workload_trays.plan')}
            hideMissing
            header={
                <div className="flex flex-wrap items-center gap-1.5 text-xs">
                    {task.missing.map((missing) => (
                        <span
                            key={missing}
                            className="inline-flex items-center gap-1 rounded-[3px] bg-warning-soft px-1.5 py-0.5"
                        >
                            {missing === 'estimate' ? (
                                <Hourglass
                                    aria-hidden="true"
                                    className="size-3.5 text-warning"
                                />
                            ) : (
                                <CalendarX
                                    aria-hidden="true"
                                    className="size-3.5 text-warning"
                                />
                            )}
                            {t(`workload_trays.missing_${missing}`)}
                        </span>
                    ))}
                    {showAssignee ? (
                        <span className="inline-flex items-center gap-1 text-muted-foreground">
                            <UserRound
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            {task.assignee.name}
                        </span>
                    ) : null}
                </div>
            }
        />
    );
}

function TrayItem({
    task,
    people,
    trays,
    fields,
    actionLabel,
    submitLabel,
    header,
    hideMissing = false,
}: {
    task: WorkloadTask;
    people: WorkloadPerson[];
    trays: WorkloadTraysData;
    fields: EditorField[];
    actionLabel: string;
    submitLabel?: string;
    header?: ReactNode;
    hideMissing?: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <li
            className={cn(
                'grid gap-2 rounded-md border p-3',
                task.overdue && 'border-danger/60',
            )}
            data-test="workload-tray-task"
        >
            {header}
            <Link
                href={urls.task(task.project.id, task.id)}
                className={cn(
                    'inline-flex items-center gap-1 justify-self-start rounded-[3px] hover:underline',
                    FOCUS_RING,
                )}
            >
                <span className="break-words">
                    {task.parent_title ? (
                        <span className="text-muted-foreground">
                            {task.parent_title} /{' '}
                        </span>
                    ) : null}
                    {task.title}
                </span>
                <ExternalLink
                    aria-hidden="true"
                    className="size-3.5 shrink-0 text-muted-foreground"
                />{' '}
                <span className="sr-only">{t('workload_task.open')}</span>
            </Link>
            <WorkloadTaskMeta task={task} hideMissing={hideMissing} />

            {task.can_edit ? (
                <Collapsible open={open} onOpenChange={setOpen}>
                    <CollapsibleTrigger asChild>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="justify-self-start"
                            aria-label={`${actionLabel}: ${task.title}`}
                        >
                            <ChevronDown
                                aria-hidden="true"
                                className={cn(
                                    'transition-transform',
                                    open && 'rotate-180',
                                )}
                            />
                            {actionLabel}
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent className="pt-3">
                        <WorkloadTaskEditor
                            key={editorKey(task)}
                            task={task}
                            fields={fields}
                            people={people}
                            extraPeople={trays.extra_people}
                            submitLabel={submitLabel}
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
