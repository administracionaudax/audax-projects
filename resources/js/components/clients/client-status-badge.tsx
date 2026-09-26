import { CircleCheck, CircleSlash } from 'lucide-react';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { t } from '@/lib/i18n';

/** Cliente activo o desactivado: icono + texto (nunca solo color). */
export function ClientStatusBadge({ isActive }: { isActive: boolean }) {
    return isActive ? (
        <StatusBadge tone="success" icon={CircleCheck}>
            {t('clients.status.active')}
        </StatusBadge>
    ) : (
        <StatusBadge tone="neutral" icon={CircleSlash}>
            {t('clients.status.inactive')}
        </StatusBadge>
    );
}
