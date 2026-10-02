import { Link } from '@inertiajs/react';
import { Search, SearchX } from 'lucide-react';
import { useId, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type LinkItem = {
    id: number;
    label: string;
    /** Texto secundario (cliente, departamento…). */
    meta?: string | null;
    color?: string | null;
    /** Desactivado, de baja o archivado: se ve atenuado y con su motivo. */
    muted?: string | null;
    href: string;
};

/** Con más elementos que estos, la lista ofrece un buscador. */
export const SEARCH_FROM = 8;

/** Minúsculas y sin tildes, para buscar «diseño» con «diseno». */
export function normalize(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

/**
 * Lista de enlaces a informes (departamentos, clientes, proyectos o personas) con buscador cuando
 * es larga. Se desplaza dentro de su contenedor.
 */
export function R1LinkList({
    label,
    items,
    emptyLabel,
}: {
    /** Nombre accesible de la lista y del buscador («Clientes»). */
    label: string;
    items: LinkItem[];
    emptyLabel: string;
}) {
    const id = useId();
    const [query, setQuery] = useState('');
    const needle = normalize(query.trim());
    const visible =
        needle === ''
            ? items
            : items.filter((item) =>
                  normalize(`${item.label} ${item.meta ?? ''}`).includes(
                      needle,
                  ),
              );

    if (items.length === 0) {
        return <EmptyState title={emptyLabel} />;
    }

    return (
        <div className="grid gap-3">
            {items.length > SEARCH_FROM ? (
                <div className="grid gap-1 sm:max-w-xs">
                    <Label htmlFor={`${id}-search`} className="sr-only">
                        {t('reports_r1.index.search', { list: label })}
                    </Label>
                    <div className="relative">
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            id={`${id}-search`}
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('reports_r1.index.search', {
                                list: label.toLowerCase(),
                            })}
                            className="pl-8"
                        />
                    </div>
                </div>
            ) : null}

            {visible.length === 0 ? (
                <EmptyState
                    icon={SearchX}
                    title={t('reports_r1.index.no_results', { query })}
                />
            ) : (
                <ul
                    aria-label={label}
                    className="grid max-h-96 gap-1 overflow-y-auto sm:grid-cols-2 lg:grid-cols-3"
                >
                    {visible.map((item) => (
                        <li key={item.id} className="min-w-0">
                            <Link
                                href={item.href}
                                className={cn(
                                    'flex min-w-0 items-center gap-2 rounded-md border bg-card px-3 py-2 text-sm hover:bg-muted',
                                    FOCUS_RING,
                                )}
                            >
                                {item.color ? (
                                    <span
                                        aria-hidden="true"
                                        className="size-2 shrink-0 rounded-full"
                                        style={{ backgroundColor: item.color }}
                                    />
                                ) : null}
                                <span className="min-w-0 flex-1">
                                    <span
                                        className={cn(
                                            'block truncate',
                                            item.muted &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        {item.label}
                                    </span>
                                    {item.meta || item.muted ? (
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {[item.meta, item.muted]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    ) : null}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
