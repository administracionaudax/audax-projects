/**
 * Cálculos de la previsión en la interfaz (D-283, D-287): la carga de una celda con las capas
 * encendidas, su nivel (el semáforo de la Carga, D-052) y la desviación de «estimado frente a
 * real». La desviación se comprueba con los mismos casos que PHP (tests/fixtures/forecast-deviation.json).
 */
import { loadLevel, loadPercent } from '@/components/charts/thresholds';
import type { LoadLevel } from '@/components/charts/thresholds';
import { formatPercent } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import type {
    Allocation,
    ForecastBoard,
    ForecastBucket,
    ForecastGranularity,
    ImpactCell,
    LoadCell,
    LoadLayer,
    LoadLayers,
    LoadSource,
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

/**
 * Cuánto (D-298, en horas): «80 h en total», «3 h al día», «1,5 h al día», «50 % de su jornada»,
 * «20 h al mes». En un hueco, el % es de una jornada («0,5 personas»).
 */
export function allocationAmountLabel(
    allocation: Pick<Allocation, 'mode' | 'minutes' | 'percent'> & {
        is_gap?: boolean;
    },
): string {
    if (allocation.mode === 'percent') {
        return t(
            allocation.is_gap
                ? 'forecast.amount.percent_gap'
                : 'forecast.amount.percent',
            { percent: allocation.percent ?? 0 },
        );
    }

    return t(`forecast.amount.${allocation.mode}`, {
        time: formatHoursExact(allocation.minutes ?? 0),
    });
}

/** Horas con un decimal si hace falta: 90 → «1,5 h», 4800 → «80 h». */
export function formatHoursExact(minutes: number): string {
    return `${new Intl.NumberFormat('es-ES', { maximumFractionDigits: 1, useGrouping: 'always' }).format(Math.round(minutes / 6) / 10)}\u00a0h`;
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

// --- Pantallas (D-300 a D-307) ----------------------------------------------------------------

/** En la URL, las capas van en español: ?capas=real,seguro,posible (todas, sin parámetro). */
const LAYER_PARAM: Record<LoadLayer, string> = {
    real: 'real',
    firm: 'seguro',
    tentative: 'posible',
};

export function parseLayers(value: string | null | undefined): LayerToggles {
    if (value === null || value === undefined) {
        return { ...ALL_LAYERS };
    }

    const parts = value.split(',').map((part) => part.trim());

    return {
        real: parts.includes(LAYER_PARAM.real),
        firm: parts.includes(LAYER_PARAM.firm),
        tentative: parts.includes(LAYER_PARAM.tentative),
    };
}

/** null con todas encendidas (la URL queda limpia). */
export function serializeLayers(layers: LayerToggles): string | null {
    if (LAYERS.every((layer) => layers[layer])) {
        return null;
    }

    return LAYERS.filter((layer) => layers[layer])
        .map((layer) => LAYER_PARAM[layer])
        .join(',');
}

export function anyLayer(layers: LayerToggles): boolean {
    return LAYERS.some((layer) => layers[layer]);
}

/**
 * Horas redondeadas a la hora, con espacio duro (D-298): 1440 → «24 h», 74400 → «1.240 h».
 * Por debajo de una hora y sin ser cero, «<1 h».
 */
export function formatHours(minutes: number): string {
    if (!Number.isFinite(minutes)) {
        return '';
    }

    if (minutes > 0 && minutes < 30) {
        return '<1 h';
    }

    return `${new Intl.NumberFormat('es-ES', { maximumFractionDigits: 0, useGrouping: 'always' }).format(Math.round(minutes / 60))} h`;
}

/** «75 %» con espacio duro; vacío sin capacidad. */
export function formatPercentValue(percent: number | null): string {
    if (percent === null) {
        return '';
    }

    return `${new Intl.NumberFormat('es-ES', { maximumFractionDigits: 0, useGrouping: 'always' }).format(percent)} %`;
}

const DAY_MONTH = new Intl.DateTimeFormat('es-ES', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});
const MONTH_SHORT = new Intl.DateTimeFormat('es-ES', {
    month: 'short',
    timeZone: 'UTC',
});
const MONTH_LONG = new Intl.DateTimeFormat('es-ES', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

function utc(date: string): Date {
    return new Date(`${date}T00:00:00Z`);
}

/** «2 nov» (sin el punto de la abreviatura). */
export function dayMonth(date: string): string {
    return DAY_MONTH.format(utc(date)).replace('.', '');
}

/** «nov» */
export function monthShort(date: string): string {
    return MONTH_SHORT.format(utc(date)).replace('.', '');
}

/** «2 nov – 18 dic»; sin fin, «desde el 2 nov». */
export function dateRange(from: string, to: string | null): string {
    if (to === null) {
        return t('forecast.dates.from', { date: dayMonth(from) });
    }

    return `${dayMonth(from)} – ${dayMonth(to)}`;
}

/** Número de semana ISO de una clave «2026-W45». */
export function isoWeek(key: string): number {
    return Number(key.split('-W')[1] ?? 0);
}

export type BucketLabel = {
    /** «S45» o «nov». */
    short: string;
    /** «2 nov» (lunes) o el año si cambia. */
    sub: string;
    /** «semana 45 (2 nov – 8 nov)» o «noviembre de 2026». */
    long: string;
};

export function bucketLabel(
    bucket: ForecastBucket,
    granularity: ForecastGranularity,
): BucketLabel {
    if (granularity === 'week') {
        const week = isoWeek(bucket.key);

        return {
            short: t('forecast.period.week_short', { week }),
            sub: dayMonth(bucket.from),
            long: t('forecast.period.week_long', {
                week,
                from: dayMonth(bucket.from),
                to: dayMonth(bucket.to),
            }),
        };
    }

    return {
        short: monthShort(bucket.from),
        sub: bucket.from.slice(0, 4),
        long: MONTH_LONG.format(utc(bucket.from)),
    };
}

/** ¿Es la columna de hoy? */
export function isCurrentBucket(bucket: ForecastBucket, today: string): boolean {
    return bucket.from <= today && today <= bucket.to;
}

/** Nombre de un contenedor de una fuente: «Kiwi · App fase 2» (cliente y proyecto). */
export function sourceTitle(source: Pick<LoadSource, 'project' | 'forecast' | 'client_name'>): string {
    const name = source.project?.name ?? source.forecast?.name ?? '';

    return source.client_name ? `${source.client_name} · ${name}` : name;
}

export type CellItem = {
    key: string;
    layer: LoadLayer;
    title: string;
    minutes: number;
    /** Enlace a la ficha del previsto o a la Planificación del proyecto. */
    href: string;
};

const LAYER_ORDER: Record<LoadLayer, number> = { real: 0, firm: 1, tentative: 2 };

/**
 * De qué proyectos sale la carga de una celda (persona, hueco de un departamento o departamento
 * entero): una fila por proyecto, de real a seguro y a posible y, dentro, de más a menos horas.
 */
export function cellItems(
    sources: LoadSource[],
    index: number,
    match: (source: LoadSource) => boolean,
): CellItem[] {
    const byKey = new Map<string, CellItem>();

    for (const source of sources) {
        const minutes = source.minutes[index] ?? 0;

        if (minutes <= 0 || !match(source)) {
            continue;
        }

        const key = source.project
            ? `p${source.project.id}`
            : `f${source.forecast?.id ?? 0}`;
        const current = byKey.get(key);

        if (current) {
            current.minutes += minutes;
            continue;
        }

        byKey.set(key, {
            key,
            layer: source.layer,
            title: sourceTitle(source),
            minutes,
            href: source.project
                ? `/proyectos/${source.project.id}/planificacion`
                : `/prevision/proyectos/${source.forecast?.id ?? 0}`,
        });
    }

    return [...byKey.values()].sort(
        (a, b) =>
            LAYER_ORDER[a.layer] - LAYER_ORDER[b.layer] || b.minutes - a.minutes,
    );
}

/** Persona, hueco o departamento con su fila del tablero. */
export type MatrixRowRef =
    | { kind: 'person'; id: number }
    | { kind: 'gap'; departmentId: number }
    | { kind: 'department'; departmentId: number | null; members: number[] };

export function sourceMatcher(row: MatrixRowRef): (source: LoadSource) => boolean {
    if (row.kind === 'person') {
        return (source) => source.user_id === row.id;
    }

    if (row.kind === 'gap') {
        return (source) =>
            source.user_id === null && source.department_id === row.departmentId;
    }

    const members = new Set(row.members);

    return (source) =>
        (source.user_id !== null && members.has(source.user_id)) ||
        (source.user_id === null &&
            row.departmentId !== null &&
            source.department_id === row.departmentId);
}

export type BoardFigures = {
    capacity: number;
    load: number;
    realOnly: number;
    /** Personas × periodo en sobrecarga (> 120 %). */
    overloaded: number;
    overloadedPeople: string[];
    gapMinutes: number;
    gapsByDepartment: { name: string; minutes: number }[];
};

/** Cifras del horizonte (D-302): del equipo de plantilla, con las capas encendidas. */
export function boardFigures(
    board: ForecastBoard,
    layers: LayerToggles,
    noDepartment: string,
): BoardFigures {
    let capacity = 0;
    let load = 0;
    let realOnly = 0;

    for (const cell of board.totals) {
        capacity += cell.capacity;
        load += cellLoad(cell, layers);
        realOnly += layers.real ? cell.real : 0;
    }

    let overloaded = 0;
    const overloadedPeople: string[] = [];

    for (const person of board.people) {
        let counted = false;

        for (const cell of person.cells) {
            if (cellLevel(cell, layers) === 'over') {
                overloaded++;

                if (!counted) {
                    overloadedPeople.push(person.name.split(' ')[0] ?? person.name);
                    counted = true;
                }
            }
        }
    }

    const gapsByDepartment = board.departments
        .map((department) => ({
            name: department.name ?? noDepartment,
            minutes: department.gaps.reduce(
                (sum, gap) => sum + cellLoad(gap, layers),
                0,
            ),
        }))
        .filter((row) => row.minutes > 0);

    return {
        capacity,
        load,
        realOnly,
        overloaded,
        overloadedPeople,
        gapMinutes: gapsByDepartment.reduce((sum, row) => sum + row.minutes, 0),
        gapsByDepartment,
    };
}

/** Iniciales para el avatar: «Luis Martín» → «LM». */
export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? '')
        .join('');
}

