import type { ReportFiltersProps, ReportRequestData } from './reports';

/**
 * Facturación (Fase 12, F1; D-380 a D-399): contrato con App\Domain\Billing\SoldVsActual,
 * InvoicePresenter y los controladores de App\Http\Controllers\Billing. Importes como cadenas
 * decimales («1234.50»), horas en minutos enteros y fechas «AAAA-MM-DD».
 */

export type SaleKind = 'bolsa' | 'precio_cerrado' | 'fee' | 'horas';

/** El semáforo de lo vendido y, por horas, lo pendiente de facturar o facturado al día (D-411). */
export type SaleStatus =
    | 'ok'
    | 'risk'
    | 'over'
    | 'unbilled'
    | 'billed'
    | 'none';

export type CollectionStatus =
    | 'paid'
    | 'partial'
    | 'unpaid'
    | 'overdue'
    | 'cancelled'
    | 'draft';

export type InvoiceLinkMethod =
    | 'f_code'
    | 'holded_project'
    | 'rectified'
    | 'manual';

export type InvoiceLineKind =
    | 'bank'
    | 'fee'
    | 'hours'
    | 'pass_through'
    | 'other';

/** InvoiceLinkSuggester: un proyecto (y su bolsa) para enlazar una factura sin enlazar. */
export type InvoiceLinkSuggestion = {
    project: { id: number; code: string; name: string };
    bank: { id: number; name: string } | null;
    reason: InvoiceLineKind;
};

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
    /** Horas de las líneas de horas y de bolsa de sus facturas (D-396). */
    invoiced_minutes: number;
    /** Por horas (D-411): las horas reales que pasan de las facturadas; null en las demás. */
    unbilled_minutes: number | null;
    sold_amount?: string | null;
    /** De dónde sale el importe vendido: la bolsa o el proyecto en Audax, o la línea de Holded. */
    sold_source?: 'audax' | 'holded' | null;
    /** Borradores de Holded enlazados: previsto, no facturado (D-395). */
    planned?: string;
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
    /** Horas pendientes de facturar de las unidades por horas (D-411). */
    unbilled_minutes: number;
    sold_amount?: string;
    income?: string;
    invoiced?: string;
    invoiced_total?: string;
    collected?: string;
    outstanding?: string;
    overdue?: string;
    to_invoice?: string;
    planned?: string;
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
    /** Cuándo se enlazó (la línea de tiempo de la ficha, D-408). */
    created_at?: string | null;
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
    is_draft: boolean;
    tags: string[];
    links: InvoiceLinkData[];
    /** En el listado, la primera sugerencia de una factura sin enlazar. */
    suggestion?: InvoiceLinkSuggestion | null;
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
        service_code: string | null;
        kind: InvoiceLineKind;
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
    /** La última lectura que acabó bien (la misma si esta acabó bien). Solo en la prop compartida. */
    last_ok_at?: string | null;
};

