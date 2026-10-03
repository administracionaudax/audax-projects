import { Head, Link, router } from '@inertiajs/react';
import { FolderOpen, MessageSquare, Trash2 } from 'lucide-react';
import { useId, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { ProjectShell } from '@/components/projects/project-shell';
import { fileIcon, formatBytes } from '@/components/tasks/task-attachments';
import { NONE } from '@/components/tasks/task-fields';
import { toastErrors } from '@/components/tasks/task-requests';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { destroy as destroyAttachment } from '@/routes/attachments';
import { files as projectFiles } from '@/routes/projects';
import type { ProjectFilesPageProps, TaskAttachment } from '@/types';

function DeleteButton({ file }: { file: TaskAttachment }) {
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
                    size="icon"
                    className="size-8"
                    aria-label={t('task_files.delete', {
                        name: file.original_name,
                    })}
                >
                    <Trash2 aria-hidden="true" />
                </Button>
            }
            title={t('task_files.delete_title')}
            description={t('task_files.delete_description', {
                name: file.original_name,
            })}
            confirmLabel={t('common.delete')}
            processing={processing}
            onConfirm={() =>
                router.delete(destroyAttachment.url(file.id), {
                    preserveScroll: true,
                    preserveState: true,
                    only: ['files', 'pagination', 'tasks'],
                    onStart: () => setProcessing(true),
                    onError: (errors) => toastErrors(errors),
                    onFinish: () => {
                        setProcessing(false);
                        setOpen(false);
                    },
                })
            }
        />
    );
}

/**
 * Pestaña Archivos del proyecto (SPEC §6): todos los adjuntos de sus tareas, sus comentarios y su
 * chat (solo para quien ve el chat; D-118), con filtros por tipo y por tarea. Descarga con URL
 * firmada; los SVG y documentos se descargan. Los del chat enlazan a su mensaje.
 */
