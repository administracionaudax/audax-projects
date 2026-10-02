import { Link } from '@inertiajs/react';
import {
    CircleAlert,
    EyeOff,
    ListChecks,
    LoaderCircle,
    Pin,
    Reply,
    Trash2,
} from 'lucide-react';
import { ChatAvatar } from '@/components/chat/chat-avatar';
import {
    formatFullDateTime,
    formatMessageTime,
} from '@/components/chat/chat-format';
import { Composer } from '@/components/chat/composer';
import type { MentionPerson } from '@/components/chat/composer';
import { LinkPreviewCard } from '@/components/chat/link-preview-card';
import { MarkdownText, mentionsUser } from '@/components/chat/markdown';
import type { MentionNames } from '@/components/chat/markdown';
import { AttachmentList, AudioMessage } from '@/components/chat/media-bridge';
import { bodyToEditable } from '@/components/chat/mentions';
import { MessageToolbar } from '@/components/chat/message-toolbar';
import type { MessageActionHandlers } from '@/components/chat/message-toolbar';
import { Reactions } from '@/components/chat/reactions';
import { systemIcon, systemText } from '@/components/chat/system-notice';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { ChatMessage, ChatParent } from '@/types/chat';

export type MessageItemHandlers = MessageActionHandlers & {
    onJump: (messageId: number) => void;
    onRetry: () => void;
    onDiscard: () => void;
    onEditSubmit: (body: string) => Promise<boolean>;
    onEditCancel: () => void;
};

function authorName(message: ChatMessage): string {
    return message.author?.name ?? t('chat.messages.unknown_author');
}

function Time({ value }: { value: string }) {
    return (
        <time
            dateTime={value}
            title={formatFullDateTime(value)}
            className="tabular text-xs text-muted-foreground"
        >
            {formatMessageTime(value)}
        </time>
    );
}

/** Cita del mensaje al que responde: al pulsarla se va a él (aunque no esté cargado). */
function ParentQuote({
    parent,
    onJump,
}: {
    parent: ChatParent;
    onJump: (id: number) => void;
}) {
    const author = parent.author ?? t('chat.messages.unknown_author');
    const excerpt = parent.deleted
        ? t('chat.messages.reply_to_deleted')
        : parent.system
          ? systemText(parent.system)
          : parent.hidden && parent.excerpt === null
            ? t('chat.messages.reply_to_hidden')
            : (parent.excerpt ?? '');

    return (
        <button
            type="button"
            onClick={() => onJump(parent.id)}
            aria-label={`${t('chat.messages.go_to_parent', { name: author })}: ${excerpt}`}
            className={cn(
                'mb-1 flex max-w-full items-start gap-1.5 rounded-[3px] border-l-2 border-border bg-muted px-2 py-1 text-left text-xs hover:bg-accent',
                FOCUS_RING,
            )}
            data-test="chat-parent-quote"
        >
            <Reply
                aria-hidden="true"
                className="mt-0.5 size-3 shrink-0 text-muted-foreground"
            />
            <span className="min-w-0">
                <span className="text-foreground">
                    {t('chat.messages.reply_to', { name: author })}
                </span>{' '}
                <span className="line-clamp-2 break-words text-muted-foreground">
                    {excerpt}
                </span>
            </span>
        </button>
    );
}

/**
 * Un mensaje (SPEC §12): avatar, nombre (los inactivos, marcados), hora y «editado»; cita del
 * hilo; cuerpo en markdown ligero saneado; audio y adjuntos (C3); previsualización del enlace;
 * tarea creada; reacciones; fijado; y sus acciones. Borrado: «Mensaje eliminado»; ocultado por
 * un admin: el aviso (quien modera lo ve con el contenido). Los de sistema, con icono y texto.
 */
