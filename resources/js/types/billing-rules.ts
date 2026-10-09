/**
 * Respuestas del propietario del 09/10/2026 en Facturación (D-430 a D-433). Contrato con
 * App\Domain\Billing\HoldedClientCreator (crear el cliente desde un contacto de Holded) y
 * App\Domain\Billing\UnbilledReport::detail (lo pendiente de un cliente, por línea).
 */

/** HoldedClientCreator::draft: los datos del diálogo, ya rellenos. */
export type ClientDraft = {
    name: string;
    tax_id: string | null;
    email: string | null;
    legal_name: string | null;
    address: string | null;
    postal_code: string | null;
    city: string | null;
    province: string | null;
    country_code: string;
};

/** Un cliente que ya podría ser el del contacto (mismo NIF, mismo nombre o uno muy parecido). */
export type ClientCandidate = {
    id: number;
    name: string;
    tax_id: string | null;
    is_active: boolean;
    reason: 'nif' | 'nombre' | 'parecido';
};

export type ClientDraftResponse = {
    draft: ClientDraft;
    candidates: ClientCandidate[];
};

/** De dónde sale una línea de lo pendiente de un cliente. «carried»: exceso pasado a la bolsa siguiente. */
export type UnbilledLineSource =
    | 'hours'
    | 'overage'
    | 'carried'
    | 'banks'
    | 'fees'
    | 'fixed';

export type UnbilledLine = {
    source: UnbilledLineSource;
    project: { id: number; code: string; name: string };
    bank: { id: number; name: string } | null;
    /** Horas sin facturar (horas y excesos; en «carried», las traspasadas). */
    minutes: number;
    /** Horas facturables sin aprobar (no cuentan). */
    pending_minutes: number;
    /** Sin IVA; null sin view-billing y en «carried». */
    amount: string | null;
    oldest: string | null;
    /** Fees: meses sin factura. */
    months: number | null;
    /** «carried»: la bolsa a la que pasa el exceso. */
    next_bank: { id: number; name: string } | null;
    /** Precios cerrados: el precio, lo facturado y las horas como contexto. */
    fixed: {
        price: string;
        invoiced: string;
        real_minutes: number;
        budget_minutes: number | null;
        consumption_pct: number | null;
    } | null;
};

export type UnbilledDetail = {
    lines: UnbilledLine[];
    totals: { minutes: number; pending_minutes: number; amount: string | null };
    financials: boolean;
};
