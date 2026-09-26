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
import { t } from '@/lib/i18n';

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
        label: t('load.level.none'),
        icon: CalendarOff,
        surface: 'bg-neutral-soft',
        tone: 'text-muted-foreground',
    },
    under: {
        label: t('load.level.under'),
        icon: CircleGauge,
        surface: 'bg-info-soft',
        tone: 'text-info',
    },
    balanced: {
        label: t('load.level.balanced'),
        icon: CircleCheck,
        surface: 'bg-success-soft',
        tone: 'text-success',
    },
    high: {
        label: t('load.level.high'),
        icon: TriangleAlert,
        surface: 'bg-warning-soft',
        tone: 'text-warning',
    },
    over: {
        label: t('load.level.over'),
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

/**
 * verde: < 75 % · ámbar: 75 % a < 100 % · rojo: ≥ 100 % (agotada, con o sin exceso).
 * `firstAlert` (proporción, p. ej. 0.8) sustituye al 75 % cuando los umbrales están configurados
 * (props compartidas config.hour_bank_thresholds, D-035: ámbar desde el primer umbral).
 * Los medidores le pasan lo que va dentro de la bolsa (HourBankFigures.inBank), como el servidor
 * al decidir si está agotada o «próxima a agotarse».
 */
export function hourBankLevel(
    consumedMinutes: number,
    totalMinutes: number,
    firstAlert: number = HOUR_BANK_ALERTS[0],
): HourBankLevel {
    if (!Number.isFinite(totalMinutes) || totalMinutes <= 0) {
        return 'exhausted';
    }

    const ratio = consumedMinutes / totalMinutes;

    if (ratio >= 1) {
        return 'exhausted';
    }

    return ratio >= firstAlert ? 'warning' : 'ok';
}

/**
 * Umbrales configurados en % ([75, 90, 100]) → proporciones ordenadas y sin repetir, de 0 a 1: los
 * que pasan del 100 % se quedan en el 100 %, que siempre está. Sin umbrales, los de por defecto.
 */
export function hourBankAlerts(
    thresholds?: readonly number[] | null,
): number[] {
    const ratios = (thresholds ?? [])
        .filter((value) => Number.isFinite(value) && value > 0)
        .map((value) => Math.min(value, 100) / 100);

    if (ratios.length === 0) {
        return [...HOUR_BANK_ALERTS];
    }

    return [...new Set([...ratios, 1])].sort((a, b) => a - b);
}

export const HOUR_BANK_LEVELS: Record<
    HourBankLevel,
    LevelMeta & { bar: string }
> = {
    ok: {
        label: t('hour_bank.level.ok'),
        icon: CircleCheck,
        surface: 'bg-success-soft',
        tone: 'text-success',
        bar: 'bg-success',
    },
    warning: {
        label: t('hour_bank.level.warning'),
        icon: TriangleAlert,
        surface: 'bg-warning-soft',
        tone: 'text-warning',
        bar: 'bg-warning',
    },
    exhausted: {
        label: t('hour_bank.level.exhausted'),
        icon: CircleAlert,
        surface: 'bg-danger-soft',
        tone: 'text-danger',
        bar: 'bg-danger',
    },
};

export type HourBankFigures = {
    consumed: number;
    total: number;
    /** Lo que va dentro de la bolsa: consumido − exceso (D-019). */
    inBank: number;
    /** Saldo: total − lo que va dentro (el exceso no ocupa saldo). */
    remaining: number;
    overage: number;
    committed: number;
    /** Minutos en que lo que va dentro + comprometido supera el total (0 si no lo supera). */
    shortfall: number;
    /** Consumido / total (puede pasar del 100 %). */
    ratio: number;
};

/**
 * Cifras de la tarjeta de bolsa, todas en minutos enteros. `overageMinutes` es el exceso que
 * calcula el servidor (overage_minutes, suma del exceso de cada entrada, D-019/D-035): con él, el
 * saldo y lo que va dentro coinciden siempre con remaining_minutes y el estado de la bolsa, también
 * si hay entradas bloqueadas en exceso y el total se amplió después. Sin él, se deduce de
 * consumido − total.
 */
export function hourBankFigures(
    consumedMinutes: number,
    totalMinutes: number,
    committedMinutes = 0,
    overageMinutes?: number | null,
): HourBankFigures {
    const consumed = Math.max(Math.round(consumedMinutes), 0);
    const total = Math.max(Math.round(totalMinutes), 0);
    const committed = Math.max(Math.round(committedMinutes), 0);
    const overage =
        overageMinutes === undefined || overageMinutes === null
            ? Math.max(consumed - total, 0)
            : Math.min(Math.max(Math.round(overageMinutes), 0), consumed);
    const inBank = consumed - overage;

    return {
        consumed,
        total,
        inBank,
        remaining: Math.max(total - inBank, 0),
        overage,
        committed,
        shortfall: Math.max(inBank + committed - total, 0),
        ratio: total > 0 ? consumed / total : 0,
    };
}