/** Prop compartida `billing` (D-405 y D-409), solo para quien tiene view-billing. */
export type BillingShared = {
    /** Contador de «Por revisar»: contactos de Holded sin casar o por confirmar. */
    review: number;
    sync: HoldedSyncSummary | null;
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

/** Servicios del informe de facturación (App\Enums\BillingService, D-400), en su orden. */
export type BillingService =
    | 'bolsas'
    | 'fees'
    | 'desarrollo'
    | 'diseno'
    | 'mantenimiento'
    | 'auditorias'
    | 'marketing'
    | 'seo'
    | 'herramientas'
    | 'inversion'
    | 'otros';

export type AgingBucket =
    | 'current'
    | 'd1_30'
    | 'd31_60'
    | 'd61_90'
    | 'd90_plus';

/** App\Domain\Billing\InvoicingReport::report() (D-400). Importes sin IVA salvo cobro y pendiente. */
export type InvoicingReport = {
    from: string;
    to: string;
    previous_from: string;
    previous_to: string;
    today: string;
    compare: boolean;
    services_filter: BillingService[];
    kpis: {
        invoiced: string;
        previous_invoiced: string;
        /** Variación en % con un decimal («12.5»); null sin facturado el año anterior. */
        variation_pct: string | null;
        collected: string;
        outstanding: string;
        overdue: string;
        planned: string;
        planned_count: number;
        count: number;
        /** Solo al comparar. */
        previous_count: number | null;
        average: string | null;
        previous_average: string | null;
        credit_notes: string;
    };
    months: {
        /** AAAA-MM */
        month: string;
        invoiced: string;
        planned: string;
        /** El mismo mes del año anterior; null sin comparar. */
        previous: string | null;
        count: number;
    }[];
    /** De mayor a menor; `sin_desglose`: la base que no está en ninguna línea. */
    services: {
        key: BillingService | 'sin_desglose';
        amount: string;
        share: string | null;
    }[];
    clients: {
        top: InvoicingClientRow[];
        rest: {
            amount: string;
            share: string | null;
            clients: number;
            count: number;
        } | null;
        unmatched: {
            amount: string;
            share: string | null;
            count: number;
        } | null;
    };
    aging: { key: AgingBucket; amount: string; count: number }[];
    overdue: {
        clients: InvoicingOverdueGroup[];
        total: number;
        shown: number;
    };
};

export type InvoicingClientRow = {
    id: number;
    name: string;
    amount: string;
    share: string | null;
    count: number;
};

export type InvoicingOverdueGroup = {
    client: { id: number; name: string } | null;
    contact_name: string | null;
    amount: string;
    invoices: {
        id: number;
        number: string | null;
        issued_on: string;
        due_on: string;
        days: number;
        pending: string;
    }[];
};

export type InvoicingReportPageProps = {
    filters: ReportFiltersProps;
    services: BillingService[];
    report: InvoicingReport;
    report_request: ReportRequestData;
};

/** Confianza de una propuesta de «Por revisar» (I5, D-413). */
export type ReviewConfidence = 'alta' | 'media' | 'baja';

/** Por qué se propone un cliente para un contacto (HoldedContactMatcher::propose). */
export type ReviewContactReason =
    | 'codigo_f'
    | 'proyecto'
    | 'nif'
    | 'nombre'
    | 'parecido'
    | 'palabras';

/** Un contacto de Holded (ReviewInbox::contactRow): bandeja y directorio de Ajustes. */
export type HoldedContactRow = {
    id: number;
    name: string;
    trade_name: string | null;
    tax_id: string | null;
    email: string | null;
    city: string | null;
    client: { id: number; name: string } | null;
    match_method: 'tax_id' | 'name' | 'approx' | 'manual' | null;
    ignored: boolean;
    invoices: number;
    /** Base facturada (sin IVA) de todas sus facturas. */
    invoiced: string;
};

export type ReviewContactRow = HoldedContactRow & {
    proposal: {
        client: { id: number; name: string };
        reason: ReviewContactReason;
        confidence: ReviewConfidence;
    } | null;
};

export type ReviewInvoiceRow = {
    id: number;
    number: string | null;
    kind: 'invoice' | 'credit_note';
    is_draft: boolean;
    issued_on: string;
    client: { id: number; name: string } | null;
    contact_name: string | null;
    subtotal: string;
    /** El tipo de línea que más pesa (bolsa, fee, horas…). */
    service: InvoiceLineKind;
    proposal:
        | (InvoiceLinkSuggestion & {
              confidence: ReviewConfidence;
              /** El proyecto estaba vivo en la fecha de la factura. */
              dated: boolean;
          })
        | null;
    alternatives: InvoiceLinkSuggestion[];
};

/** Un proyecto (y sus bolsas) con el que enlazar a mano (ReviewInbox::linkTargets). */
export type ReviewLinkTarget = {
    id: number;
    code: string;
    name: string;
    client_id: number | null;
    client: string | null;
    uses_banks: boolean;
    banks: { id: number; name: string; start_date: string }[];
};

export type BillingClientOption = {
    id: number;
    name: string;
    is_active: boolean;
    tax_id: string | null;
};

/** Un cliente con algo por facturar (App\Domain\Billing\UnbilledReport, I10, D-412). */
export type UnbilledClient = {
    client: { id: number; name: string; is_active: boolean };
    /** Horas sin facturar: las de los proyectos por horas y los excesos de bolsa. */
    minutes: number;
    /** Horas facturables aún sin aprobar (no cuentan en `minutes`). */
    pending_minutes: number;
    /** Importe sin IVA; null sin view-billing. */
    amount: string | null;
    /** Fecha (AAAA-MM-DD) de lo más antiguo sin facturar. */
    oldest: string | null;
    /** De dónde sale: proyectos por horas y bolsas con exceso, bolsas sin factura y meses de fee sin factura. */
    sources: { hours: number; overage: number; banks: number; fees: number };
};

export type UnbilledReportData = {
    clients: UnbilledClient[];
    totals: {
        clients: number;
        minutes: number;
        pending_minutes: number;
        amount: string | null;
    };
    financials: boolean;
    from: string;
    to: string;
};
