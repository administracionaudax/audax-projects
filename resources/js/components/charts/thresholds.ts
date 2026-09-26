/**
 * Umbrales de estado (SPEC §8 y §9). Los estados usan los tokens reservados
 * success / warning / danger / info / neutral y SIEMPRE van con icono y texto.
 * El texto va en tinta de texto (foreground); el color de estado, en el icono y la marca.
 */
import type { LucideIcon } from 'lucide-react';
import {
    CalendarOff,
    CircleAlert,
    CircleCheck,
    CircleGauge,
    OctagonAlert,
    TriangleAlert,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/* Semáforo de carga (SPEC §9)                                         */
/* ------------------------------------------------------------------ */

export type LoadLevel = 'none' | 'under' | 'balanced' | 'high' | 'over';

export type LevelMeta = {
    label: string;
    icon: LucideIcon;
    /** Fondo suave de la celda. */
    surface: string;
    /** Color del icono y de la marca (nunca del texto). */
    tone: string;
};

/**
 * gris: sin capacidad · azul: < 70 % · verde: 70–100 % · ámbar: > 100–120 % · rojo: > 120 %.
 * Los límites exactos (70 %, 100 %, 120 %) caen en el tramo inferior salvo el 70 %, que ya es verde.
 */
export function loadLevel(
    plannedMinutes: number,
    capacityMinutes: number,
): LoadLevel {
    if (!Number.isFinite(capacityMinutes) || capacityMinutes <= 0) {
        return 'none';
    }

    const ratio = Math.max(plannedMinutes, 0) / capacityMinutes;

    if (ratio < 0.7) {
        return 'under';
    }

    if (ratio <= 1) {
        return 'balanced';
    }

    if (ratio <= 1.2) {
        return 'high';
    }

    return 'over';
}

export const LOAD_LEVELS: Record<LoadLevel, LevelMeta> = {
    none: {
        label: 'Sin capacidad',
        icon: CalendarOff,
        surface: 'bg-neutral-soft',
        tone: 'text-muted-foreground',
    },
    under: {
        label: 'Holgada',
        icon: CircleGauge,
        surface: 'bg-info-soft',
        tone: 'text-info',
    },
    balanced: {
        label: 'Equilibrada',
        icon: CircleCheck,
        surface: 'bg-success-soft',
        tone: 'text-success',
    },
    high: {
        label: 'Alta',
        icon: TriangleAlert,
        surface: 'bg-warning-soft',
        tone: 'text-warning',
    },
    over: {
        label: 'Sobrecarga',
        icon: OctagonAlert,
        surface: 'bg-danger-soft',
        tone: 'text-danger',
    },
};

/* ------------------------------------------------------------------ */
/* Bolsas de horas (SPEC §8)                                           */
/* ------------------------------------------------------------------ */

export type HourBankLevel = 'ok' | 'warning' | 'exhausted';

/** Umbrales de alerta por defecto (configurables en la Fase 1): 75 %, 90 % y 100 %. */
export const HOUR_BANK_ALERTS = [0.75, 0.9, 1] as const;

/** verde: < 75 % · ámbar: 75 % a < 100 % · rojo: ≥ 100 % (agotada, con o sin exceso). */
export function hourBankLevel(
    consumedMinutes: number,
    totalMinutes: number,
): HourBankLevel {
    if (!Number.isFinite(totalMinutes) || totalMinutes <= 0) {
        return 'exhausted';
    }

    const ratio = consumedMinutes / totalMinutes;

    if (ratio >= 1) {
        return 'exhausted';
    }

    return ratio >= HOUR_BANK_ALERTS[0] ? 'warning' : 'ok';
}

export const HOUR_BANK_LEVELS: Record<
    HourBankLevel,
    LevelMeta & { bar: string }
> = {
    ok: {
        label: 'En margen',
        icon: CircleCheck,
        surface: 'bg-success-soft',
        tone: 'text-success',
        bar: 'bg-success',
    },
    warning: {
        label: 'Cerca del límite',
        icon: TriangleAlert,
        surface: 'bg-warning-soft',
        tone: 'text-warning',
        bar: 'bg-warning',
    },
    exhausted: {
        label: 'Agotada',
        icon: CircleAlert,
        surface: 'bg-danger-soft',
        tone: 'text-danger',
        bar: 'bg-danger',
    },
};

export type HourBankFigures = {
    consumed: number;
    total: number;
    remaining: number;
    overage: number;
    committed: number;
    /** Minutos en que consumido + comprometido supera el total (0 si no lo supera). */
    shortfall: number;
    ratio: number;
};

/** Cifras de la tarjeta de bolsa, todas en minutos enteros. */
export function hourBankFigures(
    consumedMinutes: number,
    totalMinutes: number,
    committedMinutes = 0,
): HourBankFigures {
    const consumed = Math.max(Math.round(consumedMinutes), 0);
    const total = Math.max(Math.round(totalMinutes), 0);
    const committed = Math.max(Math.round(committedMinutes), 0);

    return {
        consumed,
        total,
        remaining: Math.max(total - consumed, 0),
        overage: Math.max(consumed - total, 0),
        committed,
        shortfall: Math.max(consumed + committed - total, 0),
        ratio: total > 0 ? consumed / total : 0,
    };
}
