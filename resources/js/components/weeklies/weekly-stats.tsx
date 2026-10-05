import { Skeleton } from '@/components/ui/skeleton';
import { t } from '@/lib/i18n';
import type { WeeklyStreakSummary } from '@/types/weeklies';

/**
 * Estadísticas de envío del perfil (F-028, D-161; ProfileView de WeeklySync): weeklies enviadas,
 * a tiempo y la racha de semanas seguidas a tiempo.
 */
export function WeeklyStats({ stats }: { stats: WeeklyStreakSummary }) {
    const items = [
        { label: t('weeklies.stats.submitted'), value: stats.submitted },
        { label: t('weeklies.stats.on_time'), value: stats.on_time },
        { label: t('weeklies.stats.streak'), value: stats.streak },
    ];

    return (
        <dl
            className="grid grid-cols-3 divide-x border bg-card"
            data-test="weekly-stats"
        >
            {items.map((item) => (
                <div key={item.label} className="grid gap-1 p-3 text-center">
                    <dt className="order-2 text-xs text-muted-foreground">
                        {item.label}
                    </dt>
                    <dd className="tabular order-1 text-xl font-semibold">
                        {item.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export function WeeklyStatsSkeleton() {
    return <Skeleton className="h-16 w-full" aria-hidden="true" />;
}
