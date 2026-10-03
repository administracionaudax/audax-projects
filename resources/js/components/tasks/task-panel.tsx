import { Link, router } from '@inertiajs/react';
import {
    ArrowRightLeft,
    Bell,
    BellOff,
    ChevronLeft,
    Diamond,
    EllipsisVertical,
    Link2,
    MessageSquare,
    Trash2,
} from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { TaskDependencies } from '@/components/planning/task-dependencies';
import { MoveTaskDialog } from '@/components/tasks/move-task-dialog';
import { TaskActivity } from '@/components/tasks/task-activity';
import {
    AttachmentList,
    AttachmentUpload,
} from '@/components/tasks/task-attachments';
import { TaskComments } from '@/components/tasks/task-comments';
import { TaskDescription } from '@/components/tasks/task-description';
import { useTaskLookups } from '@/components/tasks/task-lookups';
import { TaskPanelFields } from '@/components/tasks/task-panel-fields';
import { toastErrors, updateTask } from '@/components/tasks/task-requests';
import { TaskSubtasks } from '@/components/tasks/task-subtasks';
import { TaskTime } from '@/components/tasks/task-time';
import { TimerButton } from '@/components/time/timer-button';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { destroy as destroyTask, unwatch, watch } from '@/routes/tasks';
import type { TaskPanelData } from '@/types';

function Section({
    title,
    children,
    action,
    id,
}: {
    title: string;
    children: ReactNode;
    action?: ReactNode;
    id?: string;
}) {
    const headingId = useId();

    return (
        <section
            aria-labelledby={id ?? headingId}
            className="grid gap-3 border-t pt-5"
        >
            <div className="flex items-center justify-between gap-2">
                <h3 id={id ?? headingId} className="text-sm font-medium">
                    {title}
                </h3>
                {action}
            </div>
            {children}
        </section>
    );
}

function TitleField({ panel }: { panel: TaskPanelData }) {
    const id = useId();
    const [value, setValue] = useState(panel.task.title);
    const [source, setSource] = useState(panel.task.title);

    if (source !== panel.task.title) {
        setSource(panel.task.title);
        setValue(panel.task.title);
    }

    const save = () => {
        const title = value.trim();

        if (title === '' || title === panel.task.title) {
            setValue(panel.task.title);

            return;
        }

        updateTask(
            panel.task.id,
            { title },
            { onFailure: () => setValue(panel.task.title) },
        );
    };

    return (
        <div>
            <label htmlFor={id} className="sr-only">
                {t('task_panel.title_label')}
            </label>
            <Input
                id={id}
                value={value}
                maxLength={255}
                onChange={(event) => setValue(event.target.value)}
                onBlur={save}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        event.currentTarget.blur();
                    } else if (event.key === 'Escape') {
                        // Escape deshace el cambio del título (el panel no se cierra si había cambios).
                        setValue(panel.task.title);
                    }
                }}
                data-dirty={value !== panel.task.title ? 'true' : undefined}
                className="h-auto border-transparent px-2 py-1 text-lg hover:border-input md:text-lg"
                data-test="task-title-input"
            />
        </div>
    );
}

function PanelSkeleton() {
    return (
        <div
            role="status"
            aria-label={t('task_panel.loading')}
            className="grid gap-4 p-4"
        >
            <Skeleton className="h-8 w-3/4 rounded-md" />
            <div className="grid gap-3 sm:grid-cols-2">
                {[0, 1, 2, 3, 4, 5].map((item) => (
                    <Skeleton key={item} className="h-9 rounded-md" />
                ))}
            </div>
            <Skeleton className="h-24 rounded-md" />
        </div>
    );
}

