import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';

/** Interruptor con su etiqueta visible, su ayuda y su error (ajustes del portal). */
export function SwitchField({
    id,
    label,
    help,
    checked,
    onChange,
    disabled = false,
    error,
    className,
}: {
    id: string;
    label: string;
    help: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    disabled?: boolean;
    error?: string;
    className?: string;
}) {
    return (
        <div className={cn('flex items-start gap-3', className)}>
            <Switch
                id={id}
                checked={checked}
                onCheckedChange={onChange}
                disabled={disabled}
                aria-describedby={`${id}-help`}
                aria-invalid={error ? true : undefined}
                className="mt-0.5"
            />
            <div className="grid gap-1">
                <Label
                    htmlFor={id}
                    className={cn(disabled && 'text-muted-foreground')}
                >
                    {label}
                </Label>
                <p id={`${id}-help`} className="text-sm text-muted-foreground">
                    {help}
                </p>
                <InputError message={error} />
            </div>
        </div>
    );
}
