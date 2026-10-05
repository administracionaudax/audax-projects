/**
 * Piezas puras del «Estado de proyectos» (F-119 a F-121), port de ws:src/lib/projectStatus.ts con
 * los datos de Audax en minutos: progreso, lo esperado de un fee, la desviación y sus textos, los
 * tipos de proyecto de WeeklySync (BH, FE, WE…) y el orden de la cartera.
 */
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import type {
    ProjectKindBadge,
    ProjectKindCode,
    ProjectKindTag,
    ProjectStatusEntry,
} from '@/types/weekly-insights';
import type { WeeklyProjectSnapshot } from '@/types/weeklies';

/** Orden de los grupos (PROJECT_TAGS). */
export const PROJECT_TAGS: ProjectKindTag[] = [
    'product',
    'ecommerce',
    'web',
    'audit',
    'monthly_fee',
    'hour_bank',
    'branding',
    'general',
];

/** Prefijo → grupo (PROJECT_PREFIX_TO_KIND + PROJECT_KIND_TO_TAG). */
export const PREFIX_TO_TAG: Record<ProjectKindCode, ProjectKindTag> = {
    PR: 'product',
    EC: 'ecommerce',
    WE: 'web',
    AD: 'audit',
    AM: 'audit',
    AT: 'audit',
    FE: 'monthly_fee',
    BH: 'hour_bank',
    BR: 'branding',
    GE: 'general',
};

export function kindLabel(code: ProjectKindCode): string {
    return t(`weeklies.insights.kind.${code}`);
}

export function tagLabel(tag: ProjectKindTag): string {
    return t(`weeklies.insights.tag.${tag}`);
}

/** «1 Fee mensual», «3 Fees mensuales» (getProjectCountLabel). */
export function badgeLabel(badge: ProjectKindBadge): string {
    const count = Math.max(0, Math.round(badge.count));

    return t(
        count === 1
            ? `weeklies.insights.tag_count.${badge.tag}.one`
            : `weeklies.insights.tag_count.${badge.tag}.other`,
        { count },
    );
}

/** Consumido sobre presupuesto en % (sin tope); 0 sin presupuesto (getProjectStatusProgress). */
export function progressPercent(entry: WeeklyProjectSnapshot): number {
    const budget = entry.budget_minutes ?? 0;

    return budget > 0
        ? Math.max(0, (entry.consumed_minutes / budget) * 100)
        : 0;
}

/** Lo esperado de un fee en % del presupuesto; null si no es un fee con presupuesto. */
export function expectedPercent(entry: WeeklyProjectSnapshot): number | null {
    if (entry.expected_minutes === null || !entry.budget_minutes) {
        return null;
    }

    return Math.max(
        0,
        Math.round((entry.expected_minutes / entry.budget_minutes) * 10000) /
            100,
    );
}

export function isOverBudget(entry: WeeklyProjectSnapshot): boolean {
    return (
        (entry.budget_minutes ?? 0) > 0 &&
        entry.consumed_minutes > (entry.budget_minutes ?? 0)
    );
}

export type DeltaTone = 'positive' | 'negative' | 'neutral';

/** Por encima de lo esperado (positive), por debajo (negative) o en línea (neutral). */
export function deltaTone(entry: WeeklyProjectSnapshot): DeltaTone {
    const delta = entry.deviation_minutes;

    if (delta === null || Math.abs(delta) < 1) {
        return 'neutral';
    }

    return delta > 0 ? 'positive' : 'negative';
}

/** «+1:30 h», «-0:45 h» o «0:00 h»; null sin esperado (getProjectStatusDeltaValueLabel). */
export function deltaValueLabel(entry: WeeklyProjectSnapshot): string | null {
    const delta = entry.deviation_minutes;

    if (delta === null) {
        return null;
    }

    if (Math.abs(delta) < 1) {
        return formatMinutes(0);
    }

    return `${delta > 0 ? '+' : '-'}${formatMinutes(Math.abs(delta))}`;
}

/** «En línea con lo esperado», «+1:30 sobre lo esperado»… (getProjectStatusDeltaLabel). */
export function deltaLabel(entry: WeeklyProjectSnapshot): string | null {
    const delta = entry.deviation_minutes;

    if (delta === null) {
        return null;
    }

    if (Math.abs(delta) < 1) {
        return t('weeklies.projects.on_expected');
    }

    return delta > 0
        ? t('weeklies.projects.above_expected', {
              time: formatMinutes(delta),
          })
        : t('weeklies.projects.below_expected', {
              time: formatMinutes(Math.abs(delta)),
          });
}

/** Orden de la cartera: grupo, prefijo y código (sortProjectStatuses). */
export function compareEntries(
    a: ProjectStatusEntry,
    b: ProjectStatusEntry,
): number {
    const tagA = PROJECT_TAGS.indexOf(PREFIX_TO_TAG[a.kind_code]);
    const tagB = PROJECT_TAGS.indexOf(PREFIX_TO_TAG[b.kind_code]);

    if (tagA !== tagB) {
        return tagA - tagB;
    }

    if (a.kind_code !== b.kind_code) {
        return a.kind_code.localeCompare(b.kind_code);
    }

    return a.code.localeCompare(b.code, 'es', { numeric: true });
}
