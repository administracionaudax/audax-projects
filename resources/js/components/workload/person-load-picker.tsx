import { Check, ChevronsUpDown, UserRound } from 'lucide-react';
import { useState } from 'react';
import { LOAD_LEVELS, loadLevel } from '@/components/charts/thresholds';
import type {
    WorkloadExtraPerson,
    WorkloadPerson,
} from '@/components/workload/types';
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
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Selector de responsable de la vista Carga: las personas del alcance llevan su carga del
 * horizonte (planificado / capacidad, con el icono y el nivel del semáforo) para elegir a quien
 * tiene hueco; los miembros de un proyecto que se gestiona pero de fuera del alcance, solo el
 * nombre (D-052: nunca se ve su carga).
 */
export function PersonLoadPicker({
    value,
    onChange,
    team,
    others = [],
    allowNone = false,
    placeholder,
    id,
    disabled,
    invalid,
    className,
    'aria-label': ariaLabel,
    'aria-describedby': describedBy,
}: {
    value: number | null;
    onChange: (userId: number | null) => void;
    team: WorkloadPerson[];
    others?: WorkloadExtraPerson[];
    /** Ofrece «Sin responsable» (dejar la tarea sin asignar). */
    allowNone?: boolean;
    placeholder?: string;
    id?: string;
    disabled?: boolean;
    invalid?: boolean;
    className?: string;
    'aria-label'?: string;
    'aria-describedby'?: string;
}) {
    const [open, setOpen] = useState(false);
    const selected =
        value === null
            ? null
            : (team.find((person) => person.id === value) ??
              others.find((person) => person.id === value) ??
              null);

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
                    aria-describedby={describedBy}
                    aria-invalid={invalid || undefined}
                    disabled={disabled}
                    className={cn(
                        'w-full min-w-0 justify-between',
                        className,
                    )}
                >
                    <span className="flex min-w-0 items-center gap-1.5">
                        <UserRound
                            aria-hidden="true"
                            className="size-4 shrink-0 text-muted-foreground"
                        />
                        <span
                            className={cn(
                                'truncate',
                                selected === null && 'text-muted-foreground',
                            )}
                        >
                            {selected
                                ? selected.name
                                : value === null && allowNone
                                  ? t('workload_picker.unassigned')
                                  : (placeholder ??
                                    t('workload_picker.placeholder'))}
                        </span>
                    </span>
                    <ChevronsUpDown
                        aria-hidden="true"
                        className="size-4 shrink-0 opacity-60"
                    />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-80 max-w-[calc(100vw-2rem)] p-0"
                align="start"
            >
                <Command>
                    <CommandInput
                        placeholder={t('workload_picker.search')}
                        aria-label={t('workload_picker.search')}
                    />
                    <CommandList>
                        <CommandEmpty>
                            {t('workload_picker.none_found')}
                        </CommandEmpty>
                        {allowNone ? (
                            <CommandGroup>
                                <CommandItem
                                    value={`__none ${t('workload_picker.unassigned')}`}
                                    onSelect={() => choose(null)}
                                >
                                    <UserRound aria-hidden="true" />
                                    {t('workload_picker.unassigned')}
                                    {value === null ? (
                                        <Check
                                            aria-hidden="true"
                                            className="ml-auto"
                                        />
                                    ) : null}
                                </CommandItem>
                            </CommandGroup>
                        ) : null}
                        {team.length > 0 ? (
                            <CommandGroup heading={t('workload_picker.team')}>
                                {team.map((person) => (
                                    <TeamItem
                                        key={person.id}
                                        person={person}
                                        selected={person.id === value}
                                        onSelect={() => choose(person.id)}
                                    />
                                ))}
                            </CommandGroup>
                        ) : null}
                        {others.length > 0 ? (
                            <CommandGroup heading={t('workload_picker.others')}>
                                {others.map((person) => (
                                    <CommandItem
                                        key={person.id}
                                        value={`${person.name} ${person.id}`}
                                        onSelect={() => choose(person.id)}
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate">
                                                {person.name}
                                            </span>
                                            {person.department ? (
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {person.department}
                                                </span>
                                            ) : null}
                                        </span>
                                        {person.id === value ? (
                                            <Check aria-hidden="true" />
                                        ) : null}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        ) : null}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

function TeamItem({
    person,
    selected,
    onSelect,
}: {
    person: WorkloadPerson;
    selected: boolean;
    onSelect: () => void;
}) {
    const level = loadLevel(person.planned, person.capacity);
    const meta = LOAD_LEVELS[level];
    const Icon = meta.icon;
    const load =
        level === 'none'
            ? t('workload_picker.no_capacity')
            : t('workload_picker.load', {
                  planned: formatMinutes(person.planned),
                  capacity: formatMinutes(person.capacity),
              });

    return (
        <CommandItem value={`${person.name} ${person.id}`} onSelect={onSelect}>
            <span className="min-w-0 flex-1">
                <span className="block truncate">
                    {person.name}
                    {person.is_me ? (
                        <span className="text-muted-foreground">
                            {' '}
                            {t('workload_picker.me')}
                        </span>
                    ) : null}
                </span>
                {person.department ? (
                    <span className="block truncate text-xs text-muted-foreground">
                        {person.department}
                    </span>
                ) : null}
            </span>
            <span className="tabular inline-flex shrink-0 items-center gap-1 text-xs">
                <Icon
                    aria-hidden="true"
                    className={cn('size-3.5', meta.tone)}
                />
                {load}
                <span className="sr-only">({meta.label})</span>
            </span>
            {selected ? <Check aria-hidden="true" /> : null}
        </CommandItem>
    );
}
