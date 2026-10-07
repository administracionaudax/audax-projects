/**
 * Privacidad y RGPD (D-075): contrato con App\Http\Controllers\Privacy\* y
 * App\Http\Controllers\Admin\PrivacySettingsController.
 */

/** Tipos de dato con plazo de conservación (App\Domain\Privacy\RetentionPolicy). */
export type RetentionType =
    | 'login_events'
    | 'read_notifications'
    | 'activity_log'
    | 'chat_messages'
    | 'weekly_reminder_logs'
    | 'dictations'
    | 'ai_usage'
    | 'day_plans'
    | 'people_register';

/** Plazo vigente de un tipo de dato, en meses (null = sin límite). */
export type RetentionPeriod = {
    type: RetentionType;
    months: number | null;
};

export type PrivacyNoticeData = {
    /** Markdown tal cual se guardó: se pinta SIEMPRE saneado (SafeMarkdown). */
    markdown: string;
    version: number;
    /** Sigue siendo el borrador por defecto, pendiente de asesor. */
    is_draft: boolean;
};

/** /privacidad (privacy/show). */
export type PrivacyShowProps = {
    notice: PrivacyNoticeData;
    acknowledgement: {
        needed: boolean;
        /** Última versión leída (null si ninguna). */
        version: number | null;
        /** Instante ISO (UTC) de la última lectura. */
        at: string | null;
    };
    retention: RetentionPeriod[];
    /** Días que se puede descargar una exportación de datos personales. */
    exportDays: number;
};

/** Un campo de retención del formulario de /admin/privacidad. */
export type RetentionField = {
    type: RetentionType;
    /** Clave del ajuste (retention_*_months). */
    key: string;
    min: number;
    max: number;
    unlimited_allowed: boolean;
};

export type PrivacySettingsValues = Record<string, number | null> & {
    personal_data_export_days: number;
    disk_warning_percent: number;
    attachments_warning_gb: number | null;
};

type Range = { min: number; max: number };

/** /admin/privacidad (admin/privacy). */
export type AdminPrivacyProps = {
    notice: PrivacyNoticeData & { max_length: number };
    /** Personas internas activas que han leído la versión vigente. */
    readers: { read: number; total: number };
    settings: PrivacySettingsValues;
    retention: RetentionField[];
    limits: {
        export_days: Range;
        disk_percent: Range;
        attachments_gb: Range;
    };
};

export type PersonalDataExportStatus =
    | 'pending'
    | 'processing'
    | 'ready'
    | 'failed'
    | 'expired';

/** App\Domain\Privacy\PersonalDataExportList. Instantes ISO en UTC. */
export type PersonalDataExportRow = {
    id: number;
    status: PersonalDataExportStatus;
    status_label: string;
    /** La pidió la propia persona (si no, el admin). */
    requested_by_subject: boolean;
    requester: string | null;
    created_at: string | null;
    finished_at: string | null;
    expires_at: string | null;
    downloaded_at: string | null;
    size_bytes: number | null;
    /** URL firmada y relativa, solo si está lista y sin caducar. */
    download_url: string | null;
};

/** /ajustes/mis-datos (settings/my-data). */
export type MyDataProps = {
    exports: PersonalDataExportRow[];
    /** No hay ninguna en curso. */
    canRequest: boolean;
    exportDays: number;
};
