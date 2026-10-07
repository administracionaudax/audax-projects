import { Head, router, usePage } from '@inertiajs/react';
import { CalendarOff, CalendarPlus, Info } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CancelAbsenceButton } from '@/components/absences/absence-actions';
import { AbsenceDialog } from '@/components/absences/absence-dialog';
import { AbsenceItem } from '@/components/absences/absence-item';
import { AbsencesFrame } from '@/components/absences/absences-frame';
import type {
    AbsenceRow,
    MyAbsencesPageProps,
} from '@/components/absences/types';
import { EmptyState } from '@/components/empty-state';
import { LeaveBalanceCards } from '@/components/leave/balance-cards';
import { RequestCancellationDialog } from '@/components/leave/cancellation';
import { LeaveDetails } from '@/components/leave/leave-details';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { index as absencesIndex } from '@/routes/absences';

/**
 * ?solicitar=1 (enlace de la tarjeta de Inicio) abre el formulario al entrar. Después se quita de
 * la URL: si no, al recargar, al volver desde el historial o tras enviar (back() vuelve a la URL de
 * la página) el formulario se abriría otra vez sin pedirlo.
 */
function wantsRequest(url: string | undefined): boolean {
    const query = (url ?? '').split('?')[1] ?? '';

    return new URLSearchParams(query).get('solicitar') === '1';
}

function Section({
    id,
    title,
    hint,
    absences,
    today,
    accept,
}: {
    id: string;
    title: string;
    hint?: string;
    absences: AbsenceRow[];
    today: string;
    accept?: string;
}) {
    if (absences.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby={`${id}-heading`} className="grid gap-3">
            <div className="space-y-1">
                <h2 id={`${id}-heading`} className="text-lg">
                    {title}
                </h2>
                {hint ? (
                    <p className="text-sm text-muted-foreground">{hint}</p>
                ) : null}
            </div>
            <ul className="grid gap-2" data-test={`absences-${id}`}>
                {absences.map((absence) => (
                    <AbsenceItem
                        key={absence.id}
                        absence={absence}
                        today={today}
                        extra={
                            absence.leave ? (
                                <LeaveDetails
                                    absenceId={absence.id}
                                    status={absence.status}
                                    leave={absence.leave}
                                    accept={accept}
                                />
                            ) : undefined
                        }
                    >
                        {absence.can.cancel ? (
                            <CancelAbsenceButton absence={absence} />
                        ) : null}
                        {absence.leave?.can.request_cancellation ? (
                            <RequestCancellationDialog absence={absence} />
                        ) : null}
                    </AbsenceItem>
                ))}
            </ul>
        </section>
    );
}

/**
 * «Mis ausencias» (/ausencias, SPEC §5.1, D-049): solicitar una ausencia y seguir su estado.
 * Pendientes, próximas (y en curso) y el historial del último año, cada una con sus acciones.
 */
export default function MyAbsences({
    absences,
    types,
    today,
    limits,
    self_approves: selfApproves,
    can,
    leave = null,
}: MyAbsencesPageProps) {
    const page = usePage();
    const [requesting, setRequesting] = useState(() => wantsRequest(page.url));

    useEffect(() => {
        if (wantsRequest(page.url)) {
            router.replace({
                url: absencesIndex.url(),
                preserveState: true,
                preserveScroll: true,
            });
        }
    }, [page.url]);

    const byStart = (a: AbsenceRow, b: AbsenceRow) =>
        a.start_date.localeCompare(b.start_date) || a.id - b.id;
    const pending = absences
        .filter((absence) => absence.status === 'requested')
        .sort(byStart);
    const upcoming = absences
        .filter(
            (absence) =>
                absence.status === 'approved' && absence.end_date >= today,
        )
        .sort(byStart);
    const history = absences.filter(
        (absence) => !pending.includes(absence) && !upcoming.includes(absence),
    );

    return (
        <>
            <Head title={t('absences.mine.title')} />

            <AbsencesFrame
                section="mine"
                canTeam={can.team}
                title={t('absences.mine.heading')}
                description={t('absences.mine.description')}
                actions={
                    <Button type="button" onClick={() => setRequesting(true)}>
                        <CalendarPlus aria-hidden="true" />
                        {t('absences.mine.request')}
                    </Button>
                }
            >
                {selfApproves ? (
                    <p className="flex items-start gap-2 text-sm text-muted-foreground">
                        <Info
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-info"
                        />
                        {t('absences.mine.self_approves')}
                    </p>
                ) : null}

                {leave ? (
                    <>
                        <LeaveBalanceCards
                            title={t('leave.balance.title', {
                                year: leave.year,
                            })}
                            balances={leave.balances}
                        />
                        {leave.next_balances.some(
                            (balance) =>
                                balance.total !== 0 || balance.pending > 0,
                        ) ? (
                            <LeaveBalanceCards
                                title={t('leave.balance.title', {
                                    year: leave.year + 1,
                                })}
                                balances={leave.next_balances}
                            />
                        ) : null}
                    </>
                ) : null}

                {absences.length === 0 ? (
                    <EmptyState
                        icon={CalendarOff}
                        title={t('absences.mine.empty')}
                        description={t('absences.mine.empty_description')}
                    >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setRequesting(true)}
                        >
                            <CalendarPlus aria-hidden="true" />
                            {t('absences.mine.request')}
                        </Button>
                    </EmptyState>
                ) : (
                    <>
                        <Section
                            id="pending"
                            title={t('absences.mine.pending', {
                                count: pending.length,
                            })}
                            hint={t('absences.mine.pending_hint')}
                            absences={pending}
                            today={today}
                            accept={leave?.documents.accept}
                        />
                        <Section
                            id="upcoming"
                            title={t('absences.mine.upcoming', {
                                count: upcoming.length,
                            })}
                            absences={upcoming}
                            today={today}
                            accept={leave?.documents.accept}
                        />
                        <Section
                            id="history"
                            title={t('absences.mine.history', {
                                count: history.length,
                            })}
                            absences={history}
                            today={today}
                            accept={leave?.documents.accept}
                        />
                    </>
                )}
            </AbsencesFrame>

            <AbsenceDialog
                mode="request"
                types={types}
                limits={limits}
                selfApproves={selfApproves}
                open={requesting}
                onOpenChange={setRequesting}
                leaveTypes={leave?.types}
            />
        </>
    );
}

MyAbsences.layout = {
    breadcrumbs: [{ title: t('absences.mine.title'), href: absencesIndex() }],
};
