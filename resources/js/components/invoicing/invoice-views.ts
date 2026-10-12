import type { ViewTab } from '@/components/billing/view-tabs';
import { t } from '@/lib/i18n';

/**
 * Las vistas del listado de facturas (D-406) y, con la emisión propia (E3), «Programadas» y
 * «Recurrentes» (que es su propia página, /facturacion/recurrentes, con las mismas pestañas).
 */
export type InvoiceView =
    | 'todas'
    | 'por-cobrar'
    | 'vencidas'
    | 'sin-proyecto'
    | 'borradores'
    | 'programadas'
    | 'rectificativas'
    | 'pruebas';

export const INVOICE_VIEWS: InvoiceView[] = [
    'todas',
    'por-cobrar',
    'vencidas',
    'sin-proyecto',
    'borradores',
    // E3: los borradores con fecha de emisión (solo si hay alguno).
    'programadas',
    'rectificativas',
    // La serie de pruebas de la emisión propia (D-419): solo si hay alguna.
    'pruebas',
];

/** Las que solo salen si tienen algo (o si se está en ellas). */
const OPTIONAL: InvoiceView[] = ['programadas', 'pruebas'];

export function invoiceTabs({
    views,
    current,
    href,
    recurring,
}: {
    views: Partial<Record<InvoiceView, number>>;
    current: InvoiceView | 'recurrentes';
    href: (view: InvoiceView) => string;
    /** Con la emisión visible: la pestaña «Recurrentes» con su número; null, sin ella. */
    recurring: number | null;
}): ViewTab[] {
    const tabs: ViewTab[] = INVOICE_VIEWS.filter(
        (view) =>
            !OPTIONAL.includes(view) ||
            (views[view] ?? 0) > 0 ||
            current === view,
    ).map((view) => ({
        id: view,
        label: t(`billing.invoices.view.${view}`),
        href: href(view),
        count: views[view] ?? 0,
    }));

    if (recurring !== null) {
        tabs.push({
            id: 'recurrentes',
            label: t('invoicing.recurring.tab'),
            href: '/facturacion/recurrentes',
            count: recurring,
        });
    }

    return tabs;
}
