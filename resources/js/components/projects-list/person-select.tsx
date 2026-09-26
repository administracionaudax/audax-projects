import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ProjectPersonOption } from '@/types';

const NONE = '__none__';

/**
 * Selector de una persona interna activa (gestor principal, nuevo miembro…), con su
 * departamento al lado del nombre.
 */
export function PersonSelect({
    id,
    label,
    people,
    value,
    onChange,
    error,
    placeholder,
    help,
    className,
}: {
    id: string;
    label: string;
    people: ProjectPersonOption[];
    value: number | null;
    onChange: (value: number | null) => void;
    error?: string;
    /** Texto de la opción vacía; sin él, no se puede dejar vacío. */
    placeholder?: string;
    help?: string;
    className?: string;
}) {
    return (
        <div className={cn('grid content-start gap-2', className)}>
            <Label htmlFor={id}>{label}</Label>
            <Select
                value={value === null ? NONE : String(value)}
                onValueChange={(next) =>
                    onChange(next === NONE ? null : Number(next))
                }
            >
                <SelectTrigger
                    id={id}
                    className="w-full"
                    aria-invalid={error ? true : undefined}
                    aria-describedby={help ? `${id}-help` : undefined}
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {placeholder !== undefined ? (
                        <SelectItem value={NONE}>{placeholder}</SelectItem>
                    ) : null}
                    {people.map((person) => (
                        <SelectItem key={person.id} value={String(person.id)}>
                            {person.department
                                ? t('projects.people.with_department', {
                                      name: person.name,
                                      department: person.department,
                                  })
                                : person.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {help ? (
                <p id={`${id}-help`} className="text-sm text-muted-foreground">
                    {help}
                </p>
            ) : null}
            <InputError message={error} />
        </div>
    );
}
