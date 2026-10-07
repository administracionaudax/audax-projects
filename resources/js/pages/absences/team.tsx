import { Head, router } from '@inertiajs/react';
import {
    CalendarCheck,
    Pencil,
    TriangleAlert,
    UserPlus,
    Users,
} from 'lucide-react';
import { useId } from 'react';
import {
    ApproveAbsenceButton,
    CancelAbsenceButton,
    RejectAbsenceDialog,
} from '@/components/absences/absence-actions';
import { AbsenceDialog } from '@/components/absences/absence-dialog';
import { AbsenceItem } from '@/components/absences/absence-item';
import {
    absencePeriodLabel,
    absenceStatusLabel,
    absenceTypeLabel,
} from '@/components/absences/absence-meta';
import { AbsencesFrame } from '@/components/absences/absences-frame';
import { TeamCalendarView } from '@/components/absences/team-calendar';
import type {
    PendingAbsence,
    TeamAbsencesPageProps,
} from '@/components/absences/types';
import { NativeSelect } from '@/components/admin/native-select';
import { EmptyState } from '@/components/empty-state';
import { DecideCancellationButtons } from '@/components/leave/cancellation';
import { LeaveDetails } from '@/components/leave/leave-details';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useRequiredUser } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import { index as mineIndex } from '@/routes/absences';
import { index as teamIndex } from '@/routes/absences/team';

