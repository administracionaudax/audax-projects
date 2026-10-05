import { Link } from '@inertiajs/react';
import { Skeleton } from '@/components/ui/skeleton';
import { UserAvatar } from '@/components/realtime/presence-indicator';
import { MyWeeklyCallout } from '@/components/weeklies/my-weekly-callout';
import {
    Participation,
    StreakValue,
    WeekLabel,
} from '@/components/weeklies/weekly-ui';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as weekliesIndex } from '@/routes/weeklies';
import type { HomeWeeklyCard as Card } from '@/types/weeklies';

/**
 * Tarjeta «Weekly» de Inicio (F-030 a F-040, D-161): mi weekly de la semana activa con su botón,
 * mi racha y, para quien gestiona, cuántos han enviado y quién falta (hasta 8).
 */
export function HomeWeeklyCard({ card }: { card: Card }) {
    const { cycle, me, streak, team, can } = card;

    return (
        <div className="grid gap-3" data-test="home-weekly">
            {cycle === null ? (
                <p className="text-sm text-muted-foreground">
                    {t('weeklies.overview.no_active')}
                </p>
            ) : (
                <>
                    <p className="text-sm text-muted-foreground">
                        <WeekLabel cycle={cycle} />
                    </p>
                    {me ? (
                        <MyWeeklyCallout cycle={cycle} me={me} compact />
                    ) : null}
                </>
            )}

            <StreakValue streak={streak.streak} className="text-sm" />

            {team && cycle ? (
                <div className="grid gap-2 border-t pt-3">
                    <Participation
                        submitted={team.counts.submitted}
                        expected={team.counts.expected}
                        exempt={team.counts.exempt}
                    />
                    {team.pending.length > 0 ? (
                        <div className="grid gap-1.5">
                            <p className="text-xs text-muted-foreground">
                                {t('weeklies.home.pending', {
                                    count: team.counts.pending,
                                })}
                            </p>
                            <ul
                                className="flex flex-wrap gap-1"
                                aria-label={t('weeklies.home.pending_label')}
                            >
                                {team.pending.map((person) => (
                                    <li
                                        key={person.id}
                                        title={person.name}
                                        className="flex"
                                    >
                                        <UserAvatar user={person} />
                                        <span className="sr-only">
                                            {person.name}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : (
                        <p className="text-xs text-muted-foreground">
                            {t('weeklies.manage.all_done')}
                        </p>
                    )}
                </div>
            ) : null}

            {can.open ? (
                <Link
                    href={weekliesIndex.url()}
                    className={cn(
                        'self-start text-sm text-primary-text hover:underline',
                        FOCUS_RING,
                    )}
                >
                    {t('weeklies.home.open')}
                </Link>
            ) : null}
        </div>
    );
}

export function HomeWeeklySkeleton() {
    return (
        <div className="grid gap-3" aria-hidden="true">
            <Skeleton className="h-4 w-40" />
            <Skeleton className="h-20 w-full" />
            <Skeleton className="h-4 w-24" />
        </div>
    );
}
