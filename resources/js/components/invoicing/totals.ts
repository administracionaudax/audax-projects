/**
 * Totales de una factura en el editor (PLAN-EMISION §4.2; D-422): la misma cuenta que
 * App\Domain\Billing\Issuing\DocumentTotals, con enteros exactos (BigInt) y nunca float. Casos
 * compartidos en `tests/fixtures/billing/totals.json` (Vitest y Pest).
 *
 * - Bruto = cantidad × precio, al céntimo. Base = cantidad × precio × (1 − descuento %), al céntimo.
 * - Cuota por tipo = base del tipo × tipo / 100, al céntimo, solo en S1 (las demás, 0).
 * - Retención = base total × tipo / 100. Total = base + cuotas − retención.
 * - Redondeo: mitad hacia arriba alejándose del cero (igual en una rectificativa en negativo).
 */

export type TotalsTaxInput = {
    key: string;
    rate: string;
    operation_type: string;
};

export type TotalsLineInput = {
    quantity: string;
    unit_price: string;
    discount_pct: string;
    tax: TotalsTaxInput | null;
};

export type TotalsTax = {
    key: string;
    operation_type: string;
    rate: string;
    base: string;
    tax: string;
};

export type Totals = {
    lines: { gross: string; base: string }[];
    subtotal: string;
    discount_total: string;
    taxes: TotalsTax[];
    tax_total: string;
    withholding_total: string;
    gross_total: string;
    total: string;
};

const TEN = BigInt(10);
const ZERO = BigInt(0);
const ONE = BigInt(1);
const TWO = BigInt(2);

/** «12,5» o «12.5» con `scale` decimales como entero: «12.5», 4 → 125000n. Lo no numérico, 0. */
export function scaled(
    value: string | number | null | undefined,
    scale: number,
): bigint {
    const text = String(value ?? '')
        .trim()
        .replace(',', '.');

    if (
        !/^-?\d*(\.\d*)?$/.test(text) ||
        text === '' ||
        text === '-' ||
        text === '.'
    ) {
        return ZERO;
    }

    const negative = text.startsWith('-');
    const [integer, fraction = ''] = text.replace('-', '').split('.');
    const digits =
        (integer || '0') + fraction.padEnd(scale, '0').slice(0, scale);
    const result = BigInt(digits);

    return negative ? -result : result;
}

/** n / 10^places, redondeado a la mitad alejándose del cero. */
function roundDiv(value: bigint, places: number): bigint {
    const divisor = TEN ** BigInt(places);
    const quotient = value / divisor;
    const remainder = value % divisor;
    const twice = (remainder < ZERO ? -remainder : remainder) * TWO;

    if (twice >= divisor) {
        return quotient + (value < ZERO ? -ONE : ONE);
    }

    return quotient;
}

/** Céntimos → «1234.50» (siempre dos decimales; nunca «-0.00»). */
export function centsToString(cents: bigint): string {
    const negative = cents < ZERO;
    const absolute = negative ? -cents : cents;
    const text = absolute.toString().padStart(3, '0');

    return `${negative ? '-' : ''}${text.slice(0, -2)}.${text.slice(-2)}`;
}

export function computeTotals(
    lines: ReadonlyArray<TotalsLineInput>,
    withholding: string | null,
): Totals {
    const rows: { gross: string; base: string }[] = [];
    let subtotal = ZERO;
    let discount = ZERO;
    const groups = new Map<
        string,
        { key: string; operation_type: string; rate: bigint; base: bigint }
    >();

    for (const line of lines) {
        const product = scaled(line.quantity, 4) * scaled(line.unit_price, 4);
        const factor = BigInt(10000) - scaled(line.discount_pct, 2);
        const gross = roundDiv(product, 6);
        const base = roundDiv(product * factor, 10);

        rows.push({ gross: centsToString(gross), base: centsToString(base) });
        subtotal += base;
        discount += gross - base;

        if (line.tax !== null) {
            const group = groups.get(line.tax.key) ?? {
                key: line.tax.key,
                operation_type: line.tax.operation_type,
                rate: scaled(line.tax.rate, 2),
                base: ZERO,
            };
            group.base += base;
            groups.set(line.tax.key, group);
        }
    }

    const taxes = [...groups.values()]
        .sort(
            (a, b) =>
                (a.rate === b.rate ? 0 : a.rate > b.rate ? -1 : 1) ||
                a.operation_type.localeCompare(b.operation_type) ||
                (a.key < b.key ? -1 : a.key > b.key ? 1 : 0),
        )
        .map((group) => {
            const tax =
                group.operation_type === 'S1'
                    ? roundDiv(group.base * group.rate, 4)
                    : ZERO;

            return { ...group, tax };
        });

    const taxTotal = taxes.reduce((sum, group) => sum + group.tax, ZERO);
    const withholdingRate = scaled(withholding, 2);
    const withheld =
        withholdingRate === ZERO
            ? ZERO
            : roundDiv(subtotal * withholdingRate, 4);
    const gross = subtotal + taxTotal;

    return {
        lines: rows,
        subtotal: centsToString(subtotal),
        discount_total: centsToString(discount),
        taxes: taxes.map((group) => ({
            key: group.key,
            operation_type: group.operation_type,
            rate: centsToString(group.rate),
            base: centsToString(group.base),
            tax: centsToString(group.tax),
        })),
        tax_total: centsToString(taxTotal),
        withholding_total: centsToString(withheld),
        gross_total: centsToString(gross),
        total: centsToString(gross - withheld),
    };
}
