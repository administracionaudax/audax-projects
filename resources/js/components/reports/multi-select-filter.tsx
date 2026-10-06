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
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type FilterOption = { id: number; name: string; muted?: boolean };

/**
 * Selector múltiple accesible (combobox con búsqueda) para la barra de filtros de los informes. Lo
 * reutilizan los destinatarios de los envíos por correo (D-141).
 */
export function MultiSelectFilter({
    label,
    options,
    value,
    onChange,
    disabled,
    emptyLabel,
    size = 'default',
    labelInside = true,
    className,
}: {
    label: string;
    options: FilterOption[];
    value: number[];
    onChange: (ids: number[]) => void;
    disabled?: boolean;
    /** Resumen sin nada elegido (por defecto, «Todos», como en los filtros). */
    emptyLabel?: string;
    /** `sm` (h-8) en las barras de filtros compactas (Mis tareas, calendario), como sus vecinos. */
    size?: 'sm' | 'default';
    /**
     * «Etiqueta: resumen» dentro de la caja (filtros). En un formulario con la etiqueta encima, false:
     * solo el resumen (antes salía «Personas de Audax» dos veces). D-310.
     */
    labelInside?: boolean;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const selected = options.filter((option) => value.includes(option.id));
    const summary =
        selected.length === 0
            ? (emptyLabel ?? t('reports.filters.all'))
            : selected.length === 1
              ? selected[0].name
              : t('reports.filters.selected', { count: selected.length });

    const toggle = (id: number) =>
        onChange(
            value.includes(id)
                ? value.filter((item) => item !== id)
                : [...value, id].sort((a, b) => a - b),
        );

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="field"
                    size={size}
                    role="combobox"
                    aria-expanded={open}
                    aria-label={`${label}: ${summary}`}
                    disabled={disabled}
                    className={cn('min-w-0 justify-between gap-2', className)}
                >
                    <span className="truncate">
                        {labelInside ? (
                            <>
                                <span className="text-muted-foreground">
                                    {label}:
                                </span>{' '}
                            </>
                        ) : null}
                        {summary}
                    </span>
                    <ChevronsUpDown aria-hidden="true" className="opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-72 p-0" align="start">
                <Command>
                    <CommandInput placeholder={t('reports.filters.search')} />
                    <CommandList>
                        <CommandEmpty>
                            {t('reports.filters.none_found')}
                        </CommandEmpty>
                        <CommandGroup>
                            {options.map((option) => {
                                const checked = value.includes(option.id);

                                return (
                                    <CommandItem
                                        key={option.id}
                                        value={`${option.name} ${option.id}`}
                                        onSelect={() => toggle(option.id)}
                                        aria-selected={checked}
                                    >
                                        <Check
                                            aria-hidden="true"
                                            className={cn(
                                                'size-4',
                                                checked
                                                    ? 'opacity-100'
                                                    : 'opacity-0',
                                            )}
                                        />
                                        <span
                                            className={cn(
                                                'truncate',
                                                option.muted &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {option.name}
                                        </span>
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
