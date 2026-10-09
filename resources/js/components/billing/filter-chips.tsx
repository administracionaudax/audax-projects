import { Check, ChevronsUpDown, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { DatePicker } from '@/components/domain/date-picker';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type ChipOption = { value: string; label: string };

/**
 * Disparador de un filtro en la línea de chips (D-406): «Etiqueta: valor» dentro de un campo, como
 * los filtros de los informes (MultiSelectFilter). Con un valor elegido lleva su × para quitarlo.
 */
function ChipTrigger({
    label,
    summary,
    active,
    open,
    onClear,
    clearLabel,
    children,
    dataTest,
}: {
    label: string;
    summary: string;
    active: boolean;
    open: boolean;
    onClear?: () => void;
    clearLabel: string;
    children: ReactNode;
    dataTest?: string;
}) {
    return (
        <div className="inline-flex max-w-full min-w-0">
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="field"
                    size="sm"
                    role="combobox"
                    aria-expanded={open}
                    aria-label={`${label}: ${summary}`}
                    className={cn(
                        'max-w-72 min-w-0 justify-between gap-2',
                        active && 'border-primary',
                        active && onClear && 'border-r-0',
                    )}
                    data-test={dataTest}
                >
                    <span className="truncate">
                        <span className="text-muted-foreground">{label}:</span>{' '}
                        {summary}
                    </span>
                    <ChevronsUpDown aria-hidden="true" className="opacity-50" />
                </Button>
            </PopoverTrigger>
            {active && onClear ? (
                <Button
                    type="button"
                    variant="field"
                    size="sm"
                    className="border-l-0 border-primary px-2 has-[>svg]:px-2"
                    aria-label={clearLabel}
                    onClick={onClear}
                >
                    <X aria-hidden="true" />
                </Button>
            ) : null}
            {children}
        </div>
    );
}

/**
 * Un filtro de una o varias opciones con buscador (cliente, servicio). `value` vacío = sin filtrar.
 */
export function ChipSelect({
    label,
    options,
    value,
    onChange,
    multiple = false,
    allLabel,
    searchPlaceholder,
    dataTest,
}: {
    label: string;
    options: ReadonlyArray<ChipOption>;
    value: string[];
    onChange: (value: string[]) => void;
    multiple?: boolean;
    allLabel: string;
    searchPlaceholder: string;
    dataTest?: string;
}) {
    const [open, setOpen] = useState(false);
    const selected = options.filter((option) => value.includes(option.value));
    const summary =
        selected.length === 0
            ? allLabel
            : selected.length === 1
              ? selected[0].label
              : t('billing.filters.selected', { count: selected.length });

    const choose = (option: string) => {
        if (!multiple) {
            onChange(value.includes(option) ? [] : [option]);
            setOpen(false);

            return;
        }

        onChange(
            value.includes(option)
                ? value.filter((item) => item !== option)
                : [...value, option],
        );
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <ChipTrigger
                label={label}
                summary={summary}
                active={selected.length > 0}
                open={open}
                onClear={() => onChange([])}
                clearLabel={t('billing.filters.remove', { filter: label })}
                dataTest={dataTest}
            >
                <PopoverContent className="w-72 p-0" align="start">
                    <Command>
                        <CommandInput placeholder={searchPlaceholder} />
                        <CommandList>
                            <CommandEmpty>
                                {t('billing.filters.none_found')}
                            </CommandEmpty>
                            <CommandGroup>
                                {options.map((option) => {
                                    const checked = value.includes(
                                        option.value,
                                    );

                                    return (
                                        <CommandItem
                                            key={option.value}
                                            value={`${option.label} ${option.value}`}
                                            onSelect={() =>
                                                choose(option.value)
                                            }
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
                                            <span className="truncate">
                                                {option.label}
                                            </span>
                                        </CommandItem>
                                    );
                                })}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </ChipTrigger>
        </Popover>
    );
}

export type PeriodKey =
    | 'anio'
    | 'anio-anterior'
    | 'trimestre'
    | 'mes'
    | '12-meses'
    | 'todo'
    | 'rango';

const PRESETS: Exclude<PeriodKey, 'rango'>[] = [
    'anio',
    'anio-anterior',
    'trimestre',
    'mes',
    '12-meses',
    'todo',
];

/** Nombre de un periodo («Este año», «2025», «Del 01/02/2026 al 28/02/2026»). */
export function periodLabel(
    key: string,
    today: string,
    from: string | null,
    to: string | null,
): string {
    if (key === 'anio-anterior') {
        return String(Number(today.slice(0, 4)) - 1);
    }

    if (key === 'rango') {
        if (from && to) {
            return t('billing.period.between', {
                from: formatDate(from),
                to: formatDate(to),
            });
        }

        return from
            ? t('billing.period.since', { from: formatDate(from) })
            : to
              ? t('billing.period.until', { to: formatDate(to) })
              : t('billing.period.rango');
    }

    return t(
        `billing.period.${key as Exclude<PeriodKey, 'rango' | 'anio-anterior'>}`,
    );
}

/**
 * El periodo del listado (D-406): «Periodo: Este año» con los atajos habituales y un rango de
 * fechas. `explicit` dice si está en la URL o es el de la vista (entonces no lleva ×).
 */
export function PeriodChip({
    period,
    explicit,
    today,
    onChange,
    onClear,
}: {
    period: { key: string; from: string | null; to: string | null };
    explicit: boolean;
    today: string;
    onChange: (patch: {
        periodo: PeriodKey | null;
        desde?: string | null;
        hasta?: string | null;
    }) => void;
    onClear: () => void;
}) {
    const [open, setOpen] = useState(false);
    const label = t('billing.filters.period');
    const summary = periodLabel(period.key, today, period.from, period.to);
    const range = period.key === 'rango';

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <ChipTrigger
                label={label}
                summary={summary}
                active={explicit}
                open={open}
                onClear={onClear}
                clearLabel={t('billing.filters.period_reset')}
                dataTest="invoice-period"
            >
                <PopoverContent className="w-72 p-2" align="start">
                    <ul className="grid gap-0.5" aria-label={label}>
                        {PRESETS.map((key) => {
                            const current = period.key === key;

                            return (
                                <li key={key}>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="w-full justify-start font-normal"
                                        aria-pressed={current}
                                        onClick={() => {
                                            onChange({
                                                periodo: key,
                                                desde: null,
                                                hasta: null,
                                            });
                                            setOpen(false);
                                        }}
                                    >
                                        <Check
                                            aria-hidden="true"
                                            className={cn(
                                                current
                                                    ? 'opacity-100'
                                                    : 'opacity-0',
                                            )}
                                        />
                                        {periodLabel(key, today, null, null)}
                                    </Button>
                                </li>
                            );
                        })}
                    </ul>
                    <div className="mt-2 grid gap-2 border-t pt-2">
                        <p className="text-xs text-muted-foreground">
                            {t('billing.period.rango')}
                        </p>
                        <div className="grid grid-cols-2 gap-2">
                            <div className="grid content-start gap-1">
                                <Label
                                    htmlFor="invoice-period-from"
                                    className="text-xs text-muted-foreground"
                                >
                                    {t('billing.filters.from')}
                                </Label>
                                <DatePicker
                                    id="invoice-period-from"
                                    value={range ? period.from : null}
                                    onChange={(value) =>
                                        onChange({
                                            periodo: 'rango',
                                            desde: value,
                                            hasta: range ? period.to : null,
                                        })
                                    }
                                    className="h-8 px-2"
                                />
                            </div>
                            <div className="grid content-start gap-1">
                                <Label
                                    htmlFor="invoice-period-to"
                                    className="text-xs text-muted-foreground"
                                >
                                    {t('billing.filters.to')}
                                </Label>
                                <DatePicker
                                    id="invoice-period-to"
                                    value={range ? period.to : null}
                                    onChange={(value) =>
                                        onChange({
                                            periodo: 'rango',
                                            desde: range ? period.from : null,
                                            hasta: value,
                                        })
                                    }
                                    className="h-8 px-2"
                                />
                            </div>
                        </div>
                    </div>
                </PopoverContent>
            </ChipTrigger>
        </Popover>
    );
}

/** Un filtro que solo se puede quitar (los parámetros de antes: estado, documento, enlace). */
export function RemovableChip({
    label,
    value,
    onRemove,
}: {
    label: string;
    value: string;
    onRemove: () => void;
}) {
    return (
        <Button
            type="button"
            variant="field"
            size="sm"
            className="border-primary"
            onClick={onRemove}
            aria-label={t('billing.filters.remove', {
                filter: `${label}: ${value}`,
            })}
        >
            <span className="truncate">
                <span className="text-muted-foreground">{label}:</span> {value}
            </span>
            <X aria-hidden="true" />
        </Button>
    );
}
