/**
 * Textos de la vista «Carga» que se componen con los datos (motivos de los días grises, capacidad
 * reducida, periodos y el nombre accesible de cada celda). El servidor solo manda datos y nombres
 * propios (festivo, tipo de ausencia): la frase se escribe aquí (lang/ui/workload.json).
 */
import { LOAD_LEVELS, loadLevel } from '@/components/charts/thresholds';
import type {
    WorkloadColumn,
    WorkloadHorizonKey,
    WorkloadReason,
    WorkloadReduced,
    WorkloadTotals,
} from '@/components/workload/types';
import {
    dayMonthLabel,
    weekdayLongLabel,
    weekdayShortLabel,
} from '@/components/time/week-days';
import { formatDate, formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';

export const HORIZON_KEYS: WorkloadHorizonKey[] = [
    'semana-actual',
    'semana-que-viene',
    '4-semanas',
    '3-meses',
];

/** Primera letra en mayúscula (y el resto igual): «semana del 12/10 al 18/10» → «Semana del…». */
export function upperFirst(text: string): string {
    return text.charAt(0).toLocaleUpperCase('es-ES') + text.slice(1);
}

export function horizonLabel(key: WorkloadHorizonKey): string {
    return t(`workload_horizon.${key}` as TranslationKey);
}

/** Texto corto para la celda gris: «Festivo», «Vacaciones», «No laborable»… */
export function reasonShort(reason: WorkloadReason): string {
    switch (reason.type) {
        case 'holiday':
            return t('workload_reason.holiday');
        case 'absence':
            return reason.label ?? t('workload_reason.absence');
        case 'off':
            return t('workload_reason.off');
        default:
            return t('workload_reason.mixed');
    }
}

/** Texto completo del motivo: «Festivo: Fiesta Nacional de España». */
export function reasonLong(reason: WorkloadReason): string {
    if (reason.type === 'holiday' && reason.label) {
        return t('workload_reason.holiday_named', { name: reason.label });
    }

    return reasonShort(reason);
}

/** «Capacidad reducida: festivos: 1 · ausencia parcial: 4:00 (Formación externa)». */
export function reducedLong(reduced: WorkloadReduced): string {
    const parts: string[] = [];

    if (reduced.holidays > 0) {
        parts.push(t('workload_reduced.holidays', { count: reduced.holidays }));
    }

    if (reduced.absence_days > 0) {
        parts.push(
            t('workload_reduced.absence_days', { count: reduced.absence_days }),
        );
    }

    if (reduced.partial_minutes > 0) {
        parts.push(
            t('workload_reduced.partial', {
                minutes: formatMinutes(reduced.partial_minutes),
            }),
        );
    }

    const detail = parts.join(' · ');

    return reduced.absence_label
        ? t('workload_reduced.summary_typed', {
              detail,
              type: reduced.absence_label,
          })
        : t('workload_reduced.summary', { detail });
}

/** Días: «martes 13/10/2026»; semanas: «semana del 12/10 al 18/10/2026». */
export function periodLabel(
    column: Pick<WorkloadColumn, 'from' | 'to'>,
    byWeek: boolean,
): string {
    if (!byWeek && column.from === column.to) {
        return `${weekdayLongLabel(column.from)} ${formatDate(column.from)}`;
    }

    return t('workload_period.week', {
        from: dayMonthLabel(column.from),
        to: formatDate(column.to),
    });
}

/** Cabecera de columna en dos líneas: «mar.» / «13/10» o «Semana» / «12/10». */
export function columnHeading(
    column: WorkloadColumn,
    byWeek: boolean,
): { top: string; bottom: string } {
    if (byWeek) {
        return {
            top: t('workload_matrix.week_short'),
            bottom: dayMonthLabel(column.from),
        };
    }

    return {
        top: weekdayShortLabel(column.from),
        bottom: dayMonthLabel(column.from),
    };
}

/** «10:00 planificadas de 8:00 de capacidad (125 %): Sobrecarga». */
export function loadSummary({ planned, capacity }: WorkloadTotals): string {
    const level = loadLevel(planned, capacity);

    if (level === 'none') {
        return t('workload_cell.no_capacity', {
            planned: formatMinutes(planned),
        });
    }

    return t('workload_cell.summary', {
        planned: formatMinutes(planned),
        capacity: formatMinutes(capacity),
        percent: formatPercent(planned / capacity, 0),
        level: LOAD_LEVELS[level].label,
    });
}

/**
 * Nombre accesible de una celda de la matriz: quién, cuándo, cifras, nivel, motivo del gris,
 * capacidad reducida y si lleva tareas vencidas.
 */
export function cellAccessibleLabel(
    person: string,
    period: string,
    cell: WorkloadTotals & {
        reason: WorkloadReason | null;
        reduced: WorkloadReduced | null;
        overdue: boolean;
    },
): string {
    const parts = [
        t('workload_cell.who_when', { person, period }),
        loadSummary(cell),
    ];

    if (cell.reason) {
        parts.push(reasonLong(cell.reason));
    }

    if (cell.reduced) {
        parts.push(reducedLong(cell.reduced));
    }

    if (cell.overdue) {
        parts.push(t('workload_cell.overdue'));
    }

    return parts.join('. ') + '.';
}
