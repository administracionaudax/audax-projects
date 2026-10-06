import { Deferred, Head, Link, router, usePage } from '@inertiajs/react';
import {
    ChartColumn,
    Plus,
    Search,
    Table2,
    TrendingUp,
    Users,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { ChartTable } from '@/components/charts/chart-frame';
import type { ChartTableData } from '@/components/charts/chart-frame';
import {
    LOAD_LEVELS,
    loadLevel,
    loadPercent,
} from '@/components/charts/thresholds';
import { EmptyState, HeroEmptyState } from '@/components/empty-state';
import { ForecastCellPanel } from '@/components/forecast/forecast-cell-panel';
import { ForecastLegend } from '@/components/forecast/forecast-legend';
import {
    GapsList,
    OpenForecastsList,
} from '@/components/forecast/forecast-lists';
import {
    ForecastMatrix,
    rowName,
    targetKey,
} from '@/components/forecast/forecast-matrix';
import type { MatrixTarget } from '@/components/forecast/forecast-matrix';
import { ForecastStat } from '@/components/forecast/forecast-stat';
import { HatchDefs } from '@/components/forecast/layer-swatch';
import { LayerToggles } from '@/components/forecast/layer-toggles';
import {
    matrixGroups,
    matrixRows,
    rowCell,
} from '@/components/forecast/matrix-model';
import { KeywordText } from '@/components/keyword-text';
import { NativeSelect } from '@/components/admin/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    boardFigures,
    bucketLabel,
    cellLoad,
    formatHours,
    formatPercentValue,
    parseLayers,
    plural,
    serializeLayers,
} from '@/lib/forecast';
import type { LayerToggles as Toggles } from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index } from '@/routes/forecast';
import { index as projectsIndex } from '@/routes/forecast/projects';
import type { ForecastIndexPageProps } from '@/types/forecast';

/** Horizontes que se ofrecen (D-294). */
const HORIZONS = [2, 3, 6, 12] as const;

/** Grupos que cada persona ha plegado (en su navegador, D-300). */
const COLLAPSED_KEY = 'forecast.collapsed';

function readCollapsed(): string[] {
    try {
        const value: unknown = JSON.parse(
            window.localStorage.getItem(COLLAPSED_KEY) ?? '[]',
        );

        return Array.isArray(value)
            ? value.filter((item): item is string => typeof item === 'string')
            : [];
    } catch {
        return [];
    }
}

function writeCollapsed(keys: string[]): void {
    try {
        window.localStorage.setItem(COLLAPSED_KEY, JSON.stringify(keys));
    } catch {
        // Sin almacenamiento, no se recuerda: la página funciona igual.
    }
}

type Query = Record<string, string | number>;

/**
 * Previsión del equipo (`/prevision`, D-290 a D-294 y D-300 a D-302): la matriz de ocupación por
 * departamento y persona (semanas o meses), con las cifras del horizonte arriba, las capas como
 * filtro y leyenda, «Ver como tabla», el panel de cada celda y, debajo, los huecos sin persona y
 * los previstos abiertos. Horizonte, agrupación y departamento van al servidor; las capas y la
 * búsqueda de persona se calculan en la interfaz, y todo queda en la URL.
 */
