import { Head, Link, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    CircleAlert,
    FileAudio,
    FolderKanban,
    MessageSquare,
    MessagesSquare,
    Paperclip,
    Search,
    User,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { HighlightedText } from '@/components/chat/media/text-match';
import type {
    ChatConversationType,
    ChatSearchMatch,
    ChatSearchPageProps,
    ChatSearchResult,
    ChatSearchType,
} from '@/components/chat/media/types';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as chatIndex, search as chatSearch } from '@/routes/chat';

const TYPES: { value: ChatSearchType | null; label: TranslationKey }[] = [
    { value: null, label: 'chat_media.search.type.all' },
    { value: 'mensajes', label: 'chat_media.search.type.messages' },
    { value: 'archivos', label: 'chat_media.search.type.files' },
    { value: 'audios', label: 'chat_media.search.type.audios' },
];

const MATCH: Record<
    ChatSearchMatch,
    { icon: LucideIcon; label: TranslationKey }
> = {
    message: {
        icon: MessageSquare,
        label: 'chat_media.search.match.message',
    },
    file: { icon: Paperclip, label: 'chat_media.search.match.file' },
    transcription: {
        icon: FileAudio,
        label: 'chat_media.search.match.transcription',
    },
};

const CONVERSATION: Record<ChatConversationType, LucideIcon> = {
    project: FolderKanban,
    group: Users,
    direct: User,
};

/** Query string de /chat/buscar (URL en español). */
function searchQuery(
    q: string,
    type: ChatSearchType | null,
    conversation: number | null,
    before?: number,
): Record<string, string> {
    const query: Record<string, string> = {};

    if (q.trim() !== '') {
        query.q = q.trim();
    }

    if (type) {
        query.tipo = type;
    }

    if (conversation) {
        query.conversacion = String(conversation);
    }

    if (before) {
        query.antes = String(before);
    }

    return query;
}

function ResultItem({
    result,
    query,
}: {
    result: ChatSearchResult;
    query: string;
}) {
    const match = MATCH[result.match];
    const ConversationIcon = CONVERSATION[result.conversation.type];

    return (
        <li>
            <Link
                href={result.url}
                className={cn(
                    'grid gap-1.5 rounded-[3px] border bg-card p-3 hover:bg-accent',
                    FOCUS_RING,
                )}
                data-test="chat-search-result"
            >
                <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                    <span className="inline-flex min-w-0 items-center gap-1 text-foreground">
                        <ConversationIcon
                            aria-hidden="true"
                            className="size-3.5 shrink-0"
                        />
                        <span className="truncate">
                            {result.conversation.code
                                ? `${result.conversation.code} · ${result.conversation.label}`
                                : result.conversation.label}
                        </span>
                    </span>
                    {result.author ? <span>{result.author}</span> : null}
                    {result.created_at ? (
                        <time dateTime={result.created_at}>
                            {formatDateTime(result.created_at)}
                        </time>
                    ) : null}
                    <span className="inline-flex items-center gap-1 rounded-[3px] bg-neutral-soft px-1.5 py-0.5 text-foreground">
                        <match.icon
                            aria-hidden="true"
                            className="size-3 text-muted-foreground"
                        />
                        {t(match.label)}
                    </span>
                </span>
                <span className="text-sm break-words text-foreground">
                    <HighlightedText text={result.excerpt} query={query} />
                </span>
            </Link>
        </li>
    );
}

/**
 * Búsqueda del chat (SPEC §12): mensajes, nombres de archivos y transcripciones de los audios de
 * las conversaciones que puedes ver, con el contexto resaltado. Cada resultado abre la
 * conversación en ese mensaje. Se registra con la página resources/js/pages/chat/buscar.tsx.
 */
