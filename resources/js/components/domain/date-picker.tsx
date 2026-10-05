import { CalendarDays } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type DatePickerProps = {
    /** Fecha local "YYYY-MM-DD" o null. */
    value: string | null;
    onChange: (value: string | null) => void;
    id?: string;
    placeholder?: string;
    disabled?: boolean;
    invalid?: boolean;
    clearable?: boolean;
    /** Fechas posteriores no seleccionables (p. ej. hoy, si no se admiten fechas futuras). */
    max?: string;
    /** Fechas anteriores no seleccionables (p. ej. el plazo de una weekly, desde su lunes). */
    min?: string;
    className?: string;
    'aria-label'?: string;
};

function toDate(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function toValue(date: Date): string {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

/**
 * Selector de fecha (semana desde el lunes, en español). Trabaja con fechas locales
 * "YYYY-MM-DD": nunca con instantes UTC.
 */
export function DatePicker({
    value,
    onChange,
    id,
    placeholder,
    disabled,
    invalid,
    clearable = true,
    max,
    min,
    className,
    ...aria
}: DatePickerProps) {
    const [open, setOpen] = useState(false);
    const selected = value ? toDate(value) : undefined;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    disabled={disabled}
                    aria-invalid={invalid || undefined}
                    aria-label={aria['aria-label']}
                    className={cn(
                        'w-full justify-start font-normal',
                        !value && 'text-muted-foreground',
                        className,
                    )}
                >
                    <CalendarDays aria-hidden="true" />
                    {value
                        ? formatDate(value)
                        : (placeholder ?? t('date_picker.placeholder'))}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-0" align="start">
                <Calendar
                    mode="single"
                    selected={selected}
                    defaultMonth={selected}
                    disabled={[
                        ...(min ? [{ before: toDate(min) }] : []),
                        ...(max ? [{ after: toDate(max) }] : []),
                    ]}
                    onSelect={(date) => {
                        onChange(date ? toValue(date) : null);
                        setOpen(false);
                    }}
                />
                {clearable && value ? (
                    <div className="border-t p-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="w-full"
                            onClick={() => {
                                onChange(null);
                                setOpen(false);
                            }}
                        >
                            {t('date_picker.clear')}
                        </Button>
                    </div>
                ) : null}
            </PopoverContent>
        </Popover>
    );
}
