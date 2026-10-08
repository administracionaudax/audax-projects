import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { DatePicker } from '@/components/domain/date-picker';
import { MultiSelectFilter } from '@/components/reports/multi-select-filter';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { todayInMadrid } from '@/lib/week';
import { options as reportOptions } from '@/routes/reports';
import type {
    ReportFilterKey,
    ReportFiltersProps,
    ReportOptions,
    ReportPeriod,
    ReportQuery,
} from '@/types';

const PERIODS: ReportPeriod[] = ['semana', 'mes', 'trimestre', 'anio', 'rango'];

const ALL_FILTERS: ReportFilterKey[] = [
    'persona',
    'departamento',
    'cliente',
    'proyecto',
    'bolsa',
    'tipo',
    'facturable',
];

let cachedOptions: Promise<ReportOptions> | null = null;

/** Opciones de los filtros (una sola petición por carga de la app). */
export function loadReportOptions(): Promise<ReportOptions> {
    cachedOptions ??= fetch(reportOptions.url(), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            return response.json() as Promise<ReportOptions>;
        })
        .catch((error: unknown) => {
            // Cualquier fallo (también sin red) se olvida: el siguiente intento vuelve a pedirlas.
            cachedOptions = null;
            throw error;
        });

    return cachedOptions;
}

/**
 * Cambio de una fecha del rango: si queda al revés que la otra, la otra se arrastra a la misma
 * fecha. Antes el servidor descartaba un rango invertido y volvía a «Mes» sin avisar (D-310).
 */
export function rangePatch(
    field: 'desde' | 'hasta',
    value: string,
    from: string,
    to: string,
): { desde?: string; hasta?: string } {
    if (field === 'desde') {
        return value > to ? { desde: value, hasta: value } : { desde: value };
    }

    return value < from ? { desde: value, hasta: value } : { hasta: value };
}

/** Solo para tests. */
export function resetReportOptionsCache(): void {
    cachedOptions = null;
}

/**
 * Barra de filtros globales de los informes (SPEC §10): periodo con anterior y siguiente,
 * comparación y filtros por persona, departamento, cliente, proyecto, bolsa, tipo y facturable.
 * Todo vive en la URL (se puede compartir y guardar en favoritos): cada cambio es una visita
 * Inertia a la misma página con la nueva query.
 */
