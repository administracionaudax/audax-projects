import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarDays,
    CalendarPlus,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    CircleDashed,
    Flag,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { HolidayDialog } from '@/components/absences/holiday-dialog';
import { HolidayImport } from '@/components/absences/holiday-import';
import type { Holiday, HolidaysPageProps } from '@/components/absences/types';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { KeywordText } from '@/components/keyword-text';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { weekdayLongLabel } from '@/components/time/week-days';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as adminIndex } from '@/routes/admin';
import {
    destroy,
    index as holidaysIndex,
    national as addNational,
} from '@/routes/admin/holidays';

function yearUrl(year: number): string {
    return holidaysIndex.url({ query: { anio: year } });
}

function DeleteHoliday({ holiday }: { holiday: Holiday }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={setOpen}
            trigger={
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label={t('holidays.delete_label', {
                        name: holiday.name,
                    })}
                >
                    <Trash2 aria-hidden="true" />
                </Button>
            }
            title={t('holidays.delete_title', { name: holiday.name })}
            description={t('holidays.delete_description', {
                date: formatDate(holiday.date),
            })}
            confirmLabel={t('common.delete')}
            processing={processing}
            onConfirm={() =>
                router.delete(destroy.url(holiday.id), {
                    preserveScroll: true,
                    onError: toastVisitErrors,
                    onStart: () => setProcessing(true),
                    onFinish: () => {
                        setProcessing(false);
                        setOpen(false);
                    },
                })
            }
        />
    );
}

/**
 * Festivos (/admin/festivos, D-050): los del año elegido, los nacionales de España que faltan (con
 * el Viernes Santo calculado) y la importación de los autonómicos y locales. Un festivo deja la
 * capacidad de ese día a 0 para todo el equipo.
 */
