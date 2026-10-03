import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    Bell,
    BellOff,
    ExternalLink,
    Settings2,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { RefObject } from 'react';
import { ChatAvatar, ConversationAvatar } from '@/components/chat/chat-avatar';
import { GroupSettingsDialog } from '@/components/chat/group-settings-dialog';
import { usePresence } from '@/components/chat/realtime-bridge';
import { PresenceLabel } from '@/components/realtime';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useRequiredUser } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import type { ChatConversation } from '@/types/chat';

/**
 * Cabecera de una conversación: nombre (proyecto, persona o grupo), a qué corresponde, los
 * participantes (inactivos marcados; presencia de C2), gestionar el grupo (D-119) y
 * silenciar/activar avisos. En el móvil, un botón vuelve a la lista (pantallas separadas).
 * El título es enfocable: al abrir una conversación en el móvil el foco va a él.
 */
export function ConversationHeader({
    conversation,
    muted,
    onToggleMute,
    backHref,
    showProjectLink = true,
    titleRef,
}: {
    conversation: ChatConversation;
    muted: boolean;
    onToggleMute: () => void;
    /** Solo en /chat: vuelve a la lista en el móvil. */
    backHref?: string;
    showProjectLink?: boolean;
    titleRef?: RefObject<HTMLHeadingElement | null>;
}) {
    const user = useRequiredUser();
    const [settings, setSettings] = useState(false);
    const settingsButton = useRef<HTMLButtonElement>(null);
    // Antes del primer dato de presencia, la directa dice solo que lo es.
    const { ready: presenceReady } = usePresence();
    const other = conversation.other_user;
    const subtitle =
        conversation.type === 'project' ? (
            conversation.subtitle
        ) : conversation.type === 'group' ? (
            t('chat.header.members', {
                count: conversation.participants.length,
            })
        ) : other && presenceReady ? (
            <PresenceLabel userId={other.id} />
        ) : (
            t('chat.list.type.direct')
        );

    return (
        <header className="flex items-center gap-2 border-b px-3 py-2 md:px-4">
            {backHref ? (
                <Button
                    asChild
                    variant="ghost"
                    size="icon"
                    className="size-9 md:hidden"
                >
                    <Link href={backHref} aria-label={t('chat.header.back')}>
                        <ArrowLeft aria-hidden="true" />
                    </Link>
                </Button>
            ) : null}
            <ConversationAvatar conversation={conversation} />
            <div className="min-w-0 flex-1">
                <h2
                    ref={titleRef}
                    tabIndex={-1}
                    className="truncate rounded-md text-base text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    data-test="chat-conversation-title"
                >
                    {conversation.title}
                </h2>
                <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                    {subtitle ? <span>{subtitle}</span> : null}
                    {other && !other.is_active ? (
                        <span className="rounded-md border px-1">
                            {t('chat.messages.inactive')}
                        </span>
                    ) : null}
                    {!conversation.is_participant ? (
                        <span className="inline-flex items-center gap-1">
                            <ShieldCheck
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            {t('chat.header.moderating')}
                        </span>
                    ) : null}
                </p>
            </div>

            {showProjectLink && conversation.project ? (
                <Button
                    asChild
                    variant="ghost"
                    size="sm"
                    className="hidden sm:inline-flex"
                >
                    <Link href={urls.project(conversation.project.id)}>
                        <ExternalLink aria-hidden="true" />
                        {t('chat.header.open_project')}
                    </Link>
                </Button>
            ) : null}

            {conversation.type !== 'direct' ? (
                <Popover>
                    <PopoverTrigger asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label={t('chat.header.participants', {
                                count: conversation.participants.length,
                            })}
                        >
                            <Users aria-hidden="true" />
                            <span className="tabular" aria-hidden="true">
                                {conversation.participants.length}
                            </span>
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent align="end" className="w-72 p-0">
                        <p className="border-b px-3 py-2 text-sm font-medium">
                            {t('chat.header.participants_title')}
                        </p>
                        <ul className="max-h-72 overflow-y-auto p-1">
                            {conversation.participants.map((person) => {
                                return (
                                    <li
                                        key={person.id}
                                        className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm"
                                    >
                                        <ChatAvatar
                                            small
                                            user={person}
                                            presenceOf={person.id}
                                        />
                                        <span className="min-w-0 flex-1 truncate">
                                            {person.name}
                                        </span>
                                        {!person.is_active ? (
                                            <span className="rounded-md border px-1 text-xs text-muted-foreground">
                                                {t('chat.messages.inactive')}
                                            </span>
                                        ) : null}
                                    </li>
                                );
                            })}
                        </ul>
                    </PopoverContent>
                </Popover>
            ) : null}

            {conversation.type === 'group' &&
            (conversation.can.manage || conversation.can.leave) ? (
                <>
                    <Button
                        ref={settingsButton}
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-9"
                        onClick={() => setSettings(true)}
                        aria-label={t('chat.group_settings.open')}
                        title={t('chat.group_settings.open')}
                        data-test="chat-group-settings-open"
                    >
                        <Settings2 aria-hidden="true" />
                    </Button>
                    <GroupSettingsDialog
                        conversation={conversation}
                        open={settings}
                        onOpenChange={setSettings}
                        currentUserId={user.id}
                        returnFocus={settingsButton}
                    />
                </>
            ) : null}

            {conversation.can.mute ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-9"
                    onClick={onToggleMute}
                    aria-pressed={muted}
                    aria-label={t('chat.header.mute')}
                    title={
                        muted ? t('chat.header.muted') : t('chat.header.mute')
                    }
                    data-test="chat-mute"
                >
                    {muted ? (
                        <BellOff aria-hidden="true" />
                    ) : (
                        <Bell aria-hidden="true" />
                    )}
                </Button>
            ) : null}
        </header>
    );
}
