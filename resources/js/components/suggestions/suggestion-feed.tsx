import { Link } from '@inertiajs/react';
import { Info, MessageCircle, Plus, Search, Sparkles } from 'lucide-react';
import { useId } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import {
    SuggestionStatusBadge,
    VoteButton,
} from '@/components/suggestions/suggestion-ui';
import { useSuggestionQuery } from '@/components/suggestions/use-suggestion-query';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import {
    activeCategories,
    FEED_ORDERS,
    suggestionParams,
} from '@/lib/suggestions';
import type { SuggestionQuery } from '@/lib/suggestions';
import { cn } from '@/lib/utils';
import { index as helpIndex } from '@/routes/help';
import { show as showRoute } from '@/routes/suggestions';
import type {
    SuggestionFeedOrder,
    SuggestionsTabProps,
} from '@/types/weeklies';

/**
 * Feedback (F-162): las categorías con su número de sugerencias, el buscador (resultados globales:
 * con texto se ignora la categoría), el orden Trending, Top o Nuevo o un estado del roadmap, el
 * filtro de categoría, «Añadir» y «Cargar más».
 */
export function SuggestionFeed({
    data,
    query,
    onCreate,
}: {
    data: SuggestionsTabProps;
    query: SuggestionQuery;
    onCreate: () => void;
}) {
    const id = useId();
    const { go, search, onSearch } = useSuggestionQuery(query);
    const feed = data.feed ?? { items: [], total: 0, has_more: false };
    const categories = activeCategories(data.boards);
    const total = categories.reduce(
        (sum, category) => sum + category.post_count,
        0,
    );
    const detailQuery = suggestionParams(query);
    delete detailQuery.pestana;

    return (
        <div className="grid gap-4 lg:grid-cols-[16rem_1fr]">
            <nav
                aria-labelledby={`${id}-categories`}
                className="grid content-start gap-2"
            >
                <h2 id={`${id}-categories`} className="text-sm font-medium">
                    {t('suggestions.categories')}{' '}
                    <span className="text-muted-foreground">
                        ({categories.length})
                    </span>
                </h2>
                {categories.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {data.can.manage_boards
                            ? t('suggestions.no_categories_manage')
                            : t('suggestions.no_categories')}
                    </p>
                ) : (
                    <ul className="grid gap-1">
                        <li>
                            <button
                                type="button"
                                onClick={() =>
                                    go({ board: null, category: null })
                                }
                                aria-current={
                                    query.category === null ? 'true' : undefined
                                }
                                className={cn(
                                    'flex w-full items-center justify-between gap-2 border px-3 py-2 text-left text-sm',
                                    query.category === null
                                        ? 'border-primary bg-accent'
                                        : 'hover:bg-accent',
                                    FOCUS_RING,
                                )}
                            >
                                {t('suggestions.all_categories')}
                                <span className="tabular text-muted-foreground">
                                    {total}
                                </span>
                            </button>
                        </li>
                        {categories.map((category) => (
                            <li key={category.id}>
                                <button
                                    type="button"
                                    onClick={() =>
                                        go({
                                            board: category.board.slug,
                                            category: category.slug,
                                        })
                                    }
                                    aria-current={
                                        data.filters.category === category.id
                                            ? 'true'
                                            : undefined
                                    }
                                    title={category.description ?? undefined}
                                    className={cn(
                                        'flex w-full items-center justify-between gap-2 border px-3 py-2 text-left text-sm',
                                        data.filters.category === category.id
                                            ? 'border-primary bg-accent'
                                            : 'hover:bg-accent',
                                        FOCUS_RING,
                                    )}
                                >
                                    <span className="flex min-w-0 items-center gap-1.5">
                                        <span className="truncate">
                                            {category.name}
                                        </span>
                                        {category.description ? (
                                            <>
                                                <Info
                                                    aria-hidden="true"
                                                    className="size-3.5 shrink-0 text-muted-foreground"
                                                />
                                                <span className="sr-only">
                                                    {category.description}
                                                </span>
                                            </>
                                        ) : null}
                                    </span>
                                    <span className="tabular text-muted-foreground">
                                        {category.post_count}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </nav>
            <section
                aria-labelledby={`${id}-feed`}
                className="grid content-start gap-4"
            >
                <h2 id={`${id}-feed`} className="sr-only">
                    {t('suggestions.feedback')}
                </h2>
                <div className="grid gap-2 sm:grid-cols-[1fr_auto_auto_auto]">
                    <div className="relative">
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => onSearch(event.target.value)}
                            placeholder={t('suggestions.search_placeholder')}
                            aria-label={t('suggestions.search')}
                            className="pl-8"
                        />
                    </div>
                    <NativeSelect
                        value={query.order}
                        onChange={(event) =>
                            go({
                                order: event.target
                                    .value as SuggestionFeedOrder,
                            })
                        }
                        aria-label={t('suggestions.order')}
                    >
                        {FEED_ORDERS.map((order) => (
                            <option key={order} value={order}>
                                {t(`suggestions.orders.${order}`)}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        value={data.filters.category ?? ''}
                        onChange={(event) => {
                            const category = categories.find(
                                (item) =>
                                    String(item.id) === event.target.value,
                            );
                            go({
                                board: category?.board.slug ?? null,
                                category: category?.slug ?? null,
                            });
                        }}
                        aria-label={t('suggestions.category_filter')}
                    >
                        <option value="">
                            {t('suggestions.all_categories')}
                        </option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </NativeSelect>
                    {data.can.create ? (
                        <Button
                            onClick={onCreate}
                            disabled={
                                categories.length === 0 &&
                                data.boards.length === 0
                            }
                        >
                            <Plus aria-hidden="true" />
                            {t('suggestions.add')}
                        </Button>
                    ) : null}
                </div>
                {query.q ? (
                    <p className="text-sm text-muted-foreground">
                        {t('suggestions.global_results', { q: query.q })}
                    </p>
                ) : null}
                <p className="sr-only" aria-live="polite">
                    {t('suggestions.results', { count: feed.total })}
                </p>
                {feed.items.length === 0 ? (
                    <div className="grid justify-items-center gap-2 border px-4 py-10 text-center text-sm text-muted-foreground">
                        <Sparkles aria-hidden="true" className="size-6" />
                        <p>{t('suggestions.feed_empty')}</p>
                    </div>
                ) : (
                    <ul className="grid gap-2" data-test="suggestion-feed">
                        {feed.items.map((post) => (
                            <li key={post.id} className="flex gap-3 border p-3">
                                <VoteButton
                                    postId={post.id}
                                    title={post.title}
                                    count={post.vote_count}
                                    voted={post.voted_by_me}
                                    compact
                                />
                                <div className="grid min-w-0 flex-1 gap-1">
                                    <Link
                                        href={showRoute.url(post.id, {
                                            query: detailQuery,
                                        })}
                                        className={cn(
                                            'font-medium hover:underline',
                                            FOCUS_RING,
                                        )}
                                    >
                                        {post.title}
                                    </Link>
                                    {post.preview ? (
                                        <p className="line-clamp-2 text-sm text-muted-foreground">
                                            {post.preview}
                                        </p>
                                    ) : null}
                                    <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        {post.status !== 'open' ? (
                                            <SuggestionStatusBadge
                                                status={post.status}
                                            />
                                        ) : null}
                                        {post.category ? (
                                            <span className="border px-1.5 py-0.5">
                                                {post.category.name}
                                            </span>
                                        ) : null}
                                        <span>
                                            {post.author.name} ·{' '}
                                            {formatDate(post.created_at)}
                                        </span>
                                    </div>
                                </div>
                                <span
                                    className="flex shrink-0 items-start gap-1 text-sm text-muted-foreground"
                                    aria-label={t(
                                        'suggestions.comments_count',
                                        { count: post.comment_count },
                                    )}
                                >
                                    <MessageCircle
                                        aria-hidden="true"
                                        className="mt-0.5 size-4"
                                    />
                                    <span
                                        aria-hidden="true"
                                        className="tabular"
                                    >
                                        {post.comment_count}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                {feed.has_more ? (
                    <div className="flex justify-center">
                        <Button variant="secondary" asChild>
                            <Link
                                href={helpIndex.url({
                                    query: suggestionParams({
                                        ...query,
                                        limit: data.filters.limit + 20,
                                    }),
                                })}
                                only={['suggestions']}
                                preserveScroll
                                preserveState
                            >
                                {t('suggestions.load_more')}
                            </Link>
                        </Button>
                    </div>
                ) : null}
            </section>
        </div>
    );
}
