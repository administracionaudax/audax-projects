import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ChartColumnBig,
    Check,
    ChevronsUpDown,
    Clock,
    Inbox,
    LayoutDashboard,
    Receipt,
    Scale,
    Settings,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAbilities } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Abilities } from '@/types';

/**
 * Pantallas de Facturación (D-405, cambia D-393 y D-401): primero el Resumen (`/facturacion`, I1,
 * D-411), solo con view-billing; a quien solo ve las horas /facturacion lo sigue llevando a
 * «Vendido frente a real».
 */
export type BillingSectionId =
    | 'resumen'
    | 'facturas'
    | 'por-facturar'
    | 'vendido'
    | 'por-revisar'
    | 'ventas'
    | 'ajustes';

export type BillingSection = {
    id: BillingSectionId;
    href: string;
    label:
        | 'billing.nav.summary'
        | 'billing.nav.invoices'
        | 'billing.nav.unbilled'
        | 'billing.nav.sold_vs_actual'
        | 'billing.nav.review'
        | 'billing.nav.sales'
        | 'billing.nav.settings';
    icon: LucideIcon;
    allowed: (can: Abilities) => boolean;
    /** Solo activa en su URL exacta (el Resumen, que es el prefijo de todas). */
    exact?: boolean;
};

const SECTIONS: BillingSection[] = [
    {
        id: 'resumen',
        href: '/facturacion',
        label: 'billing.nav.summary',
        icon: LayoutDashboard,
        allowed: (can) => can.viewBilling === true,
        exact: true,
    },
    {
        id: 'facturas',
        href: '/facturacion/facturas',
        label: 'billing.nav.invoices',
        icon: Receipt,
        allowed: (can) => can.viewBilling === true,
    },
    {
        // Sin el módulo `billing` también (D-402): con su permiso de siempre.
        id: 'por-facturar',
        href: '/facturacion/por-facturar',
        label: 'billing.nav.unbilled',
        icon: Clock,
        allowed: (can) => can.exportBillingHours === true,
    },
    {
        // También responsables y gestores, en horas (D-391).
        id: 'vendido',
        href: '/facturacion/vendido-frente-a-real',
        label: 'billing.nav.sold_vs_actual',
        icon: Scale,
        allowed: (can) => can.viewSoldVsActual === true,
    },
    {
        id: 'por-revisar',
        href: '/facturacion/por-revisar',
        label: 'billing.nav.review',
        icon: Inbox,
        allowed: (can) => can.viewBilling === true,
    },
    {
        id: 'ventas',
        href: '/facturacion/ventas',
        label: 'billing.nav.sales',
        icon: ChartColumnBig,
        allowed: (can) => can.viewBilling === true,
    },
    {
        id: 'ajustes',
        href: '/facturacion/ajustes',
        label: 'billing.nav.settings',
        icon: Settings,
        allowed: (can) => can.viewBilling === true,
    },
];

/**
 * Las pantallas de Facturación que ve cada persona, en el orden de la barra lateral (D-405): todo
 * con view-billing; «Vendido frente a real» con view-sold-vs-actual y «Por facturar» con su
 * permiso (exportBillingHours), también sin el módulo.
 */
export function billingSections(can: Abilities): BillingSection[] {
    return SECTIONS.filter((section) => section.allowed(can));
}

/**
 * Selector compacto de pantalla para el móvil (D-405): la barra lateral está escondida en el cajón,
 * así que la cabecera de cada pantalla lleva un menú con las demás. Con una sola pantalla visible
 * (quien solo ve «Vendido frente a real») no se pinta.
 */
export function BillingSectionSwitcher({
    current,
    className,
}: {
    current: BillingSectionId;
    className?: string;
}) {
    const sections = billingSections(useAbilities());

    if (sections.length <= 1) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className={cn('shrink-0', className)}
                    aria-label={t('billing.nav.switch')}
                    data-test="billing-section-switcher"
                >
                    <ChevronsUpDown aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="min-w-56">
                {sections.map((section) => {
                    const active = section.id === current;

                    return (
                        <DropdownMenuItem key={section.id} asChild>
                            <Link
                                href={section.href}
                                aria-current={active ? 'page' : undefined}
                                className="flex items-center gap-2"
                            >
                                <section.icon
                                    aria-hidden="true"
                                    strokeWidth={1.5}
                                />
                                <span className="flex-1">
                                    {t(section.label)}
                                </span>
                                {active ? (
                                    <Check
                                        aria-hidden="true"
                                        className="text-primary"
                                    />
                                ) : null}
                            </Link>
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
