/**
 * Contrato JSON del área de festivos y ausencias (D-049, D-050) con los controladores
 * App\Http\Controllers\Absences\* y Admin\HolidayController y App\Domain\Absences\AbsencePresenter.
 * Fechas locales "YYYY-MM-DD", instantes ISO en UTC y horas en minutos.
 */

/** App\Enums\AbsenceType */
export type AbsenceType = 'vacation' | 'sick' | 'leave' | 'training' | 'other';

/** App\Enums\AbsenceStatus */
export type AbsenceStatus = 'requested' | 'approved' | 'rejected' | 'cancelled';

export type AbsenceDepartment = { id: number; name: string; color: string };

export type AbsencePerson = {
    id: number;
    name: string;
    department: AbsenceDepartment | null;
};

/** AbsencePresenter::row */
export type AbsenceRow = {
    id: number;
    user_id: number;
    type: AbsenceType;
    status: AbsenceStatus;
    start_date: string;
    end_date: string;
    /** Minutos de una ausencia de parte del día (siempre de un solo día); null = días completos. */
    partial_minutes: number | null;
    /** Días laborables que ocupa (sin festivos ni días sin jornada); null en las parciales. */
    working_days: number | null;
    notes: string | null;
    review_comment: string | null;
    reviewed_at: string | null;
    reviewer: { id: number; name: string } | null;
    auto_approved: boolean;
    can: { cancel: boolean; review: boolean };
    /** Solo en «Ausencias del equipo». */
    user?: AbsencePerson;
};

/** Ausencia de otra persona del departamento que coincide con una solicitud pendiente. */
export type AbsenceOverlap = {
    user_name: string;
    type: AbsenceType;
    status: AbsenceStatus;
    start_date: string;
    end_date: string;
    partial_minutes: number | null;
};

export type PendingAbsence = AbsenceRow & {
    user: AbsencePerson;
    overlaps: AbsenceOverlap[];
};

/** Ausencia en el calendario del equipo (aprobada o solicitada). */
export type CalendarAbsence = {
    id: number;
    user_id: number;
    type: AbsenceType;
    status: AbsenceStatus;
    start_date: string;
    end_date: string;
    partial_minutes: number | null;
};

export type HolidayDay = { date: string; name: string };

export type TeamCalendar = {
    /** "2026-10" */
    month: string;
    current: string;
    previous: string;
    next: string;
    /** Todos los días del mes, "YYYY-MM-DD". */
    days: string[];
    people: AbsencePerson[];
    absences: CalendarAbsence[];
    holidays: HolidayDay[];
};

/** Límites de fechas que admite el servidor (AbsenceRules::YEARS_AROUND). */
export type AbsenceLimits = { from: string; to: string };

/** Props de /ausencias (MyAbsenceController::index). */
export type MyAbsencesPageProps = {
    absences: AbsenceRow[];
    types: AbsenceType[];
    today: string;
    limits: AbsenceLimits;
    self_approves: boolean;
    can: { team: boolean };
};

/** Props de /ausencias/equipo (TeamAbsenceController::index). */
export type TeamAbsencesPageProps = {
    pending: PendingAbsence[];
    upcoming: (AbsenceRow & { user: AbsencePerson })[];
    calendar: TeamCalendar;
    departments: AbsenceDepartment[];
    filters: { department: number | null };
    register_people: { id: number; name: string }[];
    types: AbsenceType[];
    today: string;
    limits: AbsenceLimits;
    pending_limit: number;
};

/** Ausencia resumida de la tarjeta «Mis ausencias» de Inicio (MyAbsencesSummary). */
export type AbsenceSummaryItem = {
    id: number;
    type: AbsenceType;
    status: AbsenceStatus;
    start_date: string;
    end_date: string;
    partial_minutes: number | null;
};

export type MyAbsencesSummary = {
    upcoming: AbsenceSummaryItem[];
    pending: AbsenceSummaryItem[];
};

export type Holiday = { id: number; date: string; name: string };

/** Props de /admin/festivos (HolidayController::index). */
export type HolidaysPageProps = {
    year: number;
    current_year: number;
    holidays: Holiday[];
    national: (HolidayDay & { exists: boolean })[];
    limits: {
        min_year: number;
        max_year: number;
        max_rows: number;
        max_kilobytes: number;
    };
};

export type HolidayImportStatus = 'new' | 'existing' | 'duplicate' | 'error';

/** Flash `holiday_import` de la vista previa (HolidayImporter::preview). */
export type HolidayImportPreview = {
    file_name: string;
    format: 'ics' | 'csv';
    rows: {
        line: number;
        date: string | null;
        name: string | null;
        status: HolidayImportStatus;
        message: string | null;
    }[];
    counts: Record<HolidayImportStatus, number>;
};
