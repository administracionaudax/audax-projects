import { FolderKanban, Users } from 'lucide-react';
import type { PresenceStatus } from '@/components/chat/realtime-bridge';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ChatConversationItem, ChatUser } from '@/types/chat';

const PRESENCE_CLASSES: Record<PresenceStatus, string> = {
    online: 'bg-success',
    away: 'bg-warning',
    offline: 'bg-muted-foreground',
};

/** Avatar de una persona con sus iniciales y, si se sabe (tiempo real), su presencia. */
export function ChatAvatar({
    user,
    presence = null,
    small = false,
    className,
}: {
    user: Pick<ChatUser, 'name' | 'avatar' | 'is_active'>;
    presence?: PresenceStatus | null;
    small?: boolean;
    className?: string;
}) {
    const initials = useInitials();

    return (
        <span className={cn('relative inline-flex shrink-0', className)}>
            <Avatar
                className={cn(
                    small ? 'size-6' : 'size-8',
                    !user.is_active && 'grayscale',
                )}
            >
                <AvatarImage src={user.avatar ?? undefined} alt="" />
                <AvatarFallback className="bg-neutral-soft text-xs font-medium text-foreground">
                    {initials(user.name)}
                </AvatarFallback>
            </Avatar>
            {presence ? (
                <span
                    className={cn(
                        'absolute -right-0.5 -bottom-0.5 size-2.5 rounded-full ring-2 ring-background',
                        PRESENCE_CLASSES[presence],
                    )}
                >
                    <span className="sr-only">
                        {t(`chat.presence.${presence}`)}
                    </span>
                </span>
            ) : null}
        </span>
    );
}

/** Icono de una conversación: la persona (directa), el color del proyecto o un grupo. */
export function ConversationAvatar({
    conversation,
    presence = null,
}: {
    conversation: Pick<
        ChatConversationItem,
        'type' | 'other_user' | 'project' | 'title'
    >;
    presence?: PresenceStatus | null;
}) {
    if (conversation.type === 'direct' && conversation.other_user) {
        return (
            <ChatAvatar user={conversation.other_user} presence={presence} />
        );
    }

    const Icon = conversation.type === 'project' ? FolderKanban : Users;

    return (
        <span
            aria-hidden="true"
            className="relative flex size-8 shrink-0 items-center justify-center rounded-full bg-neutral-soft text-foreground"
        >
            <Icon className="size-4" strokeWidth={1.5} />
            {conversation.project ? (
                <span
                    className="absolute -right-0.5 -bottom-0.5 size-2.5 rounded-full ring-2 ring-background"
                    style={{ backgroundColor: conversation.project.color }}
                />
            ) : null}
        </span>
    );
}
