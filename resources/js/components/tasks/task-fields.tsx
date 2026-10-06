import { Check, ChevronsUpDown, CircleCheck, UserRound } from 'lucide-react';
import { useState } from 'react';
import { PriorityBadge } from '@/components/domain/badges';
import { useTaskLookups } from '@/components/tasks/task-lookups';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useInitials } from '@/hooks/use-initials';
import { formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TaskBankOption, TaskPriority, UserSummary } from '@/types';

/** Valor de los selectores para «ninguno» (Radix Select no admite el texto vacío). */
export const NONE = '__none';

export const PRIORITIES: TaskPriority[] = ['urgent', 'high', 'normal', 'low'];

export function UserAvatar({
    user,
    className,
}: {
    user: Pick<UserSummary, 'name' | 'avatar'>;
    className?: string;
}) {
    const getInitials = useInitials();

    return (
        <Avatar className={cn('size-6 shrink-0 rounded-full', className)}>
            <AvatarImage src={user.avatar ?? undefined} alt="" />
            <AvatarFallback className="rounded-full bg-neutral-soft text-[0.65rem] font-medium text-foreground">
                {getInitials(user.name)}
            </AvatarFallback>
        </Avatar>
    );
}

/** Responsable en una celda o tarjeta: avatar y nombre (o «Sin responsable»). */
export function AssigneeLabel({
    user,
    compact = false,
}: {
    user: UserSummary | null | undefined;
    compact?: boolean;
}) {
    if (!user) {
        return (
            <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                <UserRound aria-hidden="true" className="size-4" />
                {compact ? (
                    <span className="sr-only">
                        {t('task_fields.no_assignee')}
                    </span>
                ) : (
                    t('task_fields.no_assignee')
                )}
            </span>
        );
    }

    return (
        <span className="inline-flex min-w-0 items-center gap-1.5">
            <UserAvatar user={user} />
            <span className={cn('truncate', compact && 'sr-only')}>
                {user.name}
            </span>
        </span>
    );
}

type SelectFieldProps = {
    id?: string;
    disabled?: boolean;
    className?: string;
    'aria-label'?: string;
    'aria-describedby'?: string;
};