export default function ForecastIndex({
    board,
    filters,
    departments,
    can,
    gaps,
    open_forecasts: openForecasts,
}: ForecastIndexPageProps) {
    const page = usePage();
    const params = new URL(page.url, 'http://localhost').searchParams;
    const [layers, setLayers] = useState<Toggles>(() =>
        parseLayers(params.get('capas')),
    );
    const [search, setSearch] = useState(() => params.get('persona') ?? '');
    const [collapsed, setCollapsed] = useState<string[]>(() => readCollapsed());
    const [target, setTarget] = useState<MatrixTarget | null>(null);
    const [asTable, setAsTable] = useState(false);
    const [navigating, setNavigating] = useState(false);
    const granularity = board.period.granularity;

    const groups = useMemo(() => matrixGroups(board, search), [board, search]);
    const figures = useMemo(
        () => boardFigures(board, layers, t('forecast.board.no_department')),
        [board, layers],
    );
    const unit = granularity === 'week' ? 'week' : 'month';

    const query = (overrides: Query = {}): Query => {
        const next: Query = {
            meses: filters.months,
            por: granularity === 'week' ? 'semanas' : 'meses',
        };

        if (filters.department_id) {
            next.departamento = filters.department_id;
        }

        const layersParam = serializeLayers(layers);

        if (layersParam !== null) {
            next.capas = layersParam;
        }

        if (search.trim() !== '') {
            next.persona = search.trim();
        }

        for (const [key, value] of Object.entries(overrides)) {
            if (value === '') {
                delete next[key];
            } else {
                next[key] = value;
            }
        }

        return next;
    };

    const visit = (overrides: Query) =>
        router.visit(index.url({ query: query(overrides) }), {
            preserveState: true,
            preserveScroll: true,
            onStart: () => setNavigating(true),
            onFinish: () => setNavigating(false),
        });

    // Lo que solo cambia en la interfaz se apunta en la URL sin pedir nada al servidor.
    const remember = (overrides: Query) =>
        router.replace({
            url: index.url({ query: query(overrides) }),
            preserveState: true,
            preserveScroll: true,
        });

    const changeLayers = (next: Toggles) => {
        setLayers(next);
        remember({ capas: serializeLayers(next) ?? '' });
    };

    const changeSearch = (value: string) => {
        setSearch(value);
        remember({ persona: value.trim() });
    };

    const toggleGroup = (key: string) => {
        const next = collapsed.includes(key)
            ? collapsed.filter((item) => item !== key)
            : [...collapsed, key];
        setCollapsed(next);
        writeCollapsed(next);
    };

    const empty = board.sources.length === 0;
    const filteredOut = groups.length === 0 && search.trim() !== '';
    const occupancy = loadPercent(figures.load, figures.capacity);
    const occupancyLevel = loadLevel(figures.load, figures.capacity);
    const OccupancyIcon = LOAD_LEVELS[occupancyLevel].icon;
    const OverIcon = LOAD_LEVELS.over.icon;

    return (
        <>
            <Head title={t('forecast.title')} />
            <HatchDefs />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="max-w-2xl space-y-1">
                        <h1 className="text-2xl font-normal tracking-tight">
                            <KeywordText text={t('forecast.index.title')} />
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('forecast.index.description')}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={projectsIndex.url()}>
                                {t('forecast.nav.projects')}
                                {openForecasts !== undefined ? (
                                    <span className="tabular border px-1.5 text-xs">
                                        {openForecasts.length}
                                    </span>
                                ) : null}
                            </Link>
                        </Button>
                        {can.manage ? (
                            <Button asChild>
                                <Link
                                    href={projectsIndex.url({
                                        query: { nuevo: 1 },
                                    })}
                                >
                                    <Plus aria-hidden="true" />
                                    {t('forecast.index.new')}
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                </header>

                <div
                    role="group"
                    aria-label={t('forecast.filters.label')}
                    className="flex flex-wrap items-center gap-2"
                    data-test="forecast-filters"
                >
                    <label className="flex h-9 items-center gap-2 border border-input bg-card pl-3 text-sm">
                        <span className="text-muted-foreground">
                            {t('forecast.filters.horizon')}
                        </span>
                        <NativeSelect
                            aria-label={t('forecast.filters.horizon')}
                            value={filters.months}
                            onChange={(event) => {
                                const months = Number(event.target.value);
                                // Al pasar a 6 o 12 meses, por meses; se puede volver a semanas (D-294).
                                visit({
                                    meses: months,
                                    por: months > 3 ? 'meses' : 'semanas',
                                });
                            }}
                            className="h-full w-32 [&_select]:h-full [&_select]:border-0 [&_select]:bg-transparent"
                        >
                            {HORIZONS.map((months) => (
                                <option key={months} value={months}>
                                    {t('forecast.filters.months', {
                                        count: months,
                                    })}
                                </option>
                            ))}
                        </NativeSelect>
                    </label>
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={granularity}
                        onValueChange={(value) =>
                            value
                                ? visit({
                                      por:
                                          value === 'week'
                                              ? 'semanas'
                                              : 'meses',
                                  })
                                : null
                        }
                        aria-label={t('forecast.filters.group_by')}
                    >
                        <ToggleGroupItem value="week" className="px-3">
                            {t('forecast.filters.weeks')}
                        </ToggleGroupItem>
                        <ToggleGroupItem value="month" className="px-3">
                            {t('forecast.filters.by_months')}
                        </ToggleGroupItem>
                    </ToggleGroup>
                    <label className="flex h-9 items-center gap-2 border border-input bg-card pl-3 text-sm">
                        <span className="text-muted-foreground">
                            {t('forecast.filters.department')}
                        </span>
                        <NativeSelect
                            aria-label={t('forecast.filters.department')}
                            value={filters.department_id ?? ''}
                            onChange={(event) =>
                                visit({ departamento: event.target.value })
                            }
                            className="h-full w-40 [&_select]:h-full [&_select]:border-0 [&_select]:bg-transparent"
                        >
                            <option value="">
                                {t('forecast.filters.all')}
                            </option>
                            {departments.map((department) => (
                                <option
                                    key={department.id}
                                    value={department.id}
                                >
                                    {department.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </label>
                    <label className="relative flex h-9 items-center">
                        <span className="sr-only">
                            {t('forecast.filters.search')}
                        </span>
                        <Search
                            aria-hidden="true"
                            className="pointer-events-none absolute left-2.5 size-4 text-muted-foreground"
                        />
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) =>
                                changeSearch(event.target.value)
                            }
                            placeholder={t('forecast.filters.search')}
                            className="w-48 pl-8"
                        />
                    </label>
                    <LayerToggles value={layers} onChange={changeLayers} />
                </div>

                <section
                    aria-label={t('forecast.figures.label')}
                    className="grid grid-cols-2 gap-3 lg:grid-cols-4"
                    data-test="forecast-figures"
                >
                    <ForecastStat
                        label={t('forecast.figures.occupancy')}
                        value={
                            occupancy === null
                                ? '—'
                                : formatPercentValue(occupancy)
                        }
                        detail={
                            <>
                                <OccupancyIcon
                                    aria-hidden="true"
                                    className={cn(
                                        'mt-px size-3 shrink-0',
                                        LOAD_LEVELS[occupancyLevel].tone,
                                    )}
                                />
                                <span>
                                    {LOAD_LEVELS[occupancyLevel].label}.{' '}
                                    {t('forecast.figures.real_only', {
                                        percent: formatPercentValue(
                                            loadPercent(
                                                figures.realOnly,
                                                figures.capacity,
                                            ),
                                        ),
                                    })}
                                </span>
                            </>
                        }
                    />
                    <ForecastStat
                        label={t('forecast.figures.free')}
                        value={formatHours(
                            Math.max(figures.capacity - figures.load, 0),
                        )}
                        detail={t('forecast.figures.free_of', {
                            capacity: formatHours(figures.capacity),
                            months: plural(
                                'forecast.filters.months_one',
                                'forecast.filters.months_other',
                                filters.months,
                            ),
                        })}
                    />
                    <ForecastStat
                        label={t(`forecast.figures.overloaded_${unit}`)}
                        value={figures.overloaded}
                        detail={
                            figures.overloadedPeople.length > 0 ? (
                                <>
                                    <OverIcon
                                        aria-hidden="true"
                                        className="mt-px size-3 shrink-0 text-danger"
                                    />
                                    <span>
                                        {plural(
                                            'forecast.figures.people_one',
                                            'forecast.figures.people_other',
                                            figures.overloadedPeople.length,
                                            {
                                                names: figures.overloadedPeople
                                                    .slice(0, 4)
                                                    .join(', '),
                                            },
                                        )}
                                    </span>
                                </>
                            ) : (
                                t('forecast.figures.nobody')
                            )
                        }
                    />
                    <ForecastStat
                        label={t('forecast.figures.gaps')}
                        value={formatHours(figures.gapMinutes)}
                        detail={
                            figures.gapsByDepartment.length > 0
                                ? figures.gapsByDepartment
                                      .map(
                                          (row) =>
                                              `${row.name} ${formatHours(row.minutes)}`,
                                      )
                                      .join(' · ')
                                : t('forecast.figures.no_gaps')
                        }
                    />
                </section>

                <section
                    aria-labelledby="forecast-matrix-title"
                    className="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-3"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2
                            id="forecast-matrix-title"
                            className="flex items-center gap-2 text-lg"
                        >
                            {t('forecast.matrix.title')}
                            {navigating ? (
                                <span
                                    className="inline-flex items-center gap-2 text-sm text-muted-foreground"
                                    role="status"
                                >
                                    <Spinner aria-hidden="true" />
                                    {t('forecast.loading')}
                                </span>
                            ) : null}
                        </h2>
                        {empty ? null : (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setAsTable((value) => !value)}
                            >
                                {asTable ? (
                                    <ChartColumn aria-hidden="true" />
                                ) : (
                                    <Table2 aria-hidden="true" />
                                )}
                                {asTable
                                    ? t('charts.view_chart')
                                    : t('charts.view_table')}
                            </Button>
                        )}
                    </div>

                    {empty ? (
                        <HeroEmptyState
                            icon={TrendingUp}
                            titleAs="h2"
                            title={t('forecast.empty.title')}
                            description={t('forecast.empty.description')}
                        >
                            {can.manage ? (
                                <Button asChild>
                                    <Link
                                        href={projectsIndex.url({
                                            query: { nuevo: 1 },
                                        })}
                                    >
                                        <Plus aria-hidden="true" />
                                        {t('forecast.index.new')}
                                    </Link>
                                </Button>
                            ) : null}
                        </HeroEmptyState>
                    ) : filteredOut ? (
                        <EmptyState
                            icon={Users}
                            title={t('forecast.empty.filtered')}
                        >
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => changeSearch('')}
                            >
                                <X aria-hidden="true" />
                                {t('forecast.empty.clear')}
                            </Button>
                        </EmptyState>
                    ) : asTable ? (
                        <ChartTable
                            table={matrixTable(board, groups, layers)}
                            caption={t('forecast.matrix.title')}
                        />
                    ) : (
                        <>
                            <ForecastLegend layers={layers} />
                            <ForecastMatrix
                                board={board}
                                groups={groups}
                                layers={layers}
                                isExpanded={(key) => !collapsed.includes(key)}
                                onToggle={toggleGroup}
                                openKey={
                                    target
                                        ? targetKey(target.row, target.index)
                                        : null
                                }
                                onOpen={setTarget}
                                loading={navigating}
                            />
                            <p className="text-xs text-muted-foreground">
                                {t('forecast.matrix.keyboard_help')}
                            </p>
                        </>
                    )}
                </section>

                <div className="grid min-w-0 gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                    <Deferred data="gaps" fallback={<GapsList />}>
                        <GapsList gaps={gaps} />
                    </Deferred>
                    <Deferred
                        data="open_forecasts"
                        fallback={<OpenForecastsList />}
                    >
                        <OpenForecastsList forecasts={openForecasts} />
                    </Deferred>
                </div>
            </div>

            <ForecastCellPanel
                board={board}
                target={target}
                layers={layers}
                gaps={gaps}
                onClose={() => setTarget(null)}
            />
        </>
    );
}

/** «Ver como tabla» (D-290 §6): persona × periodo con horas, capacidad, % y nivel. */
export function matrixTable(
    board: ForecastIndexPageProps['board'],
    groups: ReturnType<typeof matrixGroups>,
    layers: Toggles,
): ChartTableData {
    const rows = matrixRows(groups, () => true, layers);
    const data: ChartTableData['rows'][number][] = [];

    for (const row of rows) {
        board.buckets.forEach((bucket, index) => {
            const cell = rowCell(row, index);
            const load = cellLoad(cell, layers);
            const gap = row.kind === 'gap';
            const percent = gap ? null : loadPercent(load, cell.capacity);

            data.push({
                id: `${row.key}-${bucket.key}`,
                who: rowName(row),
                period: bucketLabel(bucket, board.period.granularity).long,
                hours: formatHours(load),
                capacity: gap ? '—' : formatHours(cell.capacity),
                percent: percent === null ? '—' : formatPercentValue(percent),
                level: gap
                    ? t('forecast.table.gap')
                    : LOAD_LEVELS[loadLevel(load, cell.capacity)].label,
            });
        });
    }

    return {
        columns: [
            { key: 'who', label: t('forecast.table.who') },
            { key: 'period', label: t('forecast.table.period') },
            { key: 'hours', label: t('forecast.table.hours'), numeric: true },
            {
                key: 'capacity',
                label: t('forecast.table.capacity'),
                numeric: true,
            },
            {
                key: 'percent',
                label: t('forecast.table.percent'),
                numeric: true,
            },
            { key: 'level', label: t('forecast.table.level') },
        ],
        rows: data,
    };
}

ForecastIndex.layout = {
    breadcrumbs: [{ title: t('forecast.title'), href: index() }],
};
