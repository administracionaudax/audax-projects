import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { KeywordText } from '@/components/keyword-text';
import { useAbilities } from '@/hooks/use-auth';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as mineIndex } from '@/routes/absences';
import { index as balancesIndex } from '@/routes/absences/balances';
import { index as calendarIndex } from '@/routes/absences/calendar';
import { index as teamIndex } from '@/routes/absences/team';
import { index as typesIndex } from '@/routes/absences/types';

export type AbsencesSection =
    | 'mine'
    | 'team'
    | 'balances'
    | 'calendar'
    | 'types';

/**
 * Marco de las páginas de ausencias: título (un solo h1, admite [[…]]), descripción, acciones y,
 * para responsables y admins, las pestañas «Mis ausencias» y «Ausencias del equipo». Con el módulo
 * `people` (Fase 11, R3), además «Calendario laboral» (toda la plantilla), «Saldos» (quien aprueba)
 * y «Tipos» (RR. HH.).
 */
export function AbsencesFrame({
    section,
    canTeam,
    title,
    description,
    actions,
    children,
}: {
    section: AbsencesSection;
    canTeam: boolean;
    title: string;
    description: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const can = useAbilities();
    const leave = can.usePeople === true;
    const team = canTeam || can.viewTeamAbsences === true;
    const tabs = [
        {
            id: 'mine',
            label: t('absences.nav.mine'),
            href: mineIndex.url(),
            show: true,
        },
        {
            id: 'team',
            label: t('absences.nav.team'),
            href: teamIndex.url(),
            show: team,
        },
        {
            id: 'calendar',
            label: t('leave.nav.calendar'),
            href: calendarIndex.url(),
            show: leave,
        },
        {
            id: 'balances',
            label: t('leave.nav.balances'),
            href: balancesIndex.url(),
            show: leave && team,
        },
        {
            id: 'types',
            label: t('leave.nav.types'),
            href: typesIndex.url(),
            show: leave && can.managePeopleRegister === true,
        },
    ].filter((tab) => tab.show);

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
                    <div className="flex flex-wrap gap-2">{actions}</div>
                ) : null}
            </header>

            {tabs.length > 1 ? (
                <nav
                    aria-label={t('absences.nav.label')}
                    className="-mx-4 overflow-x-auto border-b px-4 md:mx-0 md:px-0"
                >
                    <ul className="flex min-w-max gap-1">
                        {tabs.map((tab) => {
                            const current = tab.id === section;

                            return (
                                <li key={tab.id}>
                                    <Link
                                        href={tab.href}
                                        aria-current={
                                            current ? 'page' : undefined
                                        }
                                        className={cn(
                                            '-mb-px flex border-b-2 px-3 py-2 text-sm',
                                            current
                                                ? 'border-primary font-medium text-foreground'
                                                : 'border-transparent text-muted-foreground hover:text-foreground',
                                            FOCUS_RING,
                                        )}
                                    >
                                        {tab.label}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>
            ) : null}

            <div className="grid min-w-0 gap-8">{children}</div>
        </div>
    );
}
