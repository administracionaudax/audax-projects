import type { DocumentAction } from '@/components/invoicing/document-actions';

/**
 * Emisión propia (PLAN-EMISION E1; D-417 a D-429): contrato con SalesDocumentPresenter,
 * EditorOptions, InvoiceIssuer::preview e InvoicingSettingsController::props. Importes como cadenas
 * decimales («1234.50»), fechas «AAAA-MM-DD».
 */

export type SalesDocumentStatus = 'draft' | 'issued' | 'cancelled' | 'voided';

export type ServiceUnit = 'hour' | 'unit' | 'month';

export type FiscalField =
    | 'legal_name'
    | 'tax_id'
    | 'eu_vat_number'
    | 'address'
    | 'postal_code'
    | 'city';

export type InvoicingClientOption = {
    id: number;
    name: string;
    missing: FiscalField[];
    profile: {
        tax_id: string | null;
        legal_name: string | null;
        eu_vat_number: string | null;
        address: string | null;
        postal_code: string | null;
        city: string | null;
        province: string | null;
        country_code: string;
        tax_regime: string;
        language: string;
        payment_days: number | null;
        payment_method: string | null;
        payment_day: number | null;
        billing_emails: string[];
    };
};

export type InvoicingProjectOption = {
    id: number;
    code: string;
    name: string;
    client_id: number;
    banks: { id: number; name: string }[];
};

export type InvoicingServiceOption = {
    id: number;
    code: string | null;
    name: string;
    description: string | null;
    unit: ServiceUnit;
    unit_price: string;
    tax_rate_id: number | null;
};

export type InvoicingTaxOption = {
    id: number;
    key: string;
    name: string;
    rate: string;
    operation_type: string | null;
    is_default: boolean;
};

export type InvoicingSeriesOption = {
    id: number;
    code: string;
    name: string;
    format: string;
    is_test: boolean;
    is_default: boolean;
    starts_on: string | null;
    next: string;
};

export type EditorOptions = {
    clients: InvoicingClientOption[];
    projects: InvoicingProjectOption[];
    services: InvoicingServiceOption[];
    taxes: InvoicingTaxOption[];
    withholdings: InvoicingTaxOption[];
    series: InvoicingSeriesOption[];
    payment_methods: {
        id: number;
        name: string;
        due_days: number | null;
        is_default: boolean;
    }[];
    issuer_missing: FiscalField[];
    today: string;
};

export type EditorLine = {
    /** Clave estable en el editor (las nuevas no tienen id). */
    key: string;
    id?: number | null;
    kind: 'item' | 'text';
    service_id: number | null;
    description: string;
    quantity: string;
    unit: ServiceUnit;
    unit_price: string;
    discount_pct: string;
    tax_rate_id: number | null;
};

export type SalesDocumentForm = {
    id: number;
    client_id: number;
    series_id: number | null;
    issue_date: string;
    operation_date: string | null;
    due_date: string | null;
    payment_method_id: number | null;
    withholding_rate_id: number | null;
    body: string | null;
    internal_note: string | null;
    customer_reference: string | null;
    project_id: number | null;
    hour_bank_id: number | null;
    lines: Omit<EditorLine, 'key'>[];
};

export type IssuePreview = {
    series: string | null;
    number: string | null;
    issue_date: string;
    last_date: string | null;
    problems: string[];
};

