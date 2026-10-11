import { Ban, CircleAlert, CircleCheck, CircleDot, Clock } from 'lucide-react';
import { StatusBadge } from '@/components/styleguide/status-badges';
import type { InvoiceCollection } from '@/types';

/**
 * El estado de cobro de una factura tal como lo ve el cliente (portal y enlace firmado; E2, D-443):
 * pagada y anulada en gris (como D-407), lo demás con su insignia, siempre con icono y texto.
 */
export function ClientInvoiceStatus({
    collection,
    label,
}: {
    collection: InvoiceCollection;
    label: string;
}) {
    if (collection === 'paid' || collection === 'cancelled') {
        const Icon = collection === 'paid' ? CircleCheck : Ban;

        return (
            <span
                className="inline-flex items-center gap-1 text-sm text-muted-foreground"
                data-test="client-invoice-status"
            >
                <Icon aria-hidden="true" className="size-3.5" />
                {label}
            </span>
        );
    }

    const meta =
        collection === 'overdue'
            ? { tone: 'danger' as const, icon: CircleAlert }
            : collection === 'partial'
              ? { tone: 'info' as const, icon: CircleDot }
              : { tone: 'warning' as const, icon: Clock };

    return (
        <span data-test="client-invoice-status">
            <StatusBadge tone={meta.tone} icon={meta.icon}>
                {label}
            </StatusBadge>
        </span>
    );
}
