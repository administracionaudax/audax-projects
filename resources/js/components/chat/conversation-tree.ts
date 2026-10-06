import { normalizeSearch } from '@/components/chat/mentions';
import type { ChatClientSummary, ChatConversationItem } from '@/types/chat';

/**
 * Los tres niveles de la lista del chat (D-273):
 * 1. Canales: los de equipo.
 * 2. Proyectos y clientes: cada cliente con su canal y, debajo, los chats de sus proyectos (los
 *    proyectos internos, sin cliente, en su propio grupo al final).
 * 3. Directos: mensajes directos y grupos.
 *
 * Cada nivel va de la última actividad a la más antigua. Lo archivado (proyectos y canales
 * archivados, clientes desactivados) va al final, o se esconde con «Ocultar archivados».
 * «Solo los míos» deja los canales en los que se participa y los clientes con algo propio (su
 * canal o algún proyecto).
 */

export type ChatSectionKey = 'channels' | 'clients' | 'direct';

export const CHAT_SECTIONS: ChatSectionKey[] = [
    'channels',
    'clients',
    'direct',
];

export type ChatClientNode = {
    /** `client:{id}` o `internal` (proyectos sin cliente). */
    key: string;
    client: ChatClientSummary | null;
    /** El canal del cliente, si ya existe (se crea al abrirlo). */
    channel: ChatConversationItem | null;
    projects: ChatConversationItem[];
    lastActivity: string;
    archived: boolean;
};

export type ChatTree = {
    channels: ChatConversationItem[];
    clients: ChatClientNode[];
    direct: ChatConversationItem[];
    /** Cuántas conversaciones archivadas quedan escondidas. */
    hiddenArchived: number;
};

export type ChatTreeOptions = {
    query?: string;
    mineOnly?: boolean;
    showArchived?: boolean;
};

export function sectionOf(item: ChatConversationItem): ChatSectionKey {
    switch (item.type) {
        case 'team':
            return 'channels';
        case 'client':
        case 'project':
            return 'clients';
        default:
            return 'direct';
    }
}

/** Archivado: proyecto o canal archivado, o cliente desactivado. */
export function isArchived(item: ChatConversationItem): boolean {
    return (
        item.read_only ||
        item.project?.status === 'archived' ||
        item.client?.is_active === false
    );
}

function activity(item: ChatConversationItem): string {
    return item.last_activity_at ?? '';
}

function byActivity(a: { at: string }, b: { at: string }): number {
    return a.at < b.at ? 1 : a.at > b.at ? -1 : 0;
}

/** Más reciente primero y lo archivado al final (orden estable). */
function sortItems(items: ChatConversationItem[]): ChatConversationItem[] {
    return items
        .map((item, index) => ({ item, index, at: activity(item) }))
        .sort(
            (a, b) =>
                Number(isArchived(a.item)) - Number(isArchived(b.item)) ||
                byActivity(a, b) ||
                a.index - b.index,
        )
        .map(({ item }) => item);
}

function haystack(item: ChatConversationItem): string {
    return normalizeSearch(
        [
            item.title,
            item.subtitle ?? '',
            item.other_user?.name ?? '',
            item.client?.name ?? '',
        ].join(' '),
    );
}

export function buildChatTree(
    items: ChatConversationItem[],
    { query = '', mineOnly = false, showArchived = true }: ChatTreeOptions = {},
): ChatTree {
    const needle = normalizeSearch(query.trim());
    const matches = (item: ChatConversationItem) =>
        needle === '' || haystack(item).includes(needle);
    let hiddenArchived = 0;
    const keep = (item: ChatConversationItem) => {
        if (!showArchived && isArchived(item)) {
            hiddenArchived += 1;

            return false;
        }

        return true;
    };

    const channels: ChatConversationItem[] = [];
    const direct: ChatConversationItem[] = [];
    const groups = new Map<
        string,
        {
            client: ChatClientSummary | null;
            channel: ChatConversationItem | null;
            projects: ChatConversationItem[];
        }
    >();

    for (const item of items) {
        const section = sectionOf(item);

        if (section === 'channels') {
            if ((!mineOnly || item.is_participant) && keep(item)) {
                channels.push(item);
            }
            continue;
        }

        if (section === 'direct') {
            direct.push(item);
            continue;
        }

        const key = item.client ? `client:${item.client.id}` : 'internal';
        const group = groups.get(key) ?? {
            client: item.client ?? null,
            channel: null,
            projects: [],
        };

        if (item.type === 'client') {
            group.channel = item;
        } else {
            group.projects.push(item);
        }
        groups.set(key, group);
    }

    const clients: ChatClientNode[] = [];

    for (const [key, group] of groups) {
        const clientMatches =
            needle === '' ||
            normalizeSearch(group.client?.name ?? '').includes(needle);
        const clientArchived = group.client?.is_active === false;

        if (clientArchived && !showArchived) {
            hiddenArchived +=
                group.projects.length + (group.channel !== null ? 1 : 0);
            continue;
        }

        const projects = sortItems(
            group.projects.filter(
                (item) => (clientMatches || matches(item)) && keep(item),
            ),
        );
        const channel =
            group.channel !== null && (clientMatches || matches(group.channel))
                ? group.channel
                : null;
        const mine = projects.length > 0 || (channel?.is_participant ?? false);

        if (
            (channel === null && projects.length === 0) ||
            (mineOnly && !mine)
        ) {
            continue;
        }

        const lastActivity = [channel, ...projects]
            .filter((item): item is ChatConversationItem => item !== null)
            .map(activity)
            .reduce((max, at) => (at > max ? at : max), '');

        clients.push({
            key,
            client: group.client,
            // Sin coincidencia propia, el canal sigue como cabecera si coincide un proyecto.
            channel: channel ?? (projects.length > 0 ? group.channel : null),
            projects,
            lastActivity,
            archived: clientArchived,
        });
    }

    clients.sort(
        (a, b) =>
            Number(a.archived) - Number(b.archived) ||
            Number(a.client === null) - Number(b.client === null) ||
            byActivity({ at: a.lastActivity }, { at: b.lastActivity }),
    );

    return {
        channels: sortItems(channels.filter(matches)),
        clients,
        direct: sortItems(direct.filter(matches)),
        hiddenArchived,
    };
}

/** Las conversaciones que se ven de un nivel (para sus no leídos). */
export function sectionItems(
    tree: ChatTree,
    section: ChatSectionKey,
): ChatConversationItem[] {
    if (section === 'clients') {
        return tree.clients.flatMap((node) =>
            node.channel ? [node.channel, ...node.projects] : node.projects,
        );
    }

    return tree[section];
}

export function isTreeEmpty(tree: ChatTree): boolean {
    return (
        tree.channels.length === 0 &&
        tree.clients.length === 0 &&
        tree.direct.length === 0
    );
}
