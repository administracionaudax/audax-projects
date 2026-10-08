import { Link } from '@inertiajs/react';
import { useAbilities } from '@/hooks/use-auth';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Abilities } from '@/types';

export type BillingTab =
    | 'informe'
    | 'vendido'
    | 'horas'
    | 'facturas'
    | 'contactos'
    | 'ajustes';

type TabDef = {
    id: BillingTab;
    href: string;
    label:
        | 'billing.nav.report'
        | 'billing.nav.sold_vs_actual'
        | 'billing.nav.hours'
        | 'billing.nav.invoices'
        | 'billing.nav.contacts'
        | 'billing.nav.settings';
    allowed: (can: Abilities) => boolean;
};

const TABS: TabDef[] = [
    {
        id: 'informe',
        href: '/facturacion/informe',
        label: 'billing.nav.report',
        allowed: (can) => can.viewBilling === true,
    },
    {
        id: 'vendido',
        href: '/facturacion/vendido-frente-a-real',
        label: 'billing.nav.sold_vs_actual',
        allowed: (can) => can.viewSoldVsActual === true,
    },
    {
        id: 'horas',
        href: '/facturacion/horas-para-facturar',
        label: 'billing.nav.hours',
        allowed: (can) => can.exportBillingHours === true,
    },
    {
        id: 'facturas',
        href: '/facturacion/facturas',
        label: 'billing.nav.invoices',
        allowed: (can) => can.viewBilling === true,
    },
    {
        id: 'contactos',
        href: '/facturacion/contactos',
        label: 'billing.nav.contacts',
        allowed: (can) => can.viewBilling === true,
    },
    {
        id: 'ajustes',
        href: '/facturacion/ajustes',
        label: 'billing.nav.settings',
        allowed: (can) => can.viewBilling === true,
    },
];

/**
 * Pestañas de Facturación que ve cada persona (D-393 y D-401): el informe, las facturas, los
 * contactos y los ajustes con view-billing; «Vendido frente a real» con view-sold-vs-actual (también
 * responsables y gestores, en horas) y «Horas para facturar» con su permiso de siempre (D-402).
 */
export function billingTabs(can: Abilities): TabDef[] {
    return TABS.filter((tab) => tab.allowed(can));
}

/**
 * Pestañas de la sección Facturación. Con una sola pestaña visible (quien solo ve «Vendido frente a
 * real», o las horas para facturar sin el módulo), no se pintan: no hay adónde ir.
 */
export function BillingTabs({
    current,
    badges = {},
}: {
    current: BillingTab;
    badges?: Partial<Record<BillingTab, number>>;
}) {
    const tabs = billingTabs(useAbilities());

    if (tabs.length <= 1) {
        return null;
    }

    return (
        <nav
            aria-label={t('billing.nav.label')}
            className="-mx-4 overflow-x-auto border-b px-4 md:mx-0 md:px-0"
        >
            <ul className="flex min-w-max gap-1">
                {tabs.map((tab) => {
                    const active = tab.id === current;
                    const badge = badges[tab.id] ?? 0;

                    return (
                        <li key={tab.id}>
                            <Link
                                href={tab.href}
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    '-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-sm whitespace-nowrap',
                                    active
                                        ? 'border-primary font-medium text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                    FOCUS_RING,
                                )}
                            >
                                {t(tab.label)}
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
