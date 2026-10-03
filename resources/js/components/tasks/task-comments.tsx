import { router } from '@inertiajs/react';
import { MessageSquarePlus, Pencil, SmilePlus, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { RichTextContent } from '@/components/rich-text/rich-text-content';
import { LazyRichTextEditor } from '@/components/tasks/lazy-rich-text-editor';
import {
    AttachmentList,
    checkFiles,
    FilePickerButton,
    formatBytes,
} from '@/components/tasks/task-attachments';
import { UserAvatar } from '@/components/tasks/task-fields';
import { useTaskLookups } from '@/components/tasks/task-lookups';
import { toastErrors } from '@/components/tasks/task-requests';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import {
    destroy as destroyComment,
    react as reactToComment,
    store as storeComment,
    update as updateComment,
} from '@/routes/tasks/comments';
import type { TaskCommentItem, TaskPanelData } from '@/types';

const PANEL_ONLY = {
    preserveScroll: true,
    preserveState: true,
    only: ['panel'],
};

function isBlank(html: string): boolean {
    return (
        html.replace(/<[^>]*>/g, '').trim() === '' &&
        !html.includes('data-type="mention"')
    );
}

function Reactions({
    comment,
    emojis,
}: {
    comment: TaskCommentItem;
    emojis: string[];
}) {
    const [open, setOpen] = useState(false);

    const toggle = (emoji: string) => {
        setOpen(false);
        router.post(
            reactToComment.url(comment.id),
            { emoji },
            {
                ...PANEL_ONLY,
                onError: (errors) => toastErrors(errors),
            },
        );
    };

    return (
        <div
            className="flex flex-wrap items-center gap-1.5"
            data-test="reactions"
        >
            {comment.reactions.map((reaction) => (
                <button
                    key={reaction.emoji}
                    type="button"
                    onClick={() => toggle(reaction.emoji)}
                    aria-pressed={reaction.reacted}
                    aria-label={t(
                        reaction.reacted
                            ? 'task_comments.reaction_remove'
                            : 'task_comments.reaction_add_same',
                        {
                            emoji: reaction.emoji,
                            count: reaction.count,
                            people: reaction.users.join(', '),
                        },
                    )}
                    title={reaction.users.join(', ')}
                    className={cn(
                        'inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs',
                        reaction.reacted
                            ? 'border-primary bg-accent'
                            : 'bg-background hover:bg-accent',
                        FOCUS_RING,
                    )}
                >
                    <span aria-hidden="true">{reaction.emoji}</span>
                    <span aria-hidden="true" className="tabular">
                        {reaction.count}
                    </span>
                </button>
            ))}
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        aria-label={t('task_comments.add_reaction')}
                    >
                        <SmilePlus aria-hidden="true" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    align="start"
                    className="flex w-auto gap-1 p-1.5"
                >
                    {emojis.map((emoji) => (
                        <button
                            key={emoji}
                            type="button"
                            onClick={() => toggle(emoji)}
                            aria-label={t('task_comments.react_with', {
                                emoji,
                            })}
                            className={cn(
                                'rounded-md p-1.5 text-lg leading-none hover:bg-accent',
                                FOCUS_RING,
                            )}
                        >
                            {emoji}
                        </button>
                    ))}
                </PopoverContent>
            </Popover>
        </div>
    );
}

function CommentItem({
    comment,
    emojis,
}: {
    comment: TaskCommentItem;
    emojis: string[];
}) {
    const lookups = useTaskLookups();
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(comment.body);
    const [saving, setSaving] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const author = comment.author?.name ?? t('task_comments.former_user');

    const save = () => {
        if (isBlank(draft)) {
            toast.error(t('task_comments.empty'));

            return;
        }

        router.patch(
            updateComment.url(comment.id),
            { body: draft },
            {
                ...PANEL_ONLY,
                onStart: () => setSaving(true),
                onSuccess: () => setEditing(false),
                onError: (errors) => toastErrors(errors),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <li className="flex gap-3" data-test="comment">
            {comment.author ? (
                <UserAvatar user={comment.author} className="mt-0.5 size-8" />
            ) : (
                <span
                    aria-hidden="true"
                    className="mt-0.5 size-8 shrink-0 rounded-full bg-neutral-soft"
                />
            )}
            <article
                className="grid min-w-0 flex-1 gap-2"
                aria-label={t('task_comments.comment_by', { name: author })}
            >
                <header className="flex flex-wrap items-baseline gap-x-2 text-sm">
                    <span className="font-medium">{author}</span>
                    {comment.created_at ? (
                        <time
                            dateTime={comment.created_at}
                            className="text-xs text-muted-foreground"
                        >
                            {formatDateTime(comment.created_at)}
                        </time>
                    ) : null}
                    {comment.edited_at ? (
                        <span className="text-xs text-muted-foreground">
                            {t('task_comments.edited')}
                        </span>
                    ) : null}
                </header>
                {editing ? (
                    <div className="grid gap-2">
                        <LazyRichTextEditor
                            value={comment.body}
                            onChange={setDraft}
                            mentionables={lookups.users}
                            aria-label={t('task_comments.edit_label')}
                            autoFocus
                            onSubmit={save}
                        />
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setEditing(false)}
                                disabled={saving}
                            >
                                {t('common.cancel')}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                onClick={save}
                                disabled={saving}
                            >
                                {saving ? <Spinner /> : null}
                                {t('common.save')}
                            </Button>
                        </div>
                    </div>
                ) : comment.body !== '' ? (
                    <RichTextContent html={comment.body} />
                ) : null}
                <AttachmentList attachments={comment.attachments} />
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Reactions comment={comment} emojis={emojis} />
                    {!editing && (comment.can_update || comment.can_delete) ? (
                        <div className="flex gap-1">
                            {comment.can_update ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        setDraft(comment.body);
                                        setEditing(true);
                                    }}
                                    aria-label={t('task_comments.edit_label')}
                                >
                                    <Pencil aria-hidden="true" />
                                    {t('common.edit')}
                                </Button>
                            ) : null}
                            {comment.can_delete ? (
                                <ConfirmDialog
                                    open={confirming}
                                    onOpenChange={setConfirming}
                                    trigger={
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            aria-label={t(
                                                'task_comments.delete_label',
                                            )}
                                        >
                                            <Trash2 aria-hidden="true" />
                                            {t('common.delete')}
                                        </Button>
                                    }
                                    title={t('task_comments.delete_title')}
                                    description={t(
                                        'task_comments.delete_description',
                                    )}
                                    confirmLabel={t('common.delete')}
                                    processing={saving}
                                    onConfirm={() =>
                                        router.delete(
                                            destroyComment.url(comment.id),
                                            {
                                                ...PANEL_ONLY,
                                                onStart: () => setSaving(true),
                                                onError: (errors) =>
                                                    toastErrors(errors),
                                                onFinish: () => {
                                                    setSaving(false);
                                                    setConfirming(false);
                                                },
                                            },
                                        )
                                    }
                                />
                            ) : null}
                        </div>
                    ) : null}
                </div>
            </article>
        </li>
    );
}

