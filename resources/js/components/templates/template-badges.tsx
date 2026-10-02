import { CircleCheck, CirclePause, Trash2 } from 'lucide-react';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { t } from '@/lib/i18n';
import type { Replacements, TranslationKey } from '@/lib/i18n';
import type { TemplateStats } from '@/types/templates';

/** Singular o plural según `count` («1 tarea», «3 tareas»). */
export function countText(
    count: number,
    one: TranslationKey,
    other: TranslationKey,
    replacements: Replacements = {},
): string {
    return t(count === 1 ? one : other, { count, ...replacements });
}

/** Estado de una plantilla o de una regla recurrente: icono + texto (nunca solo color). */
export function ActiveBadge({
    active,
    trashed = false,
    kind = 'template',
}: {
    active: boolean;
    trashed?: boolean;
    kind?: 'template' | 'rule';
}) {
    if (trashed) {
        return (
            <StatusBadge tone="neutral" icon={Trash2}>
                {t('templates.status.trashed')}
            </StatusBadge>
        );
    }

    if (kind === 'rule') {
        return active ? (
            <StatusBadge tone="success" icon={CircleCheck}>
                {t('recurring.status.active')}
            </StatusBadge>
        ) : (
            <StatusBadge tone="neutral" icon={CirclePause}>
                {t('recurring.status.inactive')}
            </StatusBadge>
        );
    }

    return active ? (
        <StatusBadge tone="success" icon={CircleCheck}>
            {t('templates.status.active')}
        </StatusBadge>
    ) : (
        <StatusBadge tone="neutral" icon={CirclePause}>
            {t('templates.status.inactive')}
        </StatusBadge>
    );
}

/** «12 tareas (4 subtareas) · 2 hitos · 5 dependencias · 45 días». */
export function templateStatsText(stats: TemplateStats): string {
    const tasks = countText(
        stats.tasks,
        'templates.stats.tasks_one',
        'templates.stats.tasks_other',
    );

    return [
        stats.subtasks > 0
            ? t('templates.stats.with_subtasks', {
                  tasks,
                  subtasks: countText(
                      stats.subtasks,
                      'templates.stats.subtasks_one',
                      'templates.stats.subtasks_other',
                  ),
              })
            : tasks,
        countText(
            stats.milestones,
            'templates.stats.milestones_one',
            'templates.stats.milestones_other',
        ),
        countText(
            stats.dependencies,
            'templates.stats.dependencies_one',
            'templates.stats.dependencies_other',
        ),
        countText(
            stats.duration_days,
            'templates.stats.days_one',
            'templates.stats.days_other',
        ),
    ].join(' · ');
}
