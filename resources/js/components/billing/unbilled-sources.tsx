import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import type { UnbilledClient } from '@/types';

/**
 * De dónde sale lo que un cliente tiene por facturar (I10, D-412), en texto corto: «Horas · Exceso
 * de bolsa · 1 bolsa sin factura · 2 meses de fee». Sin color: es una descripción, no un estado.
 */
export function unbilledSources(sources: UnbilledClient['sources']): string {
    return [
        sources.hours > 0 ? t('billing.unbilled.source.hours') : null,
        sources.overage > 0 ? t('billing.unbilled.source.overage') : null,
        sources.banks > 0
            ? tCount('billing.unbilled.source.banks', sources.banks)
            : null,
        sources.fees > 0
            ? tCount('billing.unbilled.source.fees', sources.fees)
            : null,
    ]
        .filter(Boolean)
        .join(' · ');
}
