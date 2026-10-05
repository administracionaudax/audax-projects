import { Link } from '@inertiajs/react';
import { FOCUS_RING } from '@/lib/focus-ring';
import { cn } from '@/lib/utils';

/**
 * Pestañas de una página de la Weekly como enlaces (?pestana=…), con la actual marcada con
 * aria-current, como las de Ausencias. En el móvil se desplazan sin mover la página.
 */
export function WeeklyTabs({
    label,
    tabs,
    current,
}: {
    label: string;
    tabs: { id: string; label: string; href: string }[];
    current: string;
}) {
    return (
        <nav
            aria-label={label}
            className="-mx-4 overflow-x-auto border-b px-4 md:mx-0 md:px-0"
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
                                    '-mb-px flex border-b-2 px-3 py-2 text-sm',
                                    active
                                        ? 'border-primary font-medium text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                    FOCUS_RING,
                                )}
                                data-test={`weekly-tab-${tab.id}`}
                            >
                                {tab.label}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