function PanelBody({
    panel,
    onOpen,
}: {
    panel: TaskPanelData;
    onOpen: (taskId: number) => void;
}) {
    const lookups = useTaskLookups();
    const task = panel.task;
    const descriptionId = useId();
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [moving, setMoving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const blockedReason = panel.delete_blocked
        ? t(`task_panel.delete_blocked.${panel.delete_blocked}`)
        : null;

    const toggleWatch = () => {
        const options = {
            preserveScroll: true,
            preserveState: true,
            only: ['panel'],
            onError: (errors: Record<string, string>) => toastErrors(errors),
        };

        if (panel.is_watching) {
            router.delete(unwatch.url(task.id), options);
        } else {
            router.post(watch.url(task.id), {}, options);
        }
    };

    const copyLink = () => {
        const url = new URL(
            `/tareas/${task.id}`,
            window.location.origin,
        ).toString();

        void navigator.clipboard
            ?.writeText(url)
            .then(() => toast.success(t('task_panel.link_copied')))
            .catch(() => toast.error(t('task_panel.link_copy_failed')));
    };

    return (
        <div className="grid gap-5 px-4 pb-8">
            <div className="flex flex-wrap items-center gap-2">
                {!task.is_milestone && panel.can.log_time ? (
                    <TimerButton task={task} size="sm" />
                ) : null}
                {task.is_milestone ? (
                    <span className="inline-flex items-center gap-1 rounded-md bg-neutral-soft px-1.5 py-0.5 text-xs font-medium">
                        <Diamond aria-hidden="true" className="size-3.5" />
                        {t('task_fields.milestone')}
                    </span>
                ) : null}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={toggleWatch}
                    aria-pressed={panel.is_watching}
                >
                    {panel.is_watching ? (
                        <BellOff aria-hidden="true" />
                    ) : (
                        <Bell aria-hidden="true" />
                    )}
                    {panel.is_watching
                        ? t('task_panel.unwatch')
                        : t('task_panel.watch')}
                </Button>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label={t('task_panel.more')}
                        >
                            <EllipsisVertical aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={copyLink}>
                            <Link2 aria-hidden="true" />
                            {t('task_panel.copy_link')}
                        </DropdownMenuItem>
                        {panel.can.move ? (
                            <DropdownMenuItem onSelect={() => setMoving(true)}>
                                <ArrowRightLeft aria-hidden="true" />
                                {t('task_panel.move')}
                            </DropdownMenuItem>
                        ) : null}
                        {panel.can.update ? (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() => {
                                        if (blockedReason) {
                                            toast.error(blockedReason);

                                            return;
                                        }

                                        setConfirmDelete(true);
                                    }}
                                    variant="destructive"
                                >
                                    <Trash2 aria-hidden="true" />
                                    {t('task_panel.delete')}
                                </DropdownMenuItem>
                            </>
                        ) : null}
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            {blockedReason && panel.can.update ? (
                <p className="text-xs text-muted-foreground">{blockedReason}</p>
            ) : null}

            <TaskPanelFields panel={panel} />

            <Section title={t('task_panel.description')} id={descriptionId}>
                <TaskDescription panel={panel} headingId={descriptionId} />
            </Section>

            {!panel.parent ? (
                <Section
                    title={t('task_panel.subtasks', {
                        count: panel.subtasks.length,
                    })}
                >
                    <TaskSubtasks panel={panel} onOpen={onOpen} />
                </Section>
            ) : null}

            {panel.dependencies ? (
                <Section title={t('planning.dependencies.title')}>
                    <TaskDependencies panel={panel} onOpen={onOpen} />
                </Section>
            ) : null}

            <Section
                title={t('task_panel.attachments', {
                    count: panel.attachments.length,
                })}
            >
                <AttachmentList
                    attachments={panel.attachments}
                    emptyText={t('task_files.empty')}
                />
                {panel.can.update ? (
                    <AttachmentUpload
                        taskId={task.id}
                        maxMb={lookups.maxAttachmentMb}
                    />
                ) : null}
            </Section>

            <Section title={t('task_panel.time')}>
                <TaskTime panel={panel} />
            </Section>

            <Section
                title={t('task_panel.comments', {
                    count: panel.comments.length,
                })}
            >
                <TaskComments panel={panel} />
            </Section>

            <Section
                title={t('task_panel.watchers', {
                    count: panel.watchers.length,
                })}
            >
                {panel.watchers.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('task_panel.no_watchers')}
                    </p>
                ) : (
                    <p className="text-sm">
                        {panel.watchers
                            .map((watcher) => watcher.name)
                            .join(', ')}
                    </p>
                )}
            </Section>

            <Collapsible className="grid gap-3 border-t pt-5">
                <CollapsibleTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="justify-start px-0"
                    >
                        {t('task_panel.activity', {
                            count: panel.activity.length,
                        })}
                    </Button>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <TaskActivity items={panel.activity} />
                </CollapsibleContent>
            </Collapsible>

            <p className="text-xs text-muted-foreground">
                {t('task_panel.created', {
                    who: task.creator?.name ?? t('task_activity.system'),
                    date: formatDateTime(task.created_at),
                })}
            </p>

            {panel.can.move ? (
                <MoveTaskDialog
                    panel={panel}
                    open={moving}
                    onOpenChange={setMoving}
                />
            ) : null}

            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                trigger={<span hidden />}
                title={t('task_panel.delete_title')}
                description={
                    panel.subtasks.length > 0
                        ? t('task_panel.delete_description_subtasks', {
                              count: panel.subtasks.length,
                          })
                        : t('task_panel.delete_description')
                }
                confirmLabel={t('task_panel.delete')}
                processing={deleting}
                onConfirm={() =>
                    router.delete(destroyTask.url(task.id), {
                        preserveScroll: true,
                        onStart: () => setDeleting(true),
                        // El servidor vuelve a la lista sin ?tarea= (o al panel de la tarea padre).
                        onSuccess: () => setConfirmDelete(false),
                        onError: (errors) => toastErrors(errors),
                        onFinish: () => setDeleting(false),
                    })
                }
            />
        </div>
    );
}

