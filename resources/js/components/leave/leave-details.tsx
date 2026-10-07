import { CalendarClock, CalendarX, Clock, TriangleAlert } from 'lucide-react';
import { AbsenceDocuments } from '@/components/leave/document-list';
import { formatLeaveAmount } from '@/lib/leave';
import { t } from '@/lib/i18n';
import type { AbsenceLeave } from '@/types/leave';

/**
 * Lo que R3 añade a una ausencia en una lista (Fase 11): la franja de las de horas, lo que cuesta,
 * el primer nivel de aprobación, la cancelación pedida, los avisos (antelación, preaviso,
 * justificante…) y los justificantes. Solo llega con el módulo `people`.
 */
export function LeaveDetails({
    absenceId,
    status,
    leave,
    showWarnings = true,
    accept,
}: {
    absenceId: number;
    status: string;
    leave: AbsenceLeave;
    showWarnings?: boolean;
    accept?: string;
}) {
    const unit = leave.type?.unit ?? 'working_days';
    const warnings = showWarnings
        ? // El del justificante ya lo cuenta el bloque de justificantes de abajo.
          leave.warnings.filter(
              (warning) =>
                  status === 'requested' && warning.code !== 'document',
          )
        : [];

    return (
        <div className="grid gap-1.5" data-test="leave-details">
            <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-muted-foreground">
                {leave.start_time && leave.end_time ? (
                    <span className="inline-flex items-center gap-1">
                        <Clock aria-hidden="true" className="size-3.5" />
                        {t('leave.item.slot', {
                            from: leave.start_time,
                            to: leave.end_time,
                        })}
                    </span>
                ) : null}
                {leave.cost > 0 ? (
                    <span data-test="leave-cost">
                        {t('leave.item.cost', {
                            amount: formatLeaveAmount(leave.cost, unit),
                        })}
                    </span>
                ) : null}
                {leave.type && !leave.type.paid ? (
                    <span>{t('leave.item.unpaid')}</span>
                ) : null}
            </p>
            {leave.awaiting_second ? (
                <p className="flex items-center gap-1.5">
                    <CalendarClock
                        aria-hidden="true"
                        className="size-4 shrink-0 text-info"
                    />
                    {leave.first_approval?.by
                        ? t('leave.item.awaiting_second_by', {
                              name: leave.first_approval.by,
                          })
                        : t('leave.item.awaiting_second')}
                </p>
            ) : null}
            {leave.cancellation ? (
                <p className="flex items-start gap-1.5">
                    <CalendarX
                        aria-hidden="true"
                        className="mt-0.5 size-4 shrink-0 text-warning"
                    />
                    <span>
                        {t(
                            `leave.item.cancellation_${leave.cancellation.status}`,
                        )}
                        {leave.cancellation.status === 'rejected' &&
                        leave.cancellation.comment
                            ? ` «${leave.cancellation.comment}»`
                            : leave.cancellation.reason
                              ? ` «${leave.cancellation.reason}»`
                              : ''}
                    </span>
                </p>
            ) : null}
            {warnings.length > 0 ? (
                <ul
                    className="grid gap-1 rounded-md border border-warning bg-warning-soft px-2 py-1.5"
                    data-test="leave-warnings"
                >
                    {warnings.map((warning) => (
                        <li key={warning.code} className="flex gap-1.5">
                            <TriangleAlert
                                aria-hidden="true"
                                className="mt-0.5 size-4 shrink-0 text-warning"
                            />
                            <span>{warning.message}</span>
                        </li>
                    ))}
                </ul>
            ) : null}
            <AbsenceDocuments
                absenceId={absenceId}
                leave={leave}
                accept={accept}
            />
        </div>
    );
}
