import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/react';
import {
    CalendarClock,
    Pencil,
    Plus,
    Send,
    Timer,
    Trash2,
    UserCheck,
    UserX,
} from 'lucide-react';
import { useState } from 'react';
import { AccountStatusBadge, RoleBadge } from '@/components/admin/badges';
import { EmploymentCard } from '@/components/admin/employment-card';
import { ScheduleDialog } from '@/components/admin/schedule-dialog';
import {
    decimalToInput,
    UserFormFields,
} from '@/components/admin/user-form-fields';
import type { UserFormData } from '@/components/admin/user-form-fields';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { WEEK_DAYS_SHORT } from '@/components/admin/week-minutes-input';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { AdminPersonalDataExports } from '@/components/privacy/admin-personal-data-exports';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatDateTime, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { todayInMadrid } from '@/lib/week';
import { index as adminIndex } from '@/routes/admin';
import {
    deactivation,
    edit,
    index as usersIndex,
    invitation,
    reactivate,
    update,
} from '@/routes/admin/users';
import { destroy as destroySchedule } from '@/routes/admin/users/schedules';
import type { AdminUserEditProps, AdminWorkSchedule } from '@/types';
import type { PersonalDataExportRow } from '@/types/privacy';

/**
 * Margen de entrada, comida prevista y verano de una versión (registro de jornada, D-336), en una
 * línea. Nada si la versión no los tiene.
 */
function ScheduleRegisterSummary({
    schedule,
}: {
    schedule: AdminWorkSchedule;
}) {
    const parts = [
        schedule.start_time_from && schedule.start_time_to
            ? t('people.schedule.summary_window', {
                  from: schedule.start_time_from,
                  to: schedule.start_time_to,
              })
            : null,
        schedule.expected_pause_minutes
            ? t('people.schedule.summary_pause', {
                  minutes: schedule.expected_pause_minutes,
              })
            : null,
        schedule.summer
            ? t('people.schedule.summary_summer', {
                  from: schedule.summer.starts_on,
                  to: schedule.summer.ends_on,
              })
            : null,
    ].filter((part): part is string => part !== null);

    return parts.length === 0 ? null : (
        <p
            className="text-xs text-muted-foreground"
            data-test="schedule-register-summary"
        >
            {parts.join(' · ')}
        </p>
    );
}

/** Día siguiente a una fecha "YYYY-MM-DD" (sin zonas horarias). */
function nextDay(date: string): string {
    const [year, month, day] = date.split('-').map(Number);
    const next = new Date(Date.UTC(year, month - 1, day + 1));

    return next.toISOString().slice(0, 10);
}

