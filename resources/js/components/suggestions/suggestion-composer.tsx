import { Link, router } from '@inertiajs/react';
import { Bug } from 'lucide-react';
import { useEffect, useId, useMemo, useState } from 'react';
import { Field } from '@/components/admin/field';
import { NativeSelect } from '@/components/admin/native-select';
import RichTextEditor from '@/components/rich-text/rich-text-editor';
import {
    AttachmentList,
    PendingFiles,
    SuggestionStatusBadge,
} from '@/components/suggestions/suggestion-ui';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import {
    activeCategories,
    filesFromClipboard,
    mergeFiles,
} from '@/lib/suggestions';
import { xsrfToken } from '@/lib/xsrf';
import {
    show as showRoute,
    similar as similarRoute,
    store as storeRoute,
    update as updateRoute,
} from '@/routes/suggestions';
import type {
    SuggestionPost,
    SuggestionPostDetail,
    SuggestionsTabProps,
} from '@/types/weeklies';

/** Espera antes de buscar «similares» mientras se escribe el título (WeeklySync: 180 ms). */
export const SIMILAR_DEBOUNCE_MS = 250;

/** Sugerencias parecidas al título (F-161): las más votadas que lo contienen. */
function useSimilar(title: string, enabled: boolean): SuggestionPost[] {
    const [items, setItems] = useState<SuggestionPost[]>([]);
    const query = title.trim();

    useEffect(() => {
        if (!enabled || query.length < 3) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            const token = xsrfToken();
            fetch(similarRoute.url({ query: { q: query } }), {
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(token ? { 'X-XSRF-TOKEN': token } : {}),
                },
            })
                .then((response) =>
                    response.ok ? response.json() : { items: [] },
                )
                .then((body: { items: SuggestionPost[] }) =>
                    setItems(body.items ?? []),
                )
                .catch(() => undefined);
        }, SIMILAR_DEBOUNCE_MS);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query, enabled]);

    return enabled && query.length >= 3 ? items : [];
}

/**
 * Nueva sugerencia o editar una (F-161 y F-164): título, tablero y categoría, detalle con formato y
 * menciones, adjuntos (elegirlos o pegarlos en el detalle) y, al crear, las «sugerencias similares»
 * para no repetirlas. En modo «bug» (F-149) la categoría queda fijada en Bugs.
 */
