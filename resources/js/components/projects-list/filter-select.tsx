import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

/** Valor centinela de «todos» (Radix Select no admite un valor vacío). */
export const ALL = '__all__';

export type FilterOption = { value: string; label: string };

/**
 * Selector de un filtro de listado con etiqueta visible. `value` null = sin filtrar.
 */
export function FilterSelect({
    id,
    label,
    value,
    options,
    allLabel,
    onChange,
    className,
}: {
    id: string;
    label: string;
    value: string | null;
    options: ReadonlyArray<FilterOption>;
    /** Texto de la opción sin filtro («Todos los clientes»). Sin él, no hay opción vacía. */
    allLabel?: string;
    onChange: (value: string | null) => void;
    className?: string;
}) {
    return (
        <div className={cn('grid min-w-0 grid-cols-1 gap-1.5', className)}>
            <Label htmlFor={id} className="text-xs text-muted-foreground">
                {label}
            </Label>
            <Select
                value={value ?? ALL}
                onValueChange={(next) => onChange(next === ALL ? null : next)}
            >
                <SelectTrigger id={id} className="w-full min-w-0">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {allLabel !== undefined ? (
                        <SelectItem value={ALL}>{allLabel}</SelectItem>
                    ) : null}
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
