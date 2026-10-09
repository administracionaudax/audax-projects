import { Link } from '@inertiajs/react';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';

export type ViewTab = {
    id: string;
    label: string;
    href: string;
    /** Elementos de la vista; null = sin contador. */
    count: number | null;
};

/**
 * Vistas de un listado de Facturación (D-406): pestañas con su número, con el mismo aspecto que las
 * pestañas del proyecto (subrayado en `--primary` la actual). Son enlaces: la vista va en la URL.
 * En el móvil se desplazan en horizontal dentro de su banda, sin mover la página.
 */
export function ViewTabs({
    label,
    tabs,
    current,
    dataTest,
}: {
    label: string;
    tabs: ReadonlyArray<ViewTab>;
    current: string;
    dataTest?: string;
}) {
    return (
        <nav
            aria-label={label}
            className="-mx-4 overflow-x-auto border-b px-4 md:mx-0 md:px-0"
            data-test={dataTest}
        >
            <ul className="flex min-w-max gap-1">
                {tabs.map((tab) => {
                    const active = tab.id === current;

                    return (
                        <li key={tab.id}>
                            <Link
                                href={tab.href}
                                preserveScroll
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    '-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm whitespace-nowrap',
                                    active
                                        ? 'border-primary text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                    FOCUS_RING,
                                )}
                                data-test={`view-${tab.id}`}
                            >
                                {tab.label}
                                {tab.count !== null ? (
                                    <span
                                        className={cn(
                                            'tabular rounded-md px-1.5 text-xs',
                                            active
                                                ? 'bg-info-soft text-foreground'
                                                : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {formatNumber(tab.count, 0)}
                                    </span>
                                ) : null}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
