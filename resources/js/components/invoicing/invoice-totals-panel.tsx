import { formatCurrency } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { InvoicingTaxOption } from '@/types';
import { taxLabel } from './invoicing-format';
import type { Totals } from './totals';

/**
 * Los totales de una factura con su cuadro de impuestos (PLAN-EMISION §6.2, paso 3; L-07): base,
 * descuentos aplicados, base y cuota por tipo, retención y total, rotulados una vez por su base (sin
 * IVA, con IVA; D-410). En el editor se recalculan al escribir; en la ficha son los congelados.
 */
export function InvoiceTotalsPanel({
    totals,
    taxes,
    withholdingLabel,
}: {
    totals: Pick<
        Totals,
        | 'subtotal'
        | 'discount_total'
        | 'tax_total'
        | 'withholding_total'
        | 'total'
    > & {
        taxes: {
            key?: string;
            operation_type: string;
            rate: string;
            base: string;
            tax: string;
        }[];
    };
    taxes?: InvoicingTaxOption[];
    withholdingLabel: string | null;
}) {
    const label = (tax: {
        key?: string;
        operation_type: string;
        rate: string;
    }) => {
        const option = taxes?.find((one) => String(one.id) === tax.key);

        return option?.name ?? taxLabel(tax.operation_type, tax.rate);
    };

    return (
        <section
            aria-label={t('invoicing.totals.title')}
            className="grid gap-3 rounded-md border bg-card p-4"
            data-test="invoice-totals"
        >
            <dl className="grid gap-1.5 text-sm">
                <Row
                    label={t('invoicing.totals.subtotal')}
                    value={formatCurrency(totals.subtotal)}
                />
                {Number(totals.discount_total) !== 0 ? (
                    <Row
                        label={t('invoicing.totals.discounts')}
                        value={formatCurrency(totals.discount_total)}
                        muted
                    />
                ) : null}
            </dl>

            {totals.taxes.length > 0 ? (
                <table className="tabular w-full text-sm">
                    <caption className="sr-only">
                        {t('invoicing.totals.breakdown')}
                    </caption>
                    <thead>
                        <tr className="text-xs tracking-[0.12em] text-muted-foreground uppercase">
                            <th
                                scope="col"
                                className="pb-1 text-left font-medium"
                            >
                                {t('invoicing.totals.tax')}
                            </th>
                            <th
                                scope="col"
                                className="pb-1 text-right font-medium"
                            >
                                {t('invoicing.totals.base')}
                            </th>
                            <th
                                scope="col"
                                className="pb-1 text-right font-medium"
                            >
                                {t('invoicing.totals.amount')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {totals.taxes.map((tax, index) => (
                            <tr
                                key={`${tax.key ?? index}-${tax.rate}`}
                                className="border-t"
                            >
                                <th
                                    scope="row"
                                    className="py-1 text-left font-normal"
                                >
                                    {label(tax)}
                                </th>
                                <td className="py-1 text-right">
                                    {formatCurrency(tax.base)}
                                </td>
                                <td className="py-1 text-right">
                                    {formatCurrency(tax.tax)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : null}

            <dl className="grid gap-1.5 border-t pt-3 text-sm">
                <Row
                    label={t('invoicing.totals.tax_total')}
                    value={formatCurrency(totals.tax_total)}
                />
                {Number(totals.withholding_total) !== 0 ? (
                    <Row
                        label={
                            withholdingLabel ??
                            t('invoicing.totals.withholding')
                        }
                        value={`−${formatCurrency(totals.withholding_total)}`}
                    />
                ) : null}
                <div className="mt-1 flex items-baseline justify-between gap-3 border-t pt-2">
                    <dt>{t('invoicing.totals.total')}</dt>
                    <dd className="tabular text-2xl" data-test="invoice-total">
                        {formatCurrency(totals.total)}
                    </dd>
                </div>
            </dl>
        </section>
    );
}

function Row({
    label,
    value,
    muted,
}: {
    label: string;
    value: string;
    muted?: boolean;
}) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className={muted ? 'tabular text-muted-foreground' : 'tabular'}>
                {value}
            </dd>
        </div>
    );
}
