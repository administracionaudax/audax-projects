import { Link } from '@inertiajs/react';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type BillingTab = 'facturas' | 'contactos' | 'ajustes';

const TABS: { id: BillingTab; href: string; label: string }[] = [
    {
        id: 'facturas',
        href: '/facturacion/facturas',
        label: 'billing.nav.invoices',
    },
    {
        id: 'contactos',
        href: '/facturacion/contactos',
        label: 'billing.nav.contacts',
    },
    {
        id: 'ajustes',
        href: '/facturacion/ajustes',
        label: 'billing.nav.settings',
    },
];

/** Pestañas de la sección Facturación (D-393): facturas, contactos de Holded y ajustes. */
export function BillingTabs({
    current,
    badges = {},
}: {
    current: BillingTab;
    badges?: Partial<Record<BillingTab, number>>;
}) {
    return (
        <nav
            aria-label={t('billing.nav.label')}
            className="-mx-4 overflow-x-auto border-b px-4 md:mx-0 md:px-0"
        >
            <ul className="flex min-w-max gap-1">
                {TABS.map((tab) => {
                    const active = tab.id === current;
                    const badge = badges[tab.id] ?? 0;

                    return (
                        <li key={tab.id}>
                            <Link
                                href={tab.href}
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    '-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm',
                                    active
                                        ? 'border-primary font-medium text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                    FOCUS_RING,
                                )}
                            >
                                {t(tab.label as 'billing.nav.invoices')}
                                {badge > 0 ? (
                                    <span className="rounded-md bg-warning-soft px-1.5 text-xs text-foreground">
                                        {badge}
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
