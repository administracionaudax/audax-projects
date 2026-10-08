import type { ReportFiltersProps, ReportRequestData } from './reports';

/**
 * Facturación (Fase 12, F1; D-380 a D-399): contrato con App\Domain\Billing\SoldVsActual,
 * InvoicePresenter y los controladores de App\Http\Controllers\Billing. Importes como cadenas
 * decimales («1234.50»), horas en minutos enteros y fechas «AAAA-MM-DD».
 */

export type SaleKind = 'bolsa' | 'precio_cerrado' | 'fee' | 'horas';

export type SaleStatus = 'ok' | 'risk' | 'over' | 'none';

export type CollectionStatus =
    | 'paid'
    | 'partial'
    | 'unpaid'
    | 'overdue'
    | 'cancelled';

export type InvoiceLinkMethod =
    | 'f_code'
    | 'holded_project'
    | 'rectified'
    | 'manual';

/** Una unidad de venta (SoldVsActual::finish). Los importes, solo con view-billing. */
export type SoldVsActualUnit = {
    key: string;
    kind: SaleKind;
    name: string;
    project: { id: number; code: string; name: string; billing_type: string };
    client: { id: number; name: string } | null;
    manager: { id: number; name: string } | null;
    bank: { id: number; name: string; status: string | null } | null;
    whole: boolean;
    months: number | null;
    sold_minutes: number | null;
    real_minutes: number;
    pending_minutes: number;
    deviation_minutes: number | null;
    consumption_pct: number | null;
    status: SaleStatus;
    invoices_count: number;
    sold_amount?: string | null;
    hours_value?: string | null;
    invoiced?: string;
    invoiced_total?: string;
    collected?: string;
    outstanding?: string;
    overdue?: string;
    to_invoice?: string | null;
    cost?: string;
    margin?: string | null;
    margin_pct?: number | null;
    effective_rate?: string | null;
};

export type SoldVsActualTotals = {
    units: number;
    sold_minutes: number;
    real_minutes: number;
    real_of_sold_minutes: number;
    pending_minutes: number;
    deviation_minutes: number;
    consumption_pct: number | null;
    status: SaleStatus;
    by_status: Record<SaleStatus, number>;
    sold_amount?: string;
    income?: string;
    invoiced?: string;
    invoiced_total?: string;
    collected?: string;
    outstanding?: string;
    overdue?: string;
    to_invoice?: string;
    cost?: string;
    margin?: string;
    margin_pct?: number | null;
};

export type SoldVsActualReport = {
    units: SoldVsActualUnit[];
    totals: SoldVsActualTotals;
    financials: boolean;
    from: string;
    to: string;
};

export type InvoiceLinkData = {
    id: number;
    method: InvoiceLinkMethod;
    project: { id: number; code: string; name: string } | null;
    bank: { id: number; name: string } | null;
    created_by?: string | null;
};

/** InvoicePresenter::summary. */
export type HoldedInvoiceSummary = {
    id: number;
    number: string | null;
    kind: 'invoice' | 'credit_note';
    issued_on: string;
    due_on: string | null;
    contact_name: string | null;
    client: { id: number; name: string } | null;
    currency: string;
    subtotal: string;
    tax_total: string;
    total: string;
    paid_total: string;
    pending_total: string;
    collection_status: CollectionStatus;
    links: InvoiceLinkData[];
};

/** InvoicePresenter::detail. */
export type HoldedInvoiceDetail = HoldedInvoiceSummary & {
    holded_status: string | null;
    notes: string | null;
    synced_at: string | null;
    pdf_stored: boolean;
    lines: {
        id: number;
        name: string | null;
        description: string | null;
        units: string;
        unit_price: string;
        discount_pct: string;
        subtotal: string;
        tax_rate: string | null;
        has_project: boolean;
    }[];
    payments: {
        id: number;
        paid_on: string;
        amount: string;
        method: string | null;
    }[];
    rectified: { id: number; number: string | null; issued_on: string } | null;
    rectifications: {
        id: number;
        number: string | null;
        issued_on: string;
        subtotal: string;
    }[];
};

/** BillingPanel (fichas de proyecto, cliente y bolsa). */
export type BillingPanelData = {
    report: SoldVsActualReport;
    invoices: HoldedInvoiceSummary[] | null;
    invoice_count: number;
};

export type HoldedSyncSummary = {
    status: 'running' | 'ok' | 'failed';
    started_at: string;
    finished_at: string | null;
};

export type SoldVsActualPageProps = {
    filters: ReportFiltersProps;
    kinds: SaleKind[];
    manager: number | null;
    managers: { id: number; name: string }[];
    report: SoldVsActualReport;
    report_request: ReportRequestData;
    scope: { own_projects: boolean };
};
