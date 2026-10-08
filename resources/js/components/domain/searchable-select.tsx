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
import { cn } from '@/lib/utils';

export type SearchableOption = {
    value: string;
    label: string;
    /** Texto secundario, en gris, a la derecha (p. ej., el cliente de un proyecto). */
    hint?: string | null;
    /** Más palabras por las que se encuentra (NIF, cliente…), sin enseñarlas. */
    keywords?: string | null;
};

export type SearchableGroup = {
    label: string | null;
    options: SearchableOption[];
};

export type SearchableSelectProps = {
    id?: string;
    value: string | null;
    placeholder: string;
    groups: SearchableGroup[];
    onChange: (value: string) => void;
    /** Texto del campo de búsqueda («Busca un cliente»). */
    search: string;
    /** Lo que se ve cuando la búsqueda no encuentra nada. */
    empty: string;
    invalid?: boolean;
    disabled?: boolean;
    className?: string;
    dataTest?: string;
    'aria-label'?: string;
};

/**
 * Selector con buscador (10.9b, facturación D-245): un desplegable con un campo para filtrar por
 * nombre, código o cualquier palabra de `keywords`, para las listas largas (clientes, proyectos).
 */
export function SearchableSelect({
    value,
    placeholder,
    groups,
    onChange,
    search,
    empty,
    invalid,
    dataTest,
    ...props
}: SearchableSelectProps) {
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
                    className={cn('w-full justify-between', props.className)}
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
                                        value={[
                                            option.label,
                                            option.hint,
                                            option.keywords,
                                            option.value,
                                        ]
                                            .filter(Boolean)
                                            .join(' ')}
                                        onSelect={() => {
                                            setOpen(false);
                                            onChange(option.value);
                                        }}
                                    >
                                        <span className="truncate">
                                            {option.label}
                                        </span>
                                        {option.hint ? (
                                            <span className="ml-auto truncate pl-2 text-xs text-muted-foreground">
                                                {option.hint}
                                            </span>
                                        ) : null}
                                        {option.value === value ? (
                                            <Check
                                                aria-hidden="true"
                                                className={cn(
                                                    !option.hint && 'ml-auto',
                                                )}
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