export type SalesDocumentDetail = {
    id: number;
    view_id: number;
    type: 'invoice' | 'credit_note';
    status: SalesDocumentStatus;
    is_test: boolean;
    number: string | null;
    series: { id: number; code: string; name: string; test: boolean } | null;
    issue_date: string;
    operation_date: string | null;
    due_date: string | null;
    client: { id: number; name: string };
    client_name: string;
    client_snapshot: Record<string, string | null> | null;
    language: string;
    subtotal: string;
    discount_total: string;
    tax_total: string;
    withholding_total: string;
    withholding_rate: string | null;
    total: string;
    paid_total: string;
    body: string | null;
    internal_note: string | null;
    customer_reference: string | null;
    payment_method: string | null;
    payment_text: string | null;
    rectification: {
        kind: 'cancellation' | 'differences' | null;
        reason: string | null;
        code: string | null;
        original: {
            id: number;
            view_id: number;
            number: string | null;
            issue_date: string;
        } | null;
    } | null;
    rectifications: {
        id: number;
        number: string | null;
        issue_date: string;
        subtotal: string;
        total: string;
        kind: 'cancellation' | 'differences' | null;
        status: SalesDocumentStatus;
    }[];
    cancelled_by: {
        id: number;
        number: string | null;
        issue_date: string;
    } | null;
    voided: { at: string; by: string | null; reason: string | null } | null;
    /** «No necesita proyecto» (D-431). */
    no_project: { at: string; by: string | null; note: string | null } | null;
    issued_at: string | null;
    issued_by: string | null;
    created_at: string | null;
    created_by: string | null;
    pdf_ready: boolean;
    pdf_sha256: string | null;
    lines: {
        id: number;
        kind: 'item' | 'text';
        service_id: number | null;
        name: string | null;
        service_code: string | null;
        description: string | null;
        quantity: string;
        unit: ServiceUnit;
        unit_price: string;
        discount_pct: string;
        tax_rate_id: number | null;
        tax_rate: string | null;
        operation_type: string | null;
        line_base: string;
    }[];
    taxes: {
        operation_type: string;
        rate: string;
        base: string;
        tax: string;
        legal_mention: string | null;
    }[];
    links: {
        id: number;
        method: string;
        project: { id: number; code: string; name: string };
        bank: { id: number; name: string } | null;
        created_by: string | null;
    }[];
    records: {
        id: number;
        kind: 'alta' | 'anulacion';
        seq: number;
        installation: number;
        invoice_type: string | null;
        hash: string;
        previous_hash: string;
        is_first: boolean;
        generated_at: string;
    }[];
};

export type SalesDocumentShowProps = {
    document: SalesDocumentDetail;
    actions: DocumentAction[];
    issue: IssuePreview | null;
    void_problems: string[];
    projects: InvoicingProjectOption[];
    open_issue: boolean;
    today: string;
    list: {
        back: string;
        previous: string | null;
        next: string | null;
        position: number | null;
        total: number;
    };
};

/** Ajustes de la emisión (InvoicingSettingsController::props). */
export type InvoicingSettingsData = {
    can_manage: boolean;
    year: number;
    series: {
        id: number;
        code: string;
        name: string;
        document_type: 'invoice' | 'credit_note';
        format: string;
        kind: 'regular' | 'test' | 'external';
        refund: string | null;
        starts_on: string | null;
        is_default: boolean;
        archived: boolean;
        issued: number;
        counters: {
            year: number;
            next: number;
            next_number: string;
            used: boolean;
        }[];
    }[];
    taxes: {
        id: number;
        key: string;
        kind: 'vat' | 'withholding';
        name: string;
        rate: string;
        operation_type: string | null;
        legal_mention: string | null;
        legal_mention_en: string | null;
        is_default: boolean;
        archived: boolean;
    }[];
    services: {
        id: number;
        code: string | null;
        name: string;
        description: string | null;
        unit: ServiceUnit;
        unit_price: string;
        tax_rate_id: number | null;
        category: string | null;
        archived: boolean;
    }[];
    payment_methods: {
        id: number;
        name: string;
        document_text: string | null;
        document_text_en: string | null;
        iban: string | null;
        due_days: number | null;
        is_default: boolean;
        archived: boolean;
    }[];
    document: {
        footer: string | null;
        legal_text: string | null;
        validated: boolean;
        logo: boolean;
    };
    operation_types: string[];
    units: ServiceUnit[];
    categories: string[];
};
