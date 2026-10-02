import {
    ClipboardCopy,
    Eye,
    EyeOff,
    Link2,
    ListPlus,
    MoreHorizontal,
    Pencil,
    Pin,
    PinOff,
    Reply,
    SmilePlus,
    Trash2,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { EmojiPopover } from '@/components/chat/emoji-popover';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ChatMessage } from '@/types/chat';

export type MessageActionHandlers = {
    onReply: () => void;
    onReact: (emoji: string) => void;
    onEdit: () => void;
    onDelete: () => void;
    onPin: (pinned: boolean) => void;
    onCopyLink: () => void;
    onCopyText: () => void;
    onCreateTask: () => void;
    onModerate: (hidden: boolean) => void;
};

/**
 * Acciones de un mensaje en un menú accesible (Radix: teclado y lector de pantalla), solo con lo
 * que quien mira puede hacer: responder, reaccionar, editar y borrar lo propio, fijar, copiar el
 * enlace o el texto, crear una tarea (chats de proyecto) y ocultar o mostrar (admin, D-071).
 * En escritorio aparece al pasar por encima o al llegar con el tabulador, con atajos para
 * reaccionar y responder; en el móvil queda siempre el botón del menú.
 */
export function MessageToolbar({
    message,
    authorName,
    handlers,
}: {
    message: ChatMessage;
    authorName: string;
    handlers: MessageActionHandlers;
}) {
    const can = message.can;
    const [reacting, setReacting] = useState(false);
    const openReactAfterMenu = useRef(false);
    const hasText = message.body !== null && message.body !== '';

    return (
        <div
            className={cn(
                'absolute top-1 right-1 z-10 flex items-center gap-0.5',
                'md:-top-4 md:right-2 md:rounded-[3px] md:border md:bg-popover md:p-0.5 md:opacity-0 md:transition-opacity md:group-hover/message:opacity-100 md:focus-within:opacity-100 md:has-[[data-state=open]]:opacity-100',
            )}
        >
            {can.react ? (
                <EmojiPopover
                    quick
                    align="end"
                    onSelect={handlers.onReact}
                    trigger={
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="hidden size-7 md:inline-flex"
                            aria-label={t('chat.actions.react')}
                        >
                            <SmilePlus aria-hidden="true" />
                        </Button>
                    }
                />
            ) : null}
            {can.reply ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="hidden size-7 md:inline-flex"
                    aria-label={t('chat.actions.reply')}
                    onClick={handlers.onReply}
                >
                    <Reply aria-hidden="true" />
                </Button>
            ) : null}
            <EmojiPopover
                quick
                align="end"
                open={reacting}
                onOpenChange={setReacting}
                onSelect={handlers.onReact}
                anchor={
                    <span className="inline-flex">
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7 text-muted-foreground"
                                    aria-label={t('chat.actions.label', {
                                        name: authorName,
                                    })}
                                    data-test="chat-message-actions"
                                >
                                    <MoreHorizontal aria-hidden="true" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                align="end"
                                className="min-w-48"
                                onCloseAutoFocus={(event) => {
                                    if (openReactAfterMenu.current) {
                                        openReactAfterMenu.current = false;
                                        event.preventDefault();
                                        setReacting(true);
                                    }
                                }}
                            >
                                {can.reply ? (
                                    <DropdownMenuItem
                                        onSelect={handlers.onReply}
                                    >
                                        <Reply aria-hidden="true" />
                                        {t('chat.actions.reply')}
                                    </DropdownMenuItem>
                                ) : null}
                                {can.react ? (
                                    <DropdownMenuItem
                                        onSelect={() => {
                                            openReactAfterMenu.current = true;
                                        }}
                                    >
                                        <SmilePlus aria-hidden="true" />
                                        {t('chat.actions.react')}
                                    </DropdownMenuItem>
                                ) : null}
                                {can.edit ? (
                                    <DropdownMenuItem
                                        onSelect={handlers.onEdit}
                                    >
                                        <Pencil aria-hidden="true" />
                                        {t('chat.actions.edit')}
                                    </DropdownMenuItem>
                                ) : null}
                                {can.pin ? (
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            handlers.onPin(!message.pinned)
                                        }
                                    >
                                        {message.pinned ? (
                                            <PinOff aria-hidden="true" />
                                        ) : (
                                            <Pin aria-hidden="true" />
                                        )}
                                        {message.pinned
                                            ? t('chat.actions.unpin')
                                            : t('chat.actions.pin')}
                                    </DropdownMenuItem>
                                ) : null}
                                <DropdownMenuItem
                                    onSelect={handlers.onCopyLink}
                                >
                                    <Link2 aria-hidden="true" />
                                    {t('chat.actions.copy_link')}
                                </DropdownMenuItem>
                                {hasText ? (
                                    <DropdownMenuItem
                                        onSelect={handlers.onCopyText}
                                    >
                                        <ClipboardCopy aria-hidden="true" />
                                        {t('chat.actions.copy_text')}
                                    </DropdownMenuItem>
                                ) : null}
                                {can.create_task ? (
                                    <DropdownMenuItem
                                        onSelect={handlers.onCreateTask}
                                    >
                                        <ListPlus aria-hidden="true" />
                                        {t('chat.actions.create_task')}
                                    </DropdownMenuItem>
                                ) : null}
                                {can.moderate ? (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                handlers.onModerate(
                                                    !message.hidden,
                                                )
                                            }
                                        >
                                            {message.hidden ? (
                                                <Eye aria-hidden="true" />
                                            ) : (
                                                <EyeOff aria-hidden="true" />
                                            )}
                                            {message.hidden
                                                ? t('chat.actions.unhide')
                                                : t('chat.actions.hide')}
                                        </DropdownMenuItem>
                                    </>
                                ) : null}
                                {can.delete ? (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            variant="destructive"
                                            onSelect={handlers.onDelete}
                                        >
                                            <Trash2 aria-hidden="true" />
                                            {t('chat.actions.delete')}
                                        </DropdownMenuItem>
                                    </>
                                ) : null}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </span>
                }
            />
        </div>
    );
}
