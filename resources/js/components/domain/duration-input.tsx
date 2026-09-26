import { useId, useState } from 'react';
import { Input } from '@/components/ui/input';
import { parseDuration } from '@/lib/duration';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type DurationInputProps = {
    /** Minutos actuales (null = vacío). */
    value: number | null;
    onChange: (minutes: number | null) => void;
    id?: string;
    name?: string;
    placeholder?: string;
    disabled?: boolean;
    invalid?: boolean;
    className?: string;
    autoFocus?: boolean;
    /** Máximo en minutos (por defecto 24 h; estimaciones y bolsas usan más). */
    max?: number;
    'aria-label'?: string;
    'aria-describedby'?: string;
};

/**
 * Campo de duración (SPEC §7, D-036): acepta 1:30, 1.5, 1,5, 90m, 1h30, 2h o 2 y muestra en
 * vivo lo que ha entendido («= 1:30»). Al salir del campo lo normaliza a h:mm.
 */
export function DurationInput({
    value,
    onChange,
    id,
    name,
    placeholder,
    disabled,
    invalid,
    className,
    autoFocus,
    max,
    ...aria
}: DurationInputProps) {
    const previewId = useId();
    const [text, setText] = useState(
        value === null ? '' : formatMinutes(value),
    );
    const [lastValue, setLastValue] = useState(value);

    // Sincroniza si el valor cambia desde fuera (sin efecto: patrón de estado derivado de React).
    if (value !== lastValue) {
        setLastValue(value);
        if (value !== parseDuration(text, max)) {
            setText(value === null ? '' : formatMinutes(value));
        }
    }

    const parsed = parseDuration(text, max);
    const hasText = text.trim() !== '';
    const isInvalid = invalid || (hasText && parsed === null);

    return (
        <div className={cn('grid gap-1', className)}>
            <Input
                id={id}
                name={name}
                inputMode="decimal"
                autoComplete="off"
                autoFocus={autoFocus}
                disabled={disabled}
                placeholder={placeholder ?? t('duration.placeholder')}
                value={text}
                aria-invalid={isInvalid || undefined}
                aria-label={aria['aria-label']}
                aria-describedby={[aria['aria-describedby'], previewId]
                    .filter(Boolean)
                    .join(' ')}
                onChange={(event) => {
                    setText(event.target.value);
                    const minutes = parseDuration(event.target.value, max);
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
                {hasText
                    ? parsed === null
                        ? t('duration.invalid')
                        : t('duration.preview', {
                              duration: formatMinutes(parsed),
                          })
                    : ''}
            </p>
        </div>
    );
}