export function StatusSelect({
    value,
    onChange,
    placeholder,
    ...props
}: SelectFieldProps & {
    /** null: sin valor, con el texto de ayuda (acciones masivas). */
    value: number | null;
    onChange: (statusId: number) => void;
    placeholder?: string;
}) {
    const { statuses } = useTaskLookups();

    return (
        <Select
            value={value === null ? '' : String(value)}
            onValueChange={(next) => onChange(Number(next))}
            disabled={props.disabled}
        >
            <SelectTrigger
                id={props.id}
                aria-label={props['aria-label']}
                className={cn('w-full', props.className)}
            >
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {statuses.map((status) => (
                    <SelectItem key={status.id} value={String(status.id)}>
                        {status.category === 'done' ? (
                            <CircleCheck
                                aria-hidden="true"
                                className="size-3.5 text-success"
                            />
                        ) : (
                            <span
                                aria-hidden="true"
                                className="size-2 rounded-full"
                                style={{ backgroundColor: status.color }}
                            />
                        )}
                        {status.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export function PrioritySelect({
    value,
    onChange,
    ...props
}: SelectFieldProps & {
    value: TaskPriority;
    onChange: (priority: TaskPriority) => void;
}) {
    return (
        <Select
            value={value}
            onValueChange={(next) => onChange(next as TaskPriority)}
            disabled={props.disabled}
        >
            <SelectTrigger
                id={props.id}
                aria-label={props['aria-label']}
                className={cn('w-full', props.className)}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {PRIORITIES.map((priority) => (
                    <SelectItem key={priority} value={priority}>
                        <PriorityBadge priority={priority} />
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export function TypeSelect({
    value,
    onChange,
    ...props
}: SelectFieldProps & {
    value: number | null;
    onChange: (typeId: number | null) => void;
}) {
    const { types } = useTaskLookups();
    const selectable = types.filter(
        (type) => type.is_active || type.id === value,
    );

    return (
        <Select
            value={value === null ? NONE : String(value)}
            onValueChange={(next) =>
                onChange(next === NONE ? null : Number(next))
            }
            disabled={props.disabled}
        >
            <SelectTrigger
                id={props.id}
                aria-label={props['aria-label']}
                className={cn('w-full', props.className)}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NONE}>{t('task_fields.no_type')}</SelectItem>
                {selectable.map((type) => (
                    <SelectItem
                        key={type.id}
                        value={String(type.id)}
                        disabled={!type.is_active}
                    >
                        <span
                            aria-hidden="true"
                            className="size-2 rounded-full"
                            style={{ backgroundColor: type.color }}
                        />
                        {type.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export function bankLabel(bank: TaskBankOption): string {
    // Un colaborador externo no ve el consumo de las bolsas (D-134).
    if (bank.consumed_pct === null) {
        return bank.name;
    }

    return t('task_fields.bank_option', {
        name: bank.name,
        pct: formatPercent(bank.consumed_pct / 100, 0),
    });
}

/**
 * Bolsa (SPEC §8.3): primero las abiertas del departamento del usuario, después las demás
 * abiertas; las cerradas o renovadas solo aparecen si ya es la bolsa de la tarea.
 */
export function BankSelect({
    value,
    onChange,
    allowNone = false,
    banks: bankOptions,
    ...props
}: SelectFieldProps & {
    value: number | null;
    onChange: (bankId: number | null) => void;
    allowNone?: boolean;
    /** Otras bolsas (p. ej. las del proyecto destino al mover). */
    banks?: TaskBankOption[];
}) {
    const lookups = useTaskLookups();
    const banks = bankOptions ?? lookups.banks;
    const departmentId = lookups.currentUser.department_id;
    const open = banks.filter((bank) => bank.is_open);
    const mine = open.filter(
        (bank) => departmentId !== null && bank.department_id === departmentId,
    );
    const others = open.filter((bank) => !mine.includes(bank));
    const current = banks.find((bank) => bank.id === value && !bank.is_open);

    return (
        <Select
            value={value === null ? (allowNone ? NONE : '') : String(value)}
            onValueChange={(next) =>
                onChange(next === NONE ? null : Number(next))
            }
            disabled={props.disabled}
        >
            <SelectTrigger
                id={props.id}
                aria-label={props['aria-label']}
                aria-describedby={props['aria-describedby']}
                className={cn('w-full', props.className)}
            >
                <SelectValue placeholder={t('task_fields.choose_bank')} />
            </SelectTrigger>
            <SelectContent>
                {allowNone ? (
                    <SelectItem value={NONE}>
                        {t('task_fields.no_bank')}
                    </SelectItem>
                ) : null}
                {mine.length > 0 ? (
                    <SelectGroup>
                        <SelectLabel>{t('task_fields.banks_mine')}</SelectLabel>
                        {mine.map((bank) => (
                            <SelectItem key={bank.id} value={String(bank.id)}>
                                {bankLabel(bank)}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                ) : null}
                {others.length > 0 ? (
                    <SelectGroup>
                        {mine.length > 0 ? (
                            <SelectLabel>
                                {t('task_fields.banks_others')}
                            </SelectLabel>
                        ) : null}
                        {others.map((bank) => (
                            <SelectItem key={bank.id} value={String(bank.id)}>
                                {bankLabel(bank)}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                ) : null}
                {current ? (
                    <SelectItem value={String(current.id)} disabled>
                        {t('task_fields.bank_closed_option', {
                            name: current.name,
                        })}
                    </SelectItem>
                ) : null}
            </SelectContent>
        </Select>
    );
}

/**
 * Responsable con búsqueda (internos activos; primero los miembros del proyecto).
 */
export function AssigneePicker({
    value,
    onChange,
    current,
    placeholder,
    id,
    disabled,
    className,
    'aria-label': ariaLabel,
}: SelectFieldProps & {
    /** undefined: nada elegido todavía (se ve el texto de ayuda, p. ej. en acciones masivas). */
    value: number | null | undefined;
    onChange: (userId: number | null) => void;
    /** Responsable actual aunque ya no esté en la lista (p. ej. desactivado). */
    current?: UserSummary | null;
    placeholder?: string;
}) {
    const { users, userById } = useTaskLookups();
    const [open, setOpen] = useState(false);
    const selected =
        value === null || value === undefined
            ? null
            : (userById.get(value) ?? current);
    const members = users.filter((user) => user.is_member);
    const others = users.filter((user) => !user.is_member);

    const choose = (userId: number | null) => {
        setOpen(false);

        if (userId !== value) {
            onChange(userId);
        }
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="field"
                    role="combobox"
                    aria-expanded={open}
                    aria-label={ariaLabel}
                    disabled={disabled}
                    className={cn('w-full justify-between', className)}
                >
                    {value === undefined ? (
                        <span className="text-muted-foreground">
                            {placeholder}
                        </span>
                    ) : (
                        <AssigneeLabel user={selected} />
                    )}
                    <ChevronsUpDown
                        aria-hidden="true"
                        className="size-4 opacity-60"
                    />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-72 p-0" align="start">
                <Command>
                    <CommandInput
                        placeholder={t('task_fields.search_person')}
                        aria-label={t('task_fields.search_person')}
                    />
                    <CommandList>
                        <CommandEmpty>
                            {t('task_fields.no_people')}
                        </CommandEmpty>
                        <CommandGroup>
                            <CommandItem
                                value={`__none ${t('task_fields.no_assignee')}`}
                                onSelect={() => choose(null)}
                            >
                                <UserRound aria-hidden="true" />
                                {t('task_fields.no_assignee')}
                                {value === null ? (
                                    <Check
                                        aria-hidden="true"
                                        className="ml-auto"
                                    />
                                ) : null}
                            </CommandItem>
                        </CommandGroup>
                        {[
                            {
                                key: 'members',
                                label: t('task_fields.people_members'),
                                people: members,
                            },
                            {
                                key: 'others',
                                label: t('task_fields.people_others'),
                                people: others,
                            },
                        ]
                            .filter((group) => group.people.length > 0)
                            .map((group) => (
                                <CommandGroup
                                    key={group.key}
                                    heading={group.label}
                                >
                                    {group.people.map((user) => (
                                        <CommandItem
                                            key={user.id}
                                            value={`${user.name} ${user.id}`}
                                            onSelect={() => choose(user.id)}
                                        >
                                            <UserAvatar user={user} />
                                            <span className="truncate">
                                                {user.name}
                                            </span>
                                            {value === user.id ? (
                                                <Check
                                                    aria-hidden="true"
                                                    className="ml-auto"
                                                />
                                            ) : null}
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
