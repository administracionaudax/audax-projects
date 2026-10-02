/**
 * Auditoría visible (D-074): contrato con App\Http\Controllers\Admin\AuditController y
 * App\Domain\Audit\AuditEntries.
 */

export type AuditChange = {
    field: string;
    label: string;
    /** Valor anterior ya legible (null = sin valor). */
    from: string | null;
    /** Valor nuevo ya legible (null = sin valor). */
    to: string | null;
};

export type AuditEntry = {
    id: number;
    /** Instante ISO (UTC). */
    created_at: string | null;
    /** Quién hizo el cambio; null = el sistema (comandos, tareas programadas). */
    causer: { id: number; name: string } | null;
    entity: { key: string | null; label: string };
    /** Elemento al que se refiere; url solo si sigue existiendo. */
    subject: { label: string; url: string | null; deleted: boolean } | null;
    event: string;
    event_label: string;
    changes: AuditChange[];
};

export type AuditFiltersValue = {
    entidad: string | null;
    /** Id de la persona o «sistema». */
    persona: string | null;
    accion: string | null;
    /** Días de Madrid (AAAA-MM-DD). */
    desde: string | null;
    hasta: string | null;
};

export type AuditOption = { value: string; label: string };

export type AuditPageProps = {
    entries: AuditEntry[];
    pagination: { next: string | null; prev: string | null };
    filters: AuditFiltersValue;
    options: {
        entities: AuditOption[];
        actions: (AuditOption & { basic: boolean })[];
        people: { id: number; name: string; is_active: boolean }[];
    };
    exportUrl: string;
};
