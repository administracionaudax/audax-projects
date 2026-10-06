import { Check, ChevronsUpDown, Link2 } from 'lucide-react';
import { createContext, useContext, useId, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type { EditorRow } from './template-editor-state';
import { parentOptions, reachableFrom } from './template-editor-state';

/**
 * Todas las filas del editor y sus números, para las listas de «Subtarea de» y «Depende de…».
 * Solo las leen esas listas, que se pintan al abrir su selector: así, escribir en una fila no
 * vuelve a pintar las demás (cada una recibe solo lo suyo).
 */
export const EditorRowsContext = createContext<{
    rows: EditorRow[];
    /** Número de cada fila, por referencia (rowLabels). */
    labels: ReadonlyMap<string, string>;
}>({ rows: [], labels: new Map() });

/** «2.1. Maquetación»: número y título de una fila, como se ve en el editor. */
export function rowName(row: Pick<EditorRow, 'title'>, label: string): string {
    return t('templates.editor.row_name', {
        number: label,
        title: row.title.trim() || t('templates.editor.untitled'),
    });
}

/**
 * «Subtarea de»: botón con la tarea de la que cuelga y, al abrirlo, la lista (con búsqueda) de las
 * tareas de primer nivel. La lista solo existe mientras está abierto: con 500 tareas, un <select>
 * por fila eran 250 000 opciones en la página.
 */
export function ParentPicker({
    rowRef,
    name,
    value,
    valueName,
    disabled,
    invalid,
    describedBy,
    onSelect,
}: {
    rowRef: string;
    /** Nombre de la fila («3. Publicación»). */
    name: string;
    /** Referencia de la tarea de la que cuelga, o null si es de primer nivel. */
    value: string | null;
    /** Nombre de esa tarea («1. Diseño»). */
    valueName: string | null;
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
    onSelect: (parentRef: string | null) => void;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="field"
                    role="combobox"
                    aria-expanded={open}
                    aria-label={t('templates.editor.parent_label', {
                        name,
                        value:
                            valueName ?? t('templates.editor.top_level_value'),
                    })}
                    aria-invalid={invalid || undefined}
                    aria-describedby={describedBy}
                    disabled={disabled}
                    className="w-full justify-between"
                >
                    <span className="truncate">
                        {valueName ?? t('templates.editor.top_level')}
                    </span>
                    <ChevronsUpDown
                        aria-hidden="true"
                        className="size-4 opacity-60"
                    />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-[min(20rem,calc(100vw-2rem))] p-0"
            >
                <ParentOptions
                    rowRef={rowRef}
                    value={value}
                    onSelect={(parentRef) => {
                        setOpen(false);

                        if (parentRef !== value) {
                            onSelect(parentRef);
                        }
                    }}
                />
            </PopoverContent>
        </Popover>
    );
}

function ParentOptions({
    rowRef,
    value,
    onSelect,
}: {
    rowRef: string;
    value: string | null;
    onSelect: (parentRef: string | null) => void;
}) {
    const { rows, labels } = useContext(EditorRowsContext);

    return (
        <Command>
            <CommandInput
                placeholder={t('templates.editor.parent_search')}
                aria-label={t('templates.editor.parent_search')}
            />
            <CommandList label={t('templates.editor.parent_options')}>
                <CommandEmpty>
                    {t('templates.editor.parent_empty')}
                </CommandEmpty>
                <CommandGroup>
                    {/* Los valores de cmdk llevan prefijo: una referencia no puede chocar con «top». */}
                    <CommandItem
                        value="top"
                        keywords={[t('templates.editor.top_level')]}
                        onSelect={() => onSelect(null)}
                    >
                        <span className="min-w-0 flex-1 truncate">
                            {t('templates.editor.top_level')}
                        </span>
                        {value === null ? (
                            <Check aria-hidden="true" className="size-4" />
                        ) : null}
                    </CommandItem>
                    {parentOptions(rows, rowRef).map((option) => {
                        const optionName = rowName(
                            option,
                            labels.get(option.ref) ?? '',
                        );

                        return (
                            <CommandItem
                                key={option.ref}
                                value={`ref:${option.ref}`}
                                keywords={[optionName]}
                                onSelect={() => onSelect(option.ref)}
                            >
                                <span className="min-w-0 flex-1 truncate">
                                    {optionName}
                                </span>
                                {value === option.ref ? (
                                    <Check
                                        aria-hidden="true"
                                        className="size-4"
                                    />
                                ) : null}
                            </CommandItem>
                        );
                    })}
                </CommandGroup>
            </CommandList>
        </Command>
    );
}

/**
 * «Depende de…»: botón con cuántas tiene y, al abrirlo, casillas con las demás tareas. Las que
 * crearían un ciclo aparecen desactivadas y lo dicen (nunca solo con color). Como «Subtarea de»,
 * la lista solo se pinta abierta.
 */
export function DependencyPicker({
    rowRef,
    name,
    count,
    invalid,
    describedBy,
    onToggle,
}: {
    rowRef: string;
    name: string;
    /** Tareas de las que depende. */
    count: number;
    invalid: boolean;
    describedBy?: string;
    onToggle: (predecessor: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const value =
        count === 0
            ? t('templates.editor.no_dependencies')
            : t('templates.editor.dependencies_count', { count });

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="field"
                    className="w-full justify-start"
                    aria-label={t('templates.editor.depends_on_label', {
                        name,
                        value,
                    })}
                    aria-invalid={invalid || undefined}
                    aria-describedby={describedBy}
                >
                    <Link2 aria-hidden="true" />
                    {value}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-80 p-0">
                <DependencyOptions
                    rowRef={rowRef}
                    name={name}
                    onToggle={onToggle}
                />
            </PopoverContent>
        </Popover>
    );
}

function DependencyOptions({
    rowRef,
    name,
    onToggle,
}: {
    rowRef: string;
    name: string;
    onToggle: (predecessor: string) => void;
}) {
    const id = useId();
    const { rows, labels } = useContext(EditorRowsContext);
    const selected = new Set(
        rows.find((row) => row.ref === rowRef)?.depends_on ?? [],
    );
    // Lo que ya depende (directa o indirectamente) de esta tarea: depender de ello sería un ciclo.
    const successors = reachableFrom(rows, rowRef);
    const others = rows.filter((other) => other.ref !== rowRef);

    return (
        <fieldset className="grid max-h-72 gap-1 overflow-y-auto p-3">
            <legend className="mb-2 text-sm font-medium">
                {t('templates.editor.depends_on_legend', { name })}
            </legend>
            {others.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('templates.editor.no_other_tasks')}
                </p>
            ) : (
                others.map((other, index) => {
                    const checked = selected.has(other.ref);
                    const cycle = !checked && successors.has(other.ref);
                    const optionId = `${id}-${index}`;

                    return (
                        <div
                            key={other.ref}
                            className="flex items-start gap-2 py-1"
                        >
                            <Checkbox
                                id={optionId}
                                checked={checked}
                                disabled={cycle}
                                onCheckedChange={() => onToggle(other.ref)}
                            />
                            <label
                                htmlFor={optionId}
                                className={cn(
                                    'text-sm leading-tight',
                                    cycle && 'text-muted-foreground',
                                )}
                            >
                                {rowName(other, labels.get(other.ref) ?? '')}
                                {cycle ? (
                                    <span className="block text-xs">
                                        {t('templates.editor.would_cycle')}
                                    </span>
                                ) : null}
                            </label>
                        </div>
                    );
                })
            )}
        </fieldset>
    );
}
