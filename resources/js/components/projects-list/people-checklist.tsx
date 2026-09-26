import { useId, useState } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { t } from '@/lib/i18n';
import type { ProjectPersonOption } from '@/types';

function normalize(text: string): string {
    return text
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

/**
 * Lista de personas con casillas y un buscador (miembros iniciales de un proyecto). Las
 * deshabilitadas (p. ej. el gestor principal, que ya es miembro) se muestran marcadas.
 */
export function PeopleChecklist({
    people,
    selected,
    onChange,
    locked = [],
    label,
}: {
    people: ProjectPersonOption[];
    selected: number[];
    onChange: (ids: number[]) => void;
    /** Personas que siempre están (y no se pueden quitar aquí). */
    locked?: number[];
    label: string;
}) {
    const id = useId();
    const [query, setQuery] = useState('');
    const needle = normalize(query.trim());
    const visible = people.filter(
        (person) =>
            needle === '' ||
            normalize(person.name).includes(needle) ||
            normalize(person.department ?? '').includes(needle),
    );

    const toggle = (personId: number, checked: boolean) =>
        onChange(
            checked
                ? [...selected, personId]
                : selected.filter((current) => current !== personId),
        );

    return (
        <fieldset className="grid gap-2">
            <legend className="mb-2 text-sm font-medium">{label}</legend>
            <Input
                type="search"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder={t('projects.people.search_placeholder')}
                aria-label={t('projects.people.search')}
                autoComplete="off"
                className="sm:w-72"
            />
            <p className="text-sm text-muted-foreground" aria-live="polite">
                {t('projects.people.selected', {
                    count: selected.filter((person) => !locked.includes(person))
                        .length,
                })}
            </p>
            {visible.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('projects.people.no_results')}
                </p>
            ) : (
                <ul className="grid max-h-64 gap-1 overflow-y-auto rounded-md border p-2 sm:grid-cols-2">
                    {visible.map((person) => {
                        const isLocked = locked.includes(person.id);
                        const checked =
                            isLocked || selected.includes(person.id);
                        const inputId = `${id}-${person.id}`;

                        return (
                            <li
                                key={person.id}
                                className="flex items-center gap-2 rounded-[3px] px-1 py-1"
                            >
                                <Checkbox
                                    id={inputId}
                                    checked={checked}
                                    disabled={isLocked}
                                    onCheckedChange={(value) =>
                                        toggle(person.id, value === true)
                                    }
                                />
                                <label
                                    htmlFor={inputId}
                                    className="min-w-0 text-sm"
                                >
                                    <span className="block truncate">
                                        {person.name}
                                    </span>
                                    {person.department ? (
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {person.department}
                                        </span>
                                    ) : null}
                                </label>
                            </li>
                        );
                    })}
                </ul>
            )}
        </fieldset>
    );
}
