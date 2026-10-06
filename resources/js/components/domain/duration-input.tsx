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
    /** El texto tal cual (p. ej. para distinguir «vacío» de «no válido», que dan null los dos). */
    onTextChange?: (text: string) => void;
    /**
     * Dónde va la vista previa («= 1:30»): `reserve` (por defecto) reserva su línea bajo la caja;
     * `overlay` la pone bajo la caja sin ocupar sitio, para filas de dos columnas, y se oculta si
     * el campo tiene un error del servidor (que ocupa ese sitio).
     */
    preview?: 'reserve' | 'overlay';
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
    onTextChange,
    preview = 'reserve',
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
        <div
            className={cn(
                'grid gap-1',
                preview === 'overlay' && 'relative',
                className,
            )}
        >
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
                    onTextChange?.(event.target.value);
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
                className={cn(
                    'text-xs text-muted-foreground',
                    preview === 'overlay'
                        ? 'absolute top-full left-0 mt-1'
                        : 'min-h-4',
                    preview === 'overlay' && invalid && 'sr-only',
                )}
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
