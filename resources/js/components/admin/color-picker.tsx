import { Check } from 'lucide-react';
import { useId } from 'react';
import InputError from '@/components/input-error';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Nombre de cada color de la paleta de marca (App\Domain\Admin\Palette), para lectores de pantalla. */
const COLOR_NAMES: Record<string, TranslationKey> = {
    '#0171FF': 'admin.palette.blue',
    '#179FA5': 'admin.palette.teal',
    '#5E2DAD': 'admin.palette.violet',
    '#E65FB3': 'admin.palette.magenta',
    '#3C41AE': 'admin.palette.indigo',
    '#0892C4': 'admin.palette.sky',
    '#56667A': 'admin.palette.gray',
};

export function colorName(color: string): string {
    const key = COLOR_NAMES[color.toUpperCase()];

    return key ? t(key) : color;
}

/** Punto de color con su nombre accesible (listas y tablas). */
export function ColorDot({
    color,
    className,
}: {
    color: string;
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'inline-block size-3 shrink-0 rounded-full border border-border',
                className,
            )}
            style={{ backgroundColor: color }}
        />
    );
}

/**
 * Selector de color de la paleta de marca: grupo de botones de radio (flechas para moverse), cada
 * muestra con su nombre accesible y una marca visible en la elegida (no solo el color).
 */
export function ColorPicker({
    value,
    onChange,
    palette,
    legend,
    error,
    name = 'color',
}: {
    value: string;
    onChange: (color: string) => void;
    palette: string[];
    legend: string;
    error?: string;
    name?: string;
}) {
    const id = useId();
    const options = palette.includes(value.toUpperCase())
        ? palette
        : [...palette, value.toUpperCase()];

    return (
        <fieldset
            className="grid gap-2"
            aria-describedby={error ? `${id}-error` : undefined}
        >
            <legend className="mb-2 text-sm font-medium">{legend}</legend>
            <div className="flex flex-wrap gap-2">
                {options.map((color) => {
                    const checked = color === value.toUpperCase();

                    return (
                        <label key={color} className="relative cursor-pointer">
                            <input
                                type="radio"
                                name={`${id}-${name}`}
                                value={color}
                                checked={checked}
                                onChange={() => onChange(color)}
                                className="peer sr-only"
                                aria-label={colorName(color)}
                            />
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'flex size-8 items-center justify-center rounded-md border-2 peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-background',
                                    checked
                                        ? 'border-foreground'
                                        : 'border-transparent',
                                )}
                                style={{ backgroundColor: color }}
                            >
                                {checked ? (
                                    <Check className="size-4 text-white" />
                                ) : null}
                            </span>
                        </label>
                    );
                })}
            </div>
            <p className="text-sm text-muted-foreground">
                {t('admin.palette.selected', { color: colorName(value) })}
            </p>
            <InputError id={`${id}-error`} message={error} />
        </fieldset>
    );
}
