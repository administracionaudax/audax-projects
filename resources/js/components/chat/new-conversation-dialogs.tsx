import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { toast } from 'sonner';
import { ChatAvatar } from '@/components/chat/chat-avatar';
import { chatApi } from '@/components/chat/chat-api';
import { normalizeSearch } from '@/components/chat/mentions';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    CommandDialog,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { store as storeDirect } from '@/routes/chat/direct';
import { store as storeGroup } from '@/routes/chat/groups';
import type { ChatPerson } from '@/types/chat';

/** Personas internas activas (sin quien mira), cargadas al abrir un diálogo. */
function usePeople(open: boolean): {
    people: ChatPerson[] | null;
    failed: boolean;
} {
    const [people, setPeople] = useState<ChatPerson[] | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!open || people !== null) {
            return;
        }

        let cancelled = false;
        setFailed(false);

        chatApi
            .people()
            .then((data) => {
                if (!cancelled) {
                    setPeople(data.people);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setFailed(true);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [open, people]);

    return { people, failed };
}

function Loading() {
    return (
        <div className="grid gap-2 p-3" aria-busy="true">
            <span className="sr-only">{t('chat.direct.loading')}</span>
            <Skeleton className="h-8" />
            <Skeleton className="h-8" />
            <Skeleton className="h-8" />
        </div>
    );
}

/**
 * «Nuevo mensaje directo»: buscador de personas (cmdk: teclado completo). Al elegir, se abre la
 * directa con esa persona (una por pareja; se crea la primera vez).
 */
export function NewDirectDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { people, failed } = usePeople(open);
    const [processing, setProcessing] = useState(false);

    const choose = (person: ChatPerson) => {
        router.post(
            storeDirect.url(),
            { user_id: person.id },
            {
                onStart: () => setProcessing(true),
                onSuccess: () => onOpenChange(false),
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ?? t('chat.errors.server'),
                    ),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <CommandDialog
            open={open}
            onOpenChange={onOpenChange}
            title={t('chat.direct.title')}
            description={t('chat.direct.description')}
        >
            <CommandInput
                placeholder={t('chat.direct.search')}
                aria-label={t('chat.direct.search')}
            />
            <CommandList>
                {failed ? (
                    <p role="alert" className="p-3 text-sm text-danger">
                        {t('chat.errors.people')}
                    </p>
                ) : people === null ? (
                    <Loading />
                ) : (
                    <>
                        <CommandEmpty>{t('chat.direct.empty')}</CommandEmpty>
                        {people.map((person) => (
                            <CommandItem
                                key={person.id}
                                value={`${person.name} ${person.department ?? ''} ${person.id}`}
                                onSelect={() => choose(person)}
                                disabled={processing}
                                className="flex items-center gap-2"
                            >
                                <ChatAvatar small user={person} />
                                <span className="min-w-0 flex-1 truncate">
                                    {person.name}
                                </span>
                                {person.department ? (
                                    <span className="text-xs text-muted-foreground">
                                        {person.department}
                                    </span>
                                ) : null}
                            </CommandItem>
                        ))}
                    </>
                )}
            </CommandList>
        </CommandDialog>
    );
}

/**
 * «Nuevo grupo»: nombre y personas (casillas con búsqueda). Quien lo crea entra siempre.
 */
export function NewGroupDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { people, failed } = usePeople(open);
    const [name, setName] = useState('');
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<number[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const nameId = useId();
    const searchId = useId();
    const peopleId = useId();

    const needle = normalizeSearch(search.trim());
    const visible = (people ?? []).filter(
        (person) =>
            needle === '' ||
            normalizeSearch(
                `${person.name} ${person.department ?? ''}`,
            ).includes(needle),
    );

    const toggle = (id: number, checked: boolean) =>
        setSelected((current) =>
            checked ? [...current, id] : current.filter((item) => item !== id),
        );

    const submit = () => {
        const local: Record<string, string> = {};

        if (name.trim() === '') {
            local.name = t('chat.group.need_name');
        }

        if (selected.length === 0) {
            local.user_ids = t('chat.group.need_people');
        }

        setErrors(local);

        if (Object.keys(local).length > 0) {
            return;
        }

        router.post(
            storeGroup.url(),
            { name: name.trim(), user_ids: selected },
            {
                onStart: () => setProcessing(true),
                onSuccess: () => {
                    onOpenChange(false);
                    setName('');
                    setSelected([]);
                    setSearch('');
                },
                onError: (serverErrors) => setErrors(serverErrors),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogTitle>{t('chat.group.title')}</DialogTitle>
                <DialogDescription>
                    {t('chat.group.description')}
                </DialogDescription>
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    <div className="grid gap-1.5">
                        <Label htmlFor={nameId}>{t('chat.group.name')}</Label>
                        <Input
                            id={nameId}
                            value={name}
                            maxLength={120}
                            placeholder={t('chat.group.name_placeholder')}
                            onChange={(event) => setName(event.target.value)}
                            aria-invalid={errors.name ? true : undefined}
                        />
                        <InputError message={errors.name} />
                    </div>
                    <fieldset className="grid gap-2">
                        <legend className="mb-1.5 text-sm font-medium">
                            {t('chat.group.people')}
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
                            <Loading />
                        ) : (
                            <ul
                                id={peopleId}
                                className="max-h-64 overflow-y-auto rounded-[3px] border"
                            >
                                {visible.length === 0 ? (
                                    <li className="p-3 text-sm text-muted-foreground">
                                        {t('chat.direct.empty')}
                                    </li>
                                ) : (
                                    visible.map((person) => {
                                        const checkboxId = `${peopleId}-${person.id}`;
                                        const checked = selected.includes(
                                            person.id,
                                        );

                                        return (
                                            <li key={person.id}>
                                                <label
                                                    htmlFor={checkboxId}
                                                    className={cn(
                                                        'flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-muted',
                                                        checked && 'bg-accent',
                                                    )}
                                                >
                                                    <Checkbox
                                                        id={checkboxId}
                                                        checked={checked}
                                                        onCheckedChange={(
                                                            value,
                                                        ) =>
                                                            toggle(
                                                                person.id,
                                                                value === true,
                                                            )
                                                        }
                                                        className={FOCUS_RING}
                                                    />
                                                    <ChatAvatar
                                                        small
                                                        user={person}
                                                    />
                                                    <span className="min-w-0 flex-1 truncate">
                                                        {person.name}
                                                    </span>
                                                    {person.department ? (
                                                        <span className="text-xs text-muted-foreground">
                                                            {person.department}
                                                        </span>
                                                    ) : null}
                                                </label>
                                            </li>
                                        );
                                    })
                                )}
                            </ul>
                        )}
                        <InputError message={errors.user_ids} />
                    </fieldset>
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => onOpenChange(false)}
                            disabled={processing}
                        >
                            {t('common.cancel')}
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? <Spinner /> : null}
                            {t('chat.group.create')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
