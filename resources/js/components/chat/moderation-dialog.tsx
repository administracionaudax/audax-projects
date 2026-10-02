import { router } from '@inertiajs/react';
import { Archive, FolderKanban, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { RefObject } from 'react';
import { ChatApiError, chatApi } from '@/components/chat/chat-api';
import { formatListTime } from '@/components/chat/chat-format';
import { PeopleLoading } from '@/components/chat/new-conversation-dialogs';
import { returnFocusTo } from '@/components/chat/return-focus';
import {
    CommandDialog,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import type { ChatModerationItem } from '@/types/chat';

/**
 * «Moderar conversaciones» (solo admin; D-071, D-119): los chats de proyecto y los grupos en los
 * que no participa, con buscador (cmdk: teclado completo). Al elegir uno se abre en modo
 * moderación (lee y oculta mensajes; no escribe). Las directas no aparecen nunca.
 */
export function ModerationDialog({
    open,
    onOpenChange,
    returnFocus,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocus?: RefObject<HTMLElement | null>;
}) {
    const [items, setItems] = useState<ChatModerationItem[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        let cancelled = false;
        setError(null);

        chatApi
            .moderation()
            .then((data) => {
                if (!cancelled) {
                    setItems(data.conversations);
                }
            })
            .catch((failure: unknown) => {
                if (!cancelled) {
                    setError(
                        failure instanceof ChatApiError
                            ? failure.firstError()
                            : t('chat.errors.server'),
                    );
                }
            });

        return () => {
            cancelled = true;
        };
    }, [open]);

    const choose = (item: ChatModerationItem) => {
        onOpenChange(false);
        router.visit(urls.chatConversation(item.id), {
            // Solo las props de la conversación (como CONVERSATION_PROPS de la lista).
            only: ['conversation', 'messages', 'pinned', 'focus'],
            preserveState: true,
        });
    };

    return (
        <CommandDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('chat.moderation.title')}
            description={t('chat.moderation.description')}
            onCloseAutoFocus={
                returnFocus ? returnFocusTo(returnFocus) : undefined
            }
        >
            <CommandInput
                placeholder={t('chat.moderation.search')}
                aria-label={t('chat.moderation.search')}
            />
            <CommandList>
                {error ? (
                    <p role="alert" className="p-3 text-sm text-danger">
                        {error}
                    </p>
                ) : items === null ? (
                    <PeopleLoading />
                ) : (
                    <>
                        <CommandEmpty>
                            {t('chat.moderation.empty')}
                        </CommandEmpty>
                        {items.map((item) => {
                            const Icon =
                                item.type === 'project' ? FolderKanban : Users;

                            return (
                                <CommandItem
                                    key={item.id}
                                    value={`${item.title} ${item.subtitle ?? ''} ${item.id}`}
                                    onSelect={() => choose(item)}
                                    className="flex items-center gap-2"
                                    data-test="chat-moderation-item"
                                >
                                    <Icon
                                        aria-hidden="true"
                                        className="text-muted-foreground"
                                    />
                                    <span className="grid min-w-0 flex-1">
                                        <span className="truncate">
                                            {item.title}
                                        </span>
                                        <span className="truncate text-xs text-muted-foreground">
                                            {[
                                                t(
                                                    `chat.list.type.${item.type}`,
                                                ),
                                                item.subtitle,
                                                t('chat.list.members', {
                                                    count: item.members_count,
                                                }),
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </span>
                                    {item.read_only ? (
                                        <span className="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground">
                                            <Archive
                                                aria-hidden="true"
                                                className="size-3.5"
                                            />
                                            {t('chat.list.read_only')}
                                        </span>
                                    ) : null}
                                    <span className="tabular shrink-0 text-xs text-muted-foreground">
                                        {formatListTime(item.last_activity_at)}
                                    </span>
                                </CommandItem>
                            );
                        })}
                    </>
                )}
            </CommandList>
        </CommandDialog>
    );
}
