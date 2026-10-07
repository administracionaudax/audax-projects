import { Link, usePage } from '@inertiajs/react';
import {
    CircleCheck,
    CircleDashed,
    CircleDot,
    Hourglass,
    MessageSquareWarning,
    Minus,
    TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { KeywordText } from '@/components/keyword-text';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { incidentLabel, STATUS_TONE, statusLabel } from '@/lib/people';
import { cn } from '@/lib/utils';
import { index as pendingIndex } from '@/routes/people/pending';
import { index as teamIndex } from '@/routes/people/team';
import { index as workdayIndex } from '@/routes/people/workday';
import type { DayStatus, WorkdayIncident } from '@/types/people';

/**
 * Piezas comunes de las pantallas del registro de jornada (D-341): el marco con las pestañas
 * «Mi jornada», «Jornada del equipo» y «Pendientes», el estado de un día (con texto e icono, nunca
 * solo color) y las incidencias.
 */

const STATUS_ICON: Record<DayStatus, LucideIcon> = {
    pending: Hourglass,
    disputed: MessageSquareWarning,
    incident: TriangleAlert,
    warning: TriangleAlert,
    in_progress: CircleDot,
    future: Minus,
    off: Minus,
    today: CircleDashed,
    ok: CircleCheck,
};

export function DayStatusBadge({
    status,
    compact = false,
    className,
}: {
    status: DayStatus;
    compact?: boolean;
    className?: string;
}) {
    const Icon = STATUS_ICON[status];

    if (status === 'future') {
        return null;
    }

    return (
        <span
            className={cn(
                'inline-flex max-w-full items-center gap-1 rounded-md px-1.5 py-0.5 text-xs',
                STATUS_TONE[status],
                className,
            )}
            data-test="day-status"
            data-status={status}
        >
            <Icon
                aria-hidden="true"
                className={cn(
                    'size-3.5 shrink-0',
                    status === 'ok' && 'text-success',
                    status === 'incident' && 'text-danger',
                    (status === 'warning' || status === 'disputed') &&
                        'text-warning',
                    status === 'pending' && 'text-info',
                    status === 'in_progress' && 'text-success',
                )}
            />
            <span
                className={cn('truncate', compact && 'sr-only sm:not-sr-only')}
            >
                {statusLabel(status)}
            </span>
        </span>
    );
}

export function IncidentList({
    incidents,
    className,
}: {
    incidents: WorkdayIncident[];
    className?: string;
}) {
    if (incidents.length === 0) {
        return null;
    }

    return (
        <ul className={cn('flex flex-wrap gap-1', className)}>
            {incidents.map((incident) => (
                <li
                    key={incident}
                    className="inline-flex items-center gap-1 rounded-md bg-warning-soft px-1.5 py-0.5 text-xs"
                    data-test="day-incident"
                    data-incident={incident}
                >
                    <TriangleAlert
                        aria-hidden="true"
                        className="size-3 shrink-0 text-warning"
                    />
                    {incidentLabel(incident)}
                </li>
            ))}
        </ul>
    );
}

/** Una cifra con su etiqueta (Trabajado, Teórico, Diferencia…). */
export function Figure({
    label,
    value,
    hint,
    tone,
    testId,
}: {
    label: string;
    value: string;
    hint?: string;
    tone?: 'positive' | 'negative';
    testId?: string;
}) {
    return (
        <div className="grid gap-0.5" data-test={testId}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={cn(
                    'tabular text-lg',
                    tone === 'negative' && 'text-danger',
                    tone === 'positive' && 'text-success',
                )}
            >
                {value}
                {hint ? (
                    <span className="ml-1 text-xs text-muted-foreground">
                        {hint}
                    </span>
                ) : null}
            </dd>
        </div>
    );
}

/**
 * Marco de las pantallas: título (un solo h1), descripción, acciones y, para quien ve la jornada del
 * equipo, las pestañas.
 */
export function PeopleFrame({
    section,
    title,
    description,
    actions,
    children,
}: {
    section: 'workday' | 'team' | 'pending' | 'person';
    title: string;
    description: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const { auth, people } = usePage().props;
    const pending = people?.pending ?? 0;
    const tabs = [
        {
            id: 'workday',
            label: t('people.nav.workday'),
            href: workdayIndex.url(),
        },
        { id: 'team', label: t('people.nav.team'), href: teamIndex.url() },
        {
            id: 'pending',
            label: t('people.nav.pending'),
            href: pendingIndex.url(),
            count: pending,
        },
    ] as const;
    const current = section === 'person' ? 'team' : section;

    return (
        <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0 space-y-1">
                    <h1 className="text-2xl font-normal tracking-tight">
                        <KeywordText text={title} />
                    </h1>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                {actions ? (
                    <div className="flex flex-wrap items-center gap-2">
                        {actions}
                    </div>
                ) : null}
            </header>

            {auth.can.viewPeopleTeam ? (
                <nav
                    aria-label={t('people.nav.label')}
                    className="-mx-4 overflow-x-auto border-b px-4 md:mx-0 md:px-0"
                >
                    <ul className="flex min-w-max gap-1">
                        {tabs.map((tab) => (
                            <li key={tab.id}>
                                <Link
                                    href={tab.href}
                                    aria-current={
                                        tab.id === current ? 'page' : undefined
                                    }
                                    className={cn(
                                        '-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm',
                                        tab.id === current
                                            ? 'border-primary font-medium text-foreground'
                                            : 'border-transparent text-muted-foreground hover:text-foreground',
                                        FOCUS_RING,
                                    )}
                                >
                                    {tab.label}
                                    {'count' in tab && tab.count > 0 ? (
                                        <span className="tabular rounded-md bg-info-soft px-1.5 text-xs text-foreground">
                                            {tab.count}
                                        </span>
                                    ) : null}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
            ) : null}

            <div className="grid min-w-0 gap-8">{children}</div>
        </div>
    );
}
