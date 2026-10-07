/**
 * Registro de jornada (Fase 11, R1; docs/PLAN-FASE-11.md). Contrato con App\Domain\People
 * (WorkdayCalculator, WorkdayPresenter y TeamWorkday) y con la prop compartida `people`
 * (HandleInertiaRequests::people).
 */

export type ClockKind = 'clock_in' | 'pause_start' | 'pause_end' | 'clock_out';

export type ClockEventKind = ClockKind | 'void';

export type WorkMode = 'on_site' | 'remote';

export type ClockStatus = 'off' | 'working' | 'paused' | 'closed';

/** Estado del registro de quien ficha (el botón de la cabecera). */
export type ClockShared = {
    status: ClockStatus;
    /** Desde cuándo está en el estado actual (instante UTC). */
    since: string | null;
    /** Inicio del tramo de trabajo en curso, si está trabajando. */
    running_since: string | null;
    /** Segundos trabajados hoy sin el tramo en curso. */
    worked_seconds: number;
    /** Modo del tramo en curso o el último usado. */
    work_mode: WorkMode | null;
    /** Día que se quedó sin salida (ya no está en curso), o null. */
    unclosed_date: string | null;
    /** Hora del servidor al pintar la página: corrige el reloj del dispositivo. */
    server_now: string;
};

export type PeopleShared = {
    /** null si no está sujeto al registro (no ficha). */
    clock: ClockShared | null;
    /** Correcciones que esperan su decisión. */
    pending: number;
    /** R2: el resumen del mes que espera mi respuesta (D-347). */
    pending_close?: { id: number; month: string } | null;
    /** R2: documentos de RR. HH. sin leer (D-354). */
    unread_documents?: number;
};

export type WorkdayIncident =
    | 'missing_clock_out'
    | 'no_records'
    | 'during_absence'
    | 'short_day'
    | 'short_rest'
    | 'long_stretch'
    | 'over_nine_hours'
    | 'pause_open';

export type DayStatus =
    | 'pending'
    | 'disputed'
    | 'incident'
    | 'warning'
    | 'in_progress'
    | 'future'
    | 'off'
    | 'today'
    | 'ok';

export type DayEvent = {
    id: number;
    kind: ClockEventKind;
    at: string;
    work_mode: WorkMode | null;
    pause_type: 'meal' | null;
    source: 'web' | 'pwa' | 'correction';
    correction_id: number | null;
};

export type DaySegment = {
    kind: 'work' | 'pause';
    from: string;
    to: string | null;
    work_mode: WorkMode | null;
};

export type DayWorkday = {
    clock_in: string;
    clock_out: string | null;
    open: boolean;
    stale: boolean;
    segments: DaySegment[];
};

export type WorkdayDay = {
    date: string;
    weekday: number;
    expected_minutes: number;
    capacity_minutes: number;
    base_minutes: number;
    holiday: string | null;
    /** El tipo solo llega a quien puede verlo (D-088). */
    absence: { type: string | null; partial_minutes: number | null } | null;
    employed: boolean;
    registered: boolean;
    schedule: {
        start_from: string | null;
        start_to: string | null;
        expected_pause_minutes: number;
    } | null;
    workdays: DayWorkday[];
    events: DayEvent[];
    worked_minutes: number;
    pause_minutes: number;
    difference_minutes: number | null;
    excess_minutes: number;
    modes: WorkMode[];
    incidents: WorkdayIncident[];
    in_progress: boolean;
    status: DayStatus;
    pending_corrections: number;
    disputed_corrections: number;
};

export type WorkdayTotals = {
    worked_minutes: number;
    expected_minutes: number;
    difference_minutes: number;
    excess_minutes: number;
    incident_days: number;
    pending_days: number;
    days_worked: number;
};

export type HistoryRow = DayEvent & {
    seq: number;
    recorded_at: string;
    author: string | null;
    voided_event_id: number | null;
    voided: boolean;
    voided_by_correction_id: number | null;
    voided_at: string | null;
    hash: string;
};

export type CorrectionStatus =
    | 'pending'
    | 'accepted'
    | 'disputed'
    | 'withdrawn';

export type Correction = {
    id: number;
    user: { id: number; name: string };
    date: string;
    status: CorrectionStatus;
    reason: string;
    proposed_by: { id: number; name: string };
    proposed_by_subject: boolean;
    proposed_at: string;
    voids: DayEvent[];
    adds: { kind: ClockKind; at: string; work_mode: WorkMode | null }[];
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    dispute_reason: 'rejected' | 'no_answer' | null;
    expires_at: string | null;
    can: { decide: boolean; withdraw: boolean };
};

export type DayDetail = {
    date: string;
    day: WorkdayDay;
    history: HistoryRow[];
    corrections: Correction[];
    can: { propose: boolean };
    /** Lo imputado ese día: solo a la propia persona (PLAN-FASE-11 §3.2.5). */
    logged_minutes: number | null;
};

export type WorkdayPageProps = {
    subject: {
        id: number;
        name: string;
        avatar: string | null;
        is_me: boolean;
    };
    month: string;
    today: string;
    days: WorkdayDay[];
    totals: WorkdayTotals;
    week: WorkdayTotals;
    today_day: WorkdayDay | null;
    detail: DayDetail | null;
    awaiting_me: Correction[];
    can: { propose: boolean };
};

export type TeamNowState =
    | 'working'
    | 'paused'
    | 'closed'
    | 'not_clocked'
    | 'absent'
    | 'not_working';

export type TeamDayCell = {
    date: string;
    worked_minutes: number;
    expected_minutes: number;
    difference_minutes: number | null;
    status: DayStatus;
    incidents: WorkdayIncident[];
    holiday: boolean;
    absence: boolean;
    in_progress: boolean;
};

export type TeamPerson = {
    id: number;
    name: string;
    avatar: string | null;
    department: { id: number; name: string; color: string } | null;
    now: { state: TeamNowState; since: string | null } | null;
    days: TeamDayCell[];
    totals: WorkdayTotals;
};

export type TeamWorkdayPageProps = {
    week: string;
    previous_week: string;
    next_week: string | null;
    dates: string[];
    today: string;
    /** Las personas (no `people`: es la prop compartida del registro). */
    members: TeamPerson[];
    departments: { id: number; name: string; color: string }[];
    department_id: number | null;
    pending: number;
};

export type PendingPageProps = {
    corrections: Correction[];
    manages_all: boolean;
};

/** Prop flash `clock_summary` al fichar la salida (§3.2.5). */
export type ClockSummary = {
    date: string;
    worked_minutes: number;
    logged_minutes: number;
};

/** Una fila del formulario de corrección. */
export type CorrectionRow = {
    key: string;
    id: number | null;
    kind: ClockKind;
    time: string;
    next_day: boolean;
    work_mode: WorkMode | null;
};
