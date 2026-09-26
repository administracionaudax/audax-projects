import type { BillablePoint } from '@/components/charts/billable-hours-chart';
import type { DayMinutes } from '@/components/charts/calendar-heatmap';
import type { CategoryMinutes } from '@/components/charts/department-hours-chart';
import type { WeeklyHoursPoint } from '@/components/charts/weekly-hours-chart';

/** Datos de ejemplo deterministas para la guía de estilo (sin aleatoriedad real). */

const DAY_MS = 86_400_000;

function iso(ms: number): string {
    return new Date(ms).toISOString().slice(0, 10);
}

function ddmm(ms: number): string {
    const d = new Date(ms);

    return `${String(d.getUTCDate()).padStart(2, '0')}/${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** Generador pseudoaleatorio con semilla (LCG): mismos datos en cada carga. */
function seeded(seed: number): () => number {
    let state = seed;

    return () => {
        state = (state * 1_664_525 + 1_013_904_223) % 4_294_967_296;

        return state / 4_294_967_296;
    };
}

/** Equipo de Desarrollo (4 personas × 40 h), 12 semanas desde el lunes 06/07/2026. */
export function weeklyHours(): WeeklyHoursPoint[] {
    const start = Date.UTC(2026, 6, 6);
    const capacity = [
        9600, 9600, 9600, 9600, 7200, 4800, 7200, 9600, 9120, 9600, 9600, 9600,
    ];
    const ratio = [
        0.86, 0.9, 0.83, 0.79, 0.88, 0.95, 0.9, 0.81, 0.87, 0.92, 0.88, 0.84,
    ];

    return capacity.map((cap, i) => {
        const monday = start + i * 7 * DAY_MS;

        return {
            id: iso(monday),
            label: ddmm(monday),
            capacity: cap,
            logged: Math.round((cap * ratio[i]) / 15) * 15,
        };
    });
}

export function departmentHours(): CategoryMinutes[] {
    return [
        { id: 'design', label: 'Diseño', minutes: 24_750 },
        { id: 'development', label: 'Desarrollo', minutes: 37_080 },
        { id: 'marketing', label: 'Marketing', minutes: 17_205 },
        { id: 'management', label: 'Gestión', minutes: 5_655 },
    ];
}

export function billableHours(): BillablePoint[] {
    const months = ['abr', 'may', 'jun', 'jul', 'ago', 'sep'];
    const billable = [52_800, 55_320, 50_100, 49_560, 30_240, 54_900];
    const nonBillable = [11_400, 9_960, 12_300, 10_860, 8_100, 10_020];

    return months.map((label, i) => ({
        id: `2026-${String(i + 4).padStart(2, '0')}`,
        label,
        billable: billable[i],
        nonBillable: nonBillable[i],
    }));
}

/** 26 semanas de horas diarias de una persona, hasta el viernes 25/09/2026. */
export function dailyHours(): DayMinutes[] {
    const random = seeded(20260925);
    const end = Date.UTC(2026, 8, 25);
    const start = end - (26 * 7 - 3) * DAY_MS;
    const vacation = [Date.UTC(2026, 7, 10), Date.UTC(2026, 7, 21)];
    const holidays = new Set(['2026-05-01', '2026-08-15', '2026-06-24']);
    const days: DayMinutes[] = [];

    for (let ms = start; ms <= end; ms += DAY_MS) {
        const weekday = (new Date(ms).getUTCDay() + 6) % 7;
        const date = iso(ms);
        let minutes = 0;

        if (
            weekday < 5 &&
            !holidays.has(date) &&
            !(ms >= vacation[0] && ms <= vacation[1])
        ) {
            const roll = random();
            minutes =
                roll < 0.06
                    ? 0
                    : roll < 0.18
                      ? 60 + Math.floor(random() * 4) * 30
                      : 300 + Math.floor(random() * 10) * 30;
        }

        days.push({ date, minutes });
    }

    return days;
}
