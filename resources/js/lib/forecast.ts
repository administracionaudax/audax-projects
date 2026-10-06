/**
 * Cálculos de la previsión en la interfaz (D-283, D-287): la carga de una celda con las capas
 * encendidas, su nivel (el semáforo de la Carga, D-052) y la desviación de «estimado frente a
 * real». La desviación se comprueba con los mismos casos que PHP (tests/fixtures/forecast-deviation.json).
 */
import { loadLevel } from '@/components/charts/thresholds';
import type { LoadLevel } from '@/components/charts/thresholds';
import { formatMinutes, formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import type {
    Allocation,
    LoadCell,
    LoadLayer,
    LoadLayers,
} from '@/types/forecast';

export type LayerToggles = Record<LoadLayer, boolean>;

/** Por defecto, todas las capas («la pregunta incómoda primero», §6.5). */
export const ALL_LAYERS: LayerToggles = {
    real: true,
    firm: true,
    tentative: true,
};

export const LAYERS: LoadLayer[] = ['real', 'firm', 'tentative'];

/** Minutos asignados de una celda con las capas encendidas. */
export function cellLoad(
    cell: LoadLayers,
    layers: LayerToggles = ALL_LAYERS,
): number {
    return LAYERS.reduce(
        (sum, layer) => sum + (layers[layer] ? cell[layer] : 0),
        0,
    );
}

/** Nivel de ocupación de una celda (sin capacidad, «none»). */
export function cellLevel(
    cell: LoadCell,
    layers: LayerToggles = ALL_LAYERS,
): LoadLevel {
    return loadLevel(cellLoad(cell, layers), cell.capacity);
}

/**
 * Desviación en % con un decimal, como EstimateVsActual::deviation (PHP redondea los medios
 * alejándose del cero): (real − estimado) / estimado; null sin estimado.
 */
export function deviationPercent(
    estimated: number,
    actual: number,
): number | null {
    if (estimated <= 0) {
        return null;
    }

    const value = ((actual - estimated) / estimated) * 100;

    return (Math.sign(value) * Math.round(Math.abs(value) * 10)) / 10 || 0;
}

export type DeviationKind = 'none' | 'same' | 'over' | 'under';

/** Por debajo de medio punto, «Igual que lo estimado» (como D-079). */
export function deviationKind(percent: number | null): DeviationKind {
    if (percent === null) {
        return 'none';
    }

    if (Math.abs(percent) < 0.5) {
        return 'same';
    }

    return percent > 0 ? 'over' : 'under';
}

export function deviationLabel(percent: number | null): string {
    const kind = deviationKind(percent);

    if (kind === 'none' || percent === null) {
        return t('forecast.deviation.none');
    }

    if (kind === 'same') {
        return t('forecast.deviation.same');
    }

    return `${percent > 0 ? '+' : '−'}${formatPercent(Math.abs(percent) / 100)}`;
}

/** «80:00 en total», «4:00 al día», «50 %», «20:00 al mes». */
export function allocationAmountLabel(
    allocation: Pick<Allocation, 'mode' | 'minutes' | 'percent'>,
): string {
    if (allocation.mode === 'percent') {
        return t('forecast.amount.percent', {
            percent: allocation.percent ?? 0,
        });
    }

    return t(`forecast.amount.${allocation.mode}`, {
        time: formatMinutes(allocation.minutes ?? 0),
    });
}

/** Quién: la persona o «Diseño (hueco)». */
export function allocationWho(
    allocation: Pick<Allocation, 'user' | 'department'>,
): string {
    if (allocation.user) {
        return allocation.user.name;
    }

    return t('forecast.show.gap', {
        department: allocation.department?.name ?? '',
    });
}
