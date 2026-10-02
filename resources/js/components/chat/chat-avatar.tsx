import { FolderKanban, Users } from 'lucide-react';
import { usePresence } from '@/components/chat/realtime-bridge';
import { PresenceDot } from '@/components/realtime';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import type { ChatConversationItem, ChatUser } from '@/types/chat';

/**
 * Punto de presencia de C2 (en línea, ausente o desconectado, con forma y texto), solo cuando ya
 * se conoce (con o sin tiempo real, tras el primer dato).
 */
function Presence({ userId }: { userId: number | null | undefined }) {
    const { ready } = usePresence();

    return userId != null && ready ? <PresenceDot userId={userId} /> : null;
}

/** Avatar de una persona con sus iniciales y, si se pide, su presencia (C2). */
export function ChatAvatar({
    user,
    presenceOf = null,
    small = false,
    className,
}: {
    user: Pick<ChatUser, 'name' | 'avatar' | 'is_active'>;
    /** Id de la persona cuya presencia se pinta en la esquina. */
    presenceOf?: number | null;
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
            <Presence userId={presenceOf} />
        </span>
    );
}

/** Icono de una conversación: la persona (directa, con su presencia), el color del proyecto o un grupo. */
export function ConversationAvatar({
    conversation,
}: {
    conversation: Pick<
        ChatConversationItem,
        'type' | 'other_user' | 'project' | 'title'
    >;
}) {
    if (conversation.type === 'direct' && conversation.other_user) {
        return (
            <ChatAvatar
                user={conversation.other_user}
                presenceOf={conversation.other_user.id}
            />
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
