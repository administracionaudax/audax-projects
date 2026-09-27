/**
 * Props de las páginas de bolsas del portal (P1, Fase 5). Contrato con
 * App\Http\Controllers\Portal\Banks (PortalBankData). Nunca llevan costes, tarifas ni importes.
 */
import type { HourBankStatus, ProjectsPaginated } from '@/types';
import type {
    PortalBankFigures,
    PortalBankMonth,
    PortalEntryVisibility,
} from '@/types/portal';

/** Una bolsa tal como la ve el cliente (PortalBankData::item). */
export type PortalBank = {
    id: number;
    name: string;
    project: { code: string; name: string };
    /** Estado para el cliente (PortalBankFigures::status): cuadra con sus cifras. */
    status: HourBankStatus;
    start_date: string;
    end_date: string | null;
    /** Instante de cierre (ISO en UTC), solo en las cerradas. */
    closed_at: string | null;
    figures: PortalBankFigures;
};

/** Resumen del inicio del portal. */
export type PortalHomeSummary = {
    /** Primer día del mes en curso (AAAA-MM-01, Europe/Madrid). */
    month: string;
    month_minutes: number;
    month_overage_minutes: number;
    open_count: number;
    /** Bolsas activas desde el primer umbral configurado (D-035). */
    near_limit_count: number;
    /** Primer umbral configurado, en %. */
    first_threshold: number;
};

export type PortalHomeProps = {
    client: { name: string };
    visibility: PortalEntryVisibility;
    /** Umbrales de alerta configurados, en % (los de las barras). */
    thresholds: number[];
    summary: PortalHomeSummary;
    /** Activas o agotadas (de proyectos sin archivar). */
    banks: PortalBank[];
    /** Cerradas y renovadas, de la más reciente a la más antigua. */
    history: PortalBank[];
};

/** Una entrada de horas visible para el cliente. */
export type PortalBankEntry = {
    id: number;
    /** Día de imputación (AAAA-MM-DD). */
    date: string;
    task: string;
    type: { name: string; color: string } | null;
    /** Nombre, iniciales o «Equipo», según el ajuste del cliente. */
    person: string;
    minutes: number;
    overage_minutes: number;
    description: string | null;
};

/** Eslabón de la cadena de renovaciones (de la más antigua a la más reciente). */
export type PortalBankChainItem = PortalBank & { current: boolean };

export type PortalBankShowProps = {
    bank: PortalBank;
    visibility: PortalEntryVisibility;
    thresholds: number[];
    months: PortalBankMonth[];
    entries: ProjectsPaginated<PortalBankEntry>;
    /** Filtro de mes de las entradas (AAAA-MM) o null. */
    filters: { mes: string | null };
    /** Vacía si la bolsa nunca se ha renovado. */
    history: PortalBankChainItem[];
};
