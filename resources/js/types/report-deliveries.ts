/**
 * Envío de informes por correo y envíos programados (Fase 9, D-141): contrato con
 * App\Http\Resources\Reports\ReportScheduleData. Instantes en ISO 8601 (UTC), que se muestran en
 * Europe/Madrid; la fecha y la hora de «una vez» ya vienen en hora de Madrid.
 */
import type { ReportRequestData } from '@/types/reports';

export type DeliveryFormat = 'pdf' | 'xlsx';

/** Versión de un informe exportado (D-240): la interna y completa o la del cliente. */
export type ReportVersion = 'interno' | 'cliente';

export type RelativePeriod = 'fixed' | 'current' | 'previous';

export type ScheduleFrequency = 'once' | 'weekly' | 'monthly';

export type DeliveryStatus = 'queued' | 'sent' | 'failed' | 'skipped';

export type PauseReason =
    | 'manual'
    | 'owner_inactive'
    | 'no_access'
    | 'no_recipients';

/** GET /informes/envios/personas: quien puede recibir informes. */
export type DeliveryPerson = { id: number; name: string };

export type ReportScheduleRow = {
    id: number;
    title: string;
    kind: string;
    /** Versión del informe (D-240); null en los informes que no la tienen. */
    version: ReportVersion | null;
    owner: { id: number; name: string };
    formats: DeliveryFormat[];
    relative_period: RelativePeriod;
    frequency: ScheduleFrequency;
    /** «Una vez»: fecha local YYYY-MM-DD. */
    run_date: string | null;
    /** 1 = lunes … 7 = domingo. */
    weekday: number | null;
    /** 1-28, o 0 = el último día. */
    month_day: number | null;
    /** HH:MM, hora de Madrid. */
    time: string;
    recipient_count: number;
    external_count: number;
    is_active: boolean;
    paused_reason: PauseReason | null;
    paused_reason_label: string | null;
    next_run_at: string | null;
    last_run_at: string | null;
    last_status: DeliveryStatus | null;
};

export type ReportDeliveryItem = {
    id: number;
    created_at: string | null;
    sent_at: string | null;
    status: DeliveryStatus;
    error: string | null;
    formats: DeliveryFormat[];
    recipient_count: number;
    external_count: number;
    recipient_emails: string[];
};

export type ReportScheduleDetail = ReportScheduleRow & {
    request: ReportRequestData;
    recipient_user_ids: number[];
    recipient_users: DeliveryPerson[];
    recipient_emails: string[];
    subject: string | null;
    message: string | null;
    deliveries: ReportDeliveryItem[];
};

/** Informes que se pueden programar desde /informes/envios. */
export type NewReportOption = ReportRequestData & { title: string };

export type ReportSchedulesIndexProps = {
    schedules: ReportScheduleRow[];
    sees_all: boolean;
    new_reports: NewReportOption[];
};

export type ReportScheduleShowProps = {
    schedule: ReportScheduleDetail;
};
