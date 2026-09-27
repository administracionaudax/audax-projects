import { CircleDot, MessageSquareText } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    AbsenceStatusBadge,
    AbsenceTypeLabel,
    absencePeriodLabel,
    workingDaysLabel,
} from '@/components/absences/absence-meta';
import type { AbsenceRow } from '@/components/absences/types';
import { formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';

/** ¿La ausencia está en curso hoy? */
export function isInProgress(
    absence: Pick<AbsenceRow, 'start_date' | 'end_date' | 'status'>,
    today: string,
): boolean {
    return (
        absence.status === 'approved' &&
        absence.start_date <= today &&
        absence.end_date >= today
    );
}

/**
 * Una ausencia en una lista: tipo, fechas, días laborables (o sus horas si es parcial), estado
 * con icono y texto, quién la revisó, notas y comentario de rechazo, y sus acciones.
 */
export function AbsenceItem({
    absence,
    today,
    person,
    children,
    extra,
}: {
    absence: AbsenceRow;
    today: string;
    /** En «Ausencias del equipo»: la persona y su departamento. */
    person?: AbsenceRow['user'];
    /** Acciones (cancelar, aprobar, rechazar…). */
    children?: ReactNode;
    /** Contenido adicional bajo los datos (p. ej. las ausencias que coinciden). */
    extra?: ReactNode;
}) {
    const days =
        absence.partial_minutes !== null
            ? t('absences.partial_label', {
                  minutes: formatMinutes(absence.partial_minutes),
              })
            : workingDaysLabel(absence.working_days);
    const inProgress = isInProgress(absence, today);

    return (
        <li
            className="flex flex-col gap-3 rounded-md border p-3 sm:flex-row sm:items-start sm:justify-between"
            data-test="absence-item"
        >
            <div className="grid min-w-0 flex-1 gap-1 text-sm">
                {person ? (
                    <p className="flex flex-wrap items-center gap-x-2 font-medium">
                        {person.department ? (
                            <span
                                aria-hidden="true"
                                className="size-2 shrink-0 rounded-full"
                                style={{
                                    backgroundColor: person.department.color,
                                }}
                            />
                        ) : null}
                        <span className="min-w-0 break-words">
                            {person.name}
                        </span>
                        {person.department ? (
                            <span className="font-normal text-muted-foreground">
                                · {person.department.name}
                            </span>
                        ) : null}
                    </p>
                ) : null}
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <AbsenceTypeLabel
                        type={absence.type}
                        className="inline-flex items-center gap-1.5 font-medium"
                    />
                    <span className="tabular">
                        {absencePeriodLabel(absence)}
                    </span>
                </p>
                <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-muted-foreground">
                    {days ? <span>{days}</span> : null}
                    {inProgress ? (
                        <span className="inline-flex items-center gap-1 text-foreground">
                            <CircleDot
                                aria-hidden="true"
                                className="size-3.5 text-info"
                            />
                            {t('absences.in_progress')}
                        </span>
                    ) : null}
                    {absence.status === 'approved' ? (
                        absence.auto_approved ? (
                            <span>{t('absences.auto_approved')}</span>
                        ) : absence.reviewer ? (
                            <span>
                                {t('absences.approved_by', {
                                    name: absence.reviewer.name,
                                })}
                            </span>
                        ) : null
                    ) : null}
                </p>
                {absence.notes ? (
                    <p className="text-muted-foreground">
                        <span className="text-foreground">
                            {t('absences.notes')}
                        </span>{' '}
                        {absence.notes}
                    </p>
                ) : null}
                {absence.status === 'rejected' && absence.review_comment ? (
                    <p className="flex gap-1.5 rounded-md bg-danger-soft px-2 py-1.5">
                        <MessageSquareText
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-danger"
                        />
                        <span>
                            {absence.reviewer
                                ? t('absences.rejected_by', {
                                      name: absence.reviewer.name,
                                  })
                                : t('absences.rejected_comment')}{' '}
                            «{absence.review_comment}»
                        </span>
                    </p>
                ) : null}
                {extra}
            </div>
            <div className="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
                <AbsenceStatusBadge status={absence.status} />
                {children}
            </div>
        </li>
    );
}
