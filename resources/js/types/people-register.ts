/**
 * Registro de jornada, entrega R2 (Fase 11; D-346 a D-359): cierres mensuales, horas extra, saldo de
 * horas, informes, Inspección y documentos de RR. HH. Contrato con los controladores de
 * App\Http\Controllers\People (RegisterController, MonthCloseController, OvertimeController,
 * RegisterReportController, InspectionController y PeopleDocumentController) y con
 * InspectionPortalController.
 */
import type { WorkdayIncident, WorkMode } from '@/types/people';

export type CloseStatus =
    | 'pending'
    | 'confirmed'
    | 'disagreed'
    | 'reopened'
    | 'superseded';

export type CloseTotals = {
    worked_minutes: number;
    expected_minutes: number;
    difference_minutes: number;
    excess_minutes: number;
    overtime_minutes: number;
    overtime_compensate_minutes: number;
    overtime_pay_minutes: number;
    complementary_minutes: number;
    flex_minutes: number;
    unclassified_minutes: number;
    days_worked: number;
    incident_days: number;
    onsite_days: number;
    remote_days: number;
    absence_days: number;
    pending_correction_days: number;
    disputed_correction_days: number;
    part_time?: boolean;
};

export type MonthClose = {
    id: number;
    user_id: number;
    /** «2026-09». */
    month: string;
    version: number;
    status: CloseStatus;
    worked_minutes: number;
    expected_minutes: number;
    difference_minutes: number;
    overtime_minutes: number;
    totals: CloseTotals;
    generated_at: string;
    confirmed_at: string | null;
    disagreed_at: string | null;
    disagreement_note: string | null;
    reopened_at: string | null;
    reopened_by: string | null;
    reopen_reason: string | null;
    pdf_sha256: string;
    content_hash: string;
    register_seq: number | null;
    can: { confirm: boolean; disagree: boolean };
};

export type OvertimeDestination = 'compensate' | 'pay';

export type HourType = 'overtime' | 'complementary';

export type OvertimeDecision = {
    id: number;
    user_id: number;
    date: string;
    hour_type: HourType;
    excess_minutes: number;
    overtime_minutes: number;
    flex_minutes: number;
    destination: OvertimeDestination | null;
    note: string | null;
    decided_by: string;
    decided_at: string | null;
    rest_minutes: number;
    user?: { id: number; name: string };
};

export type CapLevel = 'ok' | 'near' | 'over';

export type YearSummary = {
    year: number;
    overtime_minutes: number;
    compensate_minutes: number;
    pay_minutes: number;
    complementary_minutes: number;
    cap_minutes: number;
    remaining_minutes: number;
    level: CapLevel;
};

export type BalanceKind =
    | 'overtime'
    | 'rest_taken'
    | 'paid'
    | 'adjustment'
    | 'opening_balance';

export type BalanceMovement = {
    id: number;
    date: string;
    kind: BalanceKind;
    minutes: number;
    reason: string;
    author: string | null;
    created_at: string | null;
};

export type PendingCompensation = {
    date: string;
    minutes: number;
    remaining_minutes: number;
    deadline: string;
    expired: boolean;
    kind: BalanceKind;
};

export type Balance = {
    minutes: number;
    pending: PendingCompensation[];
    movements: BalanceMovement[];
};

export type RegisterPageProps = {
    subject: { id: number; name: string };
    today: string;
    register_start: string | null;
    period: { from: string; to: string };
    max_days: number;
    closes: MonthClose[];
    overtime: YearSummary;
    decisions: OvertimeDecision[];
    balance: Balance;
    subject_to_register: boolean;
};

export type CloseState =
    | 'missing'
    | 'pending'
    | 'confirmed'
    | 'disagreed'
    | 'reopened';

export type CloseRow = {
    user: { id: number; name: string; department: string | null };
    state: CloseState;
    close: MonthClose | null;
    versions: number;
    can: { generate: boolean; reopen: boolean; remind: boolean };
};

export type ClosesPageProps = {
    month: string;
    current_month: string;
    rows: CloseRow[];
    counts: Record<CloseState, number>;
    departments: { id: number; name: string }[];
    department_id: number | null;
    manages_all: boolean;
};

