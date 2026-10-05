import { Head, Link, setLayoutProps } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarClock,
    Pencil,
    ShieldCheck,
    Users,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/components/empty-state';
import { UserAvatar } from '@/components/realtime/presence-indicator';
import { Button } from '@/components/ui/button';
import { AiSummaryPanel } from '@/components/weeklies/insights/ai-summary-panel';
import { ProjectKindBadges } from '@/components/weeklies/insights/project-kind';
import {
    AbsenceTodayBadge,
    CopyEmailButton,
} from '@/components/weeklies/insights/team-ui';
import {
    ClientIcon,
    isPendingStatus,
    PersonStatusBadge,
    StreakValue,
} from '@/components/weeklies/weekly-ui';
import { RemindButton } from '@/components/weeklies/reminders/remind-button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { edit as editUser } from '@/routes/admin/users';
import { show as showClient } from '@/routes/clients';
import { show as showProject } from '@/routes/projects';
import {
    aiSummary,
    index as teamIndex,
    show as showPerson,
} from '@/routes/team';
import { show as showCycle } from '@/routes/weeklies';
import type {
    PersonClient,
    SubmissionDay,
    TeamShowPageProps,
} from '@/types/weekly-insights';

const DAYS: SubmissionDay[] = ['friday', 'saturday', 'sunday', 'other'];

function Tile({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-0.5 border bg-card p-3">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-lg">{children}</dd>
        </div>
    );
}