function Overlaps({ absence }: { absence: PendingAbsence }) {
    if (absence.overlaps.length === 0) {
        return (
            <p className="text-muted-foreground">
                {t('absences.team.no_overlaps')}
            </p>
        );
    }

    return (
        <div
            className="mt-1 flex gap-2 rounded-md border border-warning bg-warning-soft px-2 py-1.5"
            data-test="absence-overlaps"
        >
            <TriangleAlert
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-warning"
            />
            <div className="grid gap-0.5">
                <p>{t('absences.team.overlaps')}</p>
                <ul className="grid gap-0.5">
                    {absence.overlaps.map((overlap, index) => (
                        <li key={index}>
                            {t('absences.team.overlap_item', {
                                name: overlap.user_name,
                                type: absenceTypeLabel(overlap.type),
                                period: absencePeriodLabel(overlap),
                                status: absenceStatusLabel(
                                    overlap.status,
                                ).toLowerCase(),
                            })}
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

/**
 * «Ausencias del equipo» (/ausencias/equipo, D-049, D-021): para responsables (su departamento) y
 * admins. Solicitudes pendientes con las ausencias que coinciden en su departamento (aprobar o
 * rechazar con comentario), el calendario mensual con ausencias y festivos, las próximas
 * aprobadas (que se pueden modificar, p. ej. acortar una baja, o anular) y registrar una ausencia
 * ya aprobada.
 */
export default function TeamAbsences({
    pending,
    upcoming,
    calendar,
    departments,
    filters,
    register_people: registerPeople,
    types,
    today,
    limits,
    pending_limit: pendingLimit,
    cancellations = [],
    leave = null,
}: TeamAbsencesPageProps) {
    const filterId = useId();
    const viewer = useRequiredUser();

    const url = (query: { mes?: string; departamento?: number | null }) => {
        const params: Record<string, string | number> = {};
        const month = query.mes ?? calendar.month;
        const department =
            query.departamento === undefined
                ? filters.department
                : query.departamento;

        if (month !== calendar.current) {
            params.mes = month;
        }

        if (department !== null) {
            params.departamento = department;
        }

        return teamIndex.url({ query: params });
    };

    return (
        <>
            <Head title={t('absences.team.title')} />

            <AbsencesFrame
                section="team"
                canTeam
                title={t('absences.team.heading')}
                description={t('absences.team.description')}
                actions={
                    registerPeople.length > 0 ? (
                        <AbsenceDialog
                            mode="register"
                            types={types}
                            limits={limits}
                            people={registerPeople}
                            leaveTypes={leave?.types}
                            trigger={
                                <Button type="button">
                                    <UserPlus aria-hidden="true" />
                                    {t('absences.team.register')}
                                </Button>
                            }
                        />
                    ) : null
                }
            >
                {departments.length > 1 ? (
                    <div className="grid max-w-xs gap-2">
                        <Label htmlFor={filterId}>
                            {t('absences.team.department')}
                        </Label>
                        <NativeSelect
                            id={filterId}
                            value={filters.department ?? ''}
                            onChange={(event) =>
                                router.get(
                                    url({
                                        departamento:
                                            event.target.value === ''
                                                ? null
                                                : Number(event.target.value),
                                    }),
                                    {},
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                    },
                                )
                            }
                        >
                            <option value="">
                                {t('absences.team.all_departments')}
                            </option>
                            {departments.map((department) => (
                                <option
                                    key={department.id}
                                    value={department.id}
                                >
                                    {department.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                ) : null}

                <section
                    aria-labelledby="team-pending-heading"
                    className="grid min-w-0 gap-3"
                >
                    <h2 id="team-pending-heading" className="text-lg">
                        {t('absences.team.pending', { count: pending.length })}
                    </h2>
                    {pending.length === 0 ? (
                        <EmptyState
                            icon={CalendarCheck}
                            title={t('absences.team.pending_empty')}
                            description={t(
                                'absences.team.pending_empty_description',
                            )}
                        />
                    ) : (
                        <ul className="grid gap-2" data-test="team-pending">
                            {pending.map((absence) => (
                                <AbsenceItem
                                    key={absence.id}
                                    absence={absence}
                                    person={absence.user}
                                    today={today}
                                    extra={
                                        <>
                                            {absence.leave ? (
                                                <LeaveDetails
                                                    absenceId={absence.id}
                                                    status={absence.status}
                                                    leave={absence.leave}
                                                />
                                            ) : null}
                                            <Overlaps absence={absence} />
                                        </>
                                    }
                                >
                                    {absence.can.review ? (
                                        <>
                                            <ApproveAbsenceButton
                                                absence={absence}
                                                personName={absence.user.name}
                                            />
                                            <RejectAbsenceDialog
                                                absence={absence}
                                                personName={absence.user.name}
                                            />
                                        </>
                                    ) : null}
                                </AbsenceItem>
                            ))}
                        </ul>
                    )}
                    {pending.length >= pendingLimit ? (
                        <p className="text-sm text-muted-foreground">
                            {t('absences.team.pending_limit', {
                                count: pendingLimit,
                            })}
                        </p>
                    ) : null}
                </section>

                {cancellations.length > 0 ? (
                    <section
                        aria-labelledby="team-cancellations-heading"
                        className="grid min-w-0 gap-3"
                    >
                        <h2 id="team-cancellations-heading" className="text-lg">
                            {t('leave.team.cancellations', {
                                count: cancellations.length,
                            })}
                        </h2>
                        <ul
                            className="grid gap-2"
                            data-test="team-cancellations"
                        >
                            {cancellations.map((absence) => (
                                <AbsenceItem
                                    key={absence.id}
                                    absence={absence}
                                    person={absence.user}
                                    today={today}
                                    extra={
                                        absence.leave ? (
                                            <LeaveDetails
                                                absenceId={absence.id}
                                                status={absence.status}
                                                leave={absence.leave}
                                                showWarnings={false}
                                            />
                                        ) : undefined
                                    }
                                >
                                    {absence.leave?.can.decide_cancellation ? (
                                        <DecideCancellationButtons
                                            absence={absence}
                                            personName={absence.user.name}
                                        />
                                    ) : null}
                                </AbsenceItem>
                            ))}
                        </ul>
                    </section>
                ) : null}

                {calendar.people.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title={t('absences.team.no_people')}
                        description={t('absences.team.no_people_description')}
                    />
                ) : (
                    <TeamCalendarView
                        calendar={calendar}
                        today={today}
                        monthUrl={(month) => url({ mes: month })}
                    />
                )}

                <section
                    aria-labelledby="team-upcoming-heading"
                    className="grid min-w-0 gap-3"
                >
                    <h2 id="team-upcoming-heading" className="text-lg">
                        {t('absences.team.upcoming', {
                            count: upcoming.length,
                        })}
                    </h2>
                    {upcoming.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('absences.team.upcoming_empty')}
                        </p>
                    ) : (
                        <ul className="grid gap-2" data-test="team-upcoming">
                            {upcoming.map((absence) => (
                                <AbsenceItem
                                    key={absence.id}
                                    absence={absence}
                                    person={absence.user}
                                    today={today}
                                    extra={
                                        absence.leave ? (
                                            <LeaveDetails
                                                absenceId={absence.id}
                                                status={absence.status}
                                                leave={absence.leave}
                                            />
                                        ) : undefined
                                    }
                                >
                                    {absence.can.update ? (
                                        <AbsenceDialog
                                            mode="edit"
                                            types={types}
                                            limits={limits}
                                            leaveTypes={leave?.types}
                                            absence={absence}
                                            personName={absence.user.name}
                                            trigger={
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label={t(
                                                        'absences.team.edit_label',
                                                        {
                                                            name: absence.user
                                                                .name,
                                                            period: absencePeriodLabel(
                                                                absence,
                                                            ),
                                                        },
                                                    )}
                                                >
                                                    <Pencil aria-hidden="true" />
                                                    {t('absences.team.edit')}
                                                </Button>
                                            }
                                        />
                                    ) : null}
                                    {absence.can.cancel ? (
                                        <CancelAbsenceButton
                                            absence={absence}
                                            personName={
                                                absence.user_id === viewer.id
                                                    ? undefined
                                                    : absence.user.name
                                            }
                                        />
                                    ) : null}
                                </AbsenceItem>
                            ))}
                        </ul>
                    )}
                </section>
            </AbsencesFrame>
        </>
    );
}

TeamAbsences.layout = {
    breadcrumbs: [
        { title: t('absences.mine.title'), href: mineIndex() },
        { title: t('absences.team.title'), href: teamIndex() },
    ],
};
