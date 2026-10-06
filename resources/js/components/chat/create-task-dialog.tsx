import { router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ChatApiError, chatApi } from '@/components/chat/chat-api';
import { returnFocusTo } from '@/components/chat/return-focus';
import { DatePicker } from '@/components/domain/date-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import type {
    ChatMessage,
    ChatMessageResponse,
    ChatTaskOptions,
} from '@/types/chat';

const NONE = '__none';

type Errors = Partial<
    Record<
        'title' | 'hour_bank_id' | 'assignee_user_id' | 'due_date' | 'message',
        string
    >
>;

/**
 * «Crear tarea» desde un mensaje de un chat de proyecto (SPEC §12): título (sale del mensaje),
 * bolsa (obligatoria en proyectos de bolsas, solo las abiertas), responsable y fecha límite. La
 * crea TaskWriter con las reglas de siempre y el mensaje queda enlazado a la tarea.
 */
export function CreateTaskDialog({
    message,
    onClose,
    onCreated,
    returnFocus,
}: {
    message: ChatMessage | null;
    onClose: () => void;
    onCreated: (response: ChatMessageResponse) => void;
    /** Al cerrar, a dónde vuelve el foco (se abre desde el menú del mensaje, que ya no está). */
    returnFocus?: (messageId: number) => HTMLElement | null;
}) {
    const [options, setOptions] = useState<ChatTaskOptions | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [title, setTitle] = useState('');
    const [bank, setBank] = useState<string>(NONE);
    const [assignee, setAssignee] = useState<string>(NONE);
    const [dueDate, setDueDate] = useState<string | null>(null);
    const [errors, setErrors] = useState<Errors>({});
    const [processing, setProcessing] = useState(false);
    const titleId = useId();
    const bankId = useId();
    const assigneeId = useId();
    const dueId = useId();
    const messageId = message?.id ?? null;
    // El último mensaje abierto (al cerrar, `message` ya es null).
    const lastMessageId = useRef<number | null>(null);

    useEffect(() => {
        if (messageId !== null) {
            lastMessageId.current = messageId;
        }
    }, [messageId]);

    useEffect(() => {
        if (messageId === null) {
            return;
        }

        let cancelled = false;
        setOptions(null);
        setLoadError(null);
        setErrors({});

        chatApi
            .taskOptions(messageId)
            .then((data) => {
                if (cancelled) {
                    return;
                }

                setOptions(data);
                setTitle(data.title);
                setBank(
                    data.banks.length === 1 ? String(data.banks[0].id) : NONE,
                );
                setAssignee(NONE);
                setDueDate(null);
            })
            .catch((error: unknown) => {
                if (!cancelled) {
                    setLoadError(
                        error instanceof ChatApiError
                            ? error.firstError()
                            : t('chat.errors.task_options'),
                    );
                }
            });

        return () => {
            cancelled = true;
        };
    }, [messageId]);

    const submit = async () => {
        if (messageId === null || processing) {
            return;
        }

        setProcessing(true);
        setErrors({});

        try {
            const response = await chatApi.createTask(messageId, {
                title: title.trim(),
                hour_bank_id: bank === NONE ? null : Number(bank),
                assignee_user_id: assignee === NONE ? null : Number(assignee),
                due_date: dueDate,
            });
            onCreated(response);
            onClose();

            if (response.task) {
                const task = response.task;
                toast.success(t('chat.task.created', { title: task.title }), {
                    action: {
                        label: t('chat.task.view'),
                        onClick: () => router.visit(task.url),
                    },
                });
            }
        } catch (error) {
            if (error instanceof ChatApiError && error.status === 422) {
                const next: Errors = {};

                for (const [field, messages] of Object.entries(error.errors)) {
                    next[field as keyof Errors] = messages[0];
                }

                setErrors(next);
            } else {
                toast.error(
                    error instanceof ChatApiError
                        ? error.firstError()
                        : t('chat.errors.server'),
                );
            }
        } finally {
            setProcessing(false);
        }
    };

    const members = options?.people.filter((person) => person.is_member) ?? [];
    const others = options?.people.filter((person) => !person.is_member) ?? [];

    return (
        <Dialog
            open={message !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent
                className="sm:max-w-lg"
                onCloseAutoFocus={returnFocusTo(() =>
                    returnFocus && lastMessageId.current !== null
                        ? returnFocus(lastMessageId.current)
                        : null,
                )}
            >
                <DialogTitle>{t('chat.task.title')}</DialogTitle>
                <DialogDescription>
                    {t('chat.task.description', {
                        project: options?.project.name ?? '…',
                    })}
                </DialogDescription>

                {loadError ? (
                    <p role="alert" className="text-sm text-danger">
                        {loadError}
                    </p>
                ) : options === null ? (
                    <div className="grid gap-3" aria-busy="true">
                        <span className="sr-only">{t('common.loading')}</span>
                        <Skeleton className="h-9" />
                        <Skeleton className="h-9" />
                        <Skeleton className="h-9" />
                    </div>
                ) : (
                    <form
                        className="grid gap-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void submit();
                        }}
                    >
                        <div className="grid gap-1.5">
                            <Label htmlFor={titleId}>
                                {t('chat.task.field_title')}
                            </Label>
                            <Input
                                id={titleId}
                                value={title}
                                maxLength={255}
                                required
                                onChange={(event) =>
                                    setTitle(event.target.value)
                                }
                                aria-invalid={errors.title ? true : undefined}
                            />
                            <InputError message={errors.title} />
                        </div>

                        {options.project.uses_hour_banks ? (
                            <div className="grid gap-1.5">
                                <Label htmlFor={bankId}>
                                    {t('chat.task.bank')}
                                </Label>
                                {options.banks.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('chat.task.no_banks')}
                                    </p>
                                ) : (
                                    <Select
                                        value={bank}
                                        onValueChange={setBank}
                                    >
                                        <SelectTrigger
                                            id={bankId}
                                            className="w-full"
                                            aria-invalid={
                                                errors.hour_bank_id
                                                    ? true
                                                    : undefined
                                            }
                                        >
                                            <SelectValue
                                                placeholder={t(
                                                    'chat.task.bank_placeholder',
                                                )}
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NONE} disabled>
                                                {t(
                                                    'chat.task.bank_placeholder',
                                                )}
                                            </SelectItem>
                                            {options.banks.map((item) => (
                                                <SelectItem
                                                    key={item.id}
                                                    value={String(item.id)}
                                                >
                                                    {item.department
                                                        ? `${item.name} · ${item.department}`
                                                        : item.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                <InputError message={errors.hour_bank_id} />
                            </div>
                        ) : null}

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid content-start gap-1.5">
                                <Label htmlFor={assigneeId}>
                                    {t('chat.task.assignee')}
                                </Label>
                                <Select
                                    value={assignee}
                                    onValueChange={setAssignee}
                                >
                                    <SelectTrigger
                                        id={assigneeId}
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            {t('chat.task.no_assignee')}
                                        </SelectItem>
                                        {members.length > 0 ? (
                                            <SelectGroup>
                                                <SelectLabel>
                                                    {t('chat.task.members')}
                                                </SelectLabel>
                                                {members.map((person) => (
                                                    <SelectItem
                                                        key={person.id}
                                                        value={String(
                                                            person.id,
                                                        )}
                                                    >
                                                        {person.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectGroup>
                                        ) : null}
                                        {others.length > 0 ? (
                                            <SelectGroup>
                                                <SelectLabel>
                                                    {t('chat.task.others')}
                                                </SelectLabel>
                                                {others.map((person) => (
                                                    <SelectItem
                                                        key={person.id}
                                                        value={String(
                                                            person.id,
                                                        )}
                                                    >
                                                        {person.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectGroup>
                                        ) : null}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.assignee_user_id} />
                            </div>
                            <div className="grid content-start gap-1.5">
                                <Label htmlFor={dueId}>
                                    {t('chat.task.due_date')}
                                </Label>
                                <DatePicker
                                    id={dueId}
                                    value={dueDate}
                                    onChange={setDueDate}
                                    invalid={Boolean(errors.due_date)}
                                />
                                <InputError message={errors.due_date} />
                            </div>
                        </div>

                        <InputError message={errors.message} />

                        <DialogFooter className="gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={onClose}
                                disabled={processing}
                            >
                                {t('common.cancel')}
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? <Spinner /> : null}
                                {t('chat.task.create')}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