export default function ChatSearchPage({
    query,
    filters,
    conversation,
    results: initialResults,
    next: initialNext,
    minLength,
}: ChatSearchPageProps) {
    const inputId = useId();
    const [text, setText] = useState(query);
    const [results, setResults] = useState(initialResults);
    const [next, setNext] = useState(initialNext);
    const [loadingMore, setLoadingMore] = useState(false);
    const [moreError, setMoreError] = useState(false);
    const [previous, setPrevious] = useState(initialResults);
    // Cada búsqueda (visita de Inertia) tiene su número: un «Ver más» de una búsqueda anterior que
    // responde tarde se descarta en lugar de mezclarse con los resultados nuevos.
    const generation = useRef(0);
    const pendingMore = useRef<AbortController | null>(null);

    // Una búsqueda nueva (visita de Inertia) sustituye a lo que se hubiera cargado con «Ver más».
    if (previous !== initialResults) {
        setPrevious(initialResults);
        setResults(initialResults);
        setNext(initialNext);
        setMoreError(false);
        setLoadingMore(false);
    }

    useEffect(() => {
        generation.current += 1;
        pendingMore.current?.abort();
        pendingMore.current = null;
    }, [initialResults]);

    useEffect(() => () => pendingMore.current?.abort(), []);

    const tooShort = query.trim().length < minLength;

    const visit = (
        q: string,
        type: ChatSearchType | null,
        conversationId: number | null,
    ) => {
        router.get(
            chatSearch.url({ query: searchQuery(q, type, conversationId) }),
            {},
            { preserveScroll: false, replace: true },
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        visit(text, filters.type, filters.conversation);
    };

    const loadMore = async () => {
        if (next === null || pendingMore.current !== null) {
            return;
        }

        const mine = generation.current;
        const abort = new AbortController();
        pendingMore.current = abort;
        setLoadingMore(true);
        setMoreError(false);

        try {
            const response = await fetch(
                chatSearch.url({
                    query: searchQuery(
                        query,
                        filters.type,
                        filters.conversation,
                        next,
                    ),
                }),
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    signal: abort.signal,
                },
            );

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = (await response.json()) as {
                results: ChatSearchResult[];
                next: number | null;
            };

            if (mine !== generation.current) {
                return;
            }

            setResults((current) => [...current, ...data.results]);
            setNext(data.next);
        } catch {
            if (mine === generation.current) {
                setMoreError(true);
            }
        } finally {
            if (pendingMore.current === abort) {
                pendingMore.current = null;
            }

            if (mine === generation.current) {
                setLoadingMore(false);
            }
        }
    };

    return (
        <>
            <Head title={t('chat_media.search.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('chat_media.search.heading')}
                    description={t('chat_media.search.description')}
                />

                <form
                    role="search"
                    onSubmit={submit}
                    className="-mt-4 grid max-w-2xl gap-3"
                >
                    <Label htmlFor={inputId} className="sr-only">
                        {t('chat_media.search.label')}
                    </Label>
                    <div className="flex gap-2">
                        <Input
                            id={inputId}
                            type="search"
                            value={text}
                            onChange={(event) => setText(event.target.value)}
                            placeholder={t('chat_media.search.placeholder')}
                            maxLength={100}
                            autoFocus={query === ''}
                            data-test="chat-search-input"
                        />
                        <Button type="submit">
                            <Search aria-hidden="true" />
                            {t('chat_media.search.submit')}
                        </Button>
                    </div>

                    <nav
                        aria-label={t('chat_media.search.type.label')}
                        className="flex flex-wrap items-center gap-2"
                    >
                        {TYPES.map((type) => {
                            const active = filters.type === type.value;

                            return (
                                <Link
                                    key={type.value ?? 'all'}
                                    href={chatSearch.url({
                                        query: searchQuery(
                                            query,
                                            type.value,
                                            filters.conversation,
                                        ),
                                    })}
                                    replace
                                    aria-current={active ? 'true' : undefined}
                                    className={cn(
                                        'rounded-[3px] border px-2.5 py-1 text-sm',
                                        active
                                            ? 'border-primary bg-accent text-foreground'
                                            : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                                        FOCUS_RING,
                                    )}
                                >
                                    {t(type.label)}
                                </Link>
                            );
                        })}

                        {conversation ? (
                            <span className="inline-flex items-center gap-1 rounded-[3px] bg-info-soft py-0.5 pr-0.5 pl-2 text-sm text-foreground">
                                {t('chat_media.search.in_conversation', {
                                    name: conversation.label,
                                })}
                                <Link
                                    href={chatSearch.url({
                                        query: searchQuery(
                                            query,
                                            filters.type,
                                            null,
                                        ),
                                    })}
                                    replace
                                    aria-label={t(
                                        'chat_media.search.all_conversations',
                                    )}
                                    className={cn(
                                        'rounded-[3px] p-1 hover:bg-accent',
                                        FOCUS_RING,
                                    )}
                                >
                                    <X
                                        aria-hidden="true"
                                        className="size-3.5"
                                    />
                                </Link>
                            </span>
                        ) : null}
                    </nav>
                </form>

                <section
                    aria-label={t('chat_media.search.results')}
                    className="grid max-w-3xl gap-3"
                >
                    {tooShort ? (
                        <EmptyState
                            icon={Search}
                            title={t('chat_media.search.hint_title')}
                            description={t('chat_media.search.hint', {
                                min: minLength,
                            })}
                        />
                    ) : results.length === 0 ? (
                        <EmptyState
                            icon={MessagesSquare}
                            title={t('chat_media.search.empty', { query })}
                            description={t('chat_media.search.empty_help')}
                        >
                            <Button asChild variant="outline" size="sm">
                                <Link href={chatIndex.url()}>
                                    {t('chat_media.search.back_to_chat')}
                                </Link>
                            </Button>
                        </EmptyState>
                    ) : (
                        <>
                            <p
                                className="text-sm text-muted-foreground"
                                aria-live="polite"
                            >
                                {next === null
                                    ? t('chat_media.search.count', {
                                          count: results.length,
                                      })
                                    : t('chat_media.search.count_more', {
                                          count: results.length,
                                      })}
                            </p>
                            <ul className="grid gap-2">
                                {results.map((result) => (
                                    <ResultItem
                                        key={result.id}
                                        result={result}
                                        query={query}
                                    />
                                ))}
                            </ul>
                            {moreError ? (
                                <p
                                    role="alert"
                                    className="inline-flex items-center gap-1.5 text-sm text-foreground"
                                >
                                    <CircleAlert
                                        aria-hidden="true"
                                        className="size-4 text-danger"
                                    />
                                    {t('chat_media.search.more_error')}
                                </p>
                            ) : null}
                            {next !== null ? (
                                <div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={loadingMore}
                                        onClick={() => void loadMore()}
                                    >
                                        {loadingMore ? <Spinner /> : null}
                                        {t('chat_media.search.more')}
                                    </Button>
                                </div>
                            ) : null}
                        </>
                    )}
                </section>
            </div>
        </>
    );
}

ChatSearchPage.layout = {
    breadcrumbs: [
        { title: t('nav.chat'), href: chatIndex() },
        { title: t('chat_media.search.breadcrumb'), href: chatSearch() },
    ],
};
