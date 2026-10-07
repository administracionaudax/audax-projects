import type { LucideIcon } from 'lucide-react';
import {
    Ban,
    CalendarOff,
    CircleCheck,
    CircleX,
    Clock,
    GraduationCap,
    HeartPulse,
    Ticket,
    TreePalm,
} from 'lucide-react';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { AbsenceStatus, AbsenceType } from '@/components/absences/types';

/**
 * Textos e iconos de las ausencias (D-049): tipo, estado y fechas. El estado va siempre con
 * icono y texto (SPEC §3.1), nunca solo con color.
 */

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

export const ABSENCE_TYPES: Record<AbsenceType, { icon: LucideIcon }> = {
    vacation: { icon: TreePalm },
    sick: { icon: HeartPulse },
    leave: { icon: Ticket },
    training: { icon: GraduationCap },
    other: { icon: CalendarOff },
};

const STATUSES: Record<AbsenceStatus, { tone: Tone; icon: LucideIcon }> = {
    requested: { tone: 'warning', icon: Clock },
    approved: { tone: 'success', icon: CircleCheck },
    rejected: { tone: 'danger', icon: CircleX },
    cancelled: { tone: 'neutral', icon: Ban },
};

export function absenceTypeLabel(type: AbsenceType): string {
    return t(`absences.type.${type}`);
}

export function absenceStatusLabel(status: AbsenceStatus): string {
    return t(`absences.status.${status}`);
}

/**
 * «05/10/2026», «05/10/2026 – 09/10/2026» o «05/10/2026 · 2:00 h».
 */
export function absencePeriodLabel(absence: {
    start_date: string;
    end_date: string;
    partial_minutes: number | null;
}): string {
    if (absence.partial_minutes !== null) {
        return t('absences.period.partial', {
            date: formatDate(absence.start_date),
            minutes: formatMinutes(absence.partial_minutes),
        });
    }

    if (absence.start_date === absence.end_date) {
        return t('absences.period.day', {
            date: formatDate(absence.start_date),
        });
    }

    return t('absences.period.range', {
        from: formatDate(absence.start_date),
        to: formatDate(absence.end_date),
    });
}

/** «1 día laborable», «5 días laborables» o null (parciales o sin dato). */
export function workingDaysLabel(days: number | null): string | null {
    if (days === null) {
        return null;
    }

    if (days === 0) {
        return t('absences.days_none');
    }

    return days === 1
        ? t('absences.days_one')
        : t('absences.days_many', { count: days });
}

export function AbsenceStatusBadge({ status }: { status: AbsenceStatus }) {
    const meta = STATUSES[status];

    return (
        <StatusBadge tone={meta.tone} icon={meta.icon}>
            {absenceStatusLabel(status)}
        </StatusBadge>
    );
}

/** Tipo con su icono (el icono es decorativo: el texto lo dice todo). */
export function AbsenceTypeLabel({
    type,
    className,
    label,
}: {
    type: AbsenceType;
    className?: string;
    /** Fase 11, R3: el nombre del tipo del catálogo (el icono sigue siendo el de su categoría). */
    label?: string;
}) {
    const Icon = ABSENCE_TYPES[type].icon;

    return (
        <span className={className ?? 'inline-flex items-center gap-1.5'}>
            <Icon
                aria-hidden="true"
                className="size-4 shrink-0 text-muted-foreground"
                strokeWidth={1.5}
            />
            {label ?? absenceTypeLabel(type)}
        </span>
    );
}
