import { Link } from '@inertiajs/react';
import { History } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ProjectActivityItem } from '@/types';

/**
 * Actividad reciente del proyecto (SPEC §6): quién hizo qué y cuándo en el proyecto, sus bolsas
 * y sus tareas. El texto llega ya redactado del servidor (sin datos económicos si no toca).
 */
export function ProjectActivity({ items }: { items: ProjectActivityItem[] }) {
    if (items.length === 0) {
        return (
            <EmptyState
                icon={History}
                title={t('projects.activity.empty')}
                description={t('projects.activity.empty_description')}
            />
        );
    }

    return (
        <ol className="grid gap-0 divide-y rounded-md border">
            {items.map((item) => (
                <li key={item.id} className="grid gap-0.5 px-3 py-2.5 text-sm">
                    <p>
                        <span className="font-medium">
                            {item.actor?.name ?? t('projects.activity.system')}
                        </span>{' '}
                        {item.url ? (
                            <Link
                                href={item.url}
                                className={cn(
                                    'rounded-[3px] hover:underline',
                                    FOCUS_RING,
                                )}
                            >
                                {item.text}
                            </Link>
                        ) : (
                            item.text
                        )}
                    </p>
                    {item.created_at ? (
                        <time
                            dateTime={item.created_at}
                            className="tabular text-xs text-muted-foreground"
                        >
                            {formatDateTime(item.created_at)}
                        </time>
                    ) : null}
                </li>
            ))}
        </ol>
    );
}