/**
 * Panel lateral de la tarea (SPEC §6: edición completa sin páginas nuevas). Se abre con
 * ?tarea={id}: la prop `panel` llega con una recarga parcial; mientras tanto, un esqueleto.
 */
export function TaskPanel({
    panel,
    loading,
    closing = false,
    onOpen,
    onClose,
}: {
    panel: TaskPanelData | null;
    loading: boolean;
    /** Se está cerrando: se oculta ya, sin esperar a la respuesta del servidor. */
    closing?: boolean;
    onOpen: (taskId: number) => void;
    onClose: () => void;
}) {
    const open = (panel !== null && !closing) || loading;
    const parent = panel?.parent ?? null;

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
                className="w-full gap-0 overflow-y-auto outline-none sm:max-w-2xl"
                tabIndex={-1}
                // Al abrir, el foco va al panel y no al título: así una tecla no lo sobrescribe.
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    (event.target as HTMLElement | null)?.focus({
                        preventScroll: true,
                    });
                }}
                onEscapeKeyDown={(event) => {
                    // Escape cierra primero lo que se está escribiendo (sugerencias de menciones,
                    // un texto enriquecido o un campo con cambios sin guardar) y no el panel.
                    const active = document.activeElement;

                    if (
                        document.querySelector('[data-mention-popup]') ||
                        (active instanceof HTMLElement &&
                            (active.closest('[data-rich-text]') !== null ||
                                active.dataset.dirty === 'true'))
                    ) {
                        event.preventDefault();
                    }
                }}
                data-test="task-panel"
            >
                <SheetHeader className="gap-1 pr-12">
                    {panel ? (
                        <>
                            <SheetDescription asChild>
                                <p className="flex flex-wrap items-center gap-1 text-xs">
                                    <span>{panel.project.code}</span>
                                    {parent ? (
                                        <>
                                            <span aria-hidden="true">/</span>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    onOpen(parent.id)
                                                }
                                                className={cn(
                                                    'inline-flex items-center gap-0.5 rounded-md hover:underline',
                                                    FOCUS_RING,
                                                )}
                                            >
                                                <ChevronLeft
                                                    aria-hidden="true"
                                                    className="size-3"
                                                />
                                                {t('task_panel.parent', {
                                                    task: parent.title,
                                                })}
                                            </button>
                                        </>
                                    ) : null}
                                </p>
                            </SheetDescription>
                            <SheetTitle className="sr-only">
                                {panel.task.title}
                            </SheetTitle>
                            {panel.can.update ? (
                                <TitleField key={panel.task.id} panel={panel} />
                            ) : (
                                <p aria-hidden="true" className="text-lg">
                                    {panel.task.title}
                                </p>
                            )}
                            {panel.source_message ? (
                                <Link
                                    href={urls.chatMessage(
                                        panel.source_message.conversation_id,
                                        panel.source_message.message_id,
                                    )}
                                    className={cn(
                                        'inline-flex w-fit items-center gap-1 rounded-md text-xs text-primary-text hover:underline',
                                        FOCUS_RING,
                                    )}
                                    data-test="task-source-message"
                                >
                                    <MessageSquare
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                    {t('chat.task.from_message')} ·{' '}
                                    {t('chat.task.view_message')}
                                </Link>
                            ) : null}
                        </>
                    ) : (
                        <>
                            <SheetTitle className="sr-only">
                                {t('task_panel.loading')}
                            </SheetTitle>
                            <SheetDescription className="sr-only">
                                {t('task_panel.loading')}
                            </SheetDescription>
                        </>
                    )}
                </SheetHeader>
                {panel ? (
                    <PanelBody
                        key={panel.task.id}
                        panel={panel}
                        onOpen={onOpen}
                    />
                ) : (
                    <PanelSkeleton />
                )}
            </SheetContent>
        </Sheet>
    );
}
