import { Link } from '@inertiajs/react';
import {
    CalendarX,
    ChevronDown,
    CircleCheck,
    ExternalLink,
    FolderKanban,
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
    WorkloadManagedTask,
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
 * Bandejas de la vista Carga (SPEC §9, D-051, D-052): «Sin planificar» (tareas de las personas
 * visibles sin estimación o sin entrega: no suman carga hasta completarlas); para quien reparte
 * trabajo, «Sin asignar» por departamento, y para quien gestiona proyectos, «De tus proyectos» (las
 * tareas de sus proyectos que no le llegan por su equipo, con el nombre de quien las tiene pero no
 * su carga). Las vencidas van primero y señaladas. Acciones rápidas con las reglas de Tareas
 * (PATCH /carga/tareas/{id}).
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
                'grid min-w-0 grid-cols-1 gap-6',
                (trays.unassigned.visible || trays.managed.visible) &&
                    'xl:grid-cols-2',
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
                        <ul className="grid min-w-0 grid-cols-1 gap-2">
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
                        <div className="grid min-w-0 grid-cols-1 gap-4">
                            {trays.unassigned.groups.map((group) => {
                                const name =
                                    group.department.name ??
                                    t('workload_matrix.no_department');

                                return (
                                    <section
                                        key={group.department.id ?? 'none'}
                                        aria-label={name}
                                        className="grid min-w-0 grid-cols-1 gap-2"
                                        data-test="workload-unassigned-group"
                                    >
                                        <h3 className="flex min-w-0 flex-wrap items-center gap-x-2 text-sm font-medium break-words">
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
                                        <ul className="grid min-w-0 grid-cols-1 gap-2">
                                            {group.tasks.map((task) => (
                                                <TrayItem
                                                    key={task.id}
                                                    task={task}
                                                    people={people}
                                                    trays={trays}
                                                    fields={['assignee']}
                                                    trayId="workload-unassigned"
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

            {trays.managed.visible ? (
                <TraySection
                    id="workload-managed"
                    icon={FolderKanban}
                    title={t('workload_trays.managed_title')}
                    count={trays.managed.total}
                    description={t('workload_trays.managed_description')}
                >
                    {trays.managed.total === 0 ? (
                        <EmptyState
                            icon={CircleCheck}
                            title={t('workload_trays.managed_empty')}
                            description={t(
                                'workload_trays.managed_empty_description',
                            )}
                        />
                    ) : (
                        <>
                            {trays.managed.unassigned > 0 ? (
                                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <Inbox
                                        aria-hidden="true"
                                        className="size-3.5 shrink-0"
                                    />
                                    {t('workload_trays.managed_summary', {
                                        count: trays.managed.unassigned,
                                    })}
                                </p>
                            ) : null}
                            <ul className="grid min-w-0 grid-cols-1 gap-2">
                                {trays.managed.tasks.map((task) => (
                                    <ManagedItem
                                        key={task.id}
                                        task={task}
                                        people={people}
                                        trays={trays}
                                    />
                                ))}
                            </ul>
                            <Truncated
                                shown={trays.managed.tasks.length}
                                total={trays.managed.total}
                            />
                        </>
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
            className="grid min-w-0 grid-cols-1 content-start gap-3 rounded-md border bg-card p-4"
            data-test={id}
        >
            <header className="grid min-w-0 gap-1">
                <h2
                    id={`${id}-title`}
                    tabIndex={-1}
                    className="flex items-center gap-2 text-base focus:outline-none"
                >
                    <Icon
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                        strokeWidth={1.5}
                    />
                    {title}
                    <span className="tabular rounded-md bg-muted px-1.5 text-xs text-muted-foreground">
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

/** Lo que le falta para sumar carga (sin estimación, sin entrega), con icono y texto. */
function MissingBadges({ missing }: { missing: ('estimate' | 'due_date')[] }) {
    return (
        <>
            {missing.map((item) => (
                <span
                    key={item}
                    className="inline-flex items-center gap-1 rounded-md bg-warning-soft px-1.5 py-0.5"
                >
                    {item === 'estimate' ? (
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
                    {t(`workload_trays.missing_${item}`)}
                </span>
            ))}
        </>
    );
}

/**
 * Tarea de un proyecto que gestiona: quién la tiene (solo el nombre) o «Sin asignar», y repartirla
 * entre los miembros del proyecto, además de sus fechas y su estimación.
 */
function ManagedItem({
    task,
    people,
    trays,
}: {
    task: WorkloadManagedTask;
    people: WorkloadPerson[];
    trays: WorkloadTraysData;
}) {
    return (
        <TrayItem
            task={task}
            people={people}
            trays={trays}
            fields={['assignee', 'dates', 'estimate']}
            actionLabel={t('workload_trays.reassign')}
            hideMissing
            trayId="workload-managed"
            header={
                <div className="flex flex-wrap items-center gap-1.5 text-xs">
                    {task.assignee ? (
                        <span className="inline-flex items-center gap-1 text-muted-foreground">
                            <UserRound
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            <span className="sr-only">
                                {`${t('workload_trays.assignee')}: `}
                            </span>
                            {task.assignee.name}
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-1 rounded-md bg-muted px-1.5 py-0.5">
                            <Inbox
                                aria-hidden="true"
                                className="size-3.5 text-muted-foreground"
                            />
                            {t('workload_trays.no_assignee')}
                        </span>
                    )}
                    <MissingBadges missing={task.missing} />
                </div>
            }
        />
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
            trayId="workload-unplanned"
            header={
                <div className="flex flex-wrap items-center gap-1.5 text-xs">
                    <MissingBadges missing={task.missing} />
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
    trayId,
}: {
    task: WorkloadTask;
    people: WorkloadPerson[];
    trays: WorkloadTraysData;
    fields: EditorField[];
    actionLabel: string;
    submitLabel?: string;
    header?: ReactNode;
    hideMissing?: boolean;
    /** Bandeja: tras guardar, el foco vuelve a su título (la tarea puede haber salido). */
    trayId: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <li
            className={cn(
                'grid min-w-0 grid-cols-1 gap-2 rounded-md border p-3',
                task.overdue && 'border-danger/60',
            )}
            data-test="workload-tray-task"
        >
            {header}
            <Link
                href={urls.task(task.project.id, task.id)}
                className={cn(
                    'inline-flex items-center gap-1 justify-self-start rounded-md hover:underline',
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
                            onSaved={() =>
                                document
                                    .getElementById(`${trayId}-title`)
                                    ?.focus()
                            }
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
