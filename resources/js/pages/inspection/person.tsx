import { Head, Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { InspectionShell } from '@/components/inspection/inspection-shell';
import { IncidentList } from '@/components/people/people-ui';
import { formatMinutes, formatTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import {
    formatDifference,
    modeLabel,
    monthLabel,
    shortDayLabel,
} from '@/lib/people';
import { cn } from '@/lib/utils';
import { index, person as personRoute } from '@/routes/inspection';
import type {
    CloseTotals,
    InspectionLine,
    InspectionPortalAccess,
} from '@/types/people-register';

/**
 * El registro de una persona, mes a mes, en solo lectura (D-353): cada día con su horario previsto,
 * la entrada y la salida, los tramos, lo trabajado, las horas extra y las incidencias. Las
 * ausencias salen sin su tipo.
 */
export default function InspectionPersonPage({
    access,
    person,
    month,
    months,
    lines,
    totals,
}: {
    access: InspectionPortalAccess;
    person: { id: number; name: string };
    month: string;
    months: string[];
    lines: InspectionLine[];
    totals: CloseTotals;
}) {
    return (
        <InspectionShell access={access}>
            <Head title={person.name} />
            <Link
                href={index.url()}
                className="inline-flex items-center gap-1 text-sm text-primary-text hover:underline"
            >
                <ChevronLeft aria-hidden="true" className="size-4" />
                {t('people.portal.back')}
            </Link>
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-normal tracking-tight">
                        {person.name}
                    </h1>
                    <p className="text-sm text-muted-foreground first-letter:uppercase">
                        {monthLabel(month)}
                    </p>
                </div>
                <nav
                    aria-label={t('people.portal.months')}
                    className="flex flex-wrap gap-1.5"
                >
                    {months.map((item) => (
                        <Link
                            key={item}
                            href={personRoute.url(person.id, {
                                query: { mes: item },
                            })}
                            aria-current={item === month ? 'page' : undefined}
                            className={cn(
                                'rounded-md border px-2 py-0.5 text-xs first-letter:uppercase',
                                item === month
                                    ? 'border-primary bg-accent'
                                    : 'hover:bg-accent',
                            )}
                        >
                            {monthLabel(item)}
                        </Link>
                    ))}
                </nav>
            </header>
            <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                {[
                    [
                        t('people.workday.worked'),
                        formatMinutes(totals.worked_minutes),
                    ],
                    [
                        t('people.portal.expected'),
                        formatMinutes(totals.expected_minutes),
                    ],
                    [
                        t('people.workday.difference'),
                        formatDifference(totals.difference_minutes),
                    ],
                    [
                        t('people.closes.overtime'),
                        formatMinutes(
                            totals.overtime_minutes +
                                totals.complementary_minutes,
                        ),
                    ],
                ].map(([label, value]) => (
                    <div key={label} className="grid gap-0.5">
                        <dt className="text-xs text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="tabular text-lg">{value}</dd>
                    </div>
                ))}
            </dl>
            <div className="overflow-x-auto rounded-md border">
                <table className="w-full text-sm" data-test="portal-lines">
                    <caption className="sr-only">
                        {t('people.portal.caption', { name: person.name })}
                    </caption>
                    <thead>
                        <tr className="border-b text-left text-xs text-muted-foreground">
                            <th scope="col" className="px-3 py-2 font-medium">
                                {t('people.workday.col_day')}
                            </th>
                            <th scope="col" className="px-3 py-2 font-medium">
                                {t('people.portal.expected')}
                            </th>
                            <th scope="col" className="px-3 py-2 font-medium">
                                {t('people.portal.in_out')}
                            </th>
                            <th scope="col" className="px-3 py-2 font-medium">
                                {t('people.workday.col_records')}
                            </th>
                            <th
                                scope="col"
                                className="px-3 py-2 text-right font-medium"
                            >
                                {t('people.workday.col_worked')}
                            </th>
                            <th
                                scope="col"
                                className="px-3 py-2 text-right font-medium"
                            >
                                {t('people.closes.overtime')}
                            </th>
                            <th scope="col" className="px-3 py-2 font-medium">
                                {t('people.workday.col_mode')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((line) => (
                            <tr
                                key={line.date}
                                className={cn(
                                    'border-b align-top last:border-0',
                                    line.expected_minutes === 0 &&
                                        line.worked_minutes === 0 &&
                                        'text-muted-foreground',
                                )}
                            >
                                <th
                                    scope="row"
                                    className="px-3 py-2 text-left font-normal capitalize"
                                >
                                    {shortDayLabel(line.date)}
                                </th>
                                <td className="tabular px-3 py-2 text-xs">
                                    {line.holiday
                                        ? t('people.workday.holiday', {
                                              name: line.holiday,
                                          })
                                        : line.absence &&
                                            line.expected_minutes === 0
                                          ? t('people.workday.absence')
                                          : line.expected_minutes > 0
                                            ? formatMinutes(
                                                  line.expected_minutes,
                                              )
                                            : '—'}
                                </td>
                                <td className="tabular px-3 py-2">
                                    {line.first_in
                                        ? `${formatTime(line.first_in)}–${line.last_out ? formatTime(line.last_out) : '…'}`
                                        : '—'}
                                </td>
                                <td className="tabular px-3 py-2 text-xs">
                                    {line.segments}
                                    <IncidentList
                                        incidents={line.incidents}
                                        className="mt-1"
                                    />
                                </td>
                                <td className="tabular px-3 py-2 text-right">
                                    {line.worked_minutes > 0
                                        ? formatMinutes(line.worked_minutes)
                                        : ''}
                                </td>
                                <td className="tabular px-3 py-2 text-right">
                                    {line.overtime_minutes > 0
                                        ? formatMinutes(line.overtime_minutes)
                                        : ''}
                                </td>
                                <td className="px-3 py-2 text-xs">
                                    {line.modes.map(modeLabel).join(', ')}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </InspectionShell>
    );
}
