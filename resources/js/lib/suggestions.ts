import type { UserSummary } from '@/types';
import type {
    SuggestionBoard,
    SuggestionComment,
    SuggestionFeedOrder,
    SuggestionPost,
    SuggestionReaction,
    SuggestionRoadmapStatus,
    SuggestionStatus,
    SuggestionStatusEvent,
    SuggestionsTabProps,
} from '@/types/weeklies';

/**
 * Piezas puras de las sugerencias (F-159 a F-168): los estados, la URL de la pestaña con sus
 * filtros, la actividad del detalle (comentarios y cambios de estado en orden), el resumen de las
 * reacciones y el estado del roadmap al arrastrar (como el kanban de tareas). Las prueba
 * tests/js/suggestions.test.ts.
 */

export const SUGGESTION_STATUSES: SuggestionStatus[] = [
    'open',
    'future',
    'planned',
    'building_now',
    'beta',
    'completed',
];

export const ROADMAP_STATUSES: SuggestionRoadmapStatus[] = [
    'planned',
    'building_now',
    'beta',
    'completed',
];

export const FEED_ORDERS: SuggestionFeedOrder[] = [
    'trending',
    'top',
    'new',
    ...ROADMAP_STATUSES,
];

export const REACTIONS: SuggestionReaction[] = [
    'thumbs_up',
    'rocket',
    'eyes',
    'heart',
];

export function isRoadmapStatus(
    status: SuggestionStatus,
): status is SuggestionRoadmapStatus {
    return (ROADMAP_STATUSES as string[]).includes(status);
}

export type SuggestionQuery = {
    view: 'roadmap' | 'feedback';
    board: string | null;
    category: string | null;
    q: string | null;
    order: SuggestionFeedOrder;
    statuses: SuggestionRoadmapStatus[];
    limit: number | null;
    composer?: 'default' | 'bug' | null;
};

/** Los filtros de la pestaña tal como llegan del servidor (con los slugs). */
export function currentQuery(data: SuggestionsTabProps): SuggestionQuery {
    const board = data.boards.find((item) => item.id === data.filters.board);
    const category = data.boards
        .flatMap((item) => item.categories)
        .find((item) => item.id === data.filters.category);

    return {
        view: data.view,
        board: board?.slug ?? null,
        category: category?.slug ?? null,
        q: data.filters.q,
        order: data.filters.order,
        statuses: data.filters.statuses,
        limit: null,
    };
}

/**
 * Los parámetros de /ayuda para unos filtros (sin los que valen lo de por defecto, para que la URL
 * quede corta y se pueda compartir).
 */
export function suggestionParams(
    query: SuggestionQuery,
): Record<string, string> {
    const params: Record<string, string> = { pestana: 'sugerencias' };

    if (query.view === 'feedback') {
        params.vista = 'feedback';
    }

    if (query.board) {
        params.tablero = query.board;
    }

    if (query.category) {
        params.categoria = query.category;
    }

    if (query.q && query.q.trim() !== '') {
        params.q = query.q.trim();
    }

    if (query.view === 'feedback' && query.order !== 'trending') {
        params.orden = query.order;
    }

    if (
        query.view === 'roadmap' &&
        query.statuses.length > 0 &&
        query.statuses.length < ROADMAP_STATUSES.length
    ) {
        params.estados = ROADMAP_STATUSES.filter((status) =>
            query.statuses.includes(status),
        ).join(',');
    }

    if (query.limit) {
        params.limite = String(query.limit);
    }

    if (query.composer) {
        params.nueva = query.composer === 'bug' ? 'bug' : '1';
    }

    return params;
}

/** Las categorías activas de los tableros activos, con su tablero. */
export function activeCategories(
    boards: SuggestionBoard[],
): (SuggestionBoard['categories'][number] & { board: SuggestionBoard })[] {
    return boards
        .filter((board) => board.is_active)
        .flatMap((board) =>
            board.categories
                .filter((category) => category.is_active)
                .map((category) => ({ ...category, board })),
        );
}

export type TimelineItem =
    | { kind: 'comment'; at: string; comment: SuggestionComment }
    | { kind: 'status'; at: string; event: SuggestionStatusEvent };

