import { Construction } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { t } from '@/lib/i18n';

/**
 * Aviso de pantalla de la Weekly aún en construcción (contrato 10.1): la ruta y los datos ya
 * existen; la interfaz llega en la entrega indicada de la Fase 10.
 */
export function WeeklyPlaceholder({ delivery }: { delivery: string }) {
    return (
        <EmptyState
            icon={Construction}
            title={t('weeklies.pending.title')}
            description={t('weeklies.pending.description', { delivery })}
        />
    );
}
