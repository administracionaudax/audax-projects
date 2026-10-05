import { Link } from '@inertiajs/react';
import { CalendarDays, ChevronRight, Lock } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PersonStatusBadge, WeekLabel } from '@/components/weeklies/weekly-ui';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as mySpaceIndex } from '@/routes/my-space';
import type { MyWeeklyRow } from '@/types/weeklies';

/** Lo que se dice debajo del estado: apuntes, borrador o por qué no hace falta. */
function detail(row: MyWeeklyRow): string {
    if (row.status === 'exempt') {
        return t('weeklies.my_weeks.no_need');
    }

    if (row.submitted_at !== null) {
        return row.entries_count === 1
            ? t('weeklies.my_weeks.entries_one')
            : t('weeklies.my_weeks.entries_other', {
                  count: row.entries_count,
              });
    }

    if (row.has_draft) {
        return t('weeklies.my_weeks.draft');
    }

    if (row.cycle.status === 'closed') {
        return row.status === 'not_required'
            ? t('weeklies.my_weeks.no_need')
            : t('weeklies.my_weeks.closed_without');
    }

    return row.is_upcoming
        ? t('weeklies.my_weeks.not_yet')
        : t('weeklies.my_weeks.pending');
}

/**
 * «Mis envíos» (F-042, ReportList de WeeklySync): mis weeklies de la más reciente a la más
 * antigua, con su estado (Pendiente, Enviado, Enviado con retraso, Próximamente, Con retraso, No
 * enviada, Exento…). Cada fila abre esa weekly (en solo lectura si la semana está cerrada, F-043).
 */
export function MyWeeksList({ weeks }: { weeks: MyWeeklyRow[] }) {
    if (weeks.length === 0) {
        return (
            <EmptyState
                icon={CalendarDays}
                title={t('weeklies.my_weeks.empty')}
                description={t('weeklies.empty.description')}
            />
        );
    }

    return (
        <ul className="divide-y border bg-card" data-test="my-weeks">
            {weeks.map((row) => {
                const closed = row.cycle.status === 'closed';

                return (
                    <li key={row.cycle.id}>
                        <Link
                            href={mySpaceIndex.url({
                                query: { semana: row.cycle.id },
                            })}
                            className={cn(
                                'flex min-w-0 items-center gap-3 p-3 hover:bg-accent/50 md:p-4',
                                closed && 'bg-muted/40',
                                FOCUS_RING,
                            )}
                            data-test="my-week"
                            data-status={row.status}
                        >
                            <span className="grid min-w-0 flex-1 gap-1">
                                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <WeekLabel
                                        cycle={row.cycle}
                                        className="font-medium"
                                    />
                                    <span
                                        className={cn(
                                            'inline-flex items-center gap-1 px-1.5 py-0.5 text-xs',
                                            closed
                                                ? 'bg-neutral-soft'
                                                : 'bg-success-soft',
                                        )}
                                    >
                                        {closed ? (
                                            <Lock
                                                aria-hidden="true"
                                                className="size-3"
                                            />
                                        ) : null}
                                        {closed
                                            ? t('weeklies.cycle_status.closed')
                                            : row.is_upcoming
                                              ? t('weeklies.my_weeks.upcoming')
                                              : t(
                                                    'weeklies.cycle_status.active',
                                                )}
                                    </span>
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {t('weeklies.cycle.deadline', {
                                        date: formatDate(
                                            row.cycle.deadline_date,
                                        ),
                                    })}
                                </span>
                            </span>
                            <span className="grid shrink-0 justify-items-end gap-1 text-right">
                                <PersonStatusBadge status={row.status} />
                                <span className="text-xs text-muted-foreground">
                                    {detail(row)}
                                </span>
                            </span>
                            <ChevronRight
                                aria-hidden="true"
                                className="size-4 shrink-0 text-muted-foreground"
                            />
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
