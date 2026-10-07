/**
 * Utilidades puras del registro de jornada, entrega R2 (Fase 11; D-346 a D-359): textos y tonos de
 * los cierres, el tope de horas extra, el saldo de horas y las URL de los informes. Sin React, para
 * poder probarlas con Vitest (tests/js/people-register-lib.test.ts).
 */
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type {
    BalanceKind,
    CapLevel,
    CloseState,
    CloseStatus,
    HourType,
    InspectionAccessState,
    OvertimeDestination,
    ReportKind,
} from '@/types/people-register';

/** Tono de cada estado de un cierre (con texto siempre: nunca solo el color). */
export const CLOSE_TONE: Record<CloseStatus | CloseState, string> = {
    pending: 'bg-info-soft text-foreground',
    confirmed: 'bg-success-soft text-foreground',
    disagreed: 'bg-warning-soft text-foreground',
    reopened: 'bg-neutral-soft text-foreground',
    superseded: 'bg-neutral-soft text-muted-foreground',
    missing: 'text-muted-foreground',
};

export function closeStatusLabel(status: CloseStatus | CloseState): string {
    return t(`people.closes.status.${status}` as TranslationKey);
}

export function destinationLabel(
    destination: OvertimeDestination | null,
): string {
    return destination === null
        ? t('people.overtime.flex_only')
        : t(`people.overtime.destination.${destination}` as TranslationKey);
}

export function hourTypeLabel(type: HourType): string {
    return t(`people.overtime.hour_type.${type}` as TranslationKey);
}

export function balanceKindLabel(kind: BalanceKind): string {
    return t(`people.balance.kind.${kind}` as TranslationKey);
}

export function accessStateLabel(state: InspectionAccessState): string {
    return t(`people.inspection.state.${state}` as TranslationKey);
}

/** «+2:00», «-1:40», «0:00». */
export function signedMinutes(minutes: number): string {
    return minutes > 0 ? `+${formatMinutes(minutes)}` : formatMinutes(minutes);
}

/** Porcentaje del tope (0 a 100) para la barra; nunca pasa de 100. */
export function capPercent(minutes: number, cap: number): number {
    if (cap <= 0) {
        return 0;
    }

    return Math.min(100, Math.max(0, Math.round((minutes / cap) * 100)));
}

/** Nivel del tope de horas extra del año (gemelo de OvertimeService::level). */
export function capLevel(
    minutes: number,
    cap = 80 * 60,
    warning = 60 * 60,
): CapLevel {
    if (minutes >= cap) {
        return 'over';
    }

    return minutes >= warning ? 'near' : 'ok';
}

export const CAP_TONE: Record<CapLevel, string> = {
    ok: 'bg-primary',
    near: 'bg-warning',
    over: 'bg-danger',
};

/** Minutos de descanso por los minutos de horas extra compensadas (80 por hora, redondeado). */
export function restMinutes(overtime: number, perHour = 80): number {
    return Math.round((overtime * perHour) / 60);
}

/**
 * Lo que queda de la clasificación: si `overtime` pasa del exceso o es negativo, no vale.
 * Devuelve la flexibilidad (exceso − extra) o null.
 */
export function flexFor(excess: number, overtime: number): number | null {
    if (!Number.isInteger(overtime) || overtime < 0 || overtime > excess) {
        return null;
    }

    return excess - overtime;
}

/** Huella corta para la pantalla: los 12 primeros caracteres y «…». */
export function shortHash(
    hash: string | null | undefined,
    length = 12,
): string {
    if (!hash) {
        return '';
    }

    return hash.length > length ? `${hash.slice(0, length)}…` : hash;
}

/** «1,2 MB», «340 KB», «900 B». */
export function formatBytes(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toLocaleString('es-ES', { maximumFractionDigits: 1 })} MB`;
    }

    if (bytes >= 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${bytes} B`;
}

export type ReportQuery = {
    kind: ReportKind;
    monthly: boolean;
    month: string;
    from: string;
    to: string;
    userIds: number[];
    departmentId: number | null;
};

/** Query de la pantalla de informes y de su descarga (sin `informe` ni `formato`). */
export function reportQuery(
    query: ReportQuery,
): Record<string, string | string[]> {
    const params: Record<string, string | string[]> = query.monthly
        ? { mes: query.month }
        : { desde: query.from, hasta: query.to };

    if (query.userIds.length > 0) {
        params['personas[]'] = query.userIds.map(String);
    }

    if (query.departmentId !== null) {
        params.departamento = String(query.departmentId);
    }

    return params;
}

/** «/personas/informes/registro-mensual?mes=2026-09&formato=pdf». */
export function reportDownloadUrl(
    query: ReportQuery,
    format: 'pdf' | 'xlsx' | 'csv',
): string {
    const search = new URLSearchParams();

    for (const [key, value] of Object.entries(reportQuery(query))) {
        if (Array.isArray(value)) {
            value.forEach((item) => search.append(key, item));
        } else {
            search.set(key, value);
        }
    }

    search.set('formato', format);

    return `/personas/informes/${query.kind}?${search.toString()}`;
}

/** Nombre del tipo de fichero anotado (people_exports.kind). */
export function exportKindLabel(kind: string): string {
    const key = `people.exports.kinds.${kind}` as TranslationKey;
    const label = t(key);

    return label === key ? kind : label;
}
