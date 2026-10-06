import { Link, router } from '@inertiajs/react';
import { ArrowLeft, History, Pencil, Trash2 } from 'lucide-react';
import { useId, useState } from 'react';
import { Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { RichTextContent } from '@/components/rich-text/rich-text-content';
import {
    CommentForm,
    CommentThread,
} from '@/components/suggestions/suggestion-comments';
import { SuggestionComposer } from '@/components/suggestions/suggestion-composer';
import {
    AttachmentList,
    statusLabel,
    SuggestionStatusBadge,
    VoteButton,
} from '@/components/suggestions/suggestion-ui';
import { UserAvatar } from '@/components/tasks/task-fields';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useRequiredUser } from '@/hooks/use-auth';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import {
    buildTimeline,
    SUGGESTION_STATUSES,
    suggestionParams,
} from '@/lib/suggestions';
import type { SuggestionQuery } from '@/lib/suggestions';
import { index as helpIndex } from '@/routes/help';
import { destroy as destroyRoute } from '@/routes/suggestions';
import { update as statusRoute } from '@/routes/suggestions/status';
import type {
    SuggestionPostDetail,
    SuggestionStatus,
    SuggestionsTabProps,
} from '@/types/weeklies';

/** Cambiar el estado con una nota oficial (F-167): solo quien gestiona. */
function ModerationPanel({ post }: { post: SuggestionPostDetail }) {
    const id = useId();
    const [status, setStatus] = useState<SuggestionStatus>(post.status);
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [source, setSource] = useState(post.status);

    if (source !== post.status) {
        setSource(post.status);
        setStatus(post.status);
    }

    return (
        <section
            aria-labelledby={`${id}-title`}
            className="grid gap-3 border p-4"
            data-test="suggestion-moderation"
        >
            <h2 id={`${id}-title`} className="text-sm font-medium">
                {t('suggestions.moderation.title')}
            </h2>
            <form
                className="grid gap-3"
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    router.put(
                        statusRoute.url(post.id),
                        { status, note },
                        {
                            preserveScroll: true,
                            preserveState: true,
                            only: ['suggestions'],
                            onStart: () => setProcessing(true),
                            onFinish: () => setProcessing(false),
                            onError: setErrors,
                            onSuccess: () => {
                                setNote('');
                                setErrors({});
                            },
                        },
                    );
                }}
            >
                <Field
                    id={`${id}-status`}
                    label={t('suggestions.moderation.status')}
                    error={errors.status}
                >
                    <NativeSelect
                        id={`${id}-status`}
                        value={status}
                        onChange={(event) =>
                            setStatus(event.target.value as SuggestionStatus)
                        }
                    >
                        {SUGGESTION_STATUSES.map((item) => (
                            <option key={item} value={item}>
                                {statusLabel(item)}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field
                    id={`${id}-note`}
                    label={t('suggestions.moderation.note')}
                    optional={t('weeklies.common.optional')}
                    help={t('suggestions.moderation.note_help')}
                    error={errors.note}
                >
                    <Textarea
                        id={`${id}-note`}
                        rows={3}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                    />
                </Field>
                <div>
                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        {t('suggestions.moderation.save')}
                    </Button>
                </div>
            </form>
        </section>
    );
}

function DeletePost({ post }: { post: SuggestionPostDetail }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={setOpen}
            trigger={
                <Button variant="secondary" size="sm">
                    <Trash2 aria-hidden="true" />
                    {t('common.delete')}
                </Button>
            }
            title={t('suggestions.delete_title')}
            description={t('suggestions.delete_description')}
            confirmLabel={t('common.delete')}
            processing={processing}
            onConfirm={() =>
                router.delete(destroyRoute.url(post.id), {
                    onStart: () => setProcessing(true),
                    onFinish: () => setProcessing(false),
                    // El servidor vuelve al feed.
                    onSuccess: () => setOpen(false),
                })
            }
        />
    );
}

/**
 * El detalle de una sugerencia (F-163 a F-167): votar, el estado y la categoría, editar o eliminar
 * (su autor o quien gestiona), el detalle con sus adjuntos, la actividad (comentarios con respuestas
 * y cambios de estado con su nota oficial), quién ha votado y, para quien gestiona, el estado.
 */