/** Ficha de una persona (SPEC §14): datos, rol y departamento, jornada versionada y estado. */
export default function AdminUserEdit({
    user,
    schedules,
    departments,
    roles,
    openTasksCount,
    hasActiveTimer,
    can,
    employment = null,
    personalDataExports = null,
}: AdminUserEditProps & {
    /** Exportaciones de sus datos personales (D-075): solo para el admin. */
    personalDataExports?: PersonalDataExportRow[] | null;
}) {
    const form = useForm<UserFormData>({
        name: user.name,
        email: user.email,
        role:
            user.role === 'client' || user.role === null
                ? 'employee'
                : user.role,
        department_id:
            user.department_id === null ? '' : String(user.department_id),
        job_title: user.job_title ?? '',
        hourly_cost: decimalToInput(user.hourly_cost),
        default_hourly_rate: decimalToInput(user.default_hourly_rate),
    });
    const [resending, setResending] = useState(false);
    const [reactivating, setReactivating] = useState(false);
    const [scheduleToDelete, setScheduleToDelete] =
        useState<AdminWorkSchedule | null>(null);
    const [deleting, setDeleting] = useState(false);

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.admin'), href: adminIndex() },
            { title: t('admin.users.title'), href: usersIndex() },
            { title: user.name, href: edit(user.id) },
        ],
    });

    const today = todayInMadrid();
    const current = schedules.find((schedule) => schedule.is_current) ?? null;
    const latest = schedules[0] ?? null;
    const defaultFrom =
        latest && latest.valid_from >= today
            ? nextDay(latest.valid_from)
            : nextDay(today);

    const save = (event: React.FormEvent) => {
        event.preventDefault();
        form.put(update.url(user.id), { preserveScroll: true });
    };

    const resend = () => {
        router.post(
            invitation.url(user.id),
            {},
            {
                preserveScroll: true,
                onError: toastVisitErrors,
                onStart: () => setResending(true),
                onFinish: () => setResending(false),
            },
        );
    };

    const doReactivate = () => {
        router.post(
            reactivate.url(user.id),
            {},
            {
                preserveScroll: true,
                onError: toastVisitErrors,
                onStart: () => setReactivating(true),
                onFinish: () => setReactivating(false),
            },
        );
    };

    const deleteSchedule = () => {
        if (!scheduleToDelete) {
            return;
        }

        router.delete(
            destroySchedule.url({
                user: user.id,
                workSchedule: scheduleToDelete.id,
            }),
            {
                preserveScroll: true,
                onError: toastVisitErrors,
                onStart: () => setDeleting(true),
                onFinish: () => {
                    setDeleting(false);
                    setScheduleToDelete(null);
                },
            },
        );
    };

    return (
        <>
            <Head title={user.name} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <h1 className="text-2xl font-normal tracking-tight break-words">
                            {user.name}
                        </h1>
                        <p className="text-sm break-all text-muted-foreground">
                            {user.email}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <RoleBadge role={user.role} />
                            <AccountStatusBadge
                                isActive={user.is_active}
                                pending={user.last_login_at === null}
                            />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {user.last_login_at
                                ? t('admin.users.last_login', {
                                      date: formatDateTime(user.last_login_at),
                                  })
                                : t('admin.users.never_logged_in')}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {user.two_factor_enabled
                                ? t('admin.users.two_factor_on')
                                : t('admin.users.two_factor_off')}
                        </p>
                    </div>
                    {can.manage ? (
                        <div className="flex flex-wrap gap-2">
                            {user.is_active ? (
                                <Button
                                    variant="outline"
                                    onClick={resend}
                                    disabled={resending}
                                    data-test="resend-invitation"
                                >
                                    {resending ? (
                                        <Spinner />
                                    ) : (
                                        <Send aria-hidden="true" />
                                    )}
                                    {t('admin.users.resend_invitation')}
                                </Button>
                            ) : null}
                            {user.is_active && can.deactivate ? (
                                <Button variant="outline" asChild>
                                    <Link href={deactivation.url(user.id)}>
                                        <UserX aria-hidden="true" />
                                        {t('admin.users.deactivate')}
                                    </Link>
                                </Button>
                            ) : null}
                            {!user.is_active ? (
                                <Button
                                    onClick={doReactivate}
                                    disabled={reactivating}
                                >
                                    {reactivating ? (
                                        <Spinner />
                                    ) : (
                                        <UserCheck aria-hidden="true" />
                                    )}
                                    {t('admin.users.reactivate')}
                                </Button>
                            ) : null}
                        </div>
                    ) : null}
                </header>

                {!can.manage ? (
                    <p className="rounded-md bg-info-soft px-3 py-2 text-sm text-foreground">
                        {t('admin.users.admin_only_notice')}
                    </p>
                ) : null}

                {user.is_active && (openTasksCount > 0 || hasActiveTimer) ? (
                    <ul className="flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
                        {openTasksCount > 0 ? (
                            <li>
                                {t('admin.users.open_tasks', {
                                    count: openTasksCount,
                                })}
                            </li>
                        ) : null}
                        {hasActiveTimer ? (
                            <li className="inline-flex items-center gap-1.5">
                                <Timer
                                    aria-hidden="true"
                                    className="size-4 text-info"
                                />
                                {t('admin.users.timer_running')}
                            </li>
                        ) : null}
                    </ul>
                ) : null}

                <div className="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                <h2 className="text-base font-medium">
                                    {t('admin.users.data_title')}
                                </h2>
                            </CardTitle>
                            <CardDescription>
                                {t('admin.users.data_description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={save}
                                className="grid gap-6"
                                noValidate
                            >
                                <fieldset
                                    disabled={!can.manage}
                                    className="contents"
                                >
                                    <UserFormFields
                                        data={form.data}
                                        setData={(key, value) =>
                                            form.setData(key, value)
                                        }
                                        errors={form.errors}
                                        roles={roles}
                                        departments={departments}
                                        canGrantAdmin={can.grantAdmin}
                                        canChangeRole={can.changeRole}
                                        showFinancials={can.viewFinancials}
                                    />
                                </fieldset>
                                {can.manage ? (
                                    <div>
                                        <Button
                                            type="submit"
                                            disabled={form.processing}
                                        >
                                            {form.processing && <Spinner />}
                                            {t('common.save')}
                                        </Button>
                                    </div>
                                ) : null}
                            </form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
                            <div className="space-y-1.5">
                                <CardTitle>
                                    <h2 className="text-base font-medium">
                                        {t('admin.schedules.title')}
                                    </h2>
                                </CardTitle>
                                <CardDescription>
                                    {current
                                        ? t('admin.schedules.current', {
                                              total: formatMinutes(
                                                  current.weekly_minutes,
                                              ),
                                          })
                                        : t('admin.schedules.none_current')}
                                </CardDescription>
                            </div>
                            {can.manage ? (
                                <ScheduleDialog
                                    userId={user.id}
                                    initial={latest ?? current ?? undefined}
                                    initialWeek={
                                        (latest ?? current)?.week ?? [
                                            480, 480, 480, 480, 480, 0, 0,
                                        ]
                                    }
                                    defaultFrom={defaultFrom}
                                    trigger={
                                        <Button variant="outline" size="sm">
                                            <Plus aria-hidden="true" />
                                            {t('admin.schedules.new')}
                                        </Button>
                                    }
                                />
                            ) : null}
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            {schedules.length === 0 ? (
                                <EmptyState
                                    icon={CalendarClock}
                                    title={t('admin.schedules.empty')}
                                    description={t(
                                        'admin.schedules.empty_description',
                                    )}
                                />
                            ) : (
                                <ol
                                    className="grid gap-3"
                                    aria-label={t(
                                        'admin.schedules.history_label',
                                    )}
                                >
                                    {schedules.map((schedule) => (
                                        <li
                                            key={schedule.id}
                                            className="grid gap-2 rounded-md border p-3"
                                            data-test="schedule-version"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <p className="text-sm font-medium">
                                                    {schedule.valid_to
                                                        ? t(
                                                              'admin.schedules.range',
                                                              {
                                                                  from: formatDate(
                                                                      schedule.valid_from,
                                                                  ),
                                                                  to: formatDate(
                                                                      schedule.valid_to,
                                                                  ),
                                                              },
                                                          )
                                                        : t(
                                                              'admin.schedules.since',
                                                              {
                                                                  from: formatDate(
                                                                      schedule.valid_from,
                                                                  ),
                                                              },
                                                          )}
                                                    {schedule.is_current ? (
                                                        <span className="ml-2 rounded-md bg-success-soft px-1.5 py-0.5 text-xs font-medium">
                                                            {t(
                                                                'admin.schedules.in_force',
                                                            )}
                                                        </span>
                                                    ) : null}
                                                    {schedule.valid_from >
                                                    today ? (
                                                        <span className="ml-2 rounded-md bg-info-soft px-1.5 py-0.5 text-xs font-medium">
                                                            {t(
                                                                'admin.schedules.upcoming',
                                                            )}
                                                        </span>
                                                    ) : null}
                                                </p>
                                                {can.manage &&
                                                schedule.is_editable ? (
                                                    <div className="flex gap-1">
                                                        <ScheduleDialog
                                                            userId={user.id}
                                                            schedule={schedule}
                                                            initialWeek={
                                                                schedule.week
                                                            }
                                                            defaultFrom={
                                                                schedule.valid_from
                                                            }
                                                            trigger={
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-8"
                                                                    aria-label={t(
                                                                        'admin.schedules.edit_label',
                                                                        {
                                                                            from: formatDate(
                                                                                schedule.valid_from,
                                                                            ),
                                                                        },
                                                                    )}
                                                                >
                                                                    <Pencil aria-hidden="true" />
                                                                </Button>
                                                            }
                                                        />
                                                        <ConfirmDialog
                                                            open={
                                                                scheduleToDelete?.id ===
                                                                schedule.id
                                                            }
                                                            onOpenChange={(
                                                                open,
                                                            ) =>
                                                                setScheduleToDelete(
                                                                    open
                                                                        ? schedule
                                                                        : null,
                                                                )
                                                            }
                                                            trigger={
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-8"
                                                                    aria-label={t(
                                                                        'admin.schedules.delete_label',
                                                                        {
                                                                            from: formatDate(
                                                                                schedule.valid_from,
                                                                            ),
                                                                        },
                                                                    )}
                                                                >
                                                                    <Trash2 aria-hidden="true" />
                                                                </Button>
                                                            }
                                                            title={t(
                                                                'admin.schedules.delete_title',
                                                            )}
                                                            description={t(
                                                                'admin.schedules.delete_description',
                                                            )}
                                                            confirmLabel={t(
                                                                'common.delete',
                                                            )}
                                                            processing={
                                                                deleting
                                                            }
                                                            onConfirm={
                                                                deleteSchedule
                                                            }
                                                        />
                                                    </div>
                                                ) : null}
                                            </div>
                                            <dl className="grid grid-cols-4 gap-x-2 gap-y-1 text-xs sm:grid-cols-8">
                                                {WEEK_DAYS_SHORT.map(
                                                    (day, index) => (
                                                        <div
                                                            key={day}
                                                            className="grid"
                                                        >
                                                            <dt className="text-muted-foreground">
                                                                {t(day)}
                                                            </dt>
                                                            <dd className="tabular">
                                                                {formatMinutes(
                                                                    schedule
                                                                        .week[
                                                                        index
                                                                    ] ?? 0,
                                                                )}
                                                            </dd>
                                                        </div>
                                                    ),
                                                )}
                                                <div className="grid">
                                                    <dt className="text-muted-foreground">
                                                        {t(
                                                            'admin.schedules.week_total',
                                                        )}
                                                    </dt>
                                                    <dd className="tabular font-medium">
                                                        {formatMinutes(
                                                            schedule.weekly_minutes,
                                                        )}
                                                    </dd>
                                                </div>
                                            </dl>
                                            <ScheduleRegisterSummary
                                                schedule={schedule}
                                            />
                                        </li>
                                    ))}
                                </ol>
                            )}
                            <p className="text-xs text-muted-foreground">
                                {t('admin.schedules.rules')}
                            </p>
                        </CardContent>
                    </Card>

                    {employment ? (
                        <EmploymentCard
                            userId={user.id}
                            employment={employment}
                        />
                    ) : null}
                </div>

                {personalDataExports !== null ? (
                    <AdminPersonalDataExports
                        userId={user.id}
                        userName={user.name}
                        rows={personalDataExports}
                    />
                ) : null}
            </div>
        </>
    );
}
