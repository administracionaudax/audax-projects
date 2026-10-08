import type {
    CollectionStatus,
    SaleKind,
    SaleStatus,
    SoldVsActualUnit,
} from '@/types';

/**
 * Reglas de «Vendido frente a real» que también se calculan en el navegador (Fase 12, D-390). Son
 * las mismas que App\Domain\Billing\SoldVsActual: consumo = real ÷ vendido × 100 con un decimal y
 * semáforo de la Weekly (riesgo desde el 85 %, pasado por encima del 100 %). Casos compartidos en
 * tests/fixtures/billing/sold-vs-actual-status.json.
 */
export const RISK_PCT = 85;
export const OVER_PCT = 100;

export function consumptionPct(
    sold: number | null,
    real: number,
): number | null {
    if (sold === null || sold <= 0) {
        return null;
    }

    return Math.round((real / sold) * 1000) / 10;
}

export function saleStatus(pct: number | null): SaleStatus {
    if (pct === null) {
        return 'none';
    }

    if (pct > OVER_PCT) {
        return 'over';
    }

    return pct >= RISK_PCT ? 'risk' : 'ok';
}

export const SALE_KINDS: SaleKind[] = [
    'bolsa',
    'precio_cerrado',
    'fee',
    'horas',
];

export const COLLECTION_STATUSES: CollectionStatus[] = [
    'overdue',
    'unpaid',
    'partial',
    'paid',
    'cancelled',
];

/** Importe «1234.50» como número (solo para pintar proporciones; los totales vienen del servidor). */
export function amount(value: string | null | undefined): number {
    const parsed = Number(value ?? 0);

    return Number.isFinite(parsed) ? parsed : 0;
}

/**
 * Unidades para la gráfica: las que tienen horas vendidas, primero las más pasadas (consumo de
 * mayor a menor) y como mucho `max`. Las demás siguen en la tabla.
 */
export function chartUnits(
    units: ReadonlyArray<SoldVsActualUnit>,
    max = 12,
): SoldVsActualUnit[] {
    return units
        .filter((unit) => unit.sold_minutes !== null && unit.sold_minutes > 0)
        .sort(
            (a, b) =>
                (b.consumption_pct ?? 0) - (a.consumption_pct ?? 0) ||
                a.name.localeCompare(b.name, 'es'),
        )
        .slice(0, max);
}

/**
 * Tramos de la barra de una unidad sobre una escala común (`scale`, minutos del mayor de vendido y
 * real de la gráfica): lo real dentro de lo vendido, el exceso por encima y la pista de lo vendido,
 * en porcentaje del ancho.
 */
export function bulletSegments(
    sold: number,
    real: number,
    scale: number,
): { inside: number; over: number; track: number } {
    if (scale <= 0) {
        return { inside: 0, over: 0, track: 0 };
    }

    const pct = (minutes: number) =>
        Math.max(0, Math.min(100, (minutes / scale) * 100));

    return {
        inside: pct(Math.min(real, sold)),
        over: pct(Math.max(0, real - sold)),
        track: pct(sold),
    };
}

/** Desviación con signo en h:mm («+2:00», «-1:30», «0:00»). */
export function signedMinutes(
    minutes: number | null,
    format: (minutes: number) => string,
): string {
    if (minutes === null) {
        return '—';
    }

    if (minutes > 0) {
        return `+${format(minutes)}`;
    }

    return minutes < 0 ? `−${format(Math.abs(minutes))}` : format(0);
}