export function SuggestionDetail({
    data,
    post,
    query,
    attachmentMaxMb,
}: {
    data: SuggestionsTabProps;
    post: SuggestionPostDetail;
    query: SuggestionQuery;
    attachmentMaxMb: number;
}) {
    const id = useId();
    const me = useRequiredUser();
    const [editing, setEditing] = useState(false);
    const back = helpIndex.url({ query: suggestionParams(query) });
    const timeline = buildTimeline(post.comments, post.status_events);

    return (
        <div className="grid gap-6">
            <div>
                <Button variant="ghost" size="sm" asChild>
                    <Link
                        href={back}
                        only={['suggestions', 'tab']}
                        preserveState
                    >
                        <ArrowLeft aria-hidden="true" />
                        {query.view === 'roadmap'
                            ? t('suggestions.back_roadmap')
                            : t('suggestions.back_feedback')}
                    </Link>
                </Button>
            </div>
            <div className="grid gap-6 lg:grid-cols-[1fr_18rem]">
                <div className="grid content-start gap-6">
                    <article
                        aria-labelledby={`${id}-title`}
                        className="grid gap-4 border p-4"
                    >
                        <div className="flex gap-3">
                            <VoteButton
                                postId={post.id}
                                title={post.title}
                                count={post.vote_count}
                                voted={post.voted_by_me}
                                disabled={!post.can.interact}
                            />
                            <div className="grid min-w-0 flex-1 gap-2">
                                <h2
                                    id={`${id}-title`}
                                    className="text-xl font-normal break-words"
                                >
                                    {post.title}
                                </h2>
                                <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                    <SuggestionStatusBadge
                                        status={post.status}
                                    />
                                    {post.category ? (
                                        <span className="border px-1.5 py-0.5">
                                            {post.category.name}
                                        </span>
                                    ) : null}
                                    <span>{post.board.name}</span>
                                    <span className="inline-flex items-center gap-1.5">
                                        <UserAvatar user={post.author} />
                                        {post.author.name} ·{' '}
                                        {formatDateTime(post.created_at)}
                                    </span>
                                </div>
                            </div>
                            {post.can.update || post.can.delete ? (
                                <div className="flex shrink-0 flex-wrap items-start gap-2">
                                    {post.can.update ? (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            onClick={() => setEditing(true)}
                                        >
                                            <Pencil aria-hidden="true" />
                                            {t('common.edit')}
                                        </Button>
                                    ) : null}
                                    {post.can.delete ? (
                                        <DeletePost post={post} />
                                    ) : null}
                                </div>
                            ) : null}
                        </div>
                        <RichTextContent html={post.body} />
                        {post.attachments.length > 0 ? (
                            <section className="grid gap-2">
                                <h3 className="text-sm font-medium">
                                    {t('suggestions.attachments')}
                                </h3>
                                <AttachmentList
                                    attachments={post.attachments}
                                />
                            </section>
                        ) : null}
                    </article>

                    <section
                        aria-labelledby={`${id}-activity`}
                        className="grid gap-4"
                    >
                        <h2
                            id={`${id}-activity`}
                            className="text-base font-medium"
                        >
                            {t('suggestions.activity')}
                        </h2>
                        {timeline.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('suggestions.activity_empty')}
                            </p>
                        ) : (
                            <ol
                                className="grid gap-5"
                                data-test="suggestion-activity"
                            >
                                {timeline.map((item) =>
                                    item.kind === 'status' ? (
                                        <li
                                            key={`s-${item.event.id}`}
                                            className="grid gap-1 border-l-2 border-primary pl-3 text-sm"
                                        >
                                            <p className="flex flex-wrap items-center gap-2">
                                                <History
                                                    aria-hidden="true"
                                                    className="size-4 text-muted-foreground"
                                                />
                                                {t(
                                                    'suggestions.status_changed',
                                                    {
                                                        name:
                                                            item.event
                                                                .changed_by
                                                                ?.name ??
                                                            t(
                                                                'suggestions.someone',
                                                            ),
                                                        status: statusLabel(
                                                            item.event
                                                                .to_status,
                                                        ),
                                                    },
                                                )}
                                                <time
                                                    className="text-xs text-muted-foreground"
                                                    dateTime={
                                                        item.event.created_at ??
                                                        undefined
                                                    }
                                                >
                                                    {formatDateTime(
                                                        item.event.created_at,
                                                    )}
                                                </time>
                                            </p>
                                            {item.event.note ? (
                                                <p className="whitespace-pre-line text-muted-foreground">
                                                    <span className="sr-only">
                                                        {t(
                                                            'suggestions.official_note',
                                                        )}
                                                        :{' '}
                                                    </span>
                                                    {item.event.note}
                                                </p>
                                            ) : null}
                                        </li>
                                    ) : (
                                        <li key={`c-${item.comment.id}`}>
                                            <CommentThread
                                                comment={item.comment}
                                                postId={post.id}
                                                meId={me.id}
                                                moderate={data.can.moderate}
                                                people={data.people}
                                                attachmentMaxMb={
                                                    attachmentMaxMb
                                                }
                                            />
                                        </li>
                                    ),
                                )}
                            </ol>
                        )}
                        {post.can.interact ? (
                            <div className="grid gap-2 border-t pt-4">
                                <h3 className="text-sm font-medium">
                                    {t('suggestions.comments.add')}
                                </h3>
                                <CommentForm
                                    postId={post.id}
                                    people={data.people}
                                    attachmentMaxMb={attachmentMaxMb}
                                    label={t('suggestions.comments.add')}
                                />
                            </div>
                        ) : (
                            <p
                                className="border-t pt-4 text-sm text-muted-foreground"
                                data-test="suggestion-hidden-board"
                            >
                                {t('suggestions.hidden_board')}
                            </p>
                        )}
                    </section>
                </div>
                <aside className="grid content-start gap-4">
                    <section
                        aria-labelledby={`${id}-voters`}
                        className="grid gap-2 border p-4"
                    >
                        <h2 id={`${id}-voters`} className="text-sm font-medium">
                            {t('suggestions.voters', {
                                count: post.voters.length,
                            })}
                        </h2>
                        {post.voters.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('suggestions.no_voters')}
                            </p>
                        ) : (
                            <ul
                                className="grid gap-1.5 text-sm"
                                data-test="suggestion-voters"
                            >
                                {post.voters.map((voter) => (
                                    <li
                                        key={voter.id}
                                        className="flex items-center gap-2"
                                    >
                                        <UserAvatar user={voter} />
                                        {voter.name}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                    {post.can.moderate ? <ModerationPanel post={post} /> : null}
                </aside>
            </div>
            {post.can.update ? (
                <SuggestionComposer
                    data={data}
                    post={post}
                    mode="default"
                    open={editing}
                    onOpenChange={setEditing}
                    attachmentMaxMb={attachmentMaxMb}
                />
            ) : null}
        </div>
    );
}
