import { formatHoursTick, hourTicks } from '@/components/charts/chart-config';
import { useWidth } from '@/components/forecast/use-width';
import { bucketLabel, isCurrentBucket } from '@/lib/forecast';
import { t } from '@/lib/i18n';
import type { ProjectPlanningPageProps } from '@/types/forecast';

type Week = ProjectPlanningPageProps['weeks'][number];

const PAD = { top: 18, right: 56, bottom: 44, left: 48 };

/**
 * Plan frente a imputado por semana (D-296), con un solo eje en horas: el plan es una línea
 * escalonada azul (las asignaciones; baja en los festivos) y lo imputado son columnas turquesa (la
 * semana en curso, atenuada); la línea de hoy y, debajo de una semana pasada, ⓘ con quién tenía
 * plan y no imputó nada.
 */
export function PlanVsLoggedChart({
    weeks,
    today,
}: {
    weeks: Week[];
    today: string;
}) {
    const [ref, width] = useWidth<HTMLDivElement>();
    const height = 260;
    const max = Math.max(
        1,
        ...weeks.map((week) => Math.max(week.planned, week.logged)),
    );
    const ticks = hourTicks(max * 1.1, 5);
    const yMax = ticks[ticks.length - 1] || 1;
    const plotWidth = Math.max(width - PAD.left - PAD.right, 40);
    const slot = plotWidth / Math.max(weeks.length, 1);
    const bar = Math.min(24, Math.max(4, slot * 0.5));
    const y = (value: number) =>
        PAD.top + (height - PAD.top - PAD.bottom) * (1 - value / yMax);
    const every = Math.max(1, Math.ceil(56 / slot));
    const todayIndex = weeks.findIndex((week) => isCurrentBucket(week, today));
    const missing = weeks.filter((week) => week.missing.length > 0);
    let path = '';

    weeks.forEach((week, index) => {
        const x0 = PAD.left + slot * index;
        path += `${index === 0 ? 'M' : 'L'}${x0},${y(week.planned)} L${x0 + slot},${y(week.planned)} `;
    });

    return (
        <div ref={ref} className="min-w-0" data-test="plan-vs-logged">
            <svg
                width={width}
                height={height}
                aria-hidden="true"
                focusable="false"
                className="block overflow-visible"
            >
                {ticks.map((tick) => (
                    <g key={tick}>
                        <line
                            x1={PAD.left}
                            x2={PAD.left + plotWidth}
                            y1={y(tick)}
                            y2={y(tick)}
                            stroke="var(--border)"
                        />
                        <text
                            x={PAD.left - 6}
                            y={y(tick)}
                            dy="0.32em"
                            textAnchor="end"
                            className="fill-muted-foreground text-[11px] tabular-nums"
                        >
                            {formatHoursTick(tick)}
                        </text>
                    </g>
                ))}
                {weeks.map((week, index) => {
                    const center = PAD.left + slot * index + slot / 2;
                    const current = index === todayIndex;
                    const label = bucketLabel(week, 'week');

                    return (
                        <g key={week.key}>
                            {week.logged > 0 ? (
                                <rect
                                    x={center - bar / 2}
                                    y={y(week.logged)}
                                    width={bar}
                                    height={y(0) - y(week.logged)}
                                    fill="var(--chart-2)"
                                    opacity={current ? 0.45 : 1}
                                />
                            ) : null}
                            {index % every === 0 ? (
                                <>
                                    <text
                                        x={center}
                                        y={height - PAD.bottom + 14}
                                        textAnchor="middle"
                                        className="fill-foreground text-[11px]"
                                    >
                                        {label.short}
                                    </text>
                                    <text
                                        x={center}
                                        y={height - PAD.bottom + 26}
                                        textAnchor="middle"
                                        className="fill-muted-foreground text-[10px]"
                                    >
                                        {label.sub}
                                    </text>
                                </>
                            ) : null}
                            {week.missing.length > 0 ? (
                                <circle
                                    data-test="missing-mark"
                                    cx={center}
                                    cy={height - 9}
                                    r={4.5}
                                    fill="none"
                                    stroke="var(--danger)"
                                    strokeWidth={1.5}
                                />
                            ) : null}
                        </g>
                    );
                })}
                <line
                    x1={PAD.left}
                    x2={PAD.left + plotWidth}
                    y1={y(0)}
                    y2={y(0)}
                    stroke="var(--muted-foreground)"
                />
                <path
                    d={path}
                    fill="none"
                    stroke="var(--chart-1)"
                    strokeWidth={2}
                />
                {weeks.length > 0 ? (
                    <text
                        x={PAD.left + plotWidth + 6}
                        y={y(weeks[weeks.length - 1].planned)}
                        dy="0.32em"
                        className="fill-foreground text-[11px]"
                    >
                        {t('forecast.planning.planned')}
                    </text>
                ) : null}
                {todayIndex >= 0 ? (
                    <g>
                        <line
                            x1={PAD.left + slot * todayIndex}
                            x2={PAD.left + slot * todayIndex}
                            y1={PAD.top - 6}
                            y2={y(0)}
                            stroke="var(--brand)"
                            strokeWidth={2}
                        />
                        <text
                            x={PAD.left + slot * todayIndex}
                            y={PAD.top - 9}
                            textAnchor="middle"
                            className="fill-primary-text text-[11px]"
                        >
                            {t('forecast.estimate.today')}
                        </text>
                    </g>
                ) : null}
            </svg>
            {missing.length > 0 ? (
                <p
                    className="mt-2 flex items-start gap-1.5 text-xs text-muted-foreground"
                    data-test="missing-list"
                >
                    <span
                        aria-hidden="true"
                        className="mt-0.5 size-2.5 shrink-0 rounded-full border-[1.5px] border-danger"
                    />
                    {t('forecast.planning.missing_list', {
                        weeks: missing
                            .slice(-6)
                            .map(
                                (week) =>
                                    `${bucketLabel(week, 'week').short} (${week.missing.join(', ')})`,
                            )
                            .join(' · '),
                    })}
                </p>
            ) : null}
        </div>
    );
}
