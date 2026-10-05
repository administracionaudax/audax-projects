import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import {
    badgeLabel,
    deltaLabel,
    deltaTone,
    deltaValueLabel,
    kindLabel,
    tagLabel,
} from '@/lib/project-status';
import { cn } from '@/lib/utils';
import type {
    ProjectKindBadge,
    ProjectKindCode,
} from '@/types/weekly-insights';
import type { WeeklyProjectSnapshot } from '@/types/weeklies';

/** Código del proyecto (ProjectCodeBadge de WeeklySync). */
export function ProjectCodeBadge({
    code,
    className,
}: {
    code: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'tabular inline-flex h-6 shrink-0 items-center border bg-card px-1.5 text-xs font-semibold',
                className,
            )}
        >
            {code}
        </span>
    );
}

/** Tipo de proyecto («Fee mensual», «Auditoría técnica»…, ProjectKindChip). */
export function ProjectKindChip({
    code,
    className,
}: {
    code: ProjectKindCode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex h-6 shrink-0 items-center gap-1 bg-muted px-1.5 text-xs whitespace-nowrap',
                className,
            )}
            data-test="project-kind"
        >
            <span aria-hidden="true" className="tabular text-muted-foreground">
                {code}
            </span>
            {kindLabel(code)}
        </span>
    );
}

/**
 * Insignias de un cliente por tipo de proyecto con su recuento (ProjectBadgeChip, F-120). Compactas,
 * solo el número con el tipo en el tooltip y para el lector de pantalla.
 */
export function ProjectKindBadges({
    badges,
    compact = false,
    className,
}: {
    badges: ProjectKindBadge[];
    compact?: boolean;
    className?: string;
}) {
    if (badges.length === 0) {
        return (
            <span className="text-xs text-muted-foreground">
                {t('weeklies.insights.no_projects')}
            </span>
        );
    }

    return (
        <ul
            aria-label={t('weeklies.insights.badges_label')}
            className={cn('flex flex-wrap gap-1', className)}
            data-test="project-kind-badges"
        >
            {badges.map((badge) => {
                const label = badgeLabel(badge);

                return (
                    <li key={badge.tag}>
                        {compact ? (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span
                                        tabIndex={0}
                                        className="tabular inline-flex h-6 min-w-6 items-center justify-center border px-1 text-xs"
                                    >
                                        <span aria-hidden="true">
                                            {badge.count}
                                        </span>
                                        <span className="sr-only">{label}</span>
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>{label}</TooltipContent>
                            </Tooltip>
                        ) : (
                            <span className="inline-flex h-6 items-center gap-1 border px-1.5 text-xs whitespace-nowrap">
                                <span className="tabular font-semibold">
                                    {badge.count}
                                </span>
                                <span aria-hidden="true">
                                    {label.replace(/^\d+\s/, '')}
                                </span>
                                <span className="sr-only">
                                    {tagLabel(badge.tag)}
                                </span>
                            </span>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

/**
 * Barra de consumo (ProjectProgressBar): verde hasta el 75 %, aviso hasta el 90 %, peligro a partir
 * de ahí y al pasarse; con la marca de lo esperado en un fee. Nunca solo color: el porcentaje y lo
 * consumido van en texto al lado y en la etiqueta.
 */
export function ProjectProgressBar({
    progress,
    expected = null,
    over = false,
    label,
    size = 'md',
}: {
    progress: number;
    expected?: number | null;
    over?: boolean;
    label: string;
    size?: 'sm' | 'md';
}) {
    const width = Math.max(0, Math.min(progress, 100));
    const marker =
        expected === null ? null : Math.max(0, Math.min(expected, 100));
    const fill = over
        ? 'bg-danger'
        : progress >= 90
          ? 'bg-danger'
          : progress > 75
            ? 'bg-warning'
            : 'bg-success';

    return (
        <div
            className={cn('relative', size === 'sm' ? 'py-0.5' : 'py-1')}
            role="img"
            aria-label={label}
            data-test="project-progress"
        >
            <div
                className={cn(
                    'relative overflow-hidden border bg-muted',
                    size === 'sm' ? 'h-1.5' : 'h-2',
                )}
            >
                <span
                    className={cn('absolute inset-y-0 left-0', fill)}
                    style={{ width: `${width}%` }}
                />
            </div>
            {marker !== null ? (
                <span
                    aria-hidden="true"
                    className="absolute inset-y-0 w-0.5 bg-foreground"
                    style={{ left: `calc(${marker}% - 1px)` }}
                    data-test="project-expected-marker"
                />
            ) : null}
        </div>
    );
}

/**
 * Desviación frente a lo esperado de un fee (ProjectDeltaIndicator): «+1:30» en tono de peligro si va
 * por encima y de éxito si va por debajo, con la frase completa en el tooltip y para el lector.
 */
export function ProjectDeltaIndicator({
    entry,
    className,
}: {
    entry: WeeklyProjectSnapshot;
    className?: string;
}) {
    const label = deltaLabel(entry);
    const value = deltaValueLabel(entry);

    if (label === null || value === null) {
        return null;
    }

    const tone = deltaTone(entry);

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    tabIndex={0}
                    className={cn(
                        'tabular text-xs font-semibold',
                        tone === 'positive'
                            ? 'text-danger'
                            : tone === 'negative'
                              ? 'text-success'
                              : 'text-muted-foreground',
                        className,
                    )}
                    data-test="project-delta"
                >
                    <span aria-hidden="true">{value}</span>
                    <span className="sr-only">{label}</span>
                </span>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}

/** «12:30 / 20:00» o «12:30 consumidas». */
export function consumedText(entry: WeeklyProjectSnapshot): string {
    return entry.budget_minutes && entry.budget_minutes > 0
        ? t('weeklies.project_status.consumed_of', {
              consumed: formatMinutes(entry.consumed_minutes),
              budget: formatMinutes(entry.budget_minutes),
          })
        : t('weeklies.project_status.consumed_only', {
              consumed: formatMinutes(entry.consumed_minutes),
          });
}
