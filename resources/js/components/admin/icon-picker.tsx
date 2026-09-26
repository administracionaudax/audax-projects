import { useId } from 'react';
import { TaskTypeIcon } from '@/components/admin/task-type-icon';
import InputError from '@/components/input-error';
import { hasTranslation, t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export function iconLabel(icon: string): string {
    const key = `admin.icons.${icon}`;

    return hasTranslation(key) ? t(key) : icon;
}

/**
 * Selector de icono de la lista cerrada: botones de radio (flechas para moverse), cada uno con su
 * nombre accesible y un recuadro visible en el elegido. «Sin icono» es la primera opción.
 */
export function IconPicker({
    value,
    onChange,
    icons,
    legend,
    color,
    error,
}: {
    value: string | null;
    onChange: (icon: string | null) => void;
    icons: string[];
    legend: string;
    color?: string;
    error?: string;
}) {
    const id = useId();
    const options: (string | null)[] = [null, ...icons];

    return (
        <fieldset
            className="grid gap-2"
            aria-describedby={error ? `${id}-error` : undefined}
        >
            <legend className="mb-2 text-sm font-medium">{legend}</legend>
            <div className="grid grid-cols-6 gap-1.5 sm:grid-cols-10">
                {options.map((icon) => {
                    const checked = icon === value;
                    const label = icon
                        ? iconLabel(icon)
                        : t('admin.icons.none');

                    return (
                        <label
                            key={icon ?? 'none'}
                            className="relative cursor-pointer"
                            title={label}
                        >
                            <input
                                type="radio"
                                name={`${id}-icon`}
                                checked={checked}
                                onChange={() => onChange(icon)}
                                className="peer sr-only"
                                aria-label={label}
                            />
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'flex size-9 items-center justify-center rounded-md border peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-background',
                                    checked
                                        ? 'border-foreground bg-muted'
                                        : 'border-border hover:bg-muted',
                                )}
                            >
                                {icon ? (
                                    <TaskTypeIcon name={icon} color={color} />
                                ) : (
                                    <span className="text-xs text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </span>
                        </label>
                    );
                })}
            </div>
            <p className="text-sm text-muted-foreground">
                {t('admin.icons.selected', {
                    icon: value ? iconLabel(value) : t('admin.icons.none'),
                })}
            </p>
            <InputError id={`${id}-error`} message={error} />
        </fieldset>
    );
}
