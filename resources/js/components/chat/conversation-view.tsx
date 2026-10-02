import { CircleAlert, Lock } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ChatApiError, chatApi } from '@/components/chat/chat-api';
import { Composer } from '@/components/chat/composer';
import type { MentionPerson } from '@/components/chat/composer';
import { ConversationHeader } from '@/components/chat/conversation-header';
import { CreateTaskDialog } from '@/components/chat/create-task-dialog';
import { markdownToPlainText } from '@/components/chat/markdown';
import {
    AttachmentDropzone,
    AudioRecorder,
    sendWithMedia,
} from '@/components/chat/media-bridge';
import type { MediaPayload } from '@/components/chat/media-bridge';
import { MessageList } from '@/components/chat/message-list';
import { PinnedBar } from '@/components/chat/pinned-bar';
import { useTyping, useUnreadCounter } from '@/components/chat/realtime-bridge';
import type { TypingUser } from '@/components/chat/realtime-bridge';
import { publishUnreadTotal } from '@/components/chat/use-chat-unread';
import { useConversation } from '@/components/chat/use-conversation';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { useClipboard } from '@/hooks/use-clipboard';
import { useRequiredUser } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type {
    ChatConversation,
    ChatLastMessage,
    ChatMessage,
    ChatMessagesPage,
    ChatPinnedMessage,
    ChatUser,
} from '@/types/chat';

/** Lo que cambia en la conversación y le interesa a la lista (último mensaje, no leídos…). */
export type ConversationActivity = {
    conversationId: number;
    last?: ChatLastMessage;
    unread?: number;
    unreadTotal?: number;
    muted?: boolean;
};

function typingText(typing: TypingUser[]): string {
    if (typing.length === 1) {
        return t('chat.typing.one', { name: typing[0].name });
    }

    if (typing.length === 2) {
        return t('chat.typing.two', {
            first: typing[0].name,
            second: typing[1].name,
        });
    }

    return t('chat.typing.many');
}

/**
 * Una conversación abierta (SPEC §12): cabecera, fijados, mensajes y editor, con todas las
 * acciones (responder en hilo, reaccionar, editar, borrar, fijar, copiar enlace, crear tarea y
 * moderar). Se usa en /chat/{id} y en la pestaña Chat del proyecto. Los mensajes nuevos de otras
 * personas se anuncian en una región aria-live; «escribiendo…» y la presencia llegan con el tiempo
 * real (C2); los audios y adjuntos, con C3 (media-bridge).
 */
