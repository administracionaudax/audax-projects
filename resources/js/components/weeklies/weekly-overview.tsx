import { Link, router } from '@inertiajs/react';
import {
    Building2,
    CalendarCog,
    CalendarOff,
    CalendarPlus,
    CircleCheck,
    Clock,
    ExternalLink,
    FileText,
    Plus,
    ShieldCheck,
    UserMinus,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { UserAvatar } from '@/components/realtime/presence-indicator';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { MyWeeklyCallout } from '@/components/weeklies/my-weekly-callout';
import { TeamStatusStrip } from '@/components/weeklies/team-status-strip';
import {
    closeBlockers,
    closeHint,
    CloseWeekDialog,
} from '@/components/weeklies/weekly-close-dialog';
import {
    DeadlineDialog,
    ExemptDialog,
    JoinProjectsDialog,
} from '@/components/weeklies/weekly-dialogs';
import {
    ClientIcon,
    CycleProgressBadge,
    isPendingStatus,
    Participation,
    PersonStatusBadge,
    StreakValue,
    WeekLabel,
} from '@/components/weeklies/weekly-ui';
import { useAbilities } from '@/hooks/use-auth';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { show as showCycle, store as storeCycle } from '@/routes/weeklies';
import { destroy as destroyExemption } from '@/routes/weeklies/exemptions';
import { leave as leaveProject } from '@/routes/weeklies/projects';
import type {
    WeekliesIndexPageProps,
    WeeklyHighlightedCycle,
    WeeklyMyClient,
} from '@/types/weeklies';

/**
 * Pestaña «Resumen» de /weeklies (F-030 a F-040; AdminDashboard y MemberDashboard de WeeklySync):
 * - para todos: mi weekly de la semana activa con su botón, mi racha y mis clientes (gestiono o
 *   colaboro), con «Unirme a proyectos» y «Dejar proyecto»,
 * - para quien gestiona: el estado global con el progreso, quién falta (con «Eximir»), quién está
 *   exento (con «Quitar exención») y «¡Todo el equipo ha enviado!»; sin semana activa, «Iniciar la
 *   semana».
 */
export function WeeklyOverview(props: WeekliesIndexPageProps) {
    const { active, me, streak, my_clients: myClients, can } = props;

    return (
        <div className="grid min-w-0 gap-8">
            {active === null ? (
                <NoActiveCycle canCreate={can.create} />
            ) : (
                <section
                    aria-labelledby="weekly-mine-title"
                    className="grid gap-3"
                >
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2
                            id="weekly-mine-title"
                            className="text-lg font-normal"
                        >
                            {t('weeklies.overview.mine')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            <WeekLabel cycle={active} />
                        </p>
                    </div>
                    <div className="grid gap-3 md:grid-cols-[1fr_auto]">
                        {me ? <MyWeeklyCallout cycle={active} me={me} /> : null}
                        <div
                            className="grid content-center gap-1 border bg-card p-4 text-sm"
                            data-test="weekly-streak-card"
                        >
                            <StreakValue
                                streak={streak.streak}
                                className="text-base font-medium"
                            />
                            <p className="text-xs text-muted-foreground">
                                {t('weeklies.streak.summary', {
                                    submitted: streak.submitted,
                                    on_time: streak.on_time,
                                })}
                            </p>
                        </div>
                    </div>
                </section>
            )}

            {can.manage && active ? (
                <ManageSection cycle={active} can={can} />
            ) : null}

            <MyClients clients={myClients} joinable={props.joinable_projects} />
        </div>
    );
}

function NoActiveCycle({ canCreate }: { canCreate: boolean }) {
    const [processing, setProcessing] = useState(false);

    return (
        <EmptyState
            icon={CalendarOff}
            title={t('weeklies.overview.no_active')}
            description={t('weeklies.empty.description')}
        >
            {canCreate ? (
                <Button
                    type="button"
                    disabled={processing}
                    onClick={() =>
                        router.post(
                            storeCycle.url(),
                            {},
                            {
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                            },
                        )
                    }
                    data-test="weekly-open-cycle"
                >
                    {processing ? (
                        <Spinner />
                    ) : (
                        <CalendarPlus aria-hidden="true" />
                    )}
                    {t('weeklies.overview.open_cycle')}
                </Button>
            ) : null}
        </EmptyState>
    );
}

/** «Gestión de la semana actual» (F-036, F-038 y F-039). */
function ManageSection({
    cycle,
    can,
}: {
    cycle: WeeklyHighlightedCycle;
    can: WeekliesIndexPageProps['can'];
}) {
    const members = cycle.team.members;
    const pending = members.filter((member) => isPendingStatus(member.status));
    const exempt = members.filter((member) => member.status === 'exempt');
    const { submitted, expected, exempt: exemptCount } = cycle.team.counts;
    const percent =
        expected > 0 ? Math.round((submitted / expected) * 100) : 100;

    return (
        <section
            aria-labelledby="weekly-manage-title"
            className="grid gap-3"
            data-test="weekly-manage"
        >
            <h2
                id="weekly-manage-title"
                className="flex items-center gap-2 text-lg font-normal"
            >
                <ShieldCheck
                    aria-hidden="true"
                    className="size-5 text-muted-foreground"
                    strokeWidth={1.5}
                />
                {t('weeklies.manage.title')}
            </h2>
            <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                <div className="grid content-start gap-4 border bg-card p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-medium">
                            {t('weeklies.manage.global')}
                        </h3>
                        <CycleProgressBadge progress={cycle.progress} />
                    </div>
                    <p
                        className="tabular text-3xl font-semibold"
                        data-test="weekly-manage-percent"
                    >
                        {percent} %
                    </p>
                    <Participation
                        submitted={submitted}
                        expected={expected}
                        exempt={exemptCount}
                    />
                    <TeamStatusStrip team={cycle.team} showCounter={false} />
                    <p className="text-sm text-muted-foreground">
                        {t('weeklies.cycle.deadline', {
                            date: formatDate(cycle.deadline_date),
                        })}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={showCycle.url(cycle.id)}>
                                <FileText aria-hidden="true" />
                                {t('weeklies.manage.report')}
                            </Link>
                        </Button>
                        {can.extendDeadline ? (
                            <DeadlineDialog
                                cycle={cycle}
                                trigger={
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                    >
                                        <CalendarCog aria-hidden="true" />
                                        {t('weeklies.deadline.open')}
                                    </Button>
                                }
                            />
                        ) : null}
                        {can.manage ? (
                            <CloseWeekDialog
                                cycle={cycle}
                                blockers={closeBlockers(cycle)}
                                pending={cycle.team.counts.pending}
                            />
                        ) : null}
                    </div>
                    {can.manage ? (
                        <p
                            className="text-xs text-muted-foreground"
                            data-test="weekly-close-hint"
                        >
                            {closeHint(
                                closeBlockers(cycle),
                                cycle.team.counts.pending,
                            )}
                        </p>
                    ) : null}
                </div>

                <div className="grid content-start gap-4 border bg-card p-4">
                    <h3 className="flex items-center gap-2 text-sm font-medium">
                        <Clock
                            aria-hidden="true"
                            className="size-4 text-muted-foreground"
                        />
                        {t('weeklies.manage.pending', {
                            count: pending.length,
                        })}
                    </h3>
                    {pending.length === 0 ? (
                        <p
                            className="flex items-center gap-2 bg-success-soft p-3 text-sm"
                            data-test="weekly-all-done"
                        >
                            <CircleCheck
                                aria-hidden="true"
                                className="size-4 text-success"
                            />
                            {t('weeklies.manage.all_done')}
                        </p>
                    ) : (
                        <ul className="divide-y" data-test="weekly-pending">
                            {pending.map((member) => (
                                <li
                                    key={member.user.id}
                                    className="flex flex-wrap items-center gap-2 py-2"
                                    data-test="weekly-pending-member"
                                >
                                    <UserAvatar user={member.user} />
                                    <span className="min-w-0 flex-1 truncate text-sm">
                                        {member.user.name}
                                    </span>
                                    <PersonStatusBadge status={member.status} />
                                    {can.exempt ? (
                                        <ExemptDialog
                                            cycle={cycle}
                                            person={member.user}
                                            trigger={
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label={t(
                                                        'weeklies.manage.exempt_person',
                                                        {
                                                            name: member.user
                                                                .name,
                                                        },
                                                    )}
                                                    data-test="weekly-exempt"
                                                >
                                                    <CalendarOff aria-hidden="true" />
                                                    {t(
                                                        'weeklies.manage.exempt',
                                                    )}
                                                </Button>
                                            }
                                        />
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}

                    {exempt.length > 0 ? (
                        <>
                            <h3 className="flex items-center gap-2 text-sm font-medium">
                                <CalendarOff
                                    aria-hidden="true"
                                    className="size-4 text-muted-foreground"
                                />
                                {t('weeklies.manage.exempt_title', {
                                    count: exempt.length,
                                })}
                            </h3>
                            <ul
                                className="divide-y"
                                data-test="weekly-exempt-list"
                            >
                                {exempt.map((member) => (
                                    <li
                                        key={member.user.id}
                                        className="flex flex-wrap items-center gap-2 py-2"
                                        data-test="weekly-exempt-member"
                                    >
                                        <UserAvatar user={member.user} />
                                        <span className="min-w-0 flex-1 truncate text-sm">
                                            {member.user.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {member.exemption_reason
                                                ? t(
                                                      `weeklies.exemption_reason.${member.exemption_reason}`,
                                                  )
                                                : null}
                                        </span>
                                        {can.exempt &&
                                        member.exemption_id !== null ? (
                                            <RemoveExemption
                                                cycleId={cycle.id}
                                                exemptionId={
                                                    member.exemption_id
                                                }
                                                name={member.user.name}
                                            />
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        </>
                    ) : null}
                </div>
            </div>
        </section>
    );
}

function RemoveExemption({
    cycleId,
    exemptionId,
    name,
}: {
    cycleId: number;
    exemptionId: number;
    name: string;
}) {
    const [processing, setProcessing] = useState(false);

    return (
        <ConfirmDialog
            trigger={
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    aria-label={t('weeklies.manage.remove_exemption_for', {
                        name,
                    })}
                    data-test="weekly-remove-exemption"
                >
                    {t('weeklies.manage.remove_exemption')}
                </Button>
            }
            title={t('weeklies.manage.remove_exemption_title', { name })}
            description={t('weeklies.manage.remove_exemption_description')}
            confirmLabel={t('weeklies.manage.remove_exemption')}
            destructive={false}
            processing={processing}
            onConfirm={() =>
                router.delete(
                    destroyExemption.url({
                        cycle: cycleId,
                        exemption: exemptionId,
                    }),
                    {
                        preserveScroll: true,
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                    },
                )
            }
        />
    );
}

/** «Mis clientes» (F-033) y «Unirme a proyectos» / «Dejar proyecto» (F-034, D-156). */
function MyClients({
    clients,
    joinable,
}: {
    clients: WeekliesIndexPageProps['my_clients'];
    joinable: WeekliesIndexPageProps['joinable_projects'];
}) {
    return (
        <section
            aria-labelledby="weekly-clients-title"
            className="grid gap-3"
            data-test="weekly-my-clients"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="weekly-clients-title" className="text-lg font-normal">
                    {t('weeklies.clients.title')}
                </h2>
                <JoinProjectsDialog
                    projects={joinable}
                    trigger={
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            data-test="weekly-join-projects"
                        >
                            <Plus aria-hidden="true" />
                            {t('weeklies.join.open')}
                        </Button>
                    }
                />
            </div>
            <div className="grid gap-3 md:grid-cols-2">
                <ClientGroup
                    icon={ShieldCheck}
                    title={t('weeklies.clients.owned', {
                        count: clients.owned.length,
                    })}
                    empty={t('weeklies.clients.owned_empty')}
                    clients={clients.owned}
                />
                <ClientGroup
                    icon={Users}
                    title={t('weeklies.clients.member', {
                        count: clients.member.length,
                    })}
                    empty={t('weeklies.clients.member_empty')}
                    clients={clients.member}
                />
            </div>
        </section>
    );
}

function ClientGroup({
    icon: Icon,
    title,
    empty,
    clients,
}: {
    icon: typeof Building2;
    title: string;
    empty: string;
    clients: WeeklyMyClient[];
}) {
    const can = useAbilities();

    return (
        <div className="grid content-start gap-3 border bg-card p-4">
            <h3 className="flex items-center gap-2 text-sm font-medium">
                <Icon
                    aria-hidden="true"
                    className="size-4 text-muted-foreground"
                />
                {title}
            </h3>
            {clients.length === 0 ? (
                <p className="text-sm text-muted-foreground">{empty}</p>
            ) : (
                <ul className="grid gap-3">
                    {clients.map((client) => (
                        <li key={client.id} className="grid gap-1.5">
                            <span className="flex items-center gap-2 text-sm">
                                <ClientIcon icon={client.icon} />
                                {can.viewClients ? (
                                    <Link
                                        href={urls.client(client.id)}
                                        className={cn(
                                            'inline-flex min-w-0 items-center gap-1 font-medium hover:underline',
                                            FOCUS_RING,
                                        )}
                                    >
                                        <span className="truncate">
                                            {client.name}
                                        </span>
                                        <ExternalLink
                                            aria-hidden="true"
                                            className="size-3.5 shrink-0 text-muted-foreground"
                                        />
                                    </Link>
                                ) : (
                                    <span className="truncate font-medium">
                                        {client.name}
                                    </span>
                                )}
                            </span>
                            <ul className="flex flex-wrap gap-1.5 pl-9">
                                {client.projects.map((project) => (
                                    <li
                                        key={project.id}
                                        className="inline-flex items-center gap-1 border bg-muted px-1.5 py-0.5 text-xs"
                                    >
                                        <Link
                                            href={urls.project(project.id)}
                                            className={cn(
                                                'hover:underline',
                                                FOCUS_RING,
                                            )}
                                            title={project.name}
                                        >
                                            {project.code}
                                        </Link>
                                        {project.can_leave ? (
                                            <LeaveProject
                                                projectId={project.id}
                                                code={project.code}
                                            />
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export function LeaveProject({
    projectId,
    code,
}: {
    projectId: number;
    code: string;
}) {
    const [processing, setProcessing] = useState(false);

    return (
        <ConfirmDialog
            trigger={
                <button
                    type="button"
                    aria-label={t('weeklies.clients.leave', { project: code })}
                    title={t('weeklies.clients.leave', { project: code })}
                    className={cn(
                        'inline-flex text-muted-foreground hover:text-foreground',
                        FOCUS_RING,
                    )}
                >
                    <UserMinus aria-hidden="true" className="size-3.5" />
                </button>
            }
            title={t('weeklies.clients.leave_title', { project: code })}
            description={t('weeklies.clients.leave_description')}
            confirmLabel={t('weeklies.clients.leave_confirm')}
            processing={processing}
            onConfirm={() =>
                router.delete(leaveProject.url(projectId), {
                    preserveScroll: true,
                    onStart: () => setProcessing(true),
                    onFinish: () => setProcessing(false),
                })
            }
        />
    );
}
