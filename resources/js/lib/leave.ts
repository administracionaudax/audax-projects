/**
 * Utilidades puras de vacaciones y permisos (Fase 11, R3): cantidades en días u horas (gemelas de
 * App\Domain\Absences\LeaveFormat), el texto de un saldo y los días de un calendario. Sin React, para
 * probarlas con Vitest (tests/js/leave.test.ts).
 */
import { formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { LeaveBalance, LeaveUnit } from '@/types/leave';

/** Centésimas de un día. */
export const DAY = 100;

/** Centésimas de día en texto, con coma y sin ceros de más: 2200 → «22», 1250 → «12,5». */
export function formatDays(amount: number): string {
    const fixed = (amount / DAY).toFixed(2).replace('.', ',');

    return fixed.replace(/0+$/, '').replace(/,$/, '');
}

/** «22 días», «1 día», «0,5 días», «16:00 h». */
export function formatLeaveAmount(amount: number, unit: LeaveUnit): string {
    if (unit === 'hours') {
        return t('leave.units.hours', { value: formatMinutes(amount) });
    }

    return Math.abs(amount) === DAY
        ? t('leave.units.day', { value: formatDays(amount) })
        : t('leave.units.days', { value: formatDays(amount) });
}

/** La cantidad para un campo de texto: días («12,5») u horas («16:00»). */
export function leaveAmountInput(
    amount: number | null,
    unit: LeaveUnit,
): string {
    if (amount === null) {
        return '';
    }

    return unit === 'hours' ? formatMinutes(amount) : formatDays(amount);
}

/** ¿Pide la franja horaria (tipos por horas)? */
export function usesSlot(unit: LeaveUnit): boolean {
    return unit === 'hours';
}

/** Minutos entre dos horas HH:MM (0 si no están o están al revés). */
export function slotMinutes(start: string | null, end: string | null): number {
    if (!start || !end) {
        return 0;
    }

    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    const minutes = eh * 60 + em - (sh * 60 + sm);

    return minutes > 0 ? minutes : 0;
}

/**
 * Las partes del resumen de un saldo, como en Woffu: «14 días disponibles» y el detalle: «22 días
 * asignados en 2026», «5 días disfrutados», «3 días pendientes de aprobar», «2 días de 2025 hasta el
 * 31/03/2026».
 */
export function balanceParts(balance: LeaveBalance): {
    headline: string;
    details: string[];
    negative: boolean;
} {
    const unit = balance.type.unit;
    const details: string[] = [
        t('leave.balance.total', {
            amount: formatLeaveAmount(balance.total, unit),
            year: balance.year,
        }),
    ];

    if (balance.taken > 0) {
        details.push(
            t('leave.balance.used', {
                amount: formatLeaveAmount(balance.taken, unit),
            }),
        );
    }
    if (balance.used - balance.taken > 0) {
        details.push(
            t('leave.balance.planned', {
                amount: formatLeaveAmount(balance.used - balance.taken, unit),
            }),
        );
    }
    if (balance.pending > 0) {
        details.push(
            t('leave.balance.pending', {
                amount: formatLeaveAmount(balance.pending, unit),
            }),
        );
    }
    for (const carried of balance.carried) {
        if (carried.remaining <= 0) {
            continue;
        }
        details.push(
            t(
                carried.expired
                    ? 'leave.balance.carried_expired'
                    : 'leave.balance.carried',
                {
                    amount: formatLeaveAmount(carried.remaining, unit),
                    year: carried.year,
                    date: formatDate(carried.expires_on),
                },
            ),
        );
    }
    for (const expiring of balance.expiring) {
        details.push(
            t('leave.balance.expiring', {
                amount: formatLeaveAmount(expiring.amount, unit),
                date: formatDate(expiring.expires_on),
            }),
        );
    }
    if (balance.expired > 0) {
        details.push(
            t('leave.balance.expired', {
                amount: formatLeaveAmount(balance.expired, unit),
            }),
        );
    }

    return {
        headline: t('leave.balance.headline', {
            available: formatLeaveAmount(balance.available, unit),
        }),
        details,
        negative: balance.available < 0,
    };
}

/** Los días de un mes (AAAA-MM-DD), del 1 al último. */
export function monthDays(year: number, month: number): string[] {
    const days: string[] = [];
    const last = new Date(Date.UTC(year, month, 0)).getUTCDate();

    for (let day = 1; day <= last; day++) {
        days.push(
            `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`,
        );
    }

    return days;
}

/** Día de la semana ISO (1 = lunes … 7 = domingo) de una fecha AAAA-MM-DD. */
export function isoWeekday(date: string): number {
    const day = new Date(`${date}T00:00:00Z`).getUTCDay();

    return day === 0 ? 7 : day;
}

/** ¿Cae la fecha dentro del rango (ambos incluidos)? */
export function inRange(date: string, start: string, end: string): boolean {
    return date >= start && date <= end;
}
