import type { LucideIcon } from 'lucide-react';
import {
    Ban,
    CircleAlert,
    CircleCheck,
    CircleDashed,
    CircleDot,
    Clock,
    PencilLine,
    TriangleAlert,
} from 'lucide-react';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { t } from '@/lib/i18n';
import type { CollectionStatus, SaleStatus } from '@/types';

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';
type Meta = { tone: Tone; icon: LucideIcon };

/** Semáforo de la unidad de venta (D-390): icono y texto, nunca solo color. */
const SALE: Record<SaleStatus, Meta> = {
    ok: { tone: 'success', icon: CircleCheck },
    risk: { tone: 'warning', icon: TriangleAlert },
    over: { tone: 'danger', icon: CircleAlert },
    none: { tone: 'neutral', icon: CircleDashed },
};

export function SaleStatusBadge({ status }: { status: SaleStatus }) {
    const meta = SALE[status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {t(`billing.status.${status}`)}
        </StatusBadge>
    );
}

/** Estado de cobro de una factura de Holded (D-386). */
const COLLECTION: Record<CollectionStatus, Meta> = {
    paid: { tone: 'success', icon: CircleCheck },
    partial: { tone: 'info', icon: CircleDot },
    unpaid: { tone: 'neutral', icon: Clock },
    overdue: { tone: 'danger', icon: CircleAlert },
    cancelled: { tone: 'neutral', icon: Ban },
    draft: { tone: 'neutral', icon: PencilLine },
};

export function CollectionStatusBadge({
    status,
}: {
    status: CollectionStatus;
}) {
    const meta = COLLECTION[status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {t(`billing.collection.${status}`)}
        </StatusBadge>
    );
}
