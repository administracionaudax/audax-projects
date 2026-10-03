import { router } from '@inertiajs/react';
import { LogOut, Search, UserMinus, UserPlus } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import type { RefObject } from 'react';
import { toast } from 'sonner';
import { ChatAvatar } from '@/components/chat/chat-avatar';
import { ChatApiError, chatApi } from '@/components/chat/chat-api';
import { normalizeSearch } from '@/components/chat/mentions';
import {
    PeopleLoading,
    usePeople,
} from '@/components/chat/new-conversation-dialogs';
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
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ChatConversation } from '@/types/chat';

function errorText(error: unknown): string {
    return error instanceof ChatApiError
        ? error.firstError()
        : t('chat.errors.server');
}

/**
 * «Gestionar el grupo» (D-119): renombrarlo y añadir o quitar personas (quien lo creó o el admin)
 * y salir de él (quien participa). Cada cambio deja un mensaje de sistema en el grupo; al terminar
 * se vuelven a pedir la cabecera y la lista. Al cerrar, el foco vuelve al botón que lo abrió.
 */
export function GroupSettingsDialog({
    conversation,
    open,
    onOpenChange,
    currentUserId,
    returnFocus,
}: {
    conversation: ChatConversation;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    currentUserId: number;
    returnFocus?: RefObject<HTMLElement | null>;
}) {
    const canManage = conversation.can.manage;
    const { people, failed } = usePeople(open && canManage);
    const [name, setName] = useState(conversation.title);
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<number[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState<string | null>(null);
    const [confirmLeave, setConfirmLeave] = useState(false);
    const nameId = useId();
    const searchId = useId();
    const addId = useId();

    // Cada vez que se abre, con el nombre de ahora y sin lo que quedara a medias.
    useEffect(() => {
        if (open) {
            setName(conversation.title);
            setSearch('');
            setSelected([]);
            setErrors({});
            setConfirmLeave(false);
        }
    }, [open, conversation.title]);

    const members = new Set(conversation.participants.map((p) => p.id));
    const needle = normalizeSearch(search.trim());
    const candidates = (people ?? []).filter(
        (person) =>
            !members.has(person.id) &&
            (needle === '' ||
                normalizeSearch(
                    `${person.name} ${person.department ?? ''}`,
                ).includes(needle)),
    );

    const run = async (key: string, action: () => Promise<unknown>) => {
        setBusy(key);
        setErrors({});

        try {
            await action();
            router.reload({ only: ['conversation', 'conversations'] });

            return true;
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
                toast.error(errorText(error));
            }

            return false;
        } finally {
            setBusy(null);
        }
    };

    const rename = () =>
        run('rename', async () => {
            await chatApi.renameGroup(conversation.id, name.trim());
            toast.success(t('chat.group_settings.renamed'));
        });

    const add = async () => {
        if (selected.length === 0) {
            setErrors({ user_ids: t('chat.group.need_people') });

            return;
        }

        if (
            await run('add', () =>
                chatApi.addToGroup(conversation.id, selected),
            )
        ) {
            toast.success(
                t('chat.group_settings.added', { count: selected.length }),
            );
            setSelected([]);
            setSearch('');
        }
    };

    const remove = (id: number, personName: string) =>
        run(`remove-${id}`, async () => {
            await chatApi.removeFromGroup(conversation.id, id);
            toast.success(
                t('chat.group_settings.removed', { name: personName }),
            );
        });

    const leave = async () => {
        setBusy('leave');

        try {
            const result = await chatApi.leaveGroup(conversation.id);
            toast.success(t('chat.group_settings.left'));
            onOpenChange(false);
            router.visit(result.url);
        } catch (error) {
            toast.error(errorText(error));
        } finally {
            setBusy(null);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="sm:max-w-md"
                onCloseAutoFocus={
                    returnFocus ? returnFocusTo(returnFocus) : undefined
                }
                data-test="chat-group-settings"
            >
                <DialogTitle>{t('chat.group_settings.title')}</DialogTitle>
                <DialogDescription>
                    {canManage
                        ? t('chat.group_settings.description')
                        : t('chat.group_settings.description_member')}
                </DialogDescription>

                <div className="grid max-h-[65svh] gap-5 overflow-y-auto pr-1">
                    {canManage ? (
                        <form
                            className="grid gap-1.5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                void rename();
                            }}
                        >
                            <Label htmlFor={nameId}>
                                {t('chat.group.name')}
                            </Label>
                            <div className="flex gap-2">
                                <Input
                                    id={nameId}
                                    value={name}
                                    maxLength={120}
                                    onChange={(event) =>
                                        setName(event.target.value)
                                    }
                                    aria-invalid={
                                        errors.name ? true : undefined
                                    }
                                />
                                <Button
                                    type="submit"
                                    variant="secondary"
                                    disabled={
                                        busy !== null ||
                                        name.trim() === '' ||
                                        name.trim() === conversation.title
                                    }
                                >
                                    {busy === 'rename' ? <Spinner /> : null}
                                    {t('chat.group_settings.rename')}
                                </Button>
                            </div>
                            <InputError message={errors.name} />
                        </form>
                    ) : null}

                    <section className="grid gap-2">
                        <h3 className="text-sm font-medium">
                            {t('chat.group_settings.members', {
                                count: conversation.participants.length,
                            })}
                        </h3>
                        <ul className="grid gap-px rounded-md border">
                            {conversation.participants.map((person) => (
                                <li
                                    key={person.id}
                                    className="flex items-center gap-2 px-3 py-1.5 text-sm"
                                >
                                    <ChatAvatar small user={person} />
                                    <span className="min-w-0 flex-1 truncate">
                                        {person.name}
                                        {person.id === currentUserId
                                            ? ` (${t('chat.list.you')})`
                                            : ''}
                                    </span>
                                    {canManage &&
                                    person.id !== currentUserId ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            disabled={busy !== null}
                                            onClick={() =>
                                                void remove(
                                                    person.id,
                                                    person.name,
                                                )
                                            }
                                            aria-label={t(
                                                'chat.group_settings.remove',
                                                { name: person.name },
                                            )}
                                        >
                                            {busy === `remove-${person.id}` ? (
                                                <Spinner />
                                            ) : (
                                                <UserMinus aria-hidden="true" />
                                            )}
                                            <span aria-hidden="true">
                                                {t(
                                                    'chat.group_settings.remove_short',
                                                )}
                                            </span>
                                        </Button>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                        <InputError message={errors.user} />
                    </section>

                    {canManage ? (
                        <fieldset className="grid gap-2">
                            <legend className="mb-1.5 text-sm font-medium">
                                {t('chat.group_settings.add')}
                                <span className="ml-2 text-xs font-normal text-muted-foreground">
                                    {t('chat.group.selected', {
                                        count: selected.length,
                                    })}
                                </span>
                            </legend>
                            <div className="relative">
                                <Search
                                    aria-hidden="true"
                                    className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                                />
                                <Label htmlFor={searchId} className="sr-only">
                                    {t('chat.group.search')}
                                </Label>
                                <Input
                                    id={searchId}
                                    value={search}
                                    placeholder={t('chat.group.search')}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    className="pl-8"
                                />
                            </div>
                            {failed ? (
                                <p role="alert" className="text-sm text-danger">
                                    {t('chat.errors.people')}
                                </p>
                            ) : people === null ? (
                                <PeopleLoading />
                            ) : (
                                <ul
                                    id={addId}
                                    className="max-h-48 overflow-y-auto rounded-md border"
                                >
                                    {candidates.length === 0 ? (
                                        <li className="p-3 text-sm text-muted-foreground">
                                            {t('chat.direct.empty')}
                                        </li>
                                    ) : (
                                        candidates.map((person) => {
                                            const checkboxId = `${addId}-${person.id}`;
                                            const checked = selected.includes(
                                                person.id,
                                            );

                                            return (
                                                <li key={person.id}>
                                                    <label
                                                        htmlFor={checkboxId}
                                                        className={cn(
                                                            'flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-muted',
                                                            checked &&
                                                                'bg-accent',
                                                        )}
                                                    >
                                                        <Checkbox
                                                            id={checkboxId}
                                                            checked={checked}
                                                            onCheckedChange={(
                                                                value,
                                                            ) =>
                                                                setSelected(
                                                                    (
                                                                        current,
                                                                    ) =>
                                                                        value ===
                                                                        true
                                                                            ? [
                                                                                  ...current,
                                                                                  person.id,
                                                                              ]
                                                                            : current.filter(
                                                                                  (
                                                                                      id,
                                                                                  ) =>
                                                                                      id !==
                                                                                      person.id,
                                                                              ),
                                                                )
                                                            }
                                                            className={
                                                                FOCUS_RING
                                                            }
                                                        />
                                                        <ChatAvatar
                                                            small
                                                            user={person}
                                                        />
                                                        <span className="min-w-0 flex-1 truncate">
                                                            {person.name}
                                                        </span>
                                                    </label>
                                                </li>
                                            );
                                        })
                                    )}
                                </ul>
                            )}
                            <InputError message={errors.user_ids} />
                            <div>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    disabled={busy !== null}
                                    onClick={() => void add()}
                                >
                                    {busy === 'add' ? (
                                        <Spinner />
                                    ) : (
                                        <UserPlus aria-hidden="true" />
                                    )}
                                    {t('chat.group_settings.add_selected')}
                                </Button>
                            </div>
                        </fieldset>
                    ) : null}
                </div>

                <DialogFooter className="gap-2 sm:justify-between">
                    {conversation.can.leave ? (
                        confirmLeave ? (
                            <div
                                role="group"
                                aria-label={t('chat.group_settings.leave')}
                                className="flex flex-wrap items-center gap-2"
                            >
                                <span className="text-sm">
                                    {t('chat.group_settings.leave_confirm')}
                                </span>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    disabled={busy !== null}
                                    onClick={() => void leave()}
                                    data-test="chat-group-leave-confirm"
                                >
                                    {busy === 'leave' ? <Spinner /> : null}
                                    {t('chat.group_settings.leave')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setConfirmLeave(false)}
                                >
                                    {t('common.cancel')}
                                </Button>
                            </div>
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setConfirmLeave(true)}
                                data-test="chat-group-leave"
                            >
                                <LogOut aria-hidden="true" />
                                {t('chat.group_settings.leave')}
                            </Button>
                        )
                    ) : (
                        <span />
                    )}
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => onOpenChange(false)}
                    >
                        {t('common.close')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