/** Peor celda del impacto «sin / con» (la de más % con el previsto). */
export type ImpactWorst = {
    name: string;
    bucket: ForecastBucket;
    without: number | null;
    with: number | null;
    level: LoadLevel;
};

export function impactWorst(
    rows: { name: string; cells: ImpactCell[] }[],
    buckets: ForecastBucket[],
): ImpactWorst | null {
    let worst: ImpactWorst | null = null;
    let worstPercent = -1;

    for (const row of rows) {
        row.cells.forEach((cell, index) => {
            const percent = loadPercent(cell.with, cell.capacity);

            if (percent === null || cell.with === cell.without || percent <= worstPercent) {
                return;
            }

            worstPercent = percent;
            worst = {
                name: row.name,
                bucket: buckets[index],
                without: loadPercent(cell.without, cell.capacity),
                with: percent,
                level: loadLevel(cell.with, cell.capacity),
            };
        });
    }

    return worst;
}

/** «Real», «Previsto seguro» o «Previsto posible». */
export function layerLabel(layer: LoadLayer): string {
    return t(`forecast.layers.${layer}`);
}

/** Plural de dos claves «_one» / «_other» (la app no tiene `trans_choice` en la interfaz). */
export function plural(
    one: TranslationKey,
    other: TranslationKey,
    count: number,
    replacements: Record<string, string | number> = {},
): string {
    return t(count === 1 ? one : other, { count, ...replacements });
}