/** La actividad del detalle: comentarios (con sus respuestas dentro) y cambios de estado, por fecha. */
export function buildTimeline(
    comments: SuggestionComment[],
    events: SuggestionStatusEvent[],
): TimelineItem[] {
    const items: TimelineItem[] = [
        ...comments.map((comment): TimelineItem => ({
            kind: 'comment',
            at: comment.created_at ?? '',
            comment,
        })),
        ...events.map((event): TimelineItem => ({
            kind: 'status',
            at: event.created_at ?? '',
            event,
        })),
    ];

    return items.sort((a, b) => a.at.localeCompare(b.at));
}

export type ReactionSummary = {
    reaction: SuggestionReaction;
    count: number;
    mine: boolean;
    names: string[];
};

/** Cuántas de cada reacción, si una es mía y quién las ha puesto. */
export function summarizeReactions(
    reactions: { reaction: SuggestionReaction; user: UserSummary }[],
    meId: number,
): ReactionSummary[] {
    return REACTIONS.map((reaction) => {
        const of = reactions.filter((item) => item.reaction === reaction);

        return {
            reaction,
            count: of.length,
            mine: of.some((item) => item.user.id === meId),
            names: of.map((item) => item.user.name),
        };
    });
}

/** Cuenta un comentario y todas sus respuestas. */
export function countComments(comments: SuggestionComment[]): number {
    return comments.reduce(
        (total, comment) => total + 1 + countComments(comment.replies),
        0,
    );
}

// --- Roadmap ----------------------------------------------------------------------------------

export type RoadmapColumns = Record<SuggestionRoadmapStatus, number[]>;

export function buildRoadmap(
    columns: { status: SuggestionRoadmapStatus; items: SuggestionPost[] }[],
): RoadmapColumns {
    const result = {
        planned: [],
        building_now: [],
        beta: [],
        completed: [],
    } as RoadmapColumns;

    for (const column of columns) {
        result[column.status] = column.items.map((post) => post.id);
    }

    return result;
}

export function findRoadmapColumn(
    columns: RoadmapColumns,
    postId: number,
): SuggestionRoadmapStatus | null {
    for (const status of ROADMAP_STATUSES) {
        if (columns[status].includes(postId)) {
            return status;
        }
    }

    return null;
}

/** Mueve la sugerencia a la columna $status en la posición $index (acotada). */
export function moveRoadmapCard(
    columns: RoadmapColumns,
    postId: number,
    status: SuggestionRoadmapStatus,
    index: number,
): RoadmapColumns {
    const next = {} as RoadmapColumns;

    for (const key of ROADMAP_STATUSES) {
        next[key] = columns[key].filter((id) => id !== postId);
    }

    const target = next[status];
    const at = Math.max(0, Math.min(index, target.length));
    next[status] = [...target.slice(0, at), postId, ...target.slice(at)];

    return next;
}

/** Vecinas para el servidor: antes de la siguiente; si es la última, después de la anterior. */
export function roadmapNeighbours(
    columns: RoadmapColumns,
    status: SuggestionRoadmapStatus,
    postId: number,
): { before_id: number | null; after_id: number | null } {
    const ids = columns[status];
    const index = ids.indexOf(postId);
    const next = ids[index + 1] ?? null;

    if (next !== null) {
        return { before_id: next, after_id: null };
    }

    return { before_id: null, after_id: ids[index - 1] ?? null };
}

export function sameRoadmap(a: RoadmapColumns, b: RoadmapColumns): boolean {
    return ROADMAP_STATUSES.every(
        (status) =>
            a[status].length === b[status].length &&
            a[status].every((id, index) => id === b[status][index]),
    );
}

// --- Adjuntos ---------------------------------------------------------------------------------

/** Los ficheros pegados del portapapeles (F-161: «pegar archivos»). */
export function filesFromClipboard(data: DataTransfer | null): File[] {
    if (!data) {
        return [];
    }

    return Array.from(data.items ?? [])
        .filter((item) => item.kind === 'file')
        .map((item) => item.getAsFile())
        .filter((file): file is File => file !== null);
}

/** Junta ficheros sin repetir (mismo nombre, tamaño y fecha). */
export function mergeFiles(current: File[], next: File[]): File[] {
    const keys = new Set(
        current.map((file) => `${file.name}:${file.size}:${file.lastModified}`),
    );
    const merged = [...current];

    for (const file of next) {
        const key = `${file.name}:${file.size}:${file.lastModified}`;

        if (!keys.has(key)) {
            keys.add(key);
            merged.push(file);
        }
    }

    return merged;
}
