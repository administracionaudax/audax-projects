import { useId, useState } from 'react';
import { Input } from '@/components/ui/input';
import { parseDuration } from '@/lib/duration';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Máximo por día: 24 h. */
export const DAY_MAX_MINUTES = 24 * 60;

const ZERO = /^0+(?:[.,:]0+)?(?:h|m|min)?$/u;

/**
 * Minutos de jornada de un día: como el campo de duración (1:30, 7,5, 8h, 90m…) pero admite 0
 * (vacío, «0» o «0:00»), porque un día sin jornada es normal (sábado y domingo). null = no válido.
 */
export function parseDayMinutes(input: string): number | null {
    const text = input.trim().toLowerCase().replace(/\s+/gu, '');

    if (text === '' || ZERO.test(text)) {
        return 0;
    }

    return parseDuration(text, DAY_MAX_MINUTES);
}

/**
 * Campo de la jornada de un día con vista previa en vivo («= 7:30»). Al salir lo normaliza a h:mm.
 */
export function DayMinutesInput({
    id,
    value,
    onChange,
    invalid,
    disabled,
    'aria-describedby': describedBy,
}: {
    id: string;
    /** Minutos (null = el texto escrito no es válido). */
    value: number | null;
    onChange: (minutes: number | null) => void;
    invalid?: boolean;
    disabled?: boolean;
    'aria-describedby'?: string;
}) {
    const previewId = useId();
    const [text, setText] = useState(
        value === null ? '' : formatMinutes(value),
    );
    const [lastValue, setLastValue] = useState(value);

    // Sincroniza si el valor cambia desde fuera (patrón de estado derivado, sin efecto).
    if (value !== lastValue) {
        setLastValue(value);
        if (value !== parseDayMinutes(text)) {
            setText(value === null ? '' : formatMinutes(value));
        }
    }

    const parsed = parseDayMinutes(text);
    const isInvalid = invalid || parsed === null;
    // La vista previa solo aparece si lo escrito no está ya en h:mm («7,5» → «= 7:30»).
    const preview =
        parsed === null
            ? t('admin.week.day_invalid')
            : text.trim() !== formatMinutes(parsed)
              ? t('admin.week.preview', { duration: formatMinutes(parsed) })
              : '';

    return (
        <div className="grid gap-1">
            <Input
                id={id}
                inputMode="decimal"
                autoComplete="off"
                disabled={disabled}
                value={text}
                placeholder="0:00"
                className={cn('tabular')}
                aria-invalid={isInvalid || undefined}
                aria-describedby={[describedBy, previewId]
                    .filter(Boolean)
                    .join(' ')}
                onChange={(event) => {
                    setText(event.target.value);
                    const minutes = parseDayMinutes(event.target.value);
                    setLastValue(minutes);
                    onChange(minutes);
                }}
                onBlur={() => {
                    if (parsed !== null) {
                        setText(formatMinutes(parsed));
                    }
                }}
            />
            <p
                id={previewId}
                aria-live="polite"
                className="min-h-4 text-xs text-muted-foreground"
            >
                {preview}
            </p>
        </div>
    );
}
