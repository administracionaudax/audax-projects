import { Link, usePage } from '@inertiajs/react';
import {
    Archive,
    BellOff,
    BellRing,
    MessageSquarePlus,
    MessagesSquare,
    Plus,
    Search,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { ConversationAvatar } from '@/components/chat/chat-avatar';
import { formatListTime } from '@/components/chat/chat-format';
import { normalizeSearch } from '@/components/chat/mentions';
import { ModerationDialog } from '@/components/chat/moderation-dialog';
import {
    NewDirectDialog,
    NewGroupDialog,
} from '@/components/chat/new-conversation-dialogs';
import { useUnreadCounter } from '@/components/chat/realtime-bridge';
import { systemText } from '@/components/chat/system-notice';
import { EmptyState } from '@/components/empty-state';
import {
    ConversationUnreadBadge,
    PushNotificationsToggle,
} from '@/components/realtime';
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

/** Props que cambian al pasar de una conversación a otra (la lista no se vuelve a pedir). */
export const CONVERSATION_PROPS = [
    'conversation',
    'messages',
    'pinned',
    'focus',
];

/** Texto de la vista previa: «Tú: …», «Ana: …» (en grupos y proyectos) o el tipo de mensaje. */
export function previewText(item: ChatConversationItem): string {
    const last = item.last_message;

    if (!last) {
        return t('chat.list.no_messages');
    }

    const body =
        last.kind === 'system' && last.system
            ? systemText(last.system)
            : last.kind === 'hidden'
              ? t('chat.list.hidden')
              : last.kind === 'audio'
                ? t('chat.list.audio')
                : last.kind === 'file' && last.preview === ''
                  ? t('chat.list.file')
                  : last.preview;

    if (last.kind === 'system') {
        return body;
    }

    if (last.is_mine) {
        return `${t('chat.list.you')}: ${body}`;
    }

    return item.type !== 'direct' && last.author
        ? `${last.author.split(/\s+/u)[0]}: ${body}`
        : body;
}

function matches(item: ChatConversationItem, needle: string): boolean {
    return normalizeSearch(
        [item.title, item.subtitle ?? '', item.other_user?.name ?? ''].join(
            ' ',
        ),
    ).includes(needle);
}

/**
 * Lista de conversaciones de /chat (SPEC §12): nombre, vista previa sin markdown, hora, no leídos
 * (con número y texto; en vivo con los contadores de C2), presencia en las directas, silenciadas y
 * proyectos archivados marcados (icono y texto visible), filtro, «Buscar en el chat» (C3), «Avisos
 * en este navegador» (Web Push, C2), «Nuevo» (mensaje directo o grupo) y, para el admin,
 * «Moderar» (D-119). Estados vacío, sin resultados y de error. Los diálogos que se abren desde el
 * menú «Nuevo» devuelven el foco a ese botón al cerrarse.
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
    const [moderation, setModeration] = useState(false);
    const newButton = useRef<HTMLButtonElement>(null);
    const moderationButton = useRef<HTMLButtonElement>(null);
    // El admin modera chats ajenos (D-119). (Sin sesión en la prop, como en algún test, no.)
    const isAdmin =
        usePage().props.auth?.user?.roles?.includes('admin') ?? false;
    const searchId = useId();
    // Los no leídos de cada fila: los de C2 en cuanto llega su primer recuento; antes, los de la lista.
    const counter = useUnreadCounter();
    const needle = normalizeSearch(query.trim());
    const visible =
        needle === '' ? items : items.filter((item) => matches(item, needle));

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
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            ref={newButton}
                            type="button"
                            size="sm"
                            aria-label={t('chat.list.new_menu')}
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
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            {items.length > 0 ? (
                <div className="relative px-3 py-2">
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-4.5 left-5.5 size-4 text-muted-foreground"
                    />
                    <label htmlFor={searchId} className="sr-only">
                        {t('chat.list.search')}
                    </label>
                    <Input
                        id={searchId}
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder={t('chat.list.search_placeholder')}
                        className="pl-8"
                        data-test="chat-list-search"
                    />
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
                aria-label={t('chat.list.label')}
                className="min-h-0 flex-1 overflow-y-auto"
                data-test="chat-conversation-list"
            >
                {items.length === 0 ? (
                    <div className="p-4">
                        <EmptyState
                            icon={MessagesSquare}
                            title={t('chat.list.empty.title')}
                            description={t('chat.list.empty.description')}
                        >
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
                        </EmptyState>
                    </div>
                ) : visible.length === 0 ? (
                    <p
                        className="p-4 text-sm text-muted-foreground"
                        role="status"
                    >
                        {t('chat.list.no_results', { query: query.trim() })}
                    </p>
                ) : (
                    <ul className="grid gap-px p-1">
                        {visible.map((item) => {
                            const active = item.id === activeId;
                            const unread = counter.ready
                                ? counter.count(item.id)
                                : item.unread;

                            return (
                                <li key={item.id}>
                                    <Link
                                        href={urls.chatConversation(item.id)}
                                        only={CONVERSATION_PROPS}
                                        preserveState
                                        preserveScroll
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                        className={cn(
                                            'flex items-center gap-3 rounded-md px-3 py-2.5 hover:bg-muted',
                                            active &&
                                                'bg-accent hover:bg-accent',
                                            FOCUS_RING,
                                            'focus-visible:ring-offset-0',
                                        )}
                                        data-test="chat-conversation-item"
                                    >
                                        <ConversationAvatar
                                            conversation={item}
                                        />
                                        <span className="grid min-w-0 flex-1 gap-0.5">
                                            <span className="flex items-baseline gap-1.5">
                                                <span
                                                    className={cn(
                                                        'truncate text-sm text-foreground',
                                                        unread > 0 &&
                                                            'font-medium',
                                                    )}
                                                >
                                                    {item.title}
                                                </span>
                                                {item.muted ? (
                                                    <span
                                                        className="inline-flex shrink-0 items-center gap-0.5 self-center text-[11px] text-muted-foreground"
                                                        data-test="chat-item-muted"
                                                    >
                                                        <BellOff
                                                            aria-hidden="true"
                                                            className="size-3.5"
                                                        />
                                                        {t('chat.list.muted')}
                                                    </span>
                                                ) : null}
                                                {item.read_only ? (
                                                    <span
                                                        className="inline-flex shrink-0 items-center gap-0.5 self-center text-[11px] text-muted-foreground"
                                                        data-test="chat-item-read-only"
                                                    >
                                                        <Archive
                                                            aria-hidden="true"
                                                            className="size-3.5"
                                                        />
                                                        {t(
                                                            'chat.list.read_only_short',
                                                        )}
                                                    </span>
                                                ) : null}
                                                <span className="tabular ml-auto shrink-0 text-xs text-muted-foreground">
                                                    {formatListTime(
                                                        item.last_activity_at,
                                                    )}
                                                </span>
                                            </span>
                                            <span className="flex items-center gap-2">
                                                <span className="truncate text-xs text-muted-foreground">
                                                    {previewText(item)}
                                                </span>
                                                {counter.ready ? (
                                                    <ConversationUnreadBadge
                                                        conversationId={item.id}
                                                        className="ml-auto shrink-0"
                                                    />
                                                ) : unread > 0 ? (
                                                    <span
                                                        aria-hidden="true"
                                                        className={cn(
                                                            'tabular ml-auto flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 text-[11px] font-medium',
                                                            item.muted
                                                                ? 'bg-neutral-soft text-foreground'
                                                                : 'bg-primary text-primary-foreground',
                                                        )}
                                                    >
                                                        {unread > 99
                                                            ? '99+'
                                                            : unread}
                                                    </span>
                                                ) : null}
                                            </span>
                                            <span className="sr-only">
                                                {[
                                                    t(
                                                        `chat.list.type.${item.type}`,
                                                    ),
                                                    // Con C2, el número lo dice su propio contador.
                                                    !counter.ready && unread > 0
                                                        ? t(
                                                              'chat.list.unread',
                                                              {
                                                                  count: unread,
                                                              },
                                                          )
                                                        : null,
                                                    // «Silenciada» y «Solo lectura» ya se leen
                                                    // en su texto visible; aquí, el porqué.
                                                    item.read_only
                                                        ? t(
                                                              'chat.list.read_only',
                                                          )
                                                        : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join('. ')}
                                            </span>
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </nav>

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
                <ModerationDialog
                    open={moderation}
                    onOpenChange={setModeration}
                    returnFocus={moderationButton}
                />
            ) : null}
        </div>
    );
}
