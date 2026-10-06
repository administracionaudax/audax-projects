import { Check, ChevronsUpDown } from 'lucide-react';
import { useState } from 'react';
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
import { t } from '@/lib/i18n';
import { catalogClients } from '@/lib/my-space-tasks';
import { cn } from '@/lib/utils';
import type { MySpaceTaskProject } from '@/types/weeklies';

const ALL = '__all';
const NONE = '__none';

/** Con más opciones que estas, el selector lleva buscador (WeeklySync: `searchable={clients.length > 8}`). */
export const SEARCHABLE_FROM = 9;

type PickerOption = { value: string; label: string };

/**
 * Selector con buscador (10.9b): un desplegable con un campo para filtrar por nombre o código,
 * para las listas largas de clientes y proyectos.
 */
function SearchablePicker({
    value,
    placeholder,
    groups,
    onChange,
    search,
    empty,
    invalid,
    dataTest,
    ...props
}: FieldProps & {
    value: string | null;
    placeholder: string;
    groups: { label: string | null; options: PickerOption[] }[];
    onChange: (value: string) => void;
    search: string;
    empty: string;
    dataTest?: string;
}) {
    const [open, setOpen] = useState(false);
    const selected = groups
        .flatMap((group) => group.options)
        .find((option) => option.value === value);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={props.id}
                    type="button"
                    variant="field"
                    role="combobox"
                    aria-expanded={open}
                    aria-label={props['aria-label']}
                    aria-invalid={invalid ? true : undefined}
                    disabled={props.disabled}
                    className={cn(
                        'w-full justify-between',
                        props.className,
                    )}
                    data-test={dataTest}
                >
                    <span
                        className={cn(
                            'truncate',
                            !selected && 'text-muted-foreground',
                        )}
                    >
                        {selected?.label ?? placeholder}
                    </span>
                    <ChevronsUpDown
                        aria-hidden="true"
                        className="size-4 opacity-60"
                    />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-(--radix-popover-trigger-width) min-w-72 p-0"
                align="start"
            >
                <Command>
                    <CommandInput placeholder={search} aria-label={search} />
                    <CommandList>
                        <CommandEmpty>{empty}</CommandEmpty>
                        {groups.map((group, index) => (
                            <CommandGroup
                                key={group.label ?? index}
                                heading={group.label ?? undefined}
                            >
                                {group.options.map((option) => (
                                    <CommandItem
                                        key={option.value}
                                        value={`${option.label} ${option.value}`}
                                        onSelect={() => {
                                            setOpen(false);
                                            onChange(option.value);
                                        }}
                                    >
                                        <span className="truncate">
                                            {option.label}
                                        </span>
                                        {option.value === value ? (
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

type FieldProps = {
    id?: string;
    invalid?: boolean;
    disabled?: boolean;
    className?: string;
    'aria-label'?: string;
};

/**
 * Cliente de la tarea (el selector «Cliente» del original): filtra los proyectos. undefined = todos;
 * null = proyectos internos, sin cliente.
 */
export function ClientPicker({
    projects,
    value,
    onChange,
    ...props
}: FieldProps & {
    projects: MySpaceTaskProject[];
    value: number | null | undefined;
    onChange: (clientId: number | null) => void;
}) {
    const clients = catalogClients(projects);
    const label = (client: {
        id: number | null;
        name: string;
        icon: string | null;
    }) =>
        client.id === null
            ? t('my_space.tasks.general')
            : `${client.icon ? `${client.icon} ` : ''}${client.name}`;

    if (clients.length >= SEARCHABLE_FROM) {
        return (
            <SearchablePicker
                {...props}
                value={
                    value === undefined
                        ? null
                        : value === null
                          ? NONE
                          : String(value)
                }
                placeholder={t('my_space.tasks.field.client_any')}
                groups={[
                    {
                        label: null,
                        options: clients.map((client) => ({
                            value:
                                client.id === null ? NONE : String(client.id),
                            label: label(client),
                        })),
                    },
                ]}
                onChange={(next) =>
                    onChange(next === NONE ? null : Number(next))
                }
                search={t('my_space.tasks.field.search_client')}
                empty={t('my_space.tasks.field.no_match')}
            />
        );
    }

    return (
        <Select
            value={
                value === undefined
                    ? ALL
                    : value === null
                      ? NONE
                      : String(value)
            }
            onValueChange={(next) =>
                next !== ALL && onChange(next === NONE ? null : Number(next))
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
                <SelectItem value={ALL} disabled>
                    {t('my_space.tasks.field.client_any')}
                </SelectItem>
                {clients.map((client) => (
                    <SelectItem
                        key={client.id ?? NONE}
                        value={client.id === null ? NONE : String(client.id)}
                    >
                        {label(client)}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** Proyecto de la tarea (obligatorio en Audax), por cliente. */
export function ProjectPicker({
    projects,
    value,
    onChange,
    invalid,
    ...props
}: FieldProps & {
    projects: MySpaceTaskProject[];
    value: number | null;
    onChange: (projectId: number | null) => void;
}) {
    const groups = new Map<string, MySpaceTaskProject[]>();

    for (const project of projects) {
        const name = project.client?.name ?? t('my_space.tasks.general');
        groups.set(name, [...(groups.get(name) ?? []), project]);
    }

    if (projects.length >= SEARCHABLE_FROM) {
        return (
            <SearchablePicker
                {...props}
                invalid={invalid}
                value={value === null ? null : String(value)}
                placeholder={t('my_space.tasks.field.project_placeholder')}
                groups={[...groups.entries()].map(([client, options]) => ({
                    label: client,
                    options: options.map((project) => ({
                        value: String(project.id),
                        label: `${project.code} · ${project.name}`,
                    })),
                }))}
                onChange={(next) => onChange(Number(next))}
                search={t('my_space.tasks.field.search_project')}
                empty={t('my_space.tasks.field.no_match')}
                dataTest="my-space-task-project"
            />
        );
    }

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
                aria-invalid={invalid ? true : undefined}
                className={cn('w-full', props.className)}
                data-test="my-space-task-project"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NONE} disabled>
                    {projects.length === 0
                        ? t('my_space.tasks.field.no_projects')
                        : t('my_space.tasks.field.project_placeholder')}
                </SelectItem>
                {[...groups.entries()].map(([client, options]) => (
                    <SelectGroup key={client}>
                        <SelectLabel>{client}</SelectLabel>
                        {options.map((project) => (
                            <SelectItem
                                key={project.id}
                                value={String(project.id)}
                            >
                                {`${project.code} · ${project.name}`}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                ))}
            </SelectContent>
        </Select>
    );
}

/** Bolsa de un proyecto de bolsas (obligatoria, SPEC §8.3). */
export function BankPicker({
    project,
    value,
    onChange,
    invalid,
    ...props
}: FieldProps & {
    project: MySpaceTaskProject;
    value: number | null;
    onChange: (bankId: number | null) => void;
}) {
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
                aria-invalid={invalid ? true : undefined}
                className={cn('w-full', props.className)}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NONE} disabled>
                    {project.banks.length === 0
                        ? t('my_space.tasks.field.no_banks')
                        : t('my_space.tasks.field.bank_placeholder')}
                </SelectItem>
                {project.banks.map((bank) => (
                    <SelectItem key={bank.id} value={String(bank.id)}>
                        {bank.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