export function ConversationView({
    conversation,
    initial,
    pinned,
    focus,
    backHref,
    showProjectLink = true,
    onActivity,
    className,
}: {
    conversation: ChatConversation;
    initial: ChatMessagesPage;
    pinned: ChatPinnedMessage[];
    focus: number | null;
    backHref?: string;
    showProjectLink?: boolean;
    onActivity?: (activity: ConversationActivity) => void;
    className?: string;
}) {
    const user = useRequiredUser();
    const currentUser: ChatUser = useMemo(
        () => ({
            id: user.id,
            name: user.name,
            avatar: user.avatar,
            is_active: true,
        }),
        [user.avatar, user.id, user.name],
    );
    const [muted, setMuted] = useState(conversation.muted);
    const [replyTo, setReplyTo] = useState<ChatMessage | null>(null);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [deleting, setDeleting] = useState<ChatMessage | null>(null);
    const [deletingBusy, setDeletingBusy] = useState(false);
    const [taskFor, setTaskFor] = useState<ChatMessage | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const [composerKey, setComposerKey] = useState(0);
    const jumpTo = useRef<((messageId: number) => void) | null>(null);
    const namesRef = useRef<ReadonlyMap<number, string>>(new Map());
    const [, copy] = useClipboard();

    const unreadCounter = useUnreadCounter();
    const reportActivity = useCallback(
        ({
            lastMessage,
            ...activity
        }: Omit<ConversationActivity, 'conversationId' | 'last'> & {
            lastMessage?: ChatMessage;
        }) => {
            if (activity.unread === 0) {
                unreadCounter.markRead(conversation.id);
            }

            onActivity?.({
                conversationId: conversation.id,
                ...activity,
                ...(lastMessage
                    ? {
                          last: {
                              id: lastMessage.id,
                              kind: lastMessage.hidden
                                  ? 'hidden'
                                  : lastMessage.type,
                              author: lastMessage.author?.name ?? null,
                              is_mine: lastMessage.author?.id === user.id,
                              preview: lastMessage.body
                                  ? markdownToPlainText(
                                        lastMessage.body,
                                        namesRef.current,
                                    ).slice(0, 140)
                                  : '',
                              system: lastMessage.system,
                              created_at: lastMessage.created_at,
                          },
                      }
                    : {}),
            });
        },
        [conversation.id, onActivity, unreadCounter, user.id],
    );

    const controller = useConversation({
        conversation,
        initial,
        initialPinned: pinned,
        currentUser,
        onIncoming: (messages) => {
            const last = messages[messages.length - 1];
            const text = last.body
                ? markdownToPlainText(last.body, controller.names).slice(0, 140)
                : last.system
                  ? ''
                  : t('chat.live.attachment');

            setAnnouncement(
                messages.length === 1
                    ? t('chat.live.new_message', {
                          name: last.author?.name ?? t('chat.system.generic'),
                          text,
                      })
                    : t('chat.live.new_messages', { count: messages.length }),
            );
        },
        onActivity: reportActivity,
    });
    const {
        typers: typing,
        notifyTyping,
        stopTyping,
    } = useTyping(conversation.id);

    useEffect(() => {
        namesRef.current = controller.names;
    }, [controller.names]);

    const people: MentionPerson[] = useMemo(
        () =>
            conversation.participants
                .filter((participant) => participant.id !== user.id)
                .map(({ id, name, is_active }) => ({ id, name, is_active })),
        [conversation.participants, user.id],
    );

    const toggleMute = async () => {
        try {
            const result = await chatApi.mute(conversation.id, !muted);
            setMuted(result.muted);
            publishUnreadTotal(result.unread_total);
            void unreadCounter.refresh();
            reportActivity({
                muted: result.muted,
                unreadTotal: result.unread_total,
            });
            toast.success(
                result.muted
                    ? t('chat.header.muted_done')
                    : t('chat.header.unmuted_done'),
            );
        } catch (error) {
            toast.error(
                error instanceof ChatApiError
                    ? error.firstError()
                    : t('chat.errors.server'),
            );
        }
    };

    const copyToClipboard = async (text: string, success: string) => {
        if (await copy(text)) {
            toast.success(success);
        } else {
            toast.error(t('chat.actions.copy_failed'));
        }
    };

    const handlersFor = (message: ChatMessage) => ({
        onReply: () => {
            setReplyTo(message);
            setComposerKey((key) => key + 1);
        },
        onReact: (emoji: string) => void controller.react(message, emoji),
        onEdit: () => setEditingId(message.id),
        onDelete: () => setDeleting(message),
        onPin: async (value: boolean) => {
            if (await controller.pin(message, value)) {
                toast.success(
                    value
                        ? t('chat.actions.pinned_done')
                        : t('chat.actions.unpinned_done'),
                );
            }
        },
        onCopyLink: () =>
            void copyToClipboard(
                `${window.location.origin}${urls.chatMessage(conversation.id, message.id)}`,
                t('chat.actions.link_copied'),
            ),
        onCopyText: () =>
            void copyToClipboard(
                message.body
                    ? markdownToPlainText(message.body, controller.names)
                    : '',
                t('chat.actions.text_copied'),
            ),
        onCreateTask: () => setTaskFor(message),
        onModerate: async (hidden: boolean) => {
            if (await controller.moderate(message, hidden)) {
                toast.success(
                    hidden
                        ? t('chat.actions.hidden_done')
                        : t('chat.actions.unhidden_done'),
                );
            }
        },
        onRetry: () => controller.retry(message.id),
        onDiscard: () => controller.discard(message.id),
        onEditSubmit: async (body: string) => {
            if (await controller.edit(message, body)) {
                setEditingId(null);

                return true;
            }

            return false;
        },
        onEditCancel: () => setEditingId(null),
    });

    const editLast = () => {
        const own = [...controller.messages]
            .reverse()
            .find(
                (message) => message.author?.id === user.id && message.can.edit,
            );

        if (own) {
            setEditingId(own.id);
        }
    };

    const send = async (body: string) => {
        const parent = replyTo;
        stopTyping();
        setReplyTo(null);

        return controller.send(body, parent);
    };

    /** Audios y adjuntos (C3): se publican por su ruta y llegan con las novedades. */
    const sendMedia = async (payload: Partial<MediaPayload>) => {
        const parent = replyTo;
        setReplyTo(null);

        try {
            await sendWithMedia(conversation.id, {
                body: null,
                files: [],
                audio: null,
                parentId: parent?.id ?? null,
                ...payload,
            });
            await controller.poll();
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : t('chat.errors.server'),
            );
        }
    };

    const confirmDelete = async () => {
        if (!deleting) {
            return;
        }

        setDeletingBusy(true);
        await controller.remove(deleting);
        setDeletingBusy(false);
        setDeleting(null);
    };

    // «Visto por»: quién ha leído el último mensaje propio (si es el último de la conversación).
    const last = controller.messages.at(-1);
    const readers =
        last && last.id > 0 && last.author?.id === user.id
            ? conversation.participants.filter(
                  (participant) =>
                      participant.id !== user.id &&
                      (controller.readState.get(participant.id) ?? 0) >=
                          last.id,
              )
            : [];
    const others = conversation.participants.length - 1;
    const readText =
        readers.length === 0
            ? null
            : readers.length === others && others > 1
              ? t('chat.read.all')
              : readers.length <= 3
                ? t('chat.read.by', {
                      names: readers.map((reader) => reader.name).join(', '),
                  })
                : t('chat.read.by_count', { count: readers.length });

    const readOnly = conversation.read_only_reason;
    const placeholder =
        conversation.type === 'direct' && conversation.other_user
            ? t('chat.composer.placeholder_to', {
                  name: conversation.other_user.name,
              })
            : undefined;

    return (
        <section
            aria-label={conversation.title}
            className={cn('flex min-h-0 flex-1 flex-col', className)}
            data-test="chat-conversation"
        >
            <ConversationHeader
                conversation={conversation}
                muted={muted}
                onToggleMute={() => void toggleMute()}
                backHref={backHref}
                showProjectLink={showProjectLink}
            />
            <PinnedBar
                pinned={controller.pinned}
                onJump={(id) => jumpTo.current?.(id)}
            />
            {controller.pollFailed ? (
                <p
                    role="status"
                    className="flex items-center gap-2 border-b bg-warning-soft px-4 py-1.5 text-xs text-foreground"
                >
                    <CircleAlert
                        aria-hidden="true"
                        className="size-3.5 text-warning"
                    />
                    {t('chat.errors.poll')}
                </p>
            ) : null}

            <MessageList
                controller={controller}
                currentUserId={user.id}
                people={people}
                initialLastRead={conversation.last_read_message_id}
                focusId={focus}
                editingId={editingId}
                title={conversation.title}
                handlersFor={handlersFor}
                jumpRef={jumpTo}
            />

            <div aria-live="polite" aria-atomic="true" className="sr-only">
                {announcement}
            </div>

            <div className="min-h-5 px-4 text-xs text-muted-foreground">
                {typing.length > 0 ? (
                    <span aria-live="polite">{typingText(typing)}</span>
                ) : readText ? (
                    <span
                        className="block text-right"
                        data-test="chat-read-receipt"
                    >
                        {readText}
                    </span>
                ) : null}
            </div>

            <footer className="border-t px-3 pt-2 pb-3 md:px-4">
                {readOnly ? (
                    <p className="flex items-center gap-2 rounded-[3px] bg-muted px-3 py-2 text-sm text-muted-foreground">
                        <Lock aria-hidden="true" className="size-4 shrink-0" />
                        {t(`chat.composer.read_only.${readOnly}`)}
                    </p>
                ) : (
                    <AttachmentDropzone
                        onFiles={(files) => void sendMedia({ files })}
                    >
                        <Composer
                            key={composerKey}
                            conversationId={conversation.id}
                            people={people}
                            onSubmit={send}
                            replyTo={
                                replyTo
                                    ? {
                                          id: replyTo.id,
                                          author:
                                              replyTo.author?.name ??
                                              t('chat.system.generic'),
                                          excerpt: replyTo.body
                                              ? markdownToPlainText(
                                                    replyTo.body,
                                                    controller.names,
                                                )
                                              : replyTo.system
                                                ? t('chat.system.generic')
                                                : t('chat.live.attachment'),
                                      }
                                    : null
                            }
                            onCancelReply={() => setReplyTo(null)}
                            onEditLast={editLast}
                            onTyping={notifyTyping}
                            placeholder={placeholder}
                            autoFocus={composerKey > 0}
                            mediaSlot={
                                <AudioRecorder
                                    onRecorded={(file, durationMs) =>
                                        void sendMedia({
                                            audio: { file, durationMs },
                                        })
                                    }
                                />
                            }
                        />
                    </AttachmentDropzone>
                )}
            </footer>

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleting(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogTitle>{t('chat.actions.delete_title')}</DialogTitle>
                    <DialogDescription>
                        {t('chat.actions.delete_description')}
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary" disabled={deletingBusy}>
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={deletingBusy}
                            onClick={() => void confirmDelete()}
                            data-test="chat-delete-confirm"
                        >
                            {t('chat.actions.delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <CreateTaskDialog
                message={taskFor}
                onClose={() => setTaskFor(null)}
                onCreated={controller.applyResponse}
            />
        </section>
    );
}
