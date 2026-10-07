/**
 * Contrato JSON de vacaciones y permisos (Fase 11, R3; D-360 a D-379) con App\Domain\Absences\LeavePresenter
 * y los controladores App\Http\Controllers\Leave\*. Las cantidades van en la unidad del tipo:
 * centésimas de día (2200 = 22 días) o minutos. Fechas locales "YYYY-MM-DD" e instantes ISO en UTC.
 */
import type {
    AbsenceDepartment,
    AbsenceType,
} from '@/components/absences/types';

/** App\Enums\LeaveUnit */
export type LeaveUnit = 'working_days' | 'calendar_days' | 'hours';

/** LeavePresenter::type */
export type LeaveTypeOption = {
    id: number;
    key: string;
    name: string;
    category: AbsenceType;
    unit: LeaveUnit;
    paid: boolean;
    requires_document: boolean;
    notice_days: number | null;
    health_data: boolean;
    default_amount: number | null;
    travel_extra: number | null;
    annual_allowance: number | null;
    allowance_in_days: boolean;
    allow_without_balance: boolean;
    second_approval: boolean;
    respects_blocked_days: boolean;
    description: string | null;
    legal_basis: string | null;
    advisor_pending: boolean;
    active: boolean;
};

/** LeavePresenter::balances */
export type LeaveBalance = {
    type: {
        id: number;
        name: string;
        unit: LeaveUnit;
        allow_without_balance: boolean;
    };
    year: number;
    entitled: number;
    adjusted: number;
    total: number;
    /** Aprobado (disfrutado o por disfrutar). */
    used: number;
    /** Aprobado y ya disfrutado (hasta hoy). */
    taken: number;
    pending: number;
    available: number;
    carried: {
        year: number;
        remaining: number;
        expires_on: string | null;
        expired: boolean;
    }[];
    expired: number;
    expiring: { amount: number; expires_on: string }[];
};

export type LeaveWarning = { code: string; message: string };

export type AbsenceDocumentRow = {
    id: number;
    name: string;
    size: number;
    created_at: string | null;
    can_delete: boolean;
};

/** Lo que R3 añade a cada ausencia (LeavePresenter::forAbsences); no llega con el módulo apagado. */
export type AbsenceLeave = {
    type: LeaveTypeOption | null;
    start_time: string | null;
    end_time: string | null;
    cost: number;
    documents_count: number;
    /** null si quien mira no puede ver los justificantes (p. ej. el responsable en un tipo de salud). */
    documents: AbsenceDocumentRow[] | null;
    warnings: LeaveWarning[];
    first_approval: { by: string | null; at: string } | null;
    awaiting_second: boolean;
    cancellation: {
        status: 'requested' | 'accepted' | 'rejected';
        reason: string | null;
        requested_at: string | null;
        comment: string | null;
        decided_at: string | null;
    } | null;
    can: {
        upload: boolean;
        request_cancellation: boolean;
        decide_cancellation: boolean;
    };
};

/** La prop `leave` de /ausencias (MyAbsenceController::leaveProps); null con el módulo apagado. */
export type MyLeaveProps = {
    types: LeaveTypeOption[];
    year: number;
    balances: LeaveBalance[];
    next_balances: LeaveBalance[];
    documents: {
        max_kilobytes: number;
        max_per_absence: number;
        accept: string;
    };
};

/** Respuesta de POST /ausencias/simular. */
export type LeaveSimulation = {
    cost: { amount: number; unit: LeaveUnit; label: string } | null;
    balance: {
        available: number;
        after: number;
        available_label: string;
        after_label: string;
    } | null;
    warnings: LeaveWarning[];
    errors: Record<string, string[]>;
};

export type LeaveLot = {
    id: number;
    year: number;
    kind: LeaveMovementKind;
    amount: number;
    used: number;
    reserved: number;
    remaining: number;
    valid_from: string;
    expires_on: string | null;
};

/** App\Enums\LeaveMovementKind */
export type LeaveMovementKind =
    | 'accrual'
    | 'adjustment'
    | 'opening_balance'
    | 'carry_over';

export type LeaveMovementRow = {
    id: number;
    type: { id: number; name: string; unit: LeaveUnit };
    year: number;
    kind: LeaveMovementKind;
    amount: number;
    valid_from: string;
    expires_on: string | null;
    reason: string;
    author: string | null;
    created_at: string | null;
};

/** Props de /ausencias/saldos (LeaveBalanceController::index). */
export type LeaveBalancesPageProps = {
    year: number;
    current_year: number;
    types: LeaveTypeOption[];
    people: {
        id: number;
        name: string;
        department: AbsenceDepartment | null;
        balances: LeaveBalance[];
    }[];
    departments: AbsenceDepartment[];
    filters: { department: number | null; person: number | null };
    detail: {
        person: { id: number; name: string };
        lots: { type_id: number; lots: LeaveLot[] }[];
        movements: LeaveMovementRow[];
    } | null;
    can: { manage: boolean };
    starts_on: string | null;
    today: string;
};

export type LeaveTypeAdminRow = LeaveTypeOption & {
    carry_over_until: string | null;
    advisor_note: string | null;
    legacy: boolean;
    sort: number;
};

/** Props de /ausencias/tipos (LeaveTypeController::index). */
export type LeaveTypesPageProps = {
    types: LeaveTypeAdminRow[];
    categories: AbsenceType[];
    units: LeaveUnit[];
};

/** App\Enums\HolidayLevel */
export type HolidayLevel = 'national' | 'regional' | 'local' | 'company';

/** App\Enums\LeaveCalendarDayKind */
export type CalendarDayKind = 'half_day' | 'blocked';

/** Props de /ausencias/calendario (LeaveCalendarController::index). */
export type LeaveCalendarPageProps = {
    year: number;
    current_year: number;
    today: string;
    work_center: string;
    holidays: {
        date: string;
        name: string;
        level: HolidayLevel | null;
        source: string | null;
    }[];
    special_days: {
        id: number;
        kind: CalendarDayKind;
        name: string;
        start_date: string;
        end_date: string;
    }[];
    absences: {
        id: number;
        name: string;
        status: 'requested' | 'approved';
        start_date: string;
        end_date: string;
        partial_minutes: number | null;
    }[];
    can: { manage: boolean; holidays: boolean };
};
