import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import type { TaskActivityItem } from '@/types';

/**
 * Actividad de la tarea (SPEC §4.6): quién cambió qué, antes y después, y cuándo. Los valores
 * llegan ya en español desde el servidor (App\Domain\Tasks\TaskActivityFeed).
 */
export function TaskActivity({ items }: { items: TaskActivityItem[] }) {
    if (items.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('task_activity.empty')}
            </p>
        );
    }

    return (
        <ol className="grid gap-3" aria-label={t('task_activity.list')}>
            {items.map((item) => {
                const who = item.causer ?? t('task_activity.system');

                return (
                    <li
                        key={item.id}
                        className="grid gap-1 border-l-2 pl-3 text-sm"
                    >
                        <p>
                            <span className="font-medium">{who}</span>{' '}
                            {item.event === 'created'
                                ? t('task_activity.created')
                                : item.event === 'deleted'
                                  ? t('task_activity.deleted')
                                  : item.event === 'restored'
                                    ? t('task_activity.restored')
                                    : t('task_activity.updated')}
                            {item.created_at ? (
                                <>
                                    {' · '}
                                    <time
                                        dateTime={item.created_at}
                                        className="text-xs text-muted-foreground"
                                    >
                                        {formatDateTime(item.created_at)}
                                    </time>
                                </>
                            ) : null}
                        </p>
                        {item.changes.length > 0 ? (
                            <ul className="grid gap-0.5 text-xs text-muted-foreground">
                                {item.changes.map((change) => (
                                    <li key={change.field}>
                                        {change.field === 'description'
                                            ? t(
                                                  'task_activity.description_changed',
                                              )
                                            : t('task_activity.change', {
                                                  field: t(
                                                      `task_activity.field.${change.field}`,
                                                  ),
                                                  from:
                                                      change.from ??
                                                      t(
                                                          'task_activity.empty_value',
                                                      ),
                                                  to:
                                                      change.to ??
                                                      t(
                                                          'task_activity.empty_value',
                                                      ),
                                              })}
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </li>
                );
            })}
        </ol>
    );
}
