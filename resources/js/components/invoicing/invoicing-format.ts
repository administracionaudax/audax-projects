import { formatCurrency, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { ServiceUnit } from '@/types';

/**
 * Formato de la emisión propia (D-428): importes con IVA o sin él, cantidades sin ceros de más y el
 * nombre corto de cada tipo de impuesto, como en el PDF.
 */

/** «8», «7,5», «0,25» (sin ceros de más). */
export function formatQuantity(value: string | number): string {
    const number = Number(value);

    if (!Number.isFinite(number)) {
        return '';
    }

    const decimals = Math.min(
        4,
        (String(value).split('.')[1] ?? '').replace(/0+$/, '').length,
    );

    return formatNumber(number, decimals);
}

/** Precio unitario: dos decimales, o hasta cuatro si los tiene. */
export function formatPrice(value: string): string {
    const decimals = (value.split('.')[1] ?? '').replace(/0+$/, '').length;

    if (decimals <= 2) {
        return formatCurrency(value);
    }

    return `${formatNumber(Number(value), Math.min(decimals, 4))} €`;
}

export function unitLabel(
    unit: ServiceUnit,
    quantity?: string | number,
): string {
    const plural = quantity === undefined || Math.abs(Number(quantity)) !== 1;

    return t(
        plural
            ? (`invoicing.unit.${unit}_other` as const)
            : (`invoicing.unit.${unit}_one` as const),
    );
}

/** «IVA 21 %», «Exenta», «No sujeta», «Inversión del sujeto pasivo». */
export function taxLabel(operationType: string | null, rate: string): string {
    switch (operationType) {
        case 'S1':
            return t('invoicing.tax.vat', { rate: formatQuantity(rate) });
        case 'S2':
            return t('invoicing.tax.reverse_charge');
        case 'N1':
        case 'N2':
            return t('invoicing.tax.not_subject');
        default:
            return t('invoicing.tax.exempt');
    }
}