function ClientList({
    title,
    clients,
    activity,
}: {
    title: string;
    clients: PersonClient[];
    activity: Record<string, string> | null;
}) {
    return (
        <div className="grid content-start gap-2">
            <h3 className="text-sm font-medium">
                {title} ({clients.length})
            </h3>
            {clients.length === 0 ? (
                <p className="text-sm text-muted-foreground">—</p>
            ) : (
                <ul className="grid gap-2">
                    {clients.map((client) => (
                        <li
                            key={client.id}
                            className="grid gap-1.5 border bg-card p-3"
                            data-test="person-client"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <Link
                                    href={showClient.url(client.id)}
                                    className={cn(
                                        'inline-flex items-center gap-2 font-medium hover:underline',
                                        FOCUS_RING,
                                    )}
                                >
                                    <ClientIcon icon={client.icon} />
                                    {client.name}
                                </Link>
                                <ProjectKindBadges
                                    badges={client.badges}
                                    compact
                                />
                            </div>
                            <ul className="flex flex-wrap gap-1">
                                {client.projects.map((project) => (
                                    <li key={project.id}>
                                        <Link
                                            href={showProject.url(project.id)}
                                            title={project.name}
                                            className={cn(
                                                'tabular border bg-muted px-1.5 py-0.5 text-xs hover:underline',
                                                FOCUS_RING,
                                            )}
                                        >
                                            {project.code}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                            {activity?.[String(client.id)] ? (
                                <p
                                    className="border-l-2 border-primary pl-2 text-sm"
                                    data-test="person-client-activity"
                                >
                                    {activity[String(client.id)]}
                                </p>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * Ficha de una persona del equipo (F-142 a F-145, TeamView de WeeklySync): su estado esta semana,
 * racha y hábitos de envío, los clientes que lidera y en los que colabora, su último reporte por
 * cliente y su historial por semanas. Para el admin y sus responsables, además, el «Resumen de
 * desempeño (IA)» y la «Actividad por cliente (IA)» (D-147).
 */
export default function TeamShow({
    person,
    absence,
    habits,
    clients,
    last_reports: lastReports,
    weeks,
    cycle,
    status,
    streak,
    ai,
    can,
}: TeamShowPageProps) {
    setLayoutProps({
        breadcrumbs: [
            { title: t('weeklies.team.title'), href: teamIndex() },
            { title: person.name, href: showPerson(person.id) },
        ],
    });

    const activity =
        ai?.client_activity?.state === 'done' ? ai.client_activity.items : null;

    return (
        <>
            <Head title={person.name} />
            <div className="mx-auto flex w-full max-w-6xl min-w-0 flex-col gap-6 p-4 md:p-6">
                <Link
                    href={teamIndex.url()}
                    className={cn(
                        'inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground',
                        FOCUS_RING,
                    )}
                >
                    <ArrowLeft aria-hidden="true" className="size-4" />
                    {t('weeklies.person.back')}
                </Link>

                <header className="flex flex-wrap items-start justify-between gap-4 border bg-card p-4">
                    <div className="flex min-w-0 items-start gap-4">
                        <UserAvatar user={person} className="size-14" />
                        <div className="grid min-w-0 gap-1">
                            <h1 className="text-2xl font-normal tracking-tight break-words">
                                {person.name}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {[
                                    person.job_title ??
                                        t('weeklies.team.no_job_title'),
                                    person.department?.name ??
                                        t('weeklies.team.no_department'),
                                    person.role
                                        ? t(`weeklies.team.role.${person.role}`)
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                            <p className="inline-flex items-center gap-1 text-sm break-all">
                                {person.email}
                                <CopyEmailButton
                                    email={person.email}
                                    name={person.name}
                                />
                            </p>
                            <div className="flex flex-wrap items-center gap-2">
                                {person.is_active ? null : (
                                    <span className="bg-muted px-1.5 py-0.5 text-xs">
                                        {t('weeklies.person.inactive')}
                                    </span>
                                )}
                                <AbsenceTodayBadge absence={absence} />
                                {cycle && status ? (
                                    <span className="inline-flex items-center gap-2 text-sm">
                                        <span className="text-muted-foreground">
                                            {t('weeklies.person.this_week')}:
                                        </span>
                                        <PersonStatusBadge status={status} />
                                    </span>
                                ) : null}
                                {cycle &&
                                status &&
                                can.remind &&
                                isPendingStatus(status) ? (
                                    <RemindButton
                                        cycleId={cycle.id}
                                        person={person}
                                    />
                                ) : null}
                            </div>
                        </div>
                    </div>
                    {can.manageUser ? (
                        <Button variant="outline" asChild>
                            <Link href={editUser.url(person.id)}>
                                <Pencil aria-hidden="true" />
                                {t('weeklies.person.edit')}
                            </Link>
                        </Button>
                    ) : null}
                </header>

                <section
                    aria-labelledby="person-streak"
                    className="grid gap-3"
                    data-test="person-habits"
                >
                    <h2 id="person-streak" className="text-lg">
                        {t('weeklies.person.streak_title')}
                    </h2>
                    <dl className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                        <Tile label={t('weeklies.person.streak')}>
                            <StreakValue streak={streak.streak} />
                        </Tile>
                        <Tile label={t('weeklies.person.submitted')}>
                            <span className="tabular">{streak.submitted}</span>
                        </Tile>
                        <Tile label={t('weeklies.person.on_time')}>
                            <span className="tabular">{streak.on_time}</span>
                        </Tile>
                        {habits ? (
                            <>
                                <Tile label={t('weeklies.person.average_time')}>
                                    <span className="tabular">
                                        {habits.average_time}
                                    </span>
                                </Tile>
                                <Tile label={t('weeklies.person.time_of_day')}>
                                    {t(
                                        `weeklies.person.time_of_day.${habits.time_of_day}`,
                                    )}
                                </Tile>
                                <Tile
                                    label={t('weeklies.person.most_common_day')}
                                >
                                    {t(
                                        `weeklies.person.day.${habits.most_common_day}`,
                                    )}
                                </Tile>
                            </>
                        ) : null}
                    </dl>
                    {habits ? (
                        <div className="grid gap-1">
                            <h3 className="text-sm text-muted-foreground">
                                {t('weeklies.person.distribution')}
                            </h3>
                            <ul className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                {DAYS.map((day) => (
                                    <li
                                        key={day}
                                        className="grid gap-1 text-sm"
                                    >
                                        <span className="flex justify-between">
                                            <span>
                                                {t(
                                                    `weeklies.person.day.${day}`,
                                                )}
                                            </span>
                                            <span className="tabular">
                                                {habits.distribution[day]}
                                            </span>
                                        </span>
                                        <span
                                            aria-hidden="true"
                                            className="h-1.5 bg-muted"
                                        >
                                            <span
                                                className="block h-full bg-primary"
                                                style={{
                                                    width: `${habits.total > 0 ? (habits.distribution[day] / habits.total) * 100 : 0}%`,
                                                }}
                                            />
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {t('weeklies.person.no_habits')}
                        </p>
                    )}
                </section>

                {ai ? (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <AiSummaryPanel
                            title={t('weeklies.person.ai_title')}
                            description={t('weeklies.person.ai_description')}
                            summary={ai.performance}
                            action={aiSummary.url(person.id)}
                            actionData={{ tipo: 'desempeno' }}
                            reloadProp="ai"
                            testId="person-ai-performance"
                        />
                        <AiSummaryPanel
                            title={t('weeklies.person.activity_title')}
                            description={t(
                                'weeklies.person.activity_description',
                            )}
                            summary={ai.client_activity}
                            action={aiSummary.url(person.id)}
                            actionData={{ tipo: 'clientes' }}
                            reloadProp="ai"
                            testId="person-ai-activity"
                        >
                            <p className="text-sm text-muted-foreground">
                                {activity && Object.keys(activity).length > 0
                                    ? t('weeklies.client.team_activity_hint')
                                    : t('weeklies.person.activity_empty')}
                            </p>
                        </AiSummaryPanel>
                    </div>
                ) : null}

                <section
                    aria-labelledby="person-clients"
                    className="grid gap-3"
                >
                    <h2
                        id="person-clients"
                        className="flex items-center gap-2 text-lg"
                    >
                        <Users aria-hidden="true" className="size-4" />
                        {t('weeklies.person.clients_title')}
                    </h2>
                    {clients.owned.length === 0 &&
                    clients.member.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('weeklies.person.clients_empty')}
                        </p>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2">
                            <ClientList
                                title={t('weeklies.person.clients_owned')}
                                clients={clients.owned}
                                activity={activity}
                            />
                            <ClientList
                                title={t('weeklies.person.clients_member')}
                                clients={clients.member}
                                activity={activity}
                            />
                        </div>
                    )}
                </section>

                <section
                    aria-labelledby="person-last-reports"
                    className="grid gap-3"
                >
                    <h2 id="person-last-reports" className="text-lg">
                        {t('weeklies.person.last_reports')}
                    </h2>
                    {lastReports.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('weeklies.person.last_reports_empty')}
                        </p>
                    ) : (
                        <ul className="grid gap-2 md:grid-cols-2">
                            {lastReports.map((report) => (
                                <li
                                    key={report.client?.id ?? 'general'}
                                    className="grid gap-1 border bg-card p-3 text-sm"
                                    data-test="person-last-report"
                                >
                                    <p className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="inline-flex items-center gap-2 font-medium">
                                            <ClientIcon
                                                icon={report.client?.icon}
                                            />
                                            {report.client?.name ??
                                                t('weeklies.person.general')}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {report.cycle.label}
                                        </span>
                                    </p>
                                    <p className="line-clamp-4 whitespace-pre-line">
                                        {report.body}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section
                    aria-labelledby="person-history"
                    className="grid gap-3"
                >
                    <h2
                        id="person-history"
                        className="flex items-center gap-2 text-lg"
                    >
                        <CalendarClock aria-hidden="true" className="size-4" />
                        {t('weeklies.person.history')}
                    </h2>
                    {weeks.length === 0 ? (
                        <EmptyState
                            icon={ShieldCheck}
                            title={t('weeklies.person.history_empty')}
                        />
                    ) : (
                        <ul className="grid gap-2">
                            {weeks.map((week, index) => (
                                <li key={week.cycle.id}>
                                    <details
                                        open={index === 0}
                                        className="border bg-card"
                                        data-test="person-week"
                                    >
                                        <summary
                                            className={cn(
                                                'flex cursor-pointer flex-wrap items-center justify-between gap-2 p-3',
                                                FOCUS_RING,
                                            )}
                                        >
                                            <span className="font-medium">
                                                {week.cycle.label}
                                            </span>
                                            <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                <span>
                                                    {t(
                                                        'weeklies.person.history_entries',
                                                        {
                                                            count: week.entries
                                                                .length,
                                                        },
                                                    )}
                                                </span>
                                                <PersonStatusBadge
                                                    status={week.status}
                                                />
                                            </span>
                                        </summary>
                                        <div className="grid gap-2 border-t p-3">
                                            <p className="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
                                                <span>
                                                    {t(
                                                        'weeklies.person.submitted_at',
                                                        {
                                                            date: formatDateTime(
                                                                week.submitted_at,
                                                            ),
                                                        },
                                                    )}
                                                </span>
                                                <Link
                                                    href={showCycle.url(
                                                        week.cycle.id,
                                                    )}
                                                    className={cn(
                                                        'text-primary-text hover:underline',
                                                        FOCUS_RING,
                                                    )}
                                                >
                                                    {t(
                                                        'weeklies.client.open_report',
                                                    )}
                                                </Link>
                                            </p>
                                            <ul className="grid gap-2">
                                                {week.entries.map((entry) => (
                                                    <li
                                                        key={entry.id}
                                                        className="grid gap-1 bg-muted p-3 text-sm"
                                                    >
                                                        <p className="flex flex-wrap items-center gap-2 text-xs">
                                                            <ClientIcon
                                                                icon={
                                                                    entry.client
                                                                        ?.icon
                                                                }
                                                                className="size-5 text-sm"
                                                            />
                                                            <span className="font-medium">
                                                                {entry.client
                                                                    ?.name ??
                                                                    t(
                                                                        'weeklies.person.general',
                                                                    )}
                                                            </span>
                                                            {entry.project ? (
                                                                <span className="tabular border px-1">
                                                                    {
                                                                        entry
                                                                            .project
                                                                            .code
                                                                    }
                                                                </span>
                                                            ) : null}
                                                        </p>
                                                        <p className="whitespace-pre-line">
                                                            {entry.body}
                                                        </p>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    </details>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}
