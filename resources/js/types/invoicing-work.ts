/**
 * Emisión propia, entrega E3 (PLAN-EMISION §3.1 y §3.8): recurrentes y «Crear borrador» desde el
 * trabajo. Contrato con App\Domain\Billing\Recurring\RecurringPresenter y
 * App\Domain\Billing\Work\UnbilledWork.
 */
import type { ServiceUnit } from './invoicing';

export type RecurringPeriodicity =
    | 'monthly'
    | 'bimonthly'
    | 'quarterly'
    | 'semiannual'
    | 'annual'
    | 'custom';

export type RecurringState = 'active' | 'paused' | 'finished';

export type RecurringDocumentRef = {
    id: number;
    view_id: number;
    number: string | null;
    status: 'draft' | 'issued' | 'cancelled' | 'voided';
    issue_date: string;
    url: string;
};

/** RecurringPresenter::row (más la última factura, en el listado). */
export type RecurringRow = {
    id: number;
    url: string;
    name: string;
    client: { id: number; name: string };
    project: { id: number; code: string; name: string } | null;
    periodicity: RecurringPeriodicity;
    interval_months: number;
    day_of_month: number;
    next_run_on: string | null;
    subtotal: string;
    mode: 'draft' | 'issue';
    auto_send: boolean;
    state: RecurringState;
    from_holded: boolean;
    reviewed: boolean;
    occurrences_count: number;
    max_occurrences: number | null;
    end_date: string | null;
    last_error: string | null;
    last_document?: RecurringDocumentRef | null;
};

/** RecurringPresenter::detail. */
export type RecurringDetail = RecurringRow & {
    bank: { id: number; name: string } | null;
    start_date: string;
    series: { id: number; code: string; name: string; test: boolean } | null;
    payment_method: string | null;
    due_days: number | null;
    body: string | null;
    internal_note: string | null;
    customer_reference: string | null;
    issue_as: string | null;
    paused: { at: string; by: string | null } | null;
    reviewed_by: { at: string | null; by: string } | null;
    last_error_at: string | null;
    lines: {
        id: number;
        kind: 'item' | 'text';
        service: { id: number; code: string | null; name: string } | null;
        description: string | null;
        quantity: string;
        unit: ServiceUnit;
        unit_price: string;
        discount_pct: string;
        tax: string | null;
    }[];
    upcoming: { date: string; skipped: boolean }[];
    runs: {
        id: number;
        period_on: string;
        status: 'generated' | 'skipped';
        trigger: 'schedule' | 'manual';
        error: string | null;
        created_by: string | null;
        created_at: string | null;
        document: (RecurringDocumentRef & { total: string }) | null;
    }[];
};

/** RecurringPresenter::form. */
export type RecurringForm = {
    id: number | null;
    name: string;
    client_id: number | null;
    project_id: number | null;
    hour_bank_id: number | null;
    periodicity: RecurringPeriodicity;
    interval_months: number;
    day_of_month: number;
    start_date: string | null;
    end: 'never' | 'date' | 'count';
    end_date: string | null;
    max_occurrences: number | null;
    mode: 'draft' | 'issue';
    auto_send: boolean;
    series_id: number | null;
    payment_method_id: number | null;
    due_days: number | null;
    withholding_rate_id: number | null;
    body: string | null;
    internal_note: string | null;
    customer_reference: string | null;
    lines: {
        id: number | null;
        kind: 'item' | 'text';
        service_id: number | null;
        description: string;
        quantity: string;
        unit: ServiceUnit;
        unit_price: string;
        discount_pct: string;
        tax_rate_id: number | null;
    }[];
};

export type WorkGrouping = 'task' | 'person' | 'type' | 'project' | 'entry';

export type WorkProjectRef = { id: number; code: string; name: string };

/** UnbilledWork::forClient: lo que un cliente tiene por facturar, con la línea que daría. */
export type UnbilledWork = {
    client: { id: number; name: string };
    from: string;
    to: string;
    grouping: WorkGrouping;
    hours: {
        project: WorkProjectRef;
        minutes: number;
        amount: string;
        pending_minutes: number;
        drafts: { id: number; minutes: number }[];
        lines: {
            key: string;
            label: string;
            description: string;
            minutes: number;
            rate: string | null;
            amount: string;
            entry_ids: number[];
            service_id: number | null;
        }[];
    }[];
    banks: {
        bank: { id: number; name: string };
        project: WorkProjectRef;
        minutes: number;
        price: string;
        start_date: string;
        previous: { id: number; name: string } | null;
        line: {
            quantity: string;
            unit: ServiceUnit;
            unit_price: string;
            discount_pct: string;
            description: string;
            service_id: number | null;
        };
    }[];
    overage: {
        bank: { id: number; name: string };
        project: WorkProjectRef;
        next_bank: { id: number; name: string } | null;
        minutes: number;
        amount: string;
        lines: {
            rate: string | null;
            minutes: number;
            amount: string;
            entry_ids: number[];
        }[];
    }[];
    fees: {
        project: WorkProjectRef;
        month: string;
        amount: string;
        description: string;
    }[];
    fixed: {
        project: WorkProjectRef;
        price: string;
        invoiced: string;
        pending: string;
        description: string;
    }[];
};
