import type { ReactNode } from 'react';
import {
    LOAD_LEVELS,
    loadLevel,
    loadPercent,
} from '@/components/charts/thresholds';
import type { LoadLevel } from '@/components/charts/thresholds';
import { LAYER_SVG_FILL } from '@/components/forecast/layer-swatch';
import { STACK_GAP } from '@/components/forecast/department-column';
import {
    bucketLabel,
    formatHours,
    formatPercentValue,
    impactWorst,
} from '@/lib/forecast';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ForecastImpact, ImpactCell } from '@/types/forecast';

const CELL_WIDTH = 96;
const CHART_HEIGHT = 40;

function LevelMark({ level, suffix }: { level: LoadLevel; suffix: string }) {
    if (level !== 'high' && level !== 'over') {
        return <>{suffix}</>;
    }

    const meta = LOAD_LEVELS[level];
    const Icon = meta.icon;

    // El icono, el nivel y la puntuación van juntos: «⚠ Alta,» nunca se parte.
    return (
        <span className="ml-1.5 inline-flex items-center gap-1 whitespace-nowrap">
            <Icon aria-hidden="true" className={cn('size-4', meta.tone)} />
            {meta.label}
            {suffix}
        </span>
    );
}

/**
 * La frase con el peor caso del impacto (D-295): el departamento y la persona que más suben si se
 * coge el previsto. Es lo que hace falta para decidir.
 */
export function ImpactSentence({ impact }: { impact: ForecastImpact }) {
    const departments = impactWorst(
        impact.departments.map((row) => ({
            name: row.name ?? t('forecast.board.no_department'),
            cells: row.cells,
        })),
        impact.buckets,
    );
    const people = impactWorst(impact.people, impact.buckets);

    if (!departments && !people) {
        return null;
    }

    const when = (bucket: ForecastImpact['buckets'][number]) =>
        impact.granularity === 'week'
            ? t('forecast.impact.in_week', {
                  week: bucketLabel(bucket, 'week').long,
              })
            : t('forecast.impact.in_month', {
                  month: bucketLabel(bucket, 'month').long,
              });

    const parts: ReactNode[] = [];

    if (departments) {
        parts.push(
            <span key="d">
                <span className="font-medium">{departments.name}</span>{' '}
                {t('forecast.impact.goes_from', {
                    from: formatPercentValue(departments.without),
                    to: formatPercentValue(departments.with),
                    when: when(departments.bucket),
                })}
                <LevelMark
                    level={departments.level}
                    suffix={people ? t('forecast.impact.and_comma') : '.'}
                />
            </span>,
        );
    }

    if (people) {
        parts.push(
            <span key="p">
                <span className="font-medium">{people.name}</span>{' '}
                {t('forecast.impact.reaches', {
                    to: formatPercentValue(people.with),
                    when:
                        departments &&
                        departments.bucket.key === people.bucket.key
                            ? ''
                            : ` ${when(people.bucket)}`,
                })}
                <LevelMark level={people.level} suffix="." />
            </span>,
        );
    }

    return (
        <p
            className="text-lg leading-relaxed text-balance"
            data-test="impact-sentence"
        >
            {t('forecast.impact.if_taken')} {parts[0]}
            {parts.length > 1 ? (
                <>
                    {' '}
                    {t('forecast.impact.and')} {parts[1]}
                </>
            ) : null}
        </p>
    );
}

/**
 * Una celda «sin / con» (D-295): una columna con «sin este proyecto» en el gris de contexto y, encima,
 * lo que añade el previsto (con trama si es posible), frente a la línea de capacidad; debajo,
 * «91 → 101 %» con el icono si el resultado es «Alta» o «Sobrecarga».
 */
export function ImpactCellView({
    cell,
    yMax,
    layer,
}: {
    cell: ImpactCell;
    yMax: number;
    layer: ForecastImpact['layer'];
}) {
    const top = 3;
    const bottom = CHART_HEIGHT - 1;
    const y = (value: number) =>
        top + (bottom - top) * (1 - Math.min(value, yMax) / Math.max(yMax, 1));
    const barWidth = 24;
    const x = (CELL_WIDTH - 16 - barWidth) / 2;
    const added = cell.with - cell.without;
    const before = loadPercent(cell.without, cell.capacity);
    const after = loadPercent(cell.with, cell.capacity);
    const level = loadLevel(cell.with, cell.capacity);
    const meta = LOAD_LEVELS[level];
    const Icon = meta.icon;
    const strong = level === 'high' || level === 'over';

    return (
        <div className="grid gap-1" data-test="impact-cell">
            <svg
                width={CELL_WIDTH - 16}
                height={CHART_HEIGHT}
                aria-hidden="true"
                focusable="false"
                className="block"
            >
                {cell.without > 0 ? (
                    <rect
                        x={x}
                        y={y(cell.without)}
                        width={barWidth}
                        height={Math.max(0, bottom - y(cell.without))}
                        fill="var(--context-mark)"
                    />
                ) : null}
                {added > 0 ? (
                    <rect
                        x={x}
                        y={y(cell.with)}
                        width={barWidth}
                        height={Math.max(
                            0,
                            y(cell.without) -
                                y(cell.with) -
                                (cell.without > 0 ? STACK_GAP : 0),
                        )}
                        fill={LAYER_SVG_FILL[layer]}
                    />
                ) : null}
                <line
                    x1={0}
                    x2={CELL_WIDTH - 16}
                    y1={bottom}
                    y2={bottom}
                    stroke="var(--muted-foreground)"
                    strokeWidth={1}
                />
                {cell.capacity > 0 ? (
                    <line
                        x1={0}
                        x2={CELL_WIDTH - 16}
                        y1={y(cell.capacity)}
                        y2={y(cell.capacity)}
                        stroke="var(--foreground)"
                        strokeWidth={2}
                    />
                ) : null}
            </svg>
            <span
                className={cn(
                    'tabular flex items-center gap-1 text-xs whitespace-nowrap',
                    strong
                        ? 'font-medium text-foreground'
                        : 'text-muted-foreground',
                )}
            >
                {after === null ? (
                    t('forecast.impact.no_capacity')
                ) : added > 0 ? (
                    <>
                        <span className="font-normal text-muted-foreground">
                            {before}
                        </span>
                        <span aria-hidden="true">→</span>
                        {strong ? (
                            <Icon
                                aria-hidden="true"
                                className={cn('size-3', meta.tone)}
                            />
                        ) : null}
                        {formatPercentValue(after)}
                    </>
                ) : (
                    formatPercentValue(after)
                )}
            </span>
        </div>
    );
}

