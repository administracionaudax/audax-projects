import { CalendarOff, Check, Clock } from 'lucide-react';
import { UserAvatar } from '@/components/realtime/presence-indicator';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    isPendingStatus,
    isSubmittedStatus,
} from '@/components/weeklies/weekly-ui';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { WeeklyTeamMember, WeeklyTeamStatus } from '@/types/weeklies';

type Group = 'submitted' | 'pending' | 'exempt';

const GROUPS: Group[] = ['submitted', 'pending', 'exempt'];

function groupOf(member: WeeklyTeamMember): Group | null {
    if (isSubmittedStatus(member.status)) {
        return 'submitted';
    }

    if (member.status === 'exempt') {
        return 'exempt';
    }

    // Una semana cerrada: quien no la envió también sale con los pendientes.
    if (isPendingStatus(member.status) || member.status === 'missed') {
        return 'pending';
    }

    return null;
}

/** «Ana García · Enviado con retraso». */
function describe(member: WeeklyTeamMember): string {
    const status = t(`weeklies.person_status.${member.status}`);
    const reason = member.exemption_reason
        ? ` (${t(`weeklies.exemption_reason.${member.exemption_reason}`)})`
        : '';

    return `${member.user.name} · ${status}${reason}`;
}

/**
 * Tira de avatares del equipo de una semana (F-067, WeeklyTeamStatusStrip de WeeklySync): primero
 * quien ha enviado (con la marca), luego quien falta y al final los exentos, separados por una
 * línea. Cada avatar dice su nombre y su estado (tooltip con el teclado y texto para el lector de
 * pantalla). Debajo, el recuento: «3 / 5 enviadas (1 exento)». En el móvil, la tira salta de línea
 * en vez de desplazarse.
 */
export function TeamStatusStrip({
    team,
    showCounter = true,
    label,
    className,
}: {
    team: WeeklyTeamStatus;
    showCounter?: boolean;
    /** Nombre accesible de la tira (por defecto, «Estado del equipo»). */
    label?: string;
    className?: string;
}) {
    const groups: Record<Group, WeeklyTeamMember[]> = {
        submitted: [],
        pending: [],
        exempt: [],
    };

    for (const member of team.members) {
        const group = groupOf(member);

        if (group) {
            groups[group].push(member);
        }
    }

    const visible = GROUPS.filter((group) => groups[group].length > 0);
    const { submitted, expected, exempt } = team.counts;

    return (
        <div
            className={cn('grid min-w-0 gap-2', className)}
            data-test="weekly-team-strip"
        >
            {visible.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('weeklies.team.empty')}
                </p>
            ) : (
                <div
                    role="group"
                    aria-label={label ?? t('weeklies.team.label')}
                    className="flex min-w-0 flex-wrap items-center gap-y-2"
                >
                    {visible.map((group, index) => (
                        <ul
                            key={group}
                            aria-label={t(`weeklies.team.group.${group}`)}
                            className={cn(
                                'flex flex-wrap items-center -space-x-1.5 gap-y-1.5',
                                index > 0 && 'ml-2 border-l pl-2',
                            )}
                            data-test={`weekly-team-${group}`}
                        >
                            {groups[group].map((member) => (
                                <li key={member.user.id} className="flex">
                                    <MemberAvatar
                                        member={member}
                                        group={group}
                                    />
                                </li>
                            ))}
                        </ul>
                    ))}
                </div>
            )}
            {showCounter ? (
                <p
                    className="text-xs text-muted-foreground"
                    data-test="weekly-team-counter"
                >
                    {t('weeklies.team.counter', { submitted, expected })}
                    {exempt > 0
                        ? ` ${
                              exempt === 1
                                  ? t('weeklies.participation.exempt_one')
                                  : t('weeklies.participation.exempt_other', {
                                        count: exempt,
                                    })
                          }`
                        : ''}
                </p>
            ) : null}
        </div>
    );
}

function MemberAvatar({
    member,
    group,
}: {
    member: WeeklyTeamMember;
    group: Group;
}) {
    const text = describe(member);
    const Icon =
        group === 'submitted'
            ? Check
            : group === 'exempt'
              ? CalendarOff
              : Clock;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    tabIndex={0}
                    role="img"
                    aria-label={text}
                    className={cn(
                        'relative inline-flex rounded-full border-2 border-card bg-card',
                        FOCUS_RING,
                    )}
                    data-test="weekly-team-member"
                    data-status={member.status}
                >
                    <UserAvatar
                        user={member.user}
                        className={cn(
                            group !== 'submitted' && 'opacity-60 grayscale',
                        )}
                    />
                    <span
                        aria-hidden="true"
                        className={cn(
                            'absolute -right-0.5 -bottom-0.5 flex size-3.5 items-center justify-center rounded-full border border-card',
                            group === 'submitted'
                                ? 'bg-success text-white'
                                : group === 'exempt'
                                  ? 'bg-warning text-white'
                                  : 'bg-muted text-muted-foreground',
                        )}
                    >
                        <Icon className="size-2.5" strokeWidth={2.5} />
                    </span>
                </span>
            </TooltipTrigger>
            <TooltipContent>{text}</TooltipContent>
        </Tooltip>
    );
}