export function SuggestionComposer({
    data,
    post,
    mode,
    open,
    onOpenChange,
    attachmentMaxMb,
}: {
    data: SuggestionsTabProps;
    post: SuggestionPostDetail | null;
    mode: 'default' | 'bug';
    open: boolean;
    onOpenChange: (open: boolean) => void;
    attachmentMaxMb: number;
}) {
    const id = useId();
    const categories = useMemo(
        () => activeCategories(data.boards),
        [data.boards],
    );
    const boards = data.boards.filter((board) => board.is_active);
    const bugs =
        mode === 'bug'
            ? categories.find(
                  (category) => category.id === data.bugs_category_id,
              )
            : undefined;
    const initial = () => {
        const current = data.filters.category
            ? categories.find(
                  (category) => category.id === data.filters.category,
              )
            : undefined;
        const category = post
            ? (post.suggestion_category_id ?? null)
            : (bugs?.id ?? current?.id ?? null);
        const board = post
            ? post.suggestion_board_id
            : (bugs?.board.id ??
              current?.board.id ??
              data.filters.board ??
              boards[0]?.id ??
              null);

        return {
            title: post?.title ?? '',
            body: post?.body ?? '',
            board: board === null ? '' : String(board),
            category: category === null ? '' : String(category),
        };
    };
    const [values, setValues] = useState(initial);
    const [files, setFiles] = useState<File[]>([]);
    const [removed, setRemoved] = useState<number[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [wasOpen, setWasOpen] = useState(open);
    const similar = useSimilar(values.title, open && post === null);

    // Al abrirse, empieza de cero (patrón de estado derivado, sin efectos).
    if (open !== wasOpen) {
        setWasOpen(open);

        if (open) {
            setValues(initial());
            setFiles([]);
            setRemoved([]);
            setErrors({});
        }
    }

    const boardCategories = categories.filter(
        (category) => String(category.board.id) === values.board,
    );

    const submit = () => {
        const payload = {
            title: values.title,
            body: values.body,
            suggestion_board_id:
                values.board === '' ? null : Number(values.board),
            suggestion_category_id:
                values.category === '' ? null : Number(values.category),
            files,
            remove_attachment_ids: removed,
        };
        const options = {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (next: Record<string, string>) => setErrors(next),
            onSuccess: () => onOpenChange(false),
        };

        if (post) {
            router.post(
                updateRoute.url(post.id),
                { ...payload, _method: 'put' },
                options,
            );
        } else {
            router.post(storeRoute.url(), payload, options);
        }
    };

    const fileError = Object.entries(errors).find(([key]) =>
        key.startsWith('files'),
    )?.[1];

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => (processing ? null : onOpenChange(next))}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form
                    className="grid gap-5"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {post
                                ? t('suggestions.composer.edit_title')
                                : mode === 'bug'
                                  ? t('suggestions.composer.bug_title')
                                  : t('suggestions.composer.title')}
                        </DialogTitle>
                        <DialogDescription>
                            {mode === 'bug'
                                ? t('suggestions.composer.bug_description')
                                : t('suggestions.composer.description')}
                        </DialogDescription>
                    </DialogHeader>
                    {mode === 'bug' && !post ? (
                        <p className="flex items-center gap-2 text-sm">
                            <Bug
                                aria-hidden="true"
                                className="size-4 text-warning"
                            />
                            {bugs
                                ? t('suggestions.composer.bug_category', {
                                      category: bugs.name,
                                  })
                                : t('suggestions.composer.bug_missing')}
                        </p>
                    ) : null}
                    <Field
                        id={`${id}-title`}
                        label={t('suggestions.composer.title_field')}
                        error={errors.title}
                    >
                        <Input
                            id={`${id}-title`}
                            value={values.title}
                            maxLength={200}
                            onChange={(event) =>
                                setValues((current) => ({
                                    ...current,
                                    title: event.target.value,
                                }))
                            }
                            aria-invalid={Boolean(errors.title)}
                            autoFocus
                        />
                    </Field>
                    {similar.length > 0 ? (
                        <section
                            aria-labelledby={`${id}-similar`}
                            className="grid gap-2 border p-3"
                            data-test="suggestion-similar"
                        >
                            <h3
                                id={`${id}-similar`}
                                className="text-sm font-medium"
                            >
                                {t('suggestions.composer.similar')}
                            </h3>
                            <ul className="grid gap-1.5">
                                {similar.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex items-center justify-between gap-2 text-sm"
                                    >
                                        <Link
                                            href={showRoute.url(item.id)}
                                            className="min-w-0 truncate text-primary-text underline"
                                            onClick={() => onOpenChange(false)}
                                        >
                                            {item.title}
                                        </Link>
                                        <span className="flex shrink-0 items-center gap-2 text-xs text-muted-foreground">
                                            {item.status !== 'open' ? (
                                                <SuggestionStatusBadge
                                                    status={item.status}
                                                />
                                            ) : null}
                                            {t('suggestions.votes', {
                                                count: item.vote_count,
                                            })}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ) : null}
                    {!(mode === 'bug' && bugs && !post) ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            {boards.length > 1 || post ? (
                                <Field
                                    id={`${id}-board`}
                                    label={t('suggestions.composer.board')}
                                    error={errors.suggestion_board_id}
                                >
                                    <NativeSelect
                                        id={`${id}-board`}
                                        value={values.board}
                                        onChange={(event) =>
                                            setValues((current) => ({
                                                ...current,
                                                board: event.target.value,
                                                category: '',
                                            }))
                                        }
                                    >
                                        {boards.map((board) => (
                                            <option
                                                key={board.id}
                                                value={board.id}
                                            >
                                                {board.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            ) : null}
                            <Field
                                id={`${id}-category`}
                                label={t('suggestions.composer.category')}
                                error={errors.suggestion_category_id}
                            >
                                <NativeSelect
                                    id={`${id}-category`}
                                    value={values.category}
                                    onChange={(event) =>
                                        setValues((current) => ({
                                            ...current,
                                            category: event.target.value,
                                        }))
                                    }
                                >
                                    <option value="">
                                        {t('suggestions.composer.no_category')}
                                    </option>
                                    {boardCategories.map((category) => (
                                        <option
                                            key={category.id}
                                            value={category.id}
                                        >
                                            {category.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                        </div>
                    ) : null}
                    <div
                        className="grid gap-2"
                        onPasteCapture={(event) => {
                            const pasted = filesFromClipboard(
                                event.clipboardData,
                            );

                            if (pasted.length > 0) {
                                event.preventDefault();
                                event.stopPropagation();
                                setFiles((current) =>
                                    mergeFiles(current, pasted),
                                );
                            }
                        }}
                    >
                        <span className="text-sm font-medium">
                            {t('suggestions.composer.body')}
                        </span>
                        <RichTextEditor
                            value={values.body}
                            onChange={(html) =>
                                setValues((current) => ({
                                    ...current,
                                    body: html,
                                }))
                            }
                            mentionables={data.people}
                            aria-label={t('suggestions.composer.body')}
                            placeholder={
                                mode === 'bug'
                                    ? t('suggestions.composer.bug_placeholder')
                                    : t('suggestions.composer.body_placeholder')
                            }
                        />
                        {errors.body ? (
                            <p className="text-sm text-destructive">
                                {errors.body}
                            </p>
                        ) : null}
                    </div>
                    {post ? (
                        <AttachmentList
                            attachments={post.attachments}
                            removed={removed}
                            onRemove={(attachment) =>
                                setRemoved((current) => [
                                    ...current,
                                    attachment,
                                ])
                            }
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
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={processing}
                            >
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={
                                processing || (mode === 'bug' && !post && !bugs)
                            }
                        >
                            {processing && <Spinner />}
                            {post
                                ? t('common.save')
                                : t('suggestions.composer.publish')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