export default function ProjectFiles({
    project,
    canManage,
    files,
    pagination,
    filters,
    categories,
    tasks,
}: ProjectFilesPageProps) {
    const typeId = useId();
    const taskId = useId();
    const tableCaptionId = useId();

    const filter = (next: { type?: string | null; task?: number | null }) => {
        const type = next.type === undefined ? filters.type : next.type;
        const task = next.task === undefined ? filters.task : next.task;
        const query: Record<string, string> = {};

        if (type) {
            query.tipo = type;
        }

        if (task !== null) {
            query.tarea_id = String(task);
        }

        router.get(
            projectFiles.url(project.id, { query }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title={t('project_files.title', { project: project.name })} />

            <ProjectShell
                project={project}
                tab="archivos"
                canManage={canManage}
            >
                <div className="flex min-w-0 flex-col gap-6">
                    <div
                        className="flex flex-wrap items-end gap-3"
                        role="group"
                        aria-label={t('project_files.filters')}
                    >
                        <div className="grid gap-1">
                            <Label
                                htmlFor={typeId}
                                className="text-xs text-muted-foreground"
                            >
                                {t('project_files.type')}
                            </Label>
                            <Select
                                value={filters.type ?? NONE}
                                onValueChange={(value) =>
                                    filter({
                                        type: value === NONE ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    id={typeId}
                                    size="sm"
                                    className="w-full min-w-40 sm:w-48"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        {t('project_files.all_types')}
                                    </SelectItem>
                                    {categories.map((category) => (
                                        <SelectItem
                                            key={category}
                                            value={category}
                                        >
                                            {t(
                                                `project_files.category.${category}`,
                                            )}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1">
                            <Label
                                htmlFor={taskId}
                                className="text-xs text-muted-foreground"
                            >
                                {t('project_files.task')}
                            </Label>
                            <Select
                                value={
                                    filters.task === null
                                        ? NONE
                                        : String(filters.task)
                                }
                                onValueChange={(value) =>
                                    filter({
                                        task:
                                            value === NONE
                                                ? null
                                                : Number(value),
                                    })
                                }
                            >
                                <SelectTrigger
                                    id={taskId}
                                    size="sm"
                                    className="w-full min-w-40 sm:w-64"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        {t('project_files.all_tasks')}
                                    </SelectItem>
                                    {tasks.map((task) => (
                                        <SelectItem
                                            key={task.id}
                                            value={String(task.id)}
                                        >
                                            {task.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <p className="text-sm text-muted-foreground sm:ml-auto">
                            {t('project_files.count', {
                                count: pagination.total,
                            })}
                        </p>
                    </div>

                    {files.length === 0 ? (
                        <EmptyState
                            icon={FolderOpen}
                            title={
                                filters.type || filters.task !== null
                                    ? t('project_files.empty_filtered')
                                    : t('project_files.empty')
                            }
                            description={t('project_files.empty_description')}
                        />
                    ) : (
                        <div
                            className={cn(
                                'overflow-x-auto rounded-md border',
                                FOCUS_RING,
                            )}
                            role="region"
                            aria-labelledby={tableCaptionId}
                            tabIndex={0}
                        >
                            <table className="w-full min-w-[46rem] text-sm">
                                <caption
                                    id={tableCaptionId}
                                    className="sr-only"
                                >
                                    {t('project_files.caption', {
                                        project: project.name,
                                    })}
                                </caption>
                                <thead>
                                    <tr className="border-b text-left text-xs text-muted-foreground">
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('project_files.column.name')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('project_files.column.task')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 text-right font-medium"
                                        >
                                            {t('project_files.column.size')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('project_files.column.uploaded')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="w-12 px-3 py-2"
                                        >
                                            <span className="sr-only">
                                                {t('common.actions')}
                                            </span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {files.map((file) => {
                                        const Icon = fileIcon(file.mime);

                                        return (
                                            <tr
                                                key={file.id}
                                                className="border-b last:border-b-0"
                                                data-test="file-row"
                                            >
                                                <th
                                                    scope="row"
                                                    className="px-3 py-2 text-left font-normal"
                                                >
                                                    <div className="flex min-w-0 items-center gap-3">
                                                        {file.thumbnail_url ? (
                                                            <img
                                                                src={
                                                                    file.thumbnail_url
                                                                }
                                                                alt=""
                                                                loading="lazy"
                                                                className="size-9 shrink-0 rounded-md border object-cover"
                                                            />
                                                        ) : (
                                                            <span className="flex size-9 shrink-0 items-center justify-center rounded-md border bg-muted">
                                                                <Icon
                                                                    aria-hidden="true"
                                                                    className="size-4 text-muted-foreground"
                                                                />
                                                            </span>
                                                        )}
                                                        <a
                                                            href={file.url}
                                                            target={
                                                                file.is_image
                                                                    ? '_blank'
                                                                    : undefined
                                                            }
                                                            rel="noopener noreferrer"
                                                            download={
                                                                file.is_image
                                                                    ? undefined
                                                                    : file.original_name
                                                            }
                                                            className={cn(
                                                                'truncate rounded-md text-primary-text hover:underline',
                                                                FOCUS_RING,
                                                            )}
                                                        >
                                                            {file.original_name}
                                                        </a>
                                                    </div>
                                                </th>
                                                <td className="max-w-64 px-3 py-2">
                                                    {file.task ? (
                                                        <Link
                                                            href={urls.task(
                                                                project.id,
                                                                file.task.id,
                                                            )}
                                                            className={cn(
                                                                'block truncate rounded-md hover:underline',
                                                                FOCUS_RING,
                                                            )}
                                                        >
                                                            {file.task.title}
                                                        </Link>
                                                    ) : null}
                                                    {file.in_comment ? (
                                                        <span className="text-xs text-muted-foreground">
                                                            {t(
                                                                'project_files.in_comment',
                                                            )}
                                                        </span>
                                                    ) : null}
                                                    {file.message ? (
                                                        <Link
                                                            href={urls.chatMessage(
                                                                file.message
                                                                    .conversation_id,
                                                                file.message
                                                                    .message_id,
                                                            )}
                                                            className={cn(
                                                                'inline-flex items-center gap-1 rounded-md text-primary-text hover:underline',
                                                                FOCUS_RING,
                                                            )}
                                                            data-test="file-message-link"
                                                        >
                                                            <MessageSquare
                                                                aria-hidden="true"
                                                                className="size-3.5"
                                                            />
                                                            {file.message
                                                                .deleted
                                                                ? t(
                                                                      'project_files.in_chat_deleted',
                                                                  )
                                                                : t(
                                                                      'project_files.in_chat',
                                                                  )}
                                                        </Link>
                                                    ) : null}
                                                </td>
                                                <td className="tabular px-3 py-2 text-right whitespace-nowrap">
                                                    {formatBytes(file.size)}
                                                </td>
                                                <td className="px-3 py-2 text-xs text-muted-foreground">
                                                    {[
                                                        file.uploader?.name,
                                                        file.created_at
                                                            ? formatDate(
                                                                  file.created_at,
                                                              )
                                                            : null,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {file.can_delete ? (
                                                        <DeleteButton
                                                            file={file}
                                                        />
                                                    ) : null}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {pagination.last_page > 1 ? (
                        <nav
                            aria-label={t('project_files.pagination')}
                            className="flex items-center justify-between gap-3 text-sm"
                        >
                            {pagination.prev_url ? (
                                <Link
                                    href={pagination.prev_url}
                                    preserveScroll
                                    className={cn(
                                        'rounded-md text-primary-text underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    {t('project_files.previous')}
                                </Link>
                            ) : (
                                <span />
                            )}
                            <span className="text-muted-foreground">
                                {t('project_files.page', {
                                    page: pagination.current_page,
                                    pages: pagination.last_page,
                                })}
                            </span>
                            {pagination.next_url ? (
                                <Link
                                    href={pagination.next_url}
                                    preserveScroll
                                    className={cn(
                                        'rounded-md text-primary-text underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    {t('project_files.next')}
                                </Link>
                            ) : (
                                <span />
                            )}
                        </nav>
                    ) : null}
                </div>
            </ProjectShell>
        </>
    );
}

ProjectFiles.layout = (props: ProjectFilesPageProps) => ({
    breadcrumbs: [
        { title: t('nav.projects'), href: urls.projects() },
        { title: props.project.name, href: urls.project(props.project.id) },
        {
            title: t('project_tabs.files'),
            href: urls.project(props.project.id, 'archivos'),
        },
    ],
});