export default function AdminHolidays({
    year,
    current_year: currentYear,
    holidays,
    national,
    limits,
}: HolidaysPageProps) {
    const [adding, setAdding] = useState(false);
    const missing = national.filter((holiday) => !holiday.exists);

    const addMissing = () =>
        router.post(
            addNational.url(),
            { year },
            {
                preserveScroll: true,
                onError: toastVisitErrors,
                onStart: () => setAdding(true),
                onFinish: () => setAdding(false),
            },
        );

    return (
        <>
            <Head title={t('holidays.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1">
                        <h1 className="text-2xl font-normal tracking-tight">
                            <KeywordText text={t('holidays.heading')} />
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('holidays.description')}
                        </p>
                    </div>
                    <HolidayDialog
                        trigger={
                            <Button>
                                <Plus aria-hidden="true" />
                                {t('holidays.new')}
                            </Button>
                        }
                    />
                </header>

                <nav
                    aria-label={t('holidays.year_nav')}
                    className="flex flex-wrap items-center gap-2"
                >
                    {year > limits.min_year ? (
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={yearUrl(year - 1)}
                                aria-label={t('holidays.previous_year', {
                                    year: year - 1,
                                })}
                            >
                                <ChevronLeft aria-hidden="true" />
                                {year - 1}
                            </Link>
                        </Button>
                    ) : null}
                    <span className="tabular px-2 text-lg" aria-current="page">
                        {year}
                    </span>
                    {year < limits.max_year ? (
                        <Button asChild variant="outline" size="sm">
                            <Link
                                href={yearUrl(year + 1)}
                                aria-label={t('holidays.next_year', {
                                    year: year + 1,
                                })}
                            >
                                {year + 1}
                                <ChevronRight aria-hidden="true" />
                            </Link>
                        </Button>
                    ) : null}
                    {year !== currentYear ? (
                        <Link
                            href={yearUrl(currentYear)}
                            className={cn(
                                'rounded-sm text-sm text-primary-text hover:underline',
                                FOCUS_RING,
                            )}
                        >
                            {t('holidays.current_year', { year: currentYear })}
                        </Link>
                    ) : null}
                </nav>

                <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <section
                        aria-labelledby="holidays-list-heading"
                        className="grid min-w-0 content-start gap-3"
                    >
                        <h2 id="holidays-list-heading" className="text-lg">
                            {t('holidays.list_heading', {
                                year,
                                count: holidays.length,
                            })}
                        </h2>
                        {holidays.length === 0 ? (
                            <EmptyState
                                icon={CalendarDays}
                                title={t('holidays.empty', { year })}
                                description={t('holidays.empty_description')}
                            />
                        ) : (
                            <div
                                className={cn(
                                    'overflow-x-auto rounded-md border',
                                    FOCUS_RING,
                                )}
                                role="region"
                                aria-label={t('holidays.table_label', {
                                    year,
                                })}
                                tabIndex={0}
                            >
                                <table className="w-full min-w-[28rem] text-sm">
                                    <caption className="sr-only">
                                        {t('holidays.table_label', { year })}
                                    </caption>
                                    <thead>
                                        <tr className="border-b text-left">
                                            <th
                                                scope="col"
                                                className="px-3 py-2 font-medium"
                                            >
                                                {t('holidays.column.date')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 font-medium"
                                            >
                                                {t('holidays.column.name')}
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-3 py-2 text-right font-medium"
                                            >
                                                <span className="sr-only">
                                                    {t('common.actions')}
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {holidays.map((holiday) => (
                                            <tr
                                                key={holiday.id}
                                                className="border-b last:border-b-0 even:bg-muted"
                                                data-test="holiday-row"
                                            >
                                                <td className="px-3 py-2 whitespace-nowrap">
                                                    <span className="tabular">
                                                        {formatDate(
                                                            holiday.date,
                                                        )}
                                                    </span>
                                                    <span className="ml-2 text-muted-foreground capitalize">
                                                        {weekdayLongLabel(
                                                            holiday.date,
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2">
                                                    {holiday.name}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <div className="flex justify-end gap-1">
                                                        <HolidayDialog
                                                            holiday={holiday}
                                                            trigger={
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-8"
                                                                    aria-label={t(
                                                                        'holidays.edit_label',
                                                                        {
                                                                            name: holiday.name,
                                                                        },
                                                                    )}
                                                                >
                                                                    <Pencil aria-hidden="true" />
                                                                </Button>
                                                            }
                                                        />
                                                        <DeleteHoliday
                                                            holiday={holiday}
                                                        />
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    <section
                        aria-labelledby="national-heading"
                        className="grid min-w-0 content-start gap-3 rounded-md border p-4"
                        data-test="national-holidays"
                    >
                        <div className="space-y-1">
                            <h2
                                id="national-heading"
                                className="flex items-center gap-2 text-lg"
                            >
                                <Flag
                                    aria-hidden="true"
                                    className="size-5 text-muted-foreground"
                                    strokeWidth={1.5}
                                />
                                {t('holidays.national.heading', { year })}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('holidays.national.description')}
                            </p>
                        </div>
                        <ul
                            className="grid gap-1 text-sm"
                            aria-label={t('holidays.national.list_label', {
                                year,
                            })}
                        >
                            {national.map((holiday) => (
                                <li
                                    key={holiday.date}
                                    className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1"
                                >
                                    <span>
                                        <span className="tabular">
                                            {formatDate(holiday.date)}
                                        </span>{' '}
                                        {holiday.name}
                                    </span>
                                    {holiday.exists ? (
                                        <StatusBadge
                                            tone="success"
                                            icon={CircleCheck}
                                        >
                                            {t('holidays.national.present')}
                                        </StatusBadge>
                                    ) : (
                                        <StatusBadge
                                            tone="neutral"
                                            icon={CircleDashed}
                                        >
                                            {t('holidays.national.missing')}
                                        </StatusBadge>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <Button
                            type="button"
                            variant={missing.length > 0 ? 'default' : 'outline'}
                            className="justify-self-start"
                            disabled={adding || missing.length === 0}
                            onClick={addMissing}
                        >
                            {adding ? (
                                <Spinner />
                            ) : (
                                <CalendarPlus aria-hidden="true" />
                            )}
                            {missing.length === 0
                                ? t('holidays.national.complete')
                                : missing.length === 1
                                  ? t('holidays.national.add_one')
                                  : t('holidays.national.add_many', {
                                        count: missing.length,
                                    })}
                        </Button>
                    </section>
                </div>

                <HolidayImport limits={limits} />
            </div>
        </>
    );
}

AdminHolidays.layout = {
    breadcrumbs: [
        { title: t('nav.admin'), href: adminIndex() },
        { title: t('holidays.title'), href: holidaysIndex() },
    ],
};