type ImpactRow = { key: string; name: string; cells: ImpactCell[] };

/**
 * Rejilla del impacto «sin / con» (D-295): los periodos del previsto (semanas o meses) por
 * departamento afectado y por persona con nombre. Cada fila con su escala; cada celda dice en su
 * nombre accesible el antes, el después y el nivel.
 */
export function ImpactGrid({ impact }: { impact: ForecastImpact }) {
    const sections: { title: string; rows: ImpactRow[] }[] = [
        {
            title: t('forecast.impact.departments'),
            rows: impact.departments.map((row) => ({
                key: `d${row.id ?? 'none'}`,
                name: row.name ?? t('forecast.board.no_department'),
                cells: row.cells,
            })),
        },
        {
            title: t('forecast.impact.people'),
            rows: impact.people.map((row) => ({
                key: `p${row.id}`,
                name: row.name,
                cells: row.cells,
            })),
        },
    ].filter((section) => section.rows.length > 0);
    const labels = impact.buckets.map((bucket) =>
        bucketLabel(bucket, impact.granularity),
    );

    return (
        <div className="overflow-x-auto" data-test="impact-grid">
            <table className="border-separate border-spacing-0 text-sm">
                <caption className="sr-only">
                    {t('forecast.impact.caption')}
                </caption>
                <thead>
                    <tr>
                        <th
                            scope="col"
                            className="sticky left-0 z-10 w-36 min-w-36 bg-card px-0 text-left md:w-48 md:min-w-48"
                        >
                            <span className="sr-only">
                                {t('forecast.impact.who')}
                            </span>
                        </th>
                        {labels.map((label, index) => (
                            <th
                                key={impact.buckets[index].key}
                                scope="col"
                                className="bg-transparent px-2 pb-2 text-left leading-tight font-normal tracking-normal normal-case"
                                style={{ minWidth: CELL_WIDTH }}
                            >
                                <span className="sr-only">{label.long}</span>
                                <span
                                    aria-hidden="true"
                                    className="block text-xs text-foreground"
                                >
                                    {label.short}
                                </span>
                                <span
                                    aria-hidden="true"
                                    className="block text-[0.6875rem] text-muted-foreground"
                                >
                                    {label.sub}
                                </span>
                            </th>
                        ))}
                    </tr>
                </thead>
                {sections.map((section) => (
                    <tbody key={section.title}>
                        <tr>
                            <th
                                scope="colgroup"
                                colSpan={labels.length + 1}
                                className="sticky left-0 bg-card px-0 pt-3 pb-1 text-left text-xs font-medium tracking-[0.12em] text-muted-foreground uppercase"
                            >
                                {section.title}
                            </th>
                        </tr>
                        {section.rows.map((row) => {
                            const added = row.cells.reduce(
                                (sum, cell) => sum + cell.with - cell.without,
                                0,
                            );
                            const yMax =
                                Math.max(
                                    1,
                                    ...row.cells.map((cell) =>
                                        Math.max(cell.capacity, cell.with),
                                    ),
                                ) * 1.1;

                            return (
                                <tr key={row.key} data-test="impact-row">
                                    <th
                                        scope="row"
                                        className="sticky left-0 z-10 border-t bg-card py-2 pr-3 text-left align-top font-normal"
                                    >
                                        <span className="block">
                                            {row.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {t('forecast.impact.adds', {
                                                hours: formatHours(added),
                                            })}
                                        </span>
                                    </th>
                                    {row.cells.map((cell, index) => (
                                        <td
                                            key={impact.buckets[index].key}
                                            className="border-t px-2 py-2 align-top"
                                            aria-label={t(
                                                'forecast.impact.cell_label',
                                                {
                                                    period: labels[index].long,
                                                    before:
                                                        formatPercentValue(
                                                            loadPercent(
                                                                cell.without,
                                                                cell.capacity,
                                                            ),
                                                        ) || '—',
                                                    after:
                                                        formatPercentValue(
                                                            loadPercent(
                                                                cell.with,
                                                                cell.capacity,
                                                            ),
                                                        ) || '—',
                                                    level: LOAD_LEVELS[
                                                        loadLevel(
                                                            cell.with,
                                                            cell.capacity,
                                                        )
                                                    ].label,
                                                },
                                            )}
                                        >
                                            <ImpactCellView
                                                cell={cell}
                                                yMax={yMax}
                                                layer={impact.layer}
                                            />
                                        </td>
                                    ))}
                                </tr>
                            );
                        })}
                    </tbody>
                ))}
            </table>
        </div>
    );
}
