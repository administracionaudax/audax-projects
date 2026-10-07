import { Link } from '@inertiajs/react';
import { Clock, PencilLine } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatDateTime, formatMinutes, formatTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import {
    formatDifference,
    kindLabel,
    longDayLabel,
    madridDate,
    modeLabel,
    segmentsLabel,
} from '@/lib/people';
import { isoWeek } from '@/lib/week';
import { cn } from '@/lib/utils';
import { index as timeIndex } from '@/routes/time';
import type { DayDetail } from '@/types/people';
import { CorrectionCard } from './correction-card';
import { CorrectionDialog } from './correction-dialog';
import { DayStatusBadge, Figure, IncidentList } from './people-ui';

/**
 * El detalle de un día del registro (PLAN-FASE-11 §6.1.3; W-020 y W-022), en un panel lateral: el
 * resumen, los fichajes que cuentan, las correcciones (con aceptar, rechazar o retirar) y el
 * **historial** completo, en orden, con lo anulado tachado: nada se borra ni se sobrescribe. A la
 * propia persona, además, lo que imputó ese día (solo ella lo ve, §3.2.5).
 */
export function DayDetailSheet({
    detail,
    subject,
    open,
    onClose,
}: {
    detail: DayDetail | null;
    subject: { id: number; name: string; is_me: boolean };
    open: boolean;
    onClose: () => void;
}) {
    const [proposing, setProposing] = useState(false);

    return (
        <Sheet
            open={open && detail !== null}
            onOpenChange={(next) => (next ? null : onClose())}
        >
            <SheetContent
                className="w-full overflow-y-auto sm:max-w-xl"
                data-test="day-detail"
            >
                {detail ? (
                    <>
                        <SheetHeader className="pr-10">
                            <SheetTitle className="text-lg font-normal first-letter:uppercase">
                                {longDayLabel(detail.date)}
                            </SheetTitle>
                            <SheetDescription asChild>
                                <div className="flex flex-wrap items-center gap-2">
                                    <DayStatusBadge
                                        status={detail.day.status}
                                    />
                                    <IncidentList
                                        incidents={detail.day.incidents}
                                    />
                                </div>
                            </SheetDescription>
                        </SheetHeader>

                        <div className="grid gap-6 px-4 pb-6">
                            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <Figure
                                    label={t('people.workday.worked')}
                                    value={formatMinutes(
                                        detail.day.worked_minutes,
                                    )}
                                    testId="day-worked"
                                />
                                <Figure
                                    label={t('people.workday.expected')}
                                    value={formatMinutes(
                                        detail.day.expected_minutes,
                                    )}
                                />
                                <Figure
                                    label={t('people.workday.difference')}
                                    value={
                                        formatDifference(
                                            detail.day.difference_minutes,
                                        ) || '—'
                                    }
                                    tone={
                                        (detail.day.difference_minutes ?? 0) < 0
                                            ? 'negative'
                                            : undefined
                                    }
                                />
                                <Figure
                                    label={t('people.workday.col_pause')}
                                    value={formatMinutes(
                                        detail.day.pause_minutes,
                                    )}
                                />
                            </dl>

                            {detail.day.schedule?.start_from &&
                            detail.day.schedule.start_to ? (
                                <p className="text-xs text-muted-foreground">
                                    {t('people.workday.window', {
                                        from: detail.day.schedule.start_from,
                                        to: detail.day.schedule.start_to,
                                    })}
                                </p>
                            ) : null}

                            {detail.logged_minutes !== null ? (
                                <div className="grid gap-1 rounded-md bg-muted/60 p-3 text-sm">
                                    <p>
                                        {t('people.day.logged', {
                                            logged: formatMinutes(
                                                detail.logged_minutes,
                                            ),
                                        })}{' '}
                                        <Link
                                            href={timeIndex.url({
                                                query: {
                                                    semana: isoWeek(
                                                        detail.date,
                                                    ),
                                                },
                                            })}
                                            className="underline"
                                        >
                                            {t('people.day.log_hours')}
                                        </Link>
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {t('people.day.logged_hint')}
                                    </p>
                                </div>
                            ) : null}

                            <section
                                className="grid gap-2"
                                aria-labelledby="day-timeline"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h2
                                        id="day-timeline"
                                        className="text-base font-medium"
                                    >
                                        {t('people.day.timeline')}
                                    </h2>
                                    {detail.can.propose ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setProposing(true)}
                                            data-test="day-propose"
                                        >
                                            <PencilLine aria-hidden="true" />
                                            {t('people.day.propose')}
                                        </Button>
                                    ) : detail.day.pending_corrections > 0 ? (
                                        <span className="text-xs text-muted-foreground">
                                            {t('people.day.propose_blocked')}
                                        </span>
                                    ) : null}
                                </div>
                                {detail.day.events.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('people.day.no_events')}
                                    </p>
                                ) : (
                                    <>
                                        <ol
                                            className="grid gap-1"
                                            data-test="day-events"
                                        >
                                            {detail.day.events.map((event) => (
                                                <li
                                                    key={event.id}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <Clock
                                                        aria-hidden="true"
                                                        className="size-3.5 text-muted-foreground"
                                                    />
                                                    <span className="tabular w-14">
                                                        {formatTime(event.at)}
                                                        {madridDate(
                                                            event.at,
                                                        ) !== detail.date
                                                            ? ` ${t('people.day.next_day')}`
                                                            : ''}
                                                    </span>
                                                    <span>
                                                        {kindLabel(event.kind)}
                                                    </span>
                                                    {event.work_mode ? (
                                                        <span className="text-muted-foreground">
                                                            ·{' '}
                                                            {modeLabel(
                                                                event.work_mode,
                                                            )}
                                                        </span>
                                                    ) : null}
                                                    {event.source ===
                                                    'correction' ? (
                                                        <span className="rounded-md bg-info-soft px-1.5 text-xs">
                                                            {t(
                                                                'people.sources.correction',
                                                            )}
                                                        </span>
                                                    ) : null}
                                                </li>
                                            ))}
                                        </ol>
                                        <p className="tabular text-xs text-muted-foreground">
                                            {segmentsLabel(
                                                detail.day.workdays,
                                                detail.date,
                                            )}
                                        </p>
                                    </>
                                )}
                            </section>

                            {detail.corrections.length > 0 ? (
                                <section
                                    className="grid gap-2"
                                    aria-labelledby="day-corrections"
                                >
                                    <h2
                                        id="day-corrections"
                                        className="text-base font-medium"
                                    >
                                        {t('people.day.corrections')}
                                    </h2>
                                    {detail.corrections.map((correction) => (
                                        <CorrectionCard
                                            key={correction.id}
                                            correction={correction}
                                        />
                                    ))}
                                </section>
                            ) : null}

                            <section
                                className="grid gap-2"
                                aria-labelledby="day-history"
                            >
                                <h2
                                    id="day-history"
                                    className="text-base font-medium"
                                >
                                    {t('people.day.history')}
                                </h2>
                                <p className="text-xs text-muted-foreground">
                                    {t('people.day.history_hint')}
                                </p>
                                <div className="overflow-x-auto">
                                    <table
                                        className="w-full min-w-[30rem] text-xs"
                                        data-test="day-history"
                                    >
                                        <caption className="sr-only">
                                            {t('people.day.history_caption')}
                                        </caption>
                                        <thead>
                                            <tr className="border-b text-left text-muted-foreground">
                                                <th
                                                    scope="col"
                                                    className="py-1 pr-2 font-medium"
                                                >
                                                    {t('people.day.col_seq')}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="py-1 pr-2 font-medium"
                                                >
                                                    {t('people.day.col_kind')}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="py-1 pr-2 font-medium"
                                                >
                                                    {t('people.day.col_time')}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="py-1 pr-2 font-medium"
                                                >
                                                    {t(
                                                        'people.day.col_recorded',
                                                    )}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="py-1 pr-2 font-medium"
                                                >
                                                    {t('people.day.col_by')}
                                                </th>
                                                <th
                                                    scope="col"
                                                    className="py-1 font-medium"
                                                >
                                                    {t('people.day.col_state')}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {detail.history.map((row) => (
                                                <tr
                                                    key={row.id}
                                                    className="border-b last:border-0"
                                                    data-test="history-row"
                                                    data-voided={
                                                        row.voided || undefined
                                                    }
                                                >
                                                    <td
                                                        className="tabular py-1 pr-2"
                                                        title={t(
                                                            'people.day.hash',
                                                            {
                                                                hash: row.hash.slice(
                                                                    0,
                                                                    12,
                                                                ),
                                                            },
                                                        )}
                                                    >
                                                        {row.seq}
                                                    </td>
                                                    <td
                                                        className={cn(
                                                            'py-1 pr-2',
                                                            row.voided &&
                                                                'text-muted-foreground line-through',
                                                        )}
                                                    >
                                                        {kindLabel(row.kind)}
                                                    </td>
                                                    <td
                                                        className={cn(
                                                            'tabular py-1 pr-2',
                                                            row.voided &&
                                                                'text-muted-foreground line-through',
                                                        )}
                                                    >
                                                        {row.kind === 'void'
                                                            ? ''
                                                            : formatTime(
                                                                  row.at,
                                                              )}
                                                    </td>
                                                    <td className="tabular py-1 pr-2 text-muted-foreground">
                                                        {formatDateTime(
                                                            row.recorded_at,
                                                        )}
                                                    </td>
                                                    <td className="py-1 pr-2">
                                                        {row.author ?? '—'}
                                                    </td>
                                                    <td className="py-1">
                                                        {row.kind === 'void'
                                                            ? t(
                                                                  'people.day.void_row',
                                                                  {
                                                                      seq:
                                                                          detail.history.find(
                                                                              (
                                                                                  candidate,
                                                                              ) =>
                                                                                  candidate.id ===
                                                                                  row.voided_event_id,
                                                                          )
                                                                              ?.seq ??
                                                                          '?',
                                                                  },
                                                              )
                                                            : row.voided
                                                              ? t(
                                                                    'people.day.voided',
                                                                    {
                                                                        id:
                                                                            row.voided_by_correction_id ??
                                                                            '?',
                                                                    },
                                                                )
                                                              : row.correction_id
                                                                ? t(
                                                                      'people.day.added',
                                                                      {
                                                                          id: row.correction_id,
                                                                      },
                                                                  )
                                                                : t(
                                                                      'people.day.original',
                                                                  )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        </div>

                        <CorrectionDialog
                            subject={subject}
                            date={detail.date}
                            events={detail.day.events}
                            open={proposing}
                            onOpenChange={setProposing}
                        />
                    </>
                ) : null}
            </SheetContent>
        </Sheet>
    );
}
