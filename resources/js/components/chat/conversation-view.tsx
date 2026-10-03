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
    AttachFilesButton,
    AttachmentDropzone,
    AudioRecorder,
    MediaComposerTray,
    useChatMediaComposer,
} from '@/components/chat/media-bridge';
import { MessageList } from '@/components/chat/message-list';
import { PinnedBar } from '@/components/chat/pinned-bar';
import {
    messageFocusTarget,
    returnFocusTo,
} from '@/components/chat/return-focus';
import {
    useReadReceipts,
    useTyping,
    useUnreadCounter,
} from '@/components/chat/realtime-bridge';
import { useConversation } from '@/components/chat/use-conversation';
import { ReadBy, TypingIndicator } from '@/components/realtime';
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
import { useIsMobile } from '@/hooks/use-mobile';
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
    muted?: boolean;
};

/**
 * Una conversación abierta (SPEC §12): cabecera, fijados, mensajes y editor, con todas las
 * acciones (responder en hilo, reaccionar, editar, borrar, fijar, copiar enlace, crear tarea y
 * moderar). Se usa en /chat/{id} y en la pestaña Chat del proyecto. Los mensajes nuevos de otras
 * personas se anuncian en una región aria-live; «escribiendo…», «leído por» y los contadores, con
 * el tiempo real de C2 (realtime-bridge); los audios y adjuntos (soltar, pegar, adjuntar y grabar),
 * con C3 (media-bridge).
 *
 * Foco (WCAG 2.4.3): al responder, el editor (sin volver a montarlo: no se pierden el borrador ni
 * una grabación); al terminar de editar, el mensaje (o el editor, si se editó con ↑); al cerrar
 * «Borrar» o «Crear tarea», el mensaje o su menú; y al abrir una conversación en el móvil, su
 * título.
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
    const [composerFocus, setComposerFocus] = useState(0);
    const editOrigin = useRef<'composer' | 'message'>('message');
    const deleted = useRef<{ id: number; done: boolean } | null>(null);
    const titleRef = useRef<HTMLHeadingElement>(null);
    const isMobile = useIsMobile();
    const jumpTo = useRef<((messageId: number) => void) | null>(null);
    const namesRef = useRef<ReadonlyMap<number, string>>(new Map());
    const [, copy] = useClipboard();

    const { refresh: refreshCounters } = useUnreadCounter();
    const reportActivity = useCallback(
        ({
            lastMessage,
            ...activity
        }: Omit<ConversationActivity, 'conversationId' | 'last'> & {
            lastMessage?: ChatMessage;
        }) => {
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
        [conversation.id, onActivity, user.id],
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
    } = useTyping(conversation.id, conversation.participants);
    const receipts = useReadReceipts(conversation.id);
    // Audios y adjuntos (C3): llegan a la lista con las novedades, que deduplican por id.
    const media = useChatMediaComposer(conversation.id, {
        onSent: () => void controller.poll(),
    });

    useEffect(() => {
        namesRef.current = controller.names;
    }, [controller.names]);

    // En el móvil la lista desaparece al abrir la conversación: el foco va a su título.
    useEffect(() => {
        if (isMobile) {
            titleRef.current?.focus();
        }
        // Solo al abrirla (cada conversación monta su propia vista).
    }, []);

    const focusComposer = () => setComposerFocus((value) => value + 1);
    const focusMessage = (
        messageId: number,
        prefer: 'message' | 'actions' = 'message',
    ) =>
        window.requestAnimationFrame(() =>
            messageFocusTarget(messageId, prefer)?.focus(),
        );

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
            // Una silenciada deja de sumar en el total: los contadores de C2 se vuelven a pedir.
            void refreshCounters();
            reportActivity({ muted: result.muted });
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
            focusComposer();
        },
        onReact: (emoji: string) => void controller.react(message, emoji),
        onEdit: () => {
            editOrigin.current = 'message';
            setEditingId(message.id);
        },
        onDelete: () => {
            deleted.current = { id: message.id, done: false };
            setDeleting(message);
        },
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
                finishEditing(message.id, 'message');

                return true;
            }

            return false;
        },
        onEditCancel: () => {
            setEditingId(null);
            finishEditing(message.id, 'actions');
        },
    });

    // Tras editar: vuelve al editor si se empezó con ↑; si no, al mensaje (o a su menú al cancelar).
    const finishEditing = (
        messageId: number,
        prefer: 'message' | 'actions',
    ) => {
        if (editOrigin.current === 'composer') {
            focusComposer();
        } else {
            focusMessage(messageId, prefer);
        }
    };

    const editLast = () => {
        const own = [...controller.messages]
            .reverse()
            .find(
                (message) => message.author?.id === user.id && message.can.edit,
            );

        if (own) {
            editOrigin.current = 'composer';
            setEditingId(own.id);
        }
    };

    const send = async (body: string) => {
        const parent = replyTo;
        stopTyping();

        // Con archivos pendientes (C3), el texto va con ellos en un solo mensaje.
        if (media.files.length > 0) {
            const sent = await media.send({
                body: body === '' ? null : body,
                parentId: parent?.id ?? null,
            });

            if (sent !== null) {
                setReplyTo(null);
            }

            return sent !== null;
        }

        setReplyTo(null);

        return controller.send(body, parent);
    };

    const sendAudio = (file: File, durationMs: number) => {
        const parent = replyTo;
        setReplyTo(null);
        void media.sendAudio(file, durationMs, parent?.id ?? null);
    };

    const confirmDelete = async () => {
        if (!deleting) {
            return;
        }

        setDeletingBusy(true);
        const done = await controller.remove(deleting);
        deleted.current = { id: deleting.id, done: done !== null };
        setDeletingBusy(false);
        setDeleting(null);
    };

    // «Leído por» (C2): quién ha leído el último mensaje propio, si es el último de la conversación.
    const last = controller.messages.at(-1);
    const ownLast =
        last && last.id > 0 && last.author?.id === user.id ? last : null;

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
                titleRef={titleRef}
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

            <AttachmentDropzone
                onFiles={media.addFiles}
                disabled={readOnly !== null || media.sending}
                className="flex min-h-0 flex-1 flex-col"
            >
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

                <div className="flex min-h-5 items-center gap-2 px-4">
                    <TypingIndicator
                        typers={typing}
                        className="min-w-0 flex-1"
                    />
                    {ownLast && typing.length === 0 && receipts.ready ? (
                        <span data-test="chat-read-receipt">
                            <ReadBy
                                readers={receipts.readersOf(
                                    ownLast.id,
                                    user.id,
                                )}
                                recipients={receipts.recipientsOf(user.id)}
                                direct={conversation.type === 'direct'}
                            />
                        </span>
                    ) : null}
                </div>

                <footer className="border-t px-3 pt-2 pb-3 md:px-4">
                    {readOnly ? (
                        <p className="flex items-center gap-2 rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground">
                            <Lock
                                aria-hidden="true"
                                className="size-4 shrink-0"
                            />
                            {t(`chat.composer.read_only.${readOnly}`)}
                        </p>
                    ) : (
                        <div className="grid gap-2">
                            <MediaComposerTray composer={media} />
                            <Composer
                                conversationId={conversation.id}
                                people={people}
                                onSubmit={send}
                                pendingFiles={media.files.length}
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
                                focusSignal={composerFocus}
                                mediaSlot={
                                    <AttachFilesButton
                                        onFiles={media.addFiles}
                                        disabled={media.sending}
                                        className="size-9"
                                    />
                                }
                                recorderSlot={
                                    <AudioRecorder
                                        onRecorded={sendAudio}
                                        disabled={media.sending}
                                    />
                                }
                            />
                        </div>
                    )}
                </footer>
            </AttachmentDropzone>

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleting(null);
                    }
                }}
            >
                <DialogContent
                    onCloseAutoFocus={returnFocusTo(() => {
                        const target = deleted.current;

                        // Borrado: al mensaje («Mensaje eliminado»); cancelado: a su menú.
                        return target
                            ? messageFocusTarget(
                                  target.id,
                                  target.done ? 'message' : 'actions',
                              )
                            : null;
                    })}
                >
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
                returnFocus={(messageId) =>
                    messageFocusTarget(messageId, 'actions')
                }
            />
        </section>
    );
}