export type PendingOvertime = {
    user: { id: number; name: string };
    date: string;
    excess_minutes: number;
    worked_minutes: number;
    expected_minutes: number;
    stale: boolean;
    previous: OvertimeDecision | null;
    part_time: boolean;
    month_confirmed: boolean;
    rest_preview: number;
};

export type OvertimePerson = {
    user: { id: number; name: string };
    part_time: boolean;
    year: YearSummary;
    balance_minutes: number;
    expiring: number;
};

export type OvertimePageProps = {
    month: string;
    current_month: string;
    pending: PendingOvertime[];
    decided: OvertimeDecision[];
    people: OvertimePerson[];
    person: {
        user: { id: number; name: string };
        balance_minutes: number;
        pending: PendingCompensation[];
        movements: BalanceMovement[];
    } | null;
    manages_all: boolean;
    rest_minutes_per_hour: number;
    cap_minutes: number;
};

export type ReportKind =
    | 'registro-mensual'
    | 'anexo-horas'
    | 'presencia-diaria'
    | 'presencia-mensual'
    | 'fichajes'
    | 'incidencias'
    | 'saldos'
    | 'actividad'
    | 'justificantes-pendientes';

export type RegisterExport = {
    id: number;
    kind: string;
    format: string;
    filename: string;
    sha256: string;
    content_hash: string | null;
    size: number;
    by: string | null;
    created_at: string | null;
};

export type ReportsPageProps = {
    kinds: { value: ReportKind; title: string; monthly: boolean }[];
    kind: ReportKind;
    filters: {
        month: string;
        from: string;
        to: string;
        user_ids: number[];
        department_id: number | null;
    };
    today: string;
    people: { id: number; name: string; active: boolean }[];
    departments: { id: number; name: string }[];
    preview: {
        title: string;
        headers: string[];
        rows: (string | number | boolean | null)[][];
        total: number;
        content_hash: string;
    };
    exports: RegisterExport[];
};

export type InspectionAccessState =
    | 'active'
    | 'scheduled'
    | 'expired'
    | 'revoked'
    | 'locked';

export type InspectionAccess = {
    id: number;
    name: string;
    email: string;
    reference: string | null;
    scope_user_ids: number[] | null;
    scope_from: string;
    scope_to: string;
    valid_from: string;
    valid_until: string;
    state: InspectionAccessState;
    created_by: string;
    created_at: string | null;
    revoked_by: string | null;
    revoked_at: string | null;
    last_used_at: string | null;
    failed_attempts: number;
};

export type RegisterAnchor = {
    date: string;
    digest: string;
    events_count: number;
    verified_ok: boolean;
    problems: string[];
};

export type InspectionPageProps = {
    enabled: boolean;
    today: string;
    period: { from: string; to: string };
    max_days: number;
    max_access_days: number;
    people: { id: number; name: string; active: boolean }[];
    accesses: InspectionAccess[];
    created: { id: number; url: string; code: string } | null;
    anchors: RegisterAnchor[];
    check: {
        ok: boolean;
        events: number;
        corrections: number;
        problems: string[];
        at: string;
    } | null;
    verification: {
        name: string;
        sha256: string;
        match: RegisterExport | null;
        close: { user: string; month: string; version: number } | null;
    } | null;
    exports: RegisterExport[];
};

export type PeopleDocumentKey = 'register_protocol' | 'disconnection_policy';

export type PeopleDocumentData = {
    id: number;
    key: PeopleDocumentKey;
    version: number;
    title: string;
    body: string;
    is_draft: boolean;
    published_at: string | null;
    published_by: string | null;
    read_at: string | null;
    readers: { id: number; name: string; read_at: string | null }[] | null;
};

export type DocumentsPageProps = {
    documents: PeopleDocumentData[];
    can_publish: boolean;
    max_length: number;
};

export type InspectionPortalAccess = {
    name: string;
    reference: string | null;
    valid_until: string;
};

export type InspectionLine = {
    date: string;
    expected_minutes: number;
    worked_minutes: number;
    pause_minutes: number;
    first_in: string | null;
    last_out: string | null;
    segments: string;
    overtime_minutes: number;
    modes: WorkMode[];
    incidents: WorkdayIncident[];
    holiday: string | null;
    absence: boolean;
    registered: boolean;
};