function CommentComposer({ panel }: { panel: TaskPanelData }) {
    const lookups = useTaskLookups();
    const [open, setOpen] = useState(false);
    const [body, setBody] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [processing, setProcessing] = useState(false);
    // Clave del editor: al publicar se vacía montando uno nuevo.
    const [round, setRound] = useState(0);

    const submit = () => {
        if (isBlank(body) && files.length === 0) {
            toast.error(t('task_comments.empty'));

            return;
        }

        router.post(
            storeComment.url(panel.task.id),
            { body, files },
            {
                ...PANEL_ONLY,
                only: ['panel', 'tasks'],
                forceFormData: files.length > 0,
                onStart: () => setProcessing(true),
                onSuccess: () => {
                    setBody('');
                    setFiles([]);
                    setRound((value) => value + 1);
                },
                onError: (errors) => toastErrors(errors),
                onHttpException: () => {
                    toast.error(t('task_errors.server'));

                    return false;
                },
                onNetworkError: () => {
                    toast.error(t('task_errors.network'));

                    return false;
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className={cn(
                    'flex w-full items-center gap-2 rounded-md border border-input px-3 py-2 text-left text-sm text-muted-foreground hover:bg-accent',
                    FOCUS_RING,
                )}
                data-test="comment-composer-open"
            >
                <MessageSquarePlus aria-hidden="true" className="size-4" />
                {t('task_comments.placeholder')}
            </button>
        );
    }

    return (
        <div className="grid gap-2">
            <LazyRichTextEditor
                key={round}
                value=""
                onChange={setBody}
                mentionables={lookups.users}
                placeholder={t('task_comments.editor_placeholder')}
                aria-label={t('task_comments.new_label')}
                autoFocus
                onSubmit={submit}
            />
            {files.length > 0 ? (
                <ul
                    className="flex flex-wrap gap-2"
                    aria-label={t('task_comments.files_to_upload')}
                >
                    {files.map((file, index) => (
                        <li
                            key={`${file.name}-${index}`}
                            className="inline-flex items-center gap-1 rounded-md border bg-muted px-2 py-0.5 text-xs"
                        >
                            {file.name} · {formatBytes(file.size)}
                            <button
                                type="button"
                                onClick={() =>
                                    setFiles((current) =>
                                        current.filter((_, i) => i !== index),
                                    )
                                }
                                aria-label={t('task_comments.remove_file', {
                                    name: file.name,
                                })}
                                className={cn(
                                    'rounded-md p-0.5 hover:bg-accent',
                                    FOCUS_RING,
                                )}
                            >
                                <X aria-hidden="true" className="size-3" />
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <FilePickerButton
                    variant="ghost"
                    label={t('task_comments.attach')}
                    disabled={processing}
                    onPick={(picked) => {
                        const next = [...files, ...picked];
                        const error = checkFiles(next, lookups.maxAttachmentMb);

                        if (error) {
                            toast.error(error);

                            return;
                        }

                        setFiles(next);
                    }}
                />
                <div className="flex items-center gap-2">
                    <span className="hidden text-xs text-muted-foreground sm:inline">
                        {t('task_comments.shortcut')}
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setOpen(false);
                            setBody('');
                            setFiles([]);
                        }}
                        disabled={processing}
                    >
                        {t('common.cancel')}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        onClick={submit}
                        disabled={processing}
                        data-test="comment-submit"
                    >
                        {processing ? <Spinner /> : null}
                        {t('task_comments.submit')}
                    </Button>
                </div>
            </div>
        </div>
    );
}

/**
 * Comentarios de la tarea (SPEC §6): con menciones (@), adjuntos y reacciones. Cada persona
 * edita los suyos; los borra ella o un admin.
 */
export function TaskComments({ panel }: { panel: TaskPanelData }) {
    return (
        <div className="grid gap-4">
            {panel.comments.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('task_comments.empty_list')}
                </p>
            ) : (
                <ol className="grid gap-5" aria-label={t('task_comments.list')}>
                    {panel.comments.map((comment) => (
                        <CommentItem
                            key={comment.id}
                            comment={comment}
                            emojis={panel.reaction_emojis}
                        />
                    ))}
                </ol>
            )}
            {panel.can.comment ? <CommentComposer panel={panel} /> : null}
        </div>
    );
}
