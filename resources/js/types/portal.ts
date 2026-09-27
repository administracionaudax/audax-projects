/**
 * Contrato de la Fase 5 (portal de cliente, D-063 a D-066). Coincide con App\Domain\Portal\*.
 * Nunca lleva costes, tarifas ni importes.
 */

/** Cifras de una bolsa según lo que ve el cliente (PortalBankFigures). */
export interface PortalBankFigures {
    total_minutes: number;
    within_minutes: number;
    overage_minutes: number;
    remaining_minutes: number;
    /** Consumo total (dentro + exceso) / total: 1 = 100 %. */
    percent: number;
}

/** Consumo de un mes (AAAA-MM-01). */
export interface PortalBankMonth {
    month: string;
    within_minutes: number;
    overage_minutes: number;
}

/** Cómo se nombra a las personas en el portal. */
export type PortalPersonDisplay = 'name' | 'initials' | 'team';

/** Qué horas ve el cliente: aprobadas y bloqueadas, o también las enviadas. */
export type PortalEntryVisibility = 'approved' | 'submitted';
