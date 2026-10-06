import { Link } from '@inertiajs/react';
import { UserAvatar } from '@/components/realtime/presence-indicator';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { show as showPerson } from '@/routes/team';
import type { ClientPortfolioTeam } from '@/types/clients';

/**
 * Responsable y equipo de un cliente en la cartera (10.9b, D-232; `ws:ClientView.tsx:433-488`):
 * el responsable con su nombre y hasta tres avatares del equipo con «+N», que llevan a su ficha.
 */
export function ClientTeamCell({
    team,
}: {
    team: ClientPortfolioTeam | null | undefined;
}) {
    if (!team || (team.owner === null && team.team_count === 0)) {
        return <span className="text-muted-foreground">—</span>;
    }

    const rest = team.team_count - team.team.length;

    return (
        <div className="grid gap-1" data-test="client-row-team">
            {team.owner ? (
                <Link
                    href={showPerson.url(team.owner.id)}
                    className={cn(
                        'inline-flex items-center gap-1.5 hover:underline',
                        FOCUS_RING,
                    )}
                    data-test="client-row-owner"
                >
                    <UserAvatar
                        user={team.owner}
                        className="[&_[data-slot=avatar]]:size-6"
                    />
                    <span className="truncate">{team.owner.name}</span>
                </Link>
            ) : null}
            {team.team_count > 0 ? (
                <ul
                    className="flex items-center -space-x-1.5"
                    aria-label={t('clients.team_label', {
                        count: team.team_count,
                    })}
                >
                    {team.team.map((person) => (
                        <li key={person.id}>
                            <Link
                                href={showPerson.url(person.id)}
                                aria-label={person.name}
                                title={person.name}
                                className={cn(
                                    'inline-flex rounded-full ring-2 ring-card',
                                    FOCUS_RING,
                                )}
                            >
                                <UserAvatar
                                    user={person}
                                    className="[&_[data-slot=avatar]]:size-6"
                                />
                            </Link>
                        </li>
                    ))}
                    {rest > 0 ? (
                        <li className="pl-2.5 text-xs text-muted-foreground">
                            {t('clients.team_more', { count: rest })}
                        </li>
                    ) : null}
                </ul>
            ) : null}
        </div>
    );
}
