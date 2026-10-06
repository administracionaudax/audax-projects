import {
    closestCorners,
    DndContext,
    DragOverlay,
    KeyboardSensor,
    PointerSensor,
    useDroppable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type {
    Announcements,
    DragEndEvent,
    DragOverEvent,
    DragStartEvent,
    UniqueIdentifier,
} from '@dnd-kit/core';
import {
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    EllipsisVertical,
    GripVertical,
    MessageCircle,
    Search,
} from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { NativeSelect } from '@/components/admin/native-select';
import {
    statusLabel,
    SuggestionStatusBadge,
    VoteButton,
} from '@/components/suggestions/suggestion-ui';
import { useSuggestionQuery } from '@/components/suggestions/use-suggestion-query';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import {
    activeCategories,
    buildRoadmap,
    findRoadmapColumn,
    moveRoadmapCard,
    ROADMAP_STATUSES,
    roadmapNeighbours,
    sameRoadmap,
    suggestionParams,
} from '@/lib/suggestions';
import type { RoadmapColumns, SuggestionQuery } from '@/lib/suggestions';
import { cn } from '@/lib/utils';
import { index as helpIndex } from '@/routes/help';
import { show as showRoute } from '@/routes/suggestions';
import { update as positionRoute } from '@/routes/suggestions/position';
import type {
    SuggestionPost,
    SuggestionRoadmapStatus,
    SuggestionsTabProps,
} from '@/types/weeklies';

const COLUMN = 'column-';
const CARD = 'post-';

function parseKey(
    id: UniqueIdentifier,
):
    | { type: 'column'; status: SuggestionRoadmapStatus }
    | { type: 'card'; id: number } {
    const value = String(id);

    return value.startsWith(COLUMN)
        ? {
              type: 'column',
              status: value.slice(COLUMN.length) as SuggestionRoadmapStatus,
          }
        : { type: 'card', id: Number(value.slice(CARD.length)) };
}

type MoveHandler = (
    postId: number,
    status: SuggestionRoadmapStatus,
    index: number,
) => void;

function CardBody({
    post,
    detailQuery,
    handle,
    menu,
}: {
    post: SuggestionPost;
    detailQuery: Record<string, string>;
    handle?: ReactNode;
    menu?: ReactNode;
}) {
    return (
        <div className="flex gap-2 p-3">
            <VoteButton
                postId={post.id}
                title={post.title}
                count={post.vote_count}
                voted={post.voted_by_me}
                compact
            />
            <div className="grid min-w-0 flex-1 gap-1">
                <div className="flex items-start gap-1">
                    {handle}
                    <Link
                        href={showRoute.url(post.id, { query: detailQuery })}
                        className={cn(
                            'min-w-0 flex-1 text-sm font-medium break-words hover:underline',
                            FOCUS_RING,
                        )}
                        data-test="roadmap-card-title"
                    >
                        {post.title}
                    </Link>
                    {menu}
                </div>
                <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    {post.category ? (
                        <span className="border px-1.5 py-0.5">
                            {post.category.name}
                        </span>
                    ) : null}
                    <span
                        className="inline-flex items-center gap-1"
                        data-test="roadmap-comment-count"
                    >
                        <MessageCircle
                            aria-hidden="true"
                            className="size-3.5"
                        />
                        <span aria-hidden="true">{post.comment_count}</span>
                        <span className="sr-only">
                            {t('suggestions.comments_count', {
                                count: post.comment_count,
                            })}
                        </span>
                    </span>
                </div>
            </div>
        </div>
    );
}

function SortableCard({
    post,
    status,
    index,
    count,
    canMove,
    detailQuery,
    onMove,
}: {
    post: SuggestionPost;
    status: SuggestionRoadmapStatus;
    index: number;
    count: number;
    canMove: boolean;
    detailQuery: Record<string, string>;
    onMove: MoveHandler;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({
        id: `${CARD}${post.id}`,
        disabled: !canMove,
        attributes: { roleDescription: t('suggestions.roadmap.role') },
    });

    return (
        <li
            ref={setNodeRef}
            style={{ transform: CSS.Translate.toString(transform), transition }}
            className={cn('border bg-card', isDragging && 'opacity-40')}
            data-test="roadmap-card"
            data-post-id={post.id}
        >
            <CardBody
                post={post}
                detailQuery={detailQuery}
                handle={
                    canMove ? (
                        <button
                            type="button"
                            ref={setActivatorNodeRef}
                            {...attributes}
                            {...listeners}
                            aria-label={t('suggestions.roadmap.handle', {
                                title: post.title,
                            })}
                            className={cn(
                                '-ml-1 cursor-grab touch-none p-0.5 text-muted-foreground hover:text-foreground',
                                FOCUS_RING,
                            )}
                        >
                            <GripVertical
                                aria-hidden="true"
                                className="size-4"
                            />
                        </button>
                    ) : null
                }
                menu={
                    canMove ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-6"
                                    aria-label={t(
                                        'suggestions.roadmap.move_menu',
                                        { title: post.title },
                                    )}
                                >
                                    <EllipsisVertical aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuLabel>
                                    {t('suggestions.roadmap.move_to')}
                                </DropdownMenuLabel>
                                {ROADMAP_STATUSES.filter(
                                    (item) => item !== status,
                                ).map((item) => (
                                    <DropdownMenuItem
                                        key={item}
                                        onSelect={() =>
                                            onMove(
                                                post.id,
                                                item,
                                                Number.MAX_SAFE_INTEGER,
                                            )
                                        }
                                    >
                                        {statusLabel(item)}
                                    </DropdownMenuItem>
                                ))}
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    disabled={index === 0}
                                    onSelect={() =>
                                        onMove(post.id, status, index - 1)
                                    }
                                >
                                    <ArrowUp aria-hidden="true" />
                                    {t('suggestions.roadmap.move_up')}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    disabled={index >= count - 1}
                                    onSelect={() =>
                                        onMove(post.id, status, index + 1)
                                    }
                                >
                                    <ArrowDown aria-hidden="true" />
                                    {t('suggestions.roadmap.move_down')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : null
                }
            />
        </li>
    );
}

function Column({
    status,
    ids,
    byId,
    total,
    canMove,
    detailQuery,
    onMove,
}: {
    status: SuggestionRoadmapStatus;
    ids: number[];
    byId: Map<number, SuggestionPost>;
    total: number;
    canMove: boolean;
    detailQuery: Record<string, string>;
    onMove: MoveHandler;
}) {
    const { setNodeRef, isOver } = useDroppable({ id: `${COLUMN}${status}` });
    const heading = `roadmap-${status}`;

    return (
        <section
            aria-labelledby={heading}
            className="flex w-72 shrink-0 flex-col gap-3 border bg-muted p-3"
            data-roadmap-column={status}
        >
            <h3
                id={heading}
                className="flex items-center justify-between gap-2 text-sm font-medium"
            >
                <SuggestionStatusBadge status={status} />
                <span className="tabular font-normal text-muted-foreground">
                    {total}
                </span>
            </h3>
            <SortableContext
                id={`${COLUMN}${status}`}
                items={ids.map((id) => `${CARD}${id}`)}
                strategy={verticalListSortingStrategy}
            >
                <ul
                    ref={setNodeRef}
                    className={cn(
                        'flex min-h-24 flex-col gap-2',
                        isOver && 'bg-accent',
                    )}
                    aria-label={t('suggestions.roadmap.column', {
                        status: statusLabel(status),
                    })}
                >
                    {ids.length === 0 ? (
                        <li className="px-2 py-6 text-center text-xs text-muted-foreground">
                            {t('suggestions.roadmap.empty')}
                        </li>
                    ) : (
                        ids.map((id, index) => {
                            const post = byId.get(id);

                            return post ? (
                                <SortableCard
                                    key={id}
                                    post={post}
                                    status={status}
                                    index={index}
                                    count={ids.length}
                                    canMove={canMove}
                                    detailQuery={detailQuery}
                                    onMove={onMove}
                                />
                            ) : null;
                        })
                    )}
                </ul>
            </SortableContext>
        </section>
    );
}

/**
 * Roadmap (F-168): una columna por estado (Planificada, En desarrollo, Beta y Completada) con su
 * orden. Quien gestiona arrastra las tarjetas entre columnas (cambia el estado) y dentro de una
 * (cambia el orden), con ratón, dedo o teclado y anuncios para lectores de pantalla, o con el menú
 * «Mover a…» de cada tarjeta. Filtro de estados visibles, categoría, buscador y «Cargar más».
 */
export function SuggestionRoadmap({
    data,
    query,
}: {
    data: SuggestionsTabProps;
    query: SuggestionQuery;
}) {
    const id = useId();
    const { go, search, onSearch } = useSuggestionQuery(query);
    const columnsData = data.roadmap ?? [];
    const posts = columnsData.flatMap((column) => column.items);
    const byId = new Map(posts.map((post) => [post.id, post]));
    const totals = Object.fromEntries(
        columnsData.map((column) => [column.status, column.total]),
    ) as Record<string, number>;
    const visible = columnsData.map((column) => column.status);
    const canMove = data.can.moderate;
    const categories = activeCategories(data.boards);
    const detailQuery = suggestionParams(query);
    delete detailQuery.pestana;

    const [source, setSource] = useState(columnsData);
    const [columns, setColumns] = useState<RoadmapColumns>(() =>
        buildRoadmap(columnsData),
    );
    const [activeId, setActiveId] = useState<number | null>(null);
    const snapshot = useRef<RoadmapColumns | null>(null);
    const latest = useRef(columns);

    useEffect(() => {
        latest.current = columns;
    }, [columns]);

    if (source !== columnsData && activeId === null) {
        setSource(columnsData);
        setColumns(buildRoadmap(columnsData));
    }

    const commit = (next: RoadmapColumns) => {
        latest.current = next;
        setColumns(next);
    };

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    const titleOf = (key: UniqueIdentifier) => {
        const parsed = parseKey(key);

        return parsed.type === 'card' ? (byId.get(parsed.id)?.title ?? '') : '';
    };

    const describe = (
        key: UniqueIdentifier | undefined,
        postId: number,
    ): string | null => {
        if (key === undefined) {
            return null;
        }

        const parsed = parseKey(key);
        const status =
            parsed.type === 'column'
                ? parsed.status
                : findRoadmapColumn(latest.current, parsed.id);

        if (status === null) {
            return null;
        }

        const ids = latest.current[status];
        const index = ids.indexOf(postId);

        return t('suggestions.roadmap.position', {
            status: statusLabel(status),
            position: index === -1 ? ids.length + 1 : index + 1,
            total: index === -1 ? ids.length + 1 : ids.length,
        });
    };

    const cardId = (key: UniqueIdentifier) => {
        const parsed = parseKey(key);

        return parsed.type === 'card' ? parsed.id : -1;
    };

    const announcements: Announcements = {
        onDragStart: ({ active }) =>
            t('suggestions.roadmap.announce.start', {
                title: titleOf(active.id),
                position: describe(active.id, cardId(active.id)) ?? '',
            }),
        onDragOver: ({ active, over }) => {
            const where = describe(over?.id, cardId(active.id));

            return where
                ? t('suggestions.roadmap.announce.over', {
                      title: titleOf(active.id),
                      position: where,
                  })
                : t('suggestions.roadmap.announce.outside', {
                      title: titleOf(active.id),
                  });
        },
        onDragEnd: ({ active, over }) => {
            const where = describe(over?.id, cardId(active.id));

            return where
                ? t('suggestions.roadmap.announce.end', {
                      title: titleOf(active.id),
                      position: where,
                  })
                : t('suggestions.roadmap.announce.cancel', {
                      title: titleOf(active.id),
                  });
        },
        onDragCancel: ({ active }) =>
            t('suggestions.roadmap.announce.cancel', {
                title: titleOf(active.id),
            }),
    };

    const persist = (
        postId: number,
        next: RoadmapColumns,
        previous: RoadmapColumns,
    ) => {
        const status = findRoadmapColumn(next, postId);

        if (status === null || sameRoadmap(next, previous)) {
            return;
        }

        commit(next);
        const revert = () => commit(previous);

        router.put(
            positionRoute.url(postId),
            { status, ...roadmapNeighbours(next, status, postId) },
            {
                // Asíncrona: mover otra tarjeta enseguida ya no interrumpe (y pierde) esta (D-310).
                async: true,
                preserveScroll: true,
                preserveState: true,
                only: ['suggestions'],
                onError: (errors) => {
                    revert();
                    toast.error(
                        Object.values(errors)[0] ??
                            t('suggestions.roadmap.move_failed'),
                    );
                },
                onHttpException: () => {
                    revert();
                    toast.error(t('suggestions.roadmap.move_failed'));

                    return false;
                },
                onNetworkError: () => {
                    revert();
                    toast.error(t('suggestions.roadmap.move_failed'));

                    return false;
                },
            },
        );
    };

    const moveWithMenu: MoveHandler = (postId, status, index) => {
        persist(
            postId,
            moveRoadmapCard(latest.current, postId, status, index),
            latest.current,
        );
    };

    const onDragStart = ({ active }: DragStartEvent) => {
        snapshot.current = latest.current;
        setActiveId(cardId(active.id));
    };

    const onDragOver = ({ active, over }: DragOverEvent) => {
        if (!over) {
            return;
        }

        const postId = cardId(active.id);
        const target = parseKey(over.id);
        const from = findRoadmapColumn(latest.current, postId);
        const to =
            target.type === 'column'
                ? target.status
                : findRoadmapColumn(latest.current, target.id);

        if (from === null || to === null || from === to) {
            return;
        }

        const ids = latest.current[to];
        const index =
            target.type === 'column' ? ids.length : ids.indexOf(target.id);
        commit(
            moveRoadmapCard(
                latest.current,
                postId,
                to,
                index === -1 ? ids.length : index,
            ),
        );
    };

    const onDragEnd = ({ active, over }: DragEndEvent) => {
        const previous = snapshot.current ?? latest.current;
        snapshot.current = null;
        setActiveId(null);

        if (!over) {
            commit(previous);

            return;
        }

        const postId = cardId(active.id);
        const target = parseKey(over.id);
        const to =
            target.type === 'column'
                ? target.status
                : findRoadmapColumn(latest.current, target.id);

        if (to === null) {
            commit(previous);

            return;
        }

        const ids = latest.current[to];
        const index =
            target.type === 'column' || target.id === postId
                ? ids.indexOf(postId) === -1
                    ? ids.length
                    : ids.indexOf(postId)
                : ids.indexOf(target.id);

        persist(
            postId,
            moveRoadmapCard(latest.current, postId, to, index),
            previous,
        );
    };

    const onDragCancel = () => {
        commit(snapshot.current ?? latest.current);
        snapshot.current = null;
        setActiveId(null);
    };

    const active = activeId !== null ? byId.get(activeId) : undefined;
    const hasMore = columnsData.some((column) => column.has_more);

    return (
        <section aria-labelledby={`${id}-roadmap`} className="grid gap-4">
            <div className="grid gap-1">
                <h2 id={`${id}-roadmap`} className="text-base font-medium">
                    {t('suggestions.roadmap.title')}
                </h2>
                <p className="text-sm text-muted-foreground">
                    {t('suggestions.roadmap.intro')}
                </p>
            </div>
            <div className="grid gap-3 md:grid-cols-[1fr_auto]">
                <div className="relative">
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        type="search"
                        value={search}
                        onChange={(event) => onSearch(event.target.value)}
                        placeholder={t(
                            'suggestions.roadmap.search_placeholder',
                        )}
                        aria-label={t('suggestions.search')}
                        className="pl-8"
                    />
                </div>
                <NativeSelect
                    value={data.filters.category ?? ''}
                    onChange={(event) => {
                        const category = categories.find(
                            (item) => String(item.id) === event.target.value,
                        );
                        go({
                            board: category?.board.slug ?? null,
                            category: category?.slug ?? null,
                        });
                    }}
                    aria-label={t('suggestions.category_filter')}
                >
                    <option value="">{t('suggestions.all_categories')}</option>
                    {categories.map((category) => (
                        <option key={category.id} value={category.id}>
                            {category.name}
                        </option>
                    ))}
                </NativeSelect>
            </div>
            <fieldset className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <legend className="mb-1 text-sm font-medium">
                    {t('suggestions.roadmap.visible_statuses')}
                </legend>
                {ROADMAP_STATUSES.map((status) => {
                    const checked = visible.includes(status);

                    return (
                        <div key={status} className="flex items-center gap-2">
                            <Checkbox
                                id={`${id}-${status}`}
                                checked={checked}
                                disabled={checked && visible.length === 1}
                                onCheckedChange={(next) =>
                                    go({
                                        statuses:
                                            next === true
                                                ? [...visible, status]
                                                : visible.filter(
                                                      (item) => item !== status,
                                                  ),
                                    })
                                }
                            />
                            <Label htmlFor={`${id}-${status}`}>
                                {statusLabel(status)}
                            </Label>
                        </div>
                    );
                })}
            </fieldset>
            {query.q ? (
                <p className="text-sm text-muted-foreground">
                    {t('suggestions.global_results', { q: query.q })}
                </p>
            ) : null}
            <DndContext
                sensors={sensors}
                collisionDetection={closestCorners}
                onDragStart={onDragStart}
                onDragOver={onDragOver}
                onDragEnd={onDragEnd}
                onDragCancel={onDragCancel}
                accessibility={{
                    announcements,
                    screenReaderInstructions: {
                        draggable: t('suggestions.roadmap.instructions'),
                    },
                }}
            >
                <div
                    className={cn(
                        '-mx-4 overflow-x-auto px-4 pb-2 md:mx-0 md:px-0',
                        FOCUS_RING,
                    )}
                    role="region"
                    aria-label={t('suggestions.roadmap.title')}
                    tabIndex={0}
                    data-test="suggestion-roadmap"
                >
                    <div className="flex min-w-max items-start gap-4">
                        {visible.map((status) => (
                            <Column
                                key={status}
                                status={status}
                                ids={columns[status]}
                                byId={byId}
                                total={totals[status] ?? 0}
                                canMove={canMove}
                                detailQuery={detailQuery}
                                onMove={moveWithMenu}
                            />
                        ))}
                    </div>
                </div>
                <DragOverlay>
                    {active ? (
                        <div className="w-72 border bg-card shadow-md">
                            <CardBody post={active} detailQuery={detailQuery} />
                        </div>
                    ) : null}
                </DragOverlay>
            </DndContext>
            {hasMore ? (
                <div className="flex justify-center">
                    <Button variant="secondary" asChild>
                        <Link
                            href={helpIndex.url({
                                query: suggestionParams({
                                    ...query,
                                    limit: data.filters.limit + 24,
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
    );
}