export function MessageItem({
    message,
    showHeader,
    currentUserId,
    names,
    people,
    highlighted,
    editing,
    handlers,
}: {
    message: ChatMessage;
    /** Primer mensaje de un grupo del mismo autor: con avatar y nombre. */
    showHeader: boolean;
    currentUserId: number;
    names: MentionNames;
    /** Personas mencionables, para el editor al editar. */
    people: MentionPerson[];
    highlighted: boolean;
    editing: boolean;
    handlers: MessageItemHandlers;
}) {
    const name = authorName(message);
    const time = formatMessageTime(message.created_at);
    const pending = message.pending;
    const hasActions = !pending && !message.deleted;

    if (message.type === 'system' && message.system) {
        const Icon = systemIcon(message.system);

        return (
            <li
                id={`mensaje-${message.id}`}
                data-message-id={message.id}
                className={cn(
                    'group/message relative flex justify-center px-4 py-1.5',
                    highlighted && 'bg-accent',
                )}
            >
                <article
                    aria-label={t('chat.system.label', { time })}
                    className="flex max-w-full flex-wrap items-center justify-center gap-x-2 gap-y-1 rounded-[3px] bg-muted px-3 py-1.5 text-center text-xs"
                    data-test="chat-system-message"
                >
                    <Icon
                        aria-hidden="true"
                        className="size-3.5 shrink-0 text-muted-foreground"
                    />
                    <span className="text-foreground">
                        {systemText(message.system)}
                    </span>
                    <Time value={message.created_at} />
                    {message.pinned ? (
                        <Pin
                            aria-label={t('chat.messages.pinned')}
                            className="size-3 text-muted-foreground"
                        />
                    ) : null}
                </article>
                {hasActions ? (
                    <MessageToolbar
                        message={message}
                        authorName={t('chat.system.generic')}
                        handlers={handlers}
                    />
                ) : null}
            </li>
        );
    }

    const mentionsMe =
        message.author?.id !== currentUserId &&
        mentionsUser(message.body, currentUserId);
    const visibleBody = !message.deleted && message.body !== null;

    return (
        <li
            id={`mensaje-${message.id}`}
            data-message-id={message.id}
            className={cn(
                'group/message relative border-l-2 border-transparent px-4 transition-colors hover:bg-muted',
                showHeader ? 'mt-2 pt-1.5 pb-0.5' : 'py-0.5',
                mentionsMe && 'border-info',
                highlighted && 'bg-accent hover:bg-accent',
                pending === 'sending' && 'text-muted-foreground',
            )}
            data-test="chat-message"
        >
            <article
                aria-label={t('chat.messages.from', { name, time })}
                className="flex gap-3"
            >
                <div className="w-8 shrink-0 pt-0.5">
                    {showHeader ? (
                        message.author ? (
                            <ChatAvatar user={message.author} />
                        ) : null
                    ) : (
                        <span
                            aria-hidden="true"
                            className="tabular hidden text-[10px] leading-5 text-muted-foreground group-hover/message:inline"
                        >
                            {time}
                        </span>
                    )}
                </div>
                <div className="min-w-0 flex-1 pr-8 md:pr-0">
                    {showHeader ? (
                        <header className="flex flex-wrap items-baseline gap-x-2">
                            <span className="text-sm font-medium text-foreground">
                                {name}
                            </span>
                            {message.author && !message.author.is_active ? (
                                <span className="rounded-[3px] border px-1 text-xs text-muted-foreground">
                                    {t('chat.messages.inactive')}
                                </span>
                            ) : null}
                            <Time value={message.created_at} />
                            {message.edited_at ? (
                                <span className="text-xs text-muted-foreground">
                                    ({t('chat.messages.edited')})
                                </span>
                            ) : null}
                        </header>
                    ) : null}

                    {message.parent && !message.deleted ? (
                        <ParentQuote
                            parent={message.parent}
                            onJump={handlers.onJump}
                        />
                    ) : null}

                    {message.deleted ? (
                        <p className="flex items-center gap-1.5 text-sm text-muted-foreground italic">
                            <Trash2 aria-hidden="true" className="size-3.5" />
                            {t('chat.messages.deleted')}
                        </p>
                    ) : message.hidden && !visibleBody ? (
                        <p className="flex items-center gap-1.5 text-sm text-muted-foreground italic">
                            <EyeOff aria-hidden="true" className="size-3.5" />
                            {t('chat.messages.hidden')}
                        </p>
                    ) : null}

                    {message.hidden && visibleBody ? (
                        <p className="flex items-center gap-1.5 text-xs text-warning">
                            <EyeOff aria-hidden="true" className="size-3.5" />
                            {message.hidden_by
                                ? t('chat.messages.hidden_by', {
                                      name: message.hidden_by,
                                  })
                                : t('chat.messages.hidden_for_others')}
                        </p>
                    ) : null}

                    {editing && message.body !== null ? (
                        <div className="py-1">
                            <Composer
                                conversationId={message.conversation_id}
                                people={people}
                                mode="edit"
                                autoFocus
                                initial={bodyToEditable(message.body, names)}
                                onSubmit={handlers.onEditSubmit}
                                onCancel={handlers.onEditCancel}
                            />
                        </div>
                    ) : visibleBody && message.body ? (
                        <MarkdownText
                            body={message.body}
                            names={names}
                            currentUserId={currentUserId}
                        />
                    ) : null}

                    {!showHeader && message.edited_at && !editing ? (
                        <span className="text-xs text-muted-foreground">
                            ({t('chat.messages.edited')})
                        </span>
                    ) : null}

                    {message.audio ? (
                        <div className="py-1">
                            <AudioMessage message={message} />
                        </div>
                    ) : null}

                    {message.attachments.length > 0 ? (
                        <div className="py-1">
                            <AttachmentList attachments={message.attachments} />
                        </div>
                    ) : null}

                    {message.link_preview ? (
                        <div className="py-1">
                            <LinkPreviewCard preview={message.link_preview} />
                        </div>
                    ) : null}

                    {message.task ? (
                        <div className="py-1">
                            <Link
                                href={urls.taskById(message.task.id)}
                                className={cn(
                                    'inline-flex max-w-full items-center gap-1.5 rounded-[3px] border px-1.5 py-0.5 text-xs text-primary-text hover:bg-accent',
                                    FOCUS_RING,
                                )}
                                data-test="chat-message-task"
                            >
                                <ListChecks
                                    aria-hidden="true"
                                    className="size-3.5 shrink-0"
                                />
                                <span className="truncate">
                                    {t('chat.messages.task', {
                                        title: message.task.title,
                                    })}
                                </span>
                            </Link>
                        </div>
                    ) : null}

                    {message.reactions.length > 0 ? (
                        <div className="py-1">
                            <Reactions
                                reactions={message.reactions}
                                canReact={message.can.react}
                                onToggle={handlers.onReact}
                            />
                        </div>
                    ) : null}

                    {message.pinned ? (
                        <p className="flex items-center gap-1 text-xs text-muted-foreground">
                            <Pin aria-hidden="true" className="size-3" />
                            {message.pinned_by
                                ? t('chat.messages.pinned_by', {
                                      name: message.pinned_by,
                                  })
                                : t('chat.messages.pinned')}
                        </p>
                    ) : null}

                    {pending === 'sending' ? (
                        <p className="flex items-center gap-1 text-xs text-muted-foreground">
                            <LoaderCircle
                                aria-hidden="true"
                                className="size-3 animate-spin"
                            />
                            {t('chat.messages.sending')}
                        </p>
                    ) : null}

                    {pending === 'failed' ? (
                        <p
                            className="flex flex-wrap items-center gap-x-2 text-xs text-destructive-foreground"
                            role="alert"
                        >
                            <CircleAlert
                                aria-hidden="true"
                                className="size-3.5"
                            />
                            {t('chat.messages.failed')}
                            <Button
                                type="button"
                                variant="link"
                                size="sm"
                                className="h-auto px-0 text-xs"
                                onClick={handlers.onRetry}
                            >
                                {t('chat.messages.retry')}
                            </Button>
                            <Button
                                type="button"
                                variant="link"
                                size="sm"
                                className="h-auto px-0 text-xs"
                                onClick={handlers.onDiscard}
                            >
                                {t('chat.messages.discard')}
                            </Button>
                        </p>
                    ) : null}
                </div>
            </article>
            {hasActions && !editing ? (
                <MessageToolbar
                    message={message}
                    authorName={name}
                    handlers={handlers}
                />
            ) : null}
        </li>
    );
}
