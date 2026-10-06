import { router } from '@inertiajs/react';
import { MessageSquare, Trash2 } from 'lucide-react';
import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { destroy } from '@/routes/day-plan/comments';
import { store } from '@/routes/day-plan/items/comments';
import type { DayPlanLine } from '@/types/day-plan';

/**
 * Comentarios de una línea (D-255): los ven la persona, su responsable y los admins (llegan a null
 * para el resto). Comentan su responsable y los admins; la persona puede contestar desde Mi día.
 */
export function LineComments({
    line,
    canComment,
}: {
    line: DayPlanLine;
    canComment: boolean;
}) {
    const id = useId();
    const comments = line.comments ?? [];
    const [open, setOpen] = useState(false);
    const [body, setBody] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    if (line.comments === null || (comments.length === 0 && !canComment)) {
        return null;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (body.trim() === '') {
            setError(t('day_plan.comments.errors.body'));

            return;
        }

        router.post(
            store.url(line.id),
            { body: body.trim() },
            {
                preserveScroll: true,
                preserveState: true,
                errorBag: 'dayPlan',
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setBody('');
                    setOpen(false);
                    setError(null);
                },
                onError: (errors) => setError(Object.values(errors).join(' ')),
            },
        );
    };

    return (
        <div className="grid gap-2 pl-6" data-test="day-plan-comments">
            {comments.length > 0 ? (
                <ul className="grid gap-1.5">
                    {comments.map((comment) => (
                        <li
                            key={comment.id}
                            className="flex items-start gap-2 border-l-2 pl-2 text-sm"
                            data-test="day-plan-comment"
                        >
                            <MessageSquare
                                aria-hidden="true"
                                className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                            />
                            <p className="min-w-0 flex-1 break-words">
                                <span className="font-medium">
                                    {comment.user.name}
                                </span>
                                {comment.created_at ? (
                                    <span className="text-xs text-muted-foreground">
                                        {' '}
                                        · {formatDateTime(comment.created_at)}
                                    </span>
                                ) : null}
                                <br />
                                {comment.body}
                            </p>
                            {comment.can_delete ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7"
                                    aria-label={t('day_plan.comments.delete')}
                                    onClick={() =>
                                        router.delete(destroy.url(comment.id), {
                                            preserveScroll: true,
                                            preserveState: true,
                                        })
                                    }
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : null}
            {canComment && !open ? (
                <Button
                    type="button"
                    variant="link"
                    size="sm"
                    className="h-auto self-start p-0 text-primary-text"
                    onClick={() => setOpen(true)}
                    data-test="day-plan-comment-open"
                >
                    {comments.length > 0
                        ? t('day_plan.comments.reply')
                        : t('day_plan.comments.add')}
                </Button>
            ) : null}
            {canComment && open ? (
                <form onSubmit={submit} className="grid gap-1.5">
                    <label htmlFor={`${id}-body`} className="sr-only">
                        {t('day_plan.comments.label', { text: line.text })}
                    </label>
                    <Textarea
                        id={`${id}-body`}
                        value={body}
                        rows={2}
                        maxLength={1000}
                        autoFocus
                        onChange={(event) => setBody(event.target.value)}
                        data-test="day-plan-comment-body"
                    />
                    <InputError message={error ?? undefined} />
                    <div className="flex gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            disabled={processing}
                            data-test="day-plan-comment-send"
                        >
                            {processing ? <Spinner /> : null}
                            {t('day_plan.comments.send')}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setOpen(false)}
                        >
                            {t('common.cancel')}
                        </Button>
                    </div>
                </form>
            ) : null}
        </div>
    );
}
