import type { ComponentProps } from 'react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';

/**
 * Campo de número entero con límites: se puede borrar y escribir con calma (el texto no se
 * corrige a cada tecla); solo los valores enteros dentro de [min, max] cambian el dato, y al salir
 * del campo el texto vuelve al último valor válido.
 */
export function IntegerInput({
    value,
    onChange,
    min,
    max,
    ...props
}: Omit<
    ComponentProps<'input'>,
    'value' | 'onChange' | 'type' | 'min' | 'max'
> & {
    value: number;
    onChange: (value: number) => void;
    min: number;
    max: number;
}) {
    const [text, setText] = useState(String(value));
    const [lastValue, setLastValue] = useState(value);

    // Sincroniza si el valor cambia desde fuera (estado derivado, sin efecto).
    if (value !== lastValue) {
        setLastValue(value);
        if (Number(text) !== value) {
            setText(String(value));
        }
    }

    return (
        <Input
            {...props}
            type="number"
            inputMode="numeric"
            min={min}
            max={max}
            step={1}
            value={text}
            onChange={(event) => {
                setText(event.target.value);
                const parsed = Number(event.target.value);

                if (
                    event.target.value.trim() !== '' &&
                    Number.isInteger(parsed) &&
                    parsed >= min &&
                    parsed <= max
                ) {
                    setLastValue(parsed);
                    onChange(parsed);
                }
            }}
            onBlur={(event) => {
                setText(String(value));
                props.onBlur?.(event);
            }}
        />
    );
}