export function ReportFilterBar({
    filters,
    show = ALL_FILTERS,
    url,
    compare = true,
    compareLabel,
}: {
    filters: ReportFiltersProps;
    /** Filtros visibles (los dashboards fijos ocultan el suyo, p. ej. «cliente» en la ficha de cliente). */
    show?: ReportFilterKey[];
    /** URL de la página (sin query); por defecto, la actual. */
    url?: string;
    /** Interruptor «Comparar con el periodo anterior»: solo en las páginas que comparan (no en Facturación). */
    compare?: boolean;
    /** Texto del interruptor (el informe de facturación compara con el año anterior, D-400). */
    compareLabel?: string;
}) {
    const id = useId();
    const [options, setOptions] = useState<ReportOptions | null>(null);
    const [failed, setFailed] = useState(false);
    const query = filters.query;
    const needsOptions = show.some((key) => key !== 'facturable');

    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        if (!needsOptions) {
            return;
        }

        let alive = true;
        loadReportOptions()
            .then((data) => alive && setOptions(data))
            .catch(() => alive && setFailed(true));

        return () => {
            alive = false;
        };
    }, [needsOptions, attempt]);

    const visit = (next: ReportQuery) =>
        router.get(url ?? window.location.pathname, next, {
            preserveState: true,
            preserveScroll: true,
        });

    const update = (patch: Partial<ReportQuery>) => {
        const next: ReportQuery = { ...query, ...patch };

        for (const key of Object.keys(next) as (keyof ReportQuery)[]) {
            const value = next[key];

            if (
                value === undefined ||
                (Array.isArray(value) && value.length === 0)
            ) {
                delete next[key];
            }
        }

        visit(next);
    };

    const setPeriod = (period: ReportPeriod) => {
        if (period === 'rango') {
            update({
                periodo: 'rango',
                fecha: undefined,
                desde: filters.from,
                hasta: filters.to,
            });
        } else {
            update({
                periodo: period,
                fecha: filters.from,
                desde: undefined,
                hasta: undefined,
            });
        }
    };

    const hasFilters = show.some((key) =>
        key === 'facturable'
            ? query.facturable !== undefined
            : (query[key]?.length ?? 0) > 0,
    );

    const multi: {
        key: Exclude<ReportFilterKey, 'facturable'>;
        options: { id: number; name: string; muted?: boolean }[];
    }[] = [
        { key: 'persona', options: options?.people ?? [] },
        { key: 'departamento', options: options?.departments ?? [] },
        {
            key: 'cliente',
            options: (options?.clients ?? []).map((c) => ({
                ...c,
                muted: !c.is_active,
            })),
        },
        {
            key: 'proyecto',
            options: (options?.projects ?? []).map((p) => ({
                ...p,
                muted: p.archived,
            })),
        },
        { key: 'bolsa', options: options?.hour_banks ?? [] },
        { key: 'tipo', options: options?.task_types ?? [] },
    ];

    return (
        <section
            aria-label={t('reports.filters.label')}
            className="grid gap-3 rounded-md border bg-card p-3"
        >
            <div className="flex flex-wrap items-end gap-2">
                <div className="grid gap-1">
                    <Label htmlFor={`${id}-period`}>
                        {t('reports.filters.period')}
                    </Label>
                    <Select
                        value={filters.period}
                        onValueChange={(value) =>
                            setPeriod(value as ReportPeriod)
                        }
                    >
                        <SelectTrigger id={`${id}-period`} className="w-44">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PERIODS.map((period) => (
                                <SelectItem key={period} value={period}>
                                    {t(`reports.period.${period}`)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {filters.period === 'rango' ? (
                    <>
                        <div className="grid gap-1">
                            <Label htmlFor={`${id}-from`}>
                                {t('reports.filters.from')}
                            </Label>
                            <DatePicker
                                id={`${id}-from`}
                                value={query.desde ?? filters.from}
                                clearable={false}
                                onChange={(value) =>
                                    value &&
                                    update(
                                        rangePatch(
                                            'desde',
                                            value,
                                            query.desde ?? filters.from,
                                            query.hasta ?? filters.to,
                                        ),
                                    )
                                }
                                className="w-40"
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor={`${id}-to`}>
                                {t('reports.filters.to')}
                            </Label>
                            <DatePicker
                                id={`${id}-to`}
                                value={query.hasta ?? filters.to}
                                clearable={false}
                                onChange={(value) =>
                                    value &&
                                    update(
                                        rangePatch(
                                            'hasta',
                                            value,
                                            query.desde ?? filters.from,
                                            query.hasta ?? filters.to,
                                        ),
                                    )
                                }
                                className="w-40"
                            />
                        </div>
                    </>
                ) : (
                    <div className="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label={t('reports.filters.previous')}
                            onClick={() => visit(filters.previous)}
                        >
                            <ChevronLeft aria-hidden="true" />
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => update({ fecha: todayInMadrid() })}
                        >
                            {t('reports.filters.today')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label={t('reports.filters.next')}
                            onClick={() => visit(filters.next)}
                        >
                            <ChevronRight aria-hidden="true" />
                        </Button>
                    </div>
                )}

                <p
                    className="self-center text-sm text-muted-foreground"
                    aria-live="polite"
                >
                    {t('reports.filters.range_summary', {
                        from: formatDate(filters.from),
                        to: formatDate(filters.to),
                    })}
                </p>

                {compare ? (
                    <div className="ml-auto flex items-center gap-2 self-center">
                        <Switch
                            id={`${id}-compare`}
                            checked={filters.compare}
                            onCheckedChange={(checked) =>
                                update({ comparar: checked ? '1' : undefined })
                            }
                        />
                        <Label htmlFor={`${id}-compare`}>
                            {compareLabel ?? t('reports.filters.compare')}
                        </Label>
                    </div>
                ) : null}
            </div>

            {compare && filters.comparison ? (
                <p className="text-xs text-muted-foreground">
                    {t('reports.filters.comparison_summary', {
                        from: formatDate(filters.comparison.from),
                        to: formatDate(filters.comparison.to),
                    })}
                </p>
            ) : null}

            <div className="flex flex-wrap items-center gap-2">
                {multi
                    .filter((item) => show.includes(item.key))
                    .map((item) => (
                        <MultiSelectFilter
                            key={item.key}
                            label={t(`reports.filters.${item.key}`)}
                            options={item.options}
                            value={query[item.key] ?? []}
                            disabled={options === null}
                            onChange={(ids) => update({ [item.key]: ids })}
                        />
                    ))}

                {show.includes('facturable') ? (
                    <Select
                        value={query.facturable ?? 'todas'}
                        onValueChange={(value) =>
                            update({
                                facturable:
                                    value === 'todas'
                                        ? undefined
                                        : (value as 'si' | 'no'),
                            })
                        }
                    >
                        <SelectTrigger
                            aria-label={t('reports.filters.facturable')}
                            className="w-52"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="todas">
                                {t('reports.filters.facturable_all')}
                            </SelectItem>
                            <SelectItem value="si">
                                {t('reports.filters.facturable_si')}
                            </SelectItem>
                            <SelectItem value="no">
                                {t('reports.filters.facturable_no')}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                ) : null}

                {hasFilters ? (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => {
                            const cleared: Partial<ReportQuery> = {};
                            for (const key of show) {
                                cleared[key] = undefined;
                            }
                            update(cleared);
                        }}
                    >
                        <X aria-hidden="true" />
                        {t('reports.filters.clear')}
                    </Button>
                ) : null}

                {needsOptions && options === null && !failed ? (
                    <span className="text-xs text-muted-foreground">
                        {t('reports.filters.loading_options')}
                    </span>
                ) : null}
                {failed ? (
                    <span className="flex items-center gap-2 text-xs text-danger">
                        {t('reports.filters.options_error')}
                        <Button
                            type="button"
                            variant="link"
                            size="sm"
                            className="h-auto p-0 text-xs"
                            onClick={() => {
                                setFailed(false);
                                setAttempt((value) => value + 1);
                            }}
                        >
                            {t('reports.filters.options_retry')}
                        </Button>
                    </span>
                ) : null}
            </div>
        </section>
    );
}
