import { router } from '@inertiajs/react';
import { SmilePlus, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { toast } from 'sonner';
import { ChatApiError, chatApi } from '@/components/chat/chat-api';
import { EmojiPopover } from '@/components/chat/emoji-popover';
import { returnFocusTo } from '@/components/chat/return-focus';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import type { ChatConversation } from '@/types/chat';

/**
 * Crear o cambiar un canal de equipo (D-272, solo admins): nombre, emoji (del selector del chat,
 * D-117) y, al cambiarlo, archivarlo (queda de solo lectura) o recuperarlo. Al crearlo se abre;
 * al cambiarlo se vuelven a pedir la cabecera y la lista. Al cerrar, el foco vuelve al botón que
 * lo abrió.
 */
export function ChannelDialog({
    conversation = null,
    open,
    onOpenChange,
    returnFocus,
}: {
    /** null: canal nuevo. */
    conversation?: ChatConversation | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    returnFocus?: RefObject<HTMLElement | null>;
}) {
    const editing = conversation !== null;
    const [name, setName] = useState('');
    const [icon, setIcon] = useState<string | null>(null);
    const [archived, setArchived] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const iconButton = useRef<HTMLButtonElement>(null);
    const nameId = useId();
    const iconId = useId();
    const archivedId = useId();

    // Cada vez que se abre, con lo de ahora y sin lo que quedara a medias.
    useEffect(() => {
        if (open) {
            setName(conversation?.title ?? '');
            setIcon(conversation?.icon ?? null);
            setArchived(conversation?.archived ?? false);
            setErrors({});
        }
    }, [open, conversation]);

    const submit = async () => {
        if (name.trim() === '') {
            setErrors({ name: t('chat.channel.need_name') });

            return;
        }

        setBusy(true);
        setErrors({});

        try {
            if (editing) {
                await chatApi.updateChannel(conversation.id, {
                    name: name.trim(),
                    icon,
                    archived,
                });
                toast.success(t('chat.channel.saved'));
                onOpenChange(false);
                router.reload({ only: ['conversation', 'conversations'] });
            } else {
                const created = await chatApi.createChannel({
                    name: name.trim(),
                    icon,
                });
                toast.success(t('chat.channel.created'));
                onOpenChange(false);
                router.visit(urls.chatConversation(created.id));
            }
        } catch (error) {
            if (error instanceof ChatApiError && error.status === 422) {
                setErrors(
                    Object.fromEntries(
                        Object.entries(error.errors).map(([field, list]) => [
                            field,
                            list[0] ?? '',
                        ]),
                    ),
                );
            } else {
                toast.error(
                    error instanceof ChatApiError
                        ? error.firstError()
                        : t('chat.errors.server'),
                );
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="sm:max-w-md"
                onCloseAutoFocus={
                    returnFocus ? returnFocusTo(returnFocus) : undefined
                }
                data-test="chat-channel-dialog"
            >
                <DialogTitle>
                    {editing
                        ? t('chat.channel.edit_title')
                        : t('chat.channel.new_title')}
                </DialogTitle>
                <DialogDescription>
                    {t('chat.channel.description')}
                </DialogDescription>

                <form
                    className="grid gap-4"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        void submit();
                    }}
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor={nameId}>{t('chat.channel.name')}</Label>
                        <div className="flex gap-2">
                            <EmojiPopover
                                side="bottom"
                                onSelect={setIcon}
                                returnFocus={iconButton}
                                trigger={
                                    <Button
                                        ref={iconButton}
                                        id={iconId}
                                        type="button"
                                        // Parte del campo: borde gris, como la caja del nombre.
                                        variant="field"
                                        size="icon"
                                        className="size-9 shrink-0 px-0 text-lg"
                                        aria-label={
                                            icon
                                                ? t(
                                                      'chat.channel.icon_change',
                                                      {
                                                          icon,
                                                      },
                                                  )
                                                : t('chat.channel.icon_choose')
                                        }
                                        data-test="chat-channel-icon"
                                    >
                                        {icon ?? (
                                            <SmilePlus aria-hidden="true" />
                                        )}
                                    </Button>
                                }
                            />
                            <Input
                                id={nameId}
                                value={name}
                                maxLength={120}
                                autoFocus
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                aria-invalid={errors.name ? true : undefined}
                                placeholder={t('chat.channel.name_placeholder')}
                                data-test="chat-channel-name"
                            />
                            {icon ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-9 shrink-0"
                                    onClick={() => setIcon(null)}
                                    aria-label={t('chat.channel.icon_remove')}
                                >
                                    <X aria-hidden="true" />
                                </Button>
                            ) : null}
                        </div>
                        <InputError message={errors.name ?? errors.icon} />
                    </div>

                    {editing ? (
                        <div className="flex items-start gap-2">
                            <Checkbox
                                id={archivedId}
                                checked={archived}
                                onCheckedChange={(value) =>
                                    setArchived(value === true)
                                }
                            />
                            <div className="grid gap-0.5">
                                <Label htmlFor={archivedId}>
                                    {t('chat.channel.archived')}
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    {t('chat.channel.archived_help')}
                                </p>
                            </div>
                        </div>
                    ) : null}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpenChange(false)}
                        >
                            {t('chat.channel.cancel')}
                        </Button>
                        <Button
                            type="submit"
                            disabled={busy}
                            data-test="chat-channel-submit"
                        >
                            {busy ? <Spinner /> : null}
                            {editing
                                ? t('chat.channel.save')
                                : t('chat.channel.create')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
