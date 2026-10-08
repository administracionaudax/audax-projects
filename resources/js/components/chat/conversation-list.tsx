import { Link, usePage } from '@inertiajs/react';
import {
    Archive,
    BellRing,
    FolderKanban,
    Hash,
    MessageCircle,
    MessageSquarePlus,
    MessagesSquare,
    Plus,
    Search,
    ShieldCheck,
    UserCheck,
    Users,
} from 'lucide-react';
import { useId, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { ChannelDialog } from '@/components/chat/channel-dialog';
import { ConversationAvatar } from '@/components/chat/chat-avatar';
import { ConversationRow } from '@/components/chat/conversation-row';
import {
    buildChatTree,
    CHAT_SECTIONS,
    isTreeEmpty,
    sectionItems,
    sectionOf,
} from '@/components/chat/conversation-tree';
import type {
    ChatClientNode,
    ChatSectionKey,
} from '@/components/chat/conversation-tree';
import { ModerationDialog } from '@/components/chat/moderation-dialog';
import {
    NewDirectDialog,
    NewGroupDialog,
} from '@/components/chat/new-conversation-dialogs';
import { useUnreadCounter } from '@/components/chat/realtime-bridge';
import {
    CHAT_LIST_VIEWS,
    useChatListPrefs,
} from '@/components/chat/use-chat-list-prefs';
import type { ChatListView } from '@/components/chat/use-chat-list-prefs';
import { EmptyState } from '@/components/empty-state';
import { PushNotificationsToggle } from '@/components/realtime';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { search as chatSearch } from '@/routes/chat';
import type { ChatConversationItem } from '@/types/chat';

export {
    CONVERSATION_PROPS,
    previewText,
} from '@/components/chat/conversation-row';

/**
 * Lista de /chat (SPEC §12, D-273 y D-246): todos los chats que se pueden ver, por tipos: Directos
 * (directas y grupos), Proyectos y clientes (cada cliente con su canal y, debajo, sus proyectos) y
 * Canales (de equipo). A la izquierda, una barra de iconos con su nombre (Todo y los tres tipos),
 * cada uno con sus no leídos (los de C2 en vivo en cuanto llegan); «Todo» enseña los tres tipos
 * seguidos, con su título. Cada tipo va de la última actividad a la más antigua. Filtro por nombre,
 * «Solo los míos» y «Ocultar archivados» (si no, van al final); el tipo elegido y los filtros se
 * recuerdan por persona en este navegador. Arriba, «Buscar en el
 * chat» (C3), «Avisos en este navegador» (Web Push, C2), «Nuevo» (directo, grupo y, para el admin,
 * canal) y, para el admin, «Moderar» (D-119). Estados vacío, sin resultados y de error.
 */
export function ConversationList({
    items,
    activeId,
    loadError,
    onRetry,
    className,
}: {
    items: ChatConversationItem[];
    activeId: number | null;
    loadError?: boolean;
    onRetry?: () => void;
    className?: string;
}) {
    const [query, setQuery] = useState('');
    const [direct, setDirect] = useState(false);
    const [group, setGroup] = useState(false);
    const [channel, setChannel] = useState(false);
    const [moderation, setModeration] = useState(false);
    const newButton = useRef<HTMLButtonElement>(null);
    const moderationButton = useRef<HTMLButtonElement>(null);
    // El admin modera chats ajenos (D-119) y crea canales (D-272). (Sin sesión en la prop, como en
    // algún test, no.)
    const sessionUser = usePage().props.auth?.user;
    const isAdmin = sessionUser?.roles?.includes('admin') ?? false;
    // Un colaborador externo no tiene directas ni grupos (D-134): solo los chats de sus proyectos.
    const canStart = !(sessionUser?.is_collaborator ?? false);
    const searchId = useId();
    // Los no leídos de cada fila: los de C2 en cuanto llega su primer recuento; antes, los de la lista.
    const counter = useUnreadCounter();
    const { prefs, setView, setMineOnly, setHideArchived } = useChatListPrefs(
        sessionUser?.id ?? null,
    );
    const listId = useId();
    const tree = useMemo(
        () =>
            buildChatTree(items, {
                query,
                mineOnly: prefs.mineOnly,
                showArchived: !prefs.hideArchived,
            }),
        [items, query, prefs.mineOnly, prefs.hideArchived],
    );
    // Sin buscar, para los no leídos de cada icono (la búsqueda solo filtra la lista).
    const baseTree = useMemo(
        () =>
            buildChatTree(items, {
                mineOnly: prefs.mineOnly,
                showArchived: !prefs.hideArchived,
            }),
        [items, prefs.mineOnly, prefs.hideArchived],
    );
    // Los tipos que existen (con algo, antes de filtrar): un colaborador no ve «Directos».
    const present = useMemo(
        () => new Set(items.map((item) => sectionOf(item))),
        [items],
    );
    const sections = CHAT_SECTIONS_BY_USE.filter((section) =>
        present.has(section),
    );
    // Con un solo tipo (una colaboradora: solo proyectos) no hace falta la barra.
    const showRail = sections.length > 1;
    const view: ChatListView =
        showRail && (prefs.view === 'all' || present.has(prefs.view))
            ? prefs.view
            : 'all';
    const shown = view === 'all' ? sections : [view];
    const unreadOf = (item: ChatConversationItem) =>
        counter.ready ? counter.count(item.id) : item.unread;
    const mutedOf = (item: ChatConversationItem) =>
        counter.ready ? counter.isMuted(item.id) : item.muted;
    const unreadIn = (section: ChatSectionKey) =>
        sectionItems(baseTree, section)
            .filter((item) => !mutedOf(item))
            .reduce((sum, item) => sum + unreadOf(item), 0);
    const totalIn = (tree: typeof baseTree, scope: ChatListView) =>
        (scope === 'all' ? sections : [scope]).reduce(
            (sum, section) => sum + sectionItems(tree, section).length,
            0,
        );
    const row = (item: ChatConversationItem, nested = false) => (
        <ConversationRow
            item={item}
            active={item.id === activeId}
            unread={unreadOf(item)}
            counterReady={counter.ready}
            nested={nested}
        />
    );

    return (
        <div className={cn('flex min-h-0 flex-col', className)}>
            <div className="flex items-center gap-2 border-b px-4 py-2.5">
                <h1 className="flex-1 text-xl font-normal text-foreground">
                    {t('chat.title')}
                </h1>
                <Button asChild variant="ghost" size="icon" className="size-9">
                    <Link
                        href={chatSearch()}
                        aria-label={t('chat_media.search.title')}
                        title={t('chat_media.search.title')}
                        data-test="chat-search-link"
                    >
                        <Search aria-hidden="true" />
                    </Link>
                </Button>
                <Popover>
                    <PopoverTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-9"
                            aria-label={t('realtime.push.label')}
                            title={t('realtime.push.label')}
                            data-test="chat-push"
                        >
                            <BellRing aria-hidden="true" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent align="end" className="w-80">
                        <PushNotificationsToggle />
                    </PopoverContent>
                </Popover>
                {isAdmin ? (
                    <Button
                        ref={moderationButton}
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-9"
                        onClick={() => setModeration(true)}
                        aria-label={t('chat.moderation.open')}
                        title={t('chat.moderation.open')}
                        data-test="chat-moderation"
                    >
                        <ShieldCheck aria-hidden="true" />
                    </Button>
                ) : null}
                {canStart ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                ref={newButton}
                                type="button"
                                size="sm"
                                aria-label={t(
                                    isAdmin
                                        ? 'chat.list.new_menu_admin'
                                        : 'chat.list.new_menu',
                                )}
                                data-test="chat-new"
                            >
                                <Plus aria-hidden="true" />
                                {t('chat.list.new')}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onSelect={() => setDirect(true)}>
                                <MessageSquarePlus aria-hidden="true" />
                                {t('chat.list.new_direct')}
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => setGroup(true)}>
                                <Users aria-hidden="true" />
                                {t('chat.list.new_group')}
                            </DropdownMenuItem>
                            {isAdmin ? (
                                <DropdownMenuItem
                                    onSelect={() => setChannel(true)}
                                    data-test="chat-new-channel"
                                >
                                    <Hash aria-hidden="true" />
                                    {t('chat.list.new_channel')}
                                </DropdownMenuItem>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
            </div>

            <div className="flex min-h-0 flex-1">
                {showRail && items.length > 0 ? (
                    <ChatRail
                        views={['all', ...sections]}
                        current={view}
                        controls={listId}
                        unread={(scope) =>
                            scope === 'all'
                                ? sections.reduce(
                                      (sum, section) => sum + unreadIn(section),
                                      0,
                                  )
                                : unreadIn(scope)
                        }
                        onChange={setView}
                    />
                ) : null}
                <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                    {items.length > 0 ? (
                        <div className="grid gap-2 px-3 py-2">
                            <div className="relative">
                                <Search
                                    aria-hidden="true"
                                    className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                                />
                                <label htmlFor={searchId} className="sr-only">
                                    {t('chat.list.search')}
                                </label>
                                <Input
                                    id={searchId}
                                    type="search"
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder={t(
                                        'chat.list.search_placeholder',
                                    )}
                                    className="pl-8"
                                    data-test="chat-list-search"
                                />
                            </div>
                            <div
                                role="group"
                                aria-label={t('chat.list.filters')}
                                className="flex flex-wrap gap-1.5"
                            >
                                <FilterToggle
                                    pressed={prefs.mineOnly}
                                    onChange={setMineOnly}
                                    icon={<UserCheck aria-hidden="true" />}
                                    label={t('chat.list.mine_only')}
                                    test="chat-filter-mine"
                                />
                                <FilterToggle
                                    pressed={prefs.hideArchived}
                                    onChange={setHideArchived}
                                    icon={<Archive aria-hidden="true" />}
                                    label={
                                        prefs.hideArchived &&
                                        tree.hiddenArchived > 0
                                            ? t('chat.list.hidden_archived', {
                                                  count: tree.hiddenArchived,
                                              })
                                            : t('chat.list.hide_archived')
                                    }
                                    test="chat-filter-archived"
                                />
                            </div>
                        </div>
                    ) : null}

                    {loadError ? (
                        <p
                            role="status"
                            className="mx-3 mb-2 flex flex-wrap items-center gap-2 rounded-md bg-warning-soft px-3 py-2 text-xs text-foreground"
                        >
                            {t('chat.errors.list')}
                            {onRetry ? (
                                <Button
                                    type="button"
                                    variant="link"
                                    size="sm"
                                    className="h-auto px-0 text-xs"
                                    onClick={onRetry}
                                >
                                    {t('chat.errors.retry')}
                                </Button>
                            ) : null}
                        </p>
                    ) : null}

                    <nav
                        id={listId}
                        aria-label={t('chat.list.label')}
                        // Solo en vertical: la lista nunca se mueve de lado (los textos largos se cortan).
                        className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-x-none"
                        data-test="chat-conversation-list"
                    >
                        {items.length === 0 ? (
                            <div className="p-4">
                                <EmptyState
                                    icon={MessagesSquare}
                                    title={t('chat.list.empty.title')}
                                    description={t(
                                        canStart
                                            ? 'chat.list.empty.description'
                                            : 'chat.list.empty.description_collaborator',
                                    )}
                                >
                                    {canStart ? (
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setDirect(true)}
                                            >
                                                <MessageSquarePlus aria-hidden="true" />
                                                {t('chat.list.new_direct')}
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setGroup(true)}
                                            >
                                                <Users aria-hidden="true" />
                                                {t('chat.list.new_group')}
                                            </Button>
                                        </div>
                                    ) : null}
                                </EmptyState>
                            </div>
                        ) : query.trim() !== '' && totalIn(tree, view) === 0 ? (
                            <div className="grid gap-2 p-4" role="status">
                                <p className="text-sm text-muted-foreground">
                                    {t('chat.list.no_results', {
                                        query: query.trim(),
                                    })}
                                </p>
                                {view !== 'all' && !isTreeEmpty(tree) ? (
                                    <div>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setView('all')}
                                            data-test="chat-search-all"
                                        >
                                            {t('chat.list.search_in_all', {
                                                count: totalIn(tree, 'all'),
                                            })}
                                        </Button>
                                    </div>
                                ) : null}
                            </div>
                        ) : (
                            <div className="grid gap-3 p-1" data-view={view}>
                                {shown.map((section) => {
                                    const visible = sectionItems(tree, section);
                                    const empty =
                                        section === 'clients'
                                            ? tree.clients.length === 0
                                            : visible.length === 0;

                                    // En «Todo», un tipo vacío (por la búsqueda o los filtros) no se pinta.
                                    if (view === 'all' && empty) {
                                        return null;
                                    }

                                    return (
                                        <section
                                            key={section}
                                            aria-labelledby={`${listId}-${section}`}
                                            data-test={`chat-section-${section}`}
                                        >
                                            <h2
                                                id={`${listId}-${section}`}
                                                className={cn(
                                                    'px-3 pt-1 pb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase',
                                                    view !== 'all' && 'sr-only',
                                                )}
                                            >
                                                {t(`chat.sections.${section}`)}
                                            </h2>
                                            {empty ? (
                                                <EmptySection
                                                    section={section}
                                                />
                                            ) : section === 'clients' ? (
                                                <ul className="grid gap-px">
                                                    {tree.clients.map(
                                                        (node) => (
                                                            <ClientGroup
                                                                key={node.key}
                                                                node={node}
                                                                row={row}
                                                            />
                                                        ),
                                                    )}
                                                </ul>
                                            ) : (
                                                <ul className="grid gap-px">
                                                    {visible.map((item) => (
                                                        <li key={item.id}>
                                                            {row(item)}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </section>
                                    );
                                })}
                            </div>
                        )}
                    </nav>
                </div>
            </div>

            <NewDirectDialog
                open={direct}
                onOpenChange={setDirect}
                returnFocus={newButton}
            />
            <NewGroupDialog
                open={group}
                onOpenChange={setGroup}
                returnFocus={newButton}
            />
            {isAdmin ? (
                <>
                    <ChannelDialog
                        open={channel}
                        onOpenChange={setChannel}
                        returnFocus={newButton}
                    />
                    <ModerationDialog
                        open={moderation}
                        onOpenChange={setModeration}
                        returnFocus={moderationButton}
                    />
                </>
            ) : null}
        </div>
    );
}

function FilterToggle({
    pressed,
    onChange,
    icon,
    label,
    test,
}: {
    pressed: boolean;
    onChange: (pressed: boolean) => void;
    icon: ReactNode;
    label: string;
    test: string;
}) {
    return (
        <Button
            type="button"
            size="sm"
            variant={pressed ? 'secondary' : 'ghost'}
            aria-pressed={pressed}
            onClick={() => onChange(!pressed)}
            className="h-7 px-2 text-xs"
            data-test={test}
        >
            {icon}
            {label}
        </Button>
    );
}

/** Orden de la barra: lo más usado primero (directos), después proyectos y clientes y canales. */
const CHAT_SECTIONS_BY_USE: ChatSectionKey[] = CHAT_LIST_VIEWS.filter(
    (view): view is ChatSectionKey =>
        view !== 'all' && CHAT_SECTIONS.includes(view),
);

const VIEW_ICONS: Record<ChatListView, LucideIcon> = {
    all: MessagesSquare,
    direct: MessageCircle,
    clients: FolderKanban,
    channels: Hash,
};

/**
 * Barra de iconos de la lista (D-246, como Teams): Todo, Directos, Proyectos y Canales, cada uno
 * con su nombre debajo y sus no leídos (sin las silenciadas). Es un grupo de pestañas vertical:
 * flechas arriba y abajo, Inicio y Fin cambian de tipo.
 */
function ChatRail({
    views,
    current,
    controls,
    unread,
    onChange,
}: {
    views: ChatListView[];
    current: ChatListView;
    controls: string;
    unread: (view: ChatListView) => number;
    onChange: (view: ChatListView) => void;
}) {
    const refs = useRef<(HTMLButtonElement | null)[]>([]);

    const move = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
        const last = views.length - 1;
        const next =
            event.key === 'ArrowDown'
                ? (index + 1) % views.length
                : event.key === 'ArrowUp'
                  ? (index - 1 + views.length) % views.length
                  : event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                      ? last
                      : null;

        if (next === null) {
            return;
        }
        event.preventDefault();
        onChange(views[next]);
        refs.current[next]?.focus();
    };

    return (
        <div
            role="tablist"
            aria-orientation="vertical"
            aria-label={t('chat.rail.label')}
            className="flex w-[4.75rem] shrink-0 flex-col gap-1 border-r p-1.5"
            data-test="chat-rail"
        >
            {views.map((view, index) => {
                const Icon = VIEW_ICONS[view];
                const selected = view === current;
                const count = unread(view);

                return (
                    <button
                        key={view}
                        ref={(element) => {
                            refs.current[index] = element;
                        }}
                        type="button"
                        role="tab"
                        aria-selected={selected}
                        aria-controls={controls}
                        tabIndex={selected ? 0 : -1}
                        onClick={() => onChange(view)}
                        onKeyDown={(event) => move(event, index)}
                        className={cn(
                            'relative flex flex-col items-center gap-1 rounded-md px-1 py-2 text-[11px] leading-tight text-muted-foreground hover:bg-muted hover:text-foreground',
                            selected &&
                                'bg-accent text-foreground hover:bg-accent',
                            FOCUS_RING,
                            'focus-visible:ring-offset-0',
                        )}
                        data-test={`chat-rail-${view}`}
                    >
                        <Icon aria-hidden="true" className="size-5" />
                        <span>{t(`chat.rail.${view}`)}</span>
                        {count > 0 ? (
                            <span
                                aria-hidden="true"
                                className="tabular absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-medium text-primary-foreground"
                                data-test={`chat-rail-unread-${view}`}
                            >
                                {count > 99 ? '99+' : count}
                            </span>
                        ) : null}
                        {count > 0 ? (
                            <span className="sr-only">
                                {t('chat.list.unread', { count })}
                            </span>
                        ) : null}
                    </button>
                );
            })}
        </div>
    );
}

function EmptySection({ section }: { section: ChatSectionKey }) {
    return (
        <p className="px-3 py-2 text-xs text-muted-foreground">
            {t(`chat.sections.empty.${section}`)}
        </p>
    );
}

/**
 * Un cliente del nivel «Proyectos y clientes»: su canal como cabecera (si aún no existe, un enlace
 * que lo crea al abrirlo, D-271) y, sangrados debajo, los chats de sus proyectos. Los proyectos
 * internos (sin cliente) van bajo un título sin canal.
 */
function ClientGroup({
    node,
    row,
}: {
    node: ChatClientNode;
    row: (item: ChatConversationItem, nested?: boolean) => ReactNode;
}) {
    const title = node.client?.name ?? t('chat.sections.internal');

    return (
        <li data-test="chat-client-group">
            {node.channel ? (
                row(node.channel)
            ) : node.client ? (
                <Link
                    href={urls.chatClient(node.client.id)}
                    className={cn(
                        'flex items-center gap-3 rounded-md px-3 py-2.5 hover:bg-muted',
                        FOCUS_RING,
                        'focus-visible:ring-offset-0',
                    )}
                    data-test="chat-client-open"
                >
                    <ConversationAvatar
                        conversation={{
                            type: 'client',
                            title,
                            icon: node.client.icon,
                            other_user: null,
                            project: null,
                        }}
                    />
                    <span className="grid min-w-0 flex-1 gap-0.5">
                        <span className="truncate text-sm text-foreground">
                            {title}
                        </span>
                        <span className="truncate text-xs text-muted-foreground">
                            {t('chat.list.open_client_channel')}
                        </span>
                    </span>
                </Link>
            ) : (
                <p className="px-3 pt-2 pb-1 text-xs text-muted-foreground">
                    {title}
                </p>
            )}
            {node.projects.length > 0 ? (
                <ul
                    className="grid gap-px"
                    aria-label={t('chat.sections.projects_of', {
                        client: title,
                    })}
                >
                    {node.projects.map((item) => (
                        <li key={item.id}>{row(item, true)}</li>
                    ))}
                </ul>
            ) : null}
        </li>
    );
}
