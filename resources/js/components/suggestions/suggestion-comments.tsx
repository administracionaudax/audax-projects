import { router } from '@inertiajs/react';
import {
    Eye,
    Heart,
    Pencil,
    Reply,
    Rocket,
    ThumbsUp,
    Trash2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { RichTextContent } from '@/components/rich-text/rich-text-content';
import RichTextEditor from '@/components/rich-text/rich-text-editor';
import {
    AttachmentList,
    PendingFiles,
} from '@/components/suggestions/suggestion-ui';
import { UserAvatar } from '@/components/tasks/task-fields';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import {
    filesFromClipboard,
    mergeFiles,
    summarizeReactions,
} from '@/lib/suggestions';
import { cn } from '@/lib/utils';
import {
    destroy as destroyComment,
    react as reactRoute,
    store as storeComment,
    update as updateComment,
} from '@/routes/suggestions/comments';
import type {
    SuggestionAttachment,
    SuggestionComment,
    SuggestionPerson,
    SuggestionReaction,
} from '@/types/weeklies';

const REACTION_ICONS: Record<SuggestionReaction, LucideIcon> = {
    thumbs_up: ThumbsUp,
    rocket: Rocket,
    eyes: Eye,
    heart: Heart,
};

/**
 * Escribir un comentario, una respuesta o editar uno (F-165): texto con formato y menciones @ con
 * autocompletado, adjuntos (elegirlos o pegarlos) y, al editar, quitar los que ya tenía.
 */
export function CommentForm({
    postId,
    parentId = null,
    comment = null,
    people,
    attachmentMaxMb,
    label,
    onDone,
    onCancel,
}: {
    postId: number;
    parentId?: number | null;
    comment?: SuggestionComment | null;
    people: SuggestionPerson[];
    attachmentMaxMb: number;
    label: string;
    onDone?: () => void;
    onCancel?: () => void;
}) {
    const [body, setBody] = useState(comment?.body ?? '');
    const [files, setFiles] = useState<File[]>([]);
    const [removed, setRemoved] = useState<number[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    // El editor se vuelve a montar vacío tras publicar.
    const [round, setRound] = useState(0);

    const submit = () => {
        const options = {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            only: ['suggestions'],
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (next: Record<string, string>) => setErrors(next),
            onSuccess: () => {
                setBody('');
                setFiles([]);
                setRemoved([]);
                setErrors({});
                setRound((current) => current + 1);
                onDone?.();
            },
        };

        if (comment) {
            router.post(
                updateComment.url(comment.id),
                { _method: 'put', body, files, remove_attachment_ids: removed },
                options,
            );
        } else {
            router.post(
                storeComment.url(postId),
                { body, parent_id: parentId, files },
                options,
            );
        }
    };

    const fileError = Object.entries(errors).find(([key]) =>
        key.startsWith('files'),
    )?.[1];

    return (
        <form
            className="grid gap-2"
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <div
                onPasteCapture={(event) => {
                    const pasted = filesFromClipboard(event.clipboardData);

                    if (pasted.length > 0) {
                        event.preventDefault();
                        event.stopPropagation();
                        setFiles((current) => mergeFiles(current, pasted));
                    }
                }}
            >
                <RichTextEditor
                    key={round}
                    value={body}
                    onChange={setBody}
                    mentionables={people}
                    aria-label={label}
                    placeholder={t('suggestions.comments.placeholder')}
                    onSubmit={submit}
                    autoFocus={comment !== null || parentId !== null}
                />
            </div>
            {errors.body ? (
                <p className="text-sm text-destructive">{errors.body}</p>
            ) : null}
            {errors.parent_id ? (
                <p className="text-sm text-destructive">{errors.parent_id}</p>
            ) : null}
            {comment ? (
                <AttachmentList
                    attachments={comment.attachments}
                    removed={removed}
                    onRemove={(id) => setRemoved((current) => [...current, id])}
                />
            ) : null}
            <PendingFiles
                files={files}
                onChange={setFiles}
                maxMegabytes={attachmentMaxMb}
                disabled={processing}
            />
            {fileError ? (
                <p className="text-sm text-destructive">{fileError}</p>
            ) : null}
            <div className="flex flex-wrap justify-end gap-2">
                {onCancel ? (
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        onClick={onCancel}
                        disabled={processing}
                    >
                        {t('common.cancel')}
                    </Button>
                ) : null}
                <Button type="submit" size="sm" disabled={processing}>
                    {processing && <Spinner />}
                    {comment
                        ? t('common.save')
                        : parentId !== null
                          ? t('suggestions.comments.reply_submit')
                          : t('suggestions.comments.submit')}
                </Button>
            </div>
        </form>
    );
}

function Reactions({
    comment,
    meId,
}: {
    comment: SuggestionComment;
    meId: number;
}) {
    const summary = summarizeReactions(comment.reactions, meId);

    return (
        <div
            className="flex flex-wrap gap-1.5"
            role="group"
            aria-label={t('suggestions.reactions.label')}
        >
            {summary.map((item) => {
                const Icon = REACTION_ICONS[item.reaction];
                const name = t(`suggestions.reaction.${item.reaction}`);

                return (
                    <button
                        key={item.reaction}
                        type="button"
                        aria-pressed={item.mine}
                        title={
                            item.names.length > 0 ? item.names.join(', ') : name
                        }
                        aria-label={t('suggestions.reactions.button', {
                            reaction: name,
                            count: item.count,
                            names: item.names.join(', '),
                        })}
                        onClick={() =>
                            router.post(
                                reactRoute.url(comment.id),
                                { reaction: item.reaction },
                                {
                                    preserveScroll: true,
                                    preserveState: true,
                                    only: ['suggestions'],
                                },
                            )
                        }
                        className={cn(
                            'inline-flex h-7 items-center gap-1 border px-2 text-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            item.mine
                                ? 'border-primary bg-accent text-primary-text'
                                : 'hover:bg-accent',
                        )}
                        data-test={`reaction-${item.reaction}`}
                    >
                        <Icon
                            aria-hidden="true"
                            className={cn(
                                'size-3.5',
                                item.mine && 'fill-current',
                            )}
                        />
                        {item.count > 0 ? (
                            <span className="tabular">{item.count}</span>
                        ) : null}
                    </button>
                );
            })}
        </div>
    );
}

function DeleteComment({ comment }: { comment: SuggestionComment }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={setOpen}
            trigger={
                <Button variant="ghost" size="sm" className="h-7 px-2">
                    <Trash2 aria-hidden="true" />
                    {t('common.delete')}
                </Button>
            }
            title={t('suggestions.comments.delete_title')}
            description={t('suggestions.comments.delete_description')}
            confirmLabel={t('common.delete')}
            processing={processing}
            onConfirm={() =>
                router.delete(destroyComment.url(comment.id), {
                    preserveScroll: true,
                    preserveState: true,
                    only: ['suggestions'],
                    onStart: () => setProcessing(true),
                    onFinish: () => setProcessing(false),
                    onSuccess: () => setOpen(false),
                })
            }
        />
    );
}

/**
 * Un comentario con sus respuestas anidadas (F-165) y sus reacciones (F-166): responder, editar (su
 * autor) y eliminar (su autor o quien gestiona, con todas sus respuestas).
 */
export function CommentThread({
    comment,
    postId,
    meId,
    moderate,
    people,
    attachmentMaxMb,
    depth = 0,
}: {
    comment: SuggestionComment;
    postId: number;
    meId: number;
    moderate: boolean;
    people: SuggestionPerson[];
    attachmentMaxMb: number;
    depth?: number;
}) {
    const [replying, setReplying] = useState(false);
    const [editing, setEditing] = useState(false);
    const mine = comment.author.id === meId;

    return (
        <article
            className={cn('grid gap-2', depth > 0 && 'border-l pl-4')}
            data-test="suggestion-comment"
        >
            <header className="flex flex-wrap items-center gap-2 text-sm">
                <UserAvatar user={comment.author} />
                <span className="font-medium">{comment.author.name}</span>
                <time
                    className="text-xs text-muted-foreground"
                    dateTime={comment.created_at ?? undefined}
                >
                    {formatDateTime(comment.created_at)}
                </time>
                {comment.edited_at ? (
                    <span className="text-xs text-muted-foreground">
                        {t('suggestions.comments.edited')}
                    </span>
                ) : null}
            </header>
            {editing ? (
                <CommentForm
                    postId={postId}
                    comment={comment}
                    people={people}
                    attachmentMaxMb={attachmentMaxMb}
                    label={t('suggestions.comments.edit_label')}
                    onDone={() => setEditing(false)}
                    onCancel={() => setEditing(false)}
                />
            ) : (
                <>
                    {comment.body.trim() !== '' ? (
                        <RichTextContent html={comment.body} />
                    ) : null}
                    <AttachmentList
                        attachments={
                            comment.attachments as SuggestionAttachment[]
                        }
                    />
                </>
            )}
            <div className="flex flex-wrap items-center gap-2">
                <Reactions comment={comment} meId={meId} />
                <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 px-2"
                    aria-expanded={replying}
                    onClick={() => setReplying((current) => !current)}
                >
                    <Reply aria-hidden="true" />
                    {t('suggestions.comments.reply')}
                </Button>
                {mine && !editing ? (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 px-2"
                        onClick={() => setEditing(true)}
                    >
                        <Pencil aria-hidden="true" />
                        {t('common.edit')}
                    </Button>
                ) : null}
                {mine || moderate ? <DeleteComment comment={comment} /> : null}
            </div>
            {replying ? (
                <CommentForm
                    postId={postId}
                    parentId={comment.id}
                    people={people}
                    attachmentMaxMb={attachmentMaxMb}
                    label={t('suggestions.comments.reply_label', {
                        name: comment.author.name,
                    })}
                    onDone={() => setReplying(false)}
                    onCancel={() => setReplying(false)}
                />
            ) : null}
            {comment.replies.length > 0 ? (
                <ul className="grid gap-4">
                    {comment.replies.map((reply) => (
                        <li key={reply.id}>
                            <CommentThread
                                comment={reply}
                                postId={postId}
                                meId={meId}
                                moderate={moderate}
                                people={people}
                                attachmentMaxMb={attachmentMaxMb}
                                depth={Math.min(depth + 1, 4)}
                            />
                        </li>
                    ))}
                </ul>
            ) : null}
        </article>
    );
}
