import { Head, Link, router, setLayoutProps, usePage } from '@inertiajs/react';
import {
    ChartColumn,
    Clock,
    FolderKanban,
    Globe,
    History,
    Pencil,
    Power,
    PowerOff,
    Receipt,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { ClientDialog } from '@/components/clients/client-dialog';
import { ClientHourBankCard } from '@/components/clients/client-hour-bank-card';
import { ClientStatusBadge } from '@/components/clients/client-status-badge';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    HourBankStatusBadge,
    ProjectStatusBadge,
} from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import { ClientPortalSection } from '@/components/portal/access/client-portal-section';
import type { ClientPortalAccess } from '@/components/portal/access/types';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import {
    deactivate,
    index as clientsIndex,
    reactivate,
    show,
} from '@/routes/clients';
import { billing, client as clientReport } from '@/routes/reports';
import type { ClientShowProps } from '@/types';

const MONTHS = new Intl.DateTimeFormat('es-ES', {
    month: 'long',
    timeZone: 'UTC',
});

function monthName(date: string): string {
    const [year, month] = date.split('-').map(Number);

    return MONTHS.format(new Date(Date.UTC(year, month - 1, 1)));
}

function Stat({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof Clock;
    label: string;
    value: string;
}) {
    // Un solo <div> por grupo dt/dd dentro del <dl> (UX-01): el icono va al lado, oculto a los
    // lectores de pantalla, sin otro <div> entre el grupo y sus dt/dd.
    return (
        <div className="grid min-w-0 grid-cols-1 items-start gap-x-3 gap-y-0.5 rounded-md border bg-card p-3 sm:grid-cols-[auto_minmax(0,1fr)] sm:p-4">
            <Icon
                aria-hidden="true"
                className="row-span-2 mt-0.5 hidden size-5 shrink-0 text-muted-foreground sm:block"
                strokeWidth={1.5}
            />
            <dt className="min-w-0 text-sm text-muted-foreground sm:col-start-2">
                {label}
            </dt>
            <dd className="tabular min-w-0 text-xl sm:col-start-2 sm:text-2xl">
                {value}
            </dd>
        </div>
    );
}

function Detail({
    label,
    children,
    className,
}: {
    label: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('grid gap-0.5', className)}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm break-words">{children}</dd>
        </div>
    );
}

/**
 * Ficha de cliente (SPEC §6): datos, proyectos, bolsas activas con su consumo, horas del mes y del
 * año (totales de todas las personas), histórico de bolsas y el acceso al portal (Fase 5).
 */
export default function ClientShow({
    client,
    projects,
    hourBanks,
    hourBankHistory,
    hours,
    can,
    portal,
}: ClientShowProps & {
    /** Acceso al portal (Fase 5, D-063): null para quien no lo gestiona. */
    portal?: ClientPortalAccess | null;
}) {
    const page = usePage();
    const showFinancials = page.props.auth?.can?.viewFinancials === true;
    const thresholds = page.props.config?.hour_bank_thresholds;
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const activeProjects = projects.filter(
        (project) => project.status === 'active',
    ).length;

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.clients'), href: clientsIndex() },
            { title: client.name, href: show(client.id) },
        ],
    });

    const toggleActive = () => {
        router.post(
            client.is_active
                ? deactivate.url(client.id)
                : reactivate.url(client.id),
            {},
            {
                preserveScroll: true,
                onError: toastVisitErrors,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setConfirmOpen(false);
                },
            },
        );
    };

    return (
        <>
            <Head title={client.name} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-2">
                        <h1 className="text-2xl font-normal tracking-tight break-words">
                            {client.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <ClientStatusBadge isActive={client.is_active} />
                            {client.tax_id ? (
                                <span className="text-sm text-muted-foreground">
                                    {client.tax_id}
                                </span>
                            ) : null}
                        </div>
                    </div>
                    {can.update || can.viewReport || can.viewBilling ? (
                        <div className="flex flex-wrap gap-2">
                            {/* Informe del cliente (Fase 2, R2): admins, responsables y sus gestores. */}
                            {can.viewReport ? (
                                <Button variant="outline" asChild>
                                    <Link href={clientReport.url(client.id)}>
                                        <ChartColumn aria-hidden="true" />
                                        {t('reports_r2.link.client_report')}
                                    </Link>
                                </Button>
                            ) : null}
                            {/* Horas para facturar (Fase 2, R2): admins y quien tenga view-financials. */}
                            {can.viewBilling ? (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={billing.url({
                                            query: { cliente: [client.id] },
                                        })}
                                    >
                                        <Receipt aria-hidden="true" />
                                        {t('reports_r2.link.billing')}
                                    </Link>
                                </Button>
                            ) : null}
                            {can.update ? (
                                <>
                                    <ClientDialog
                                        client={client}
                                        showFinancials={showFinancials}
                                        trigger={
                                            <Button variant="outline">
                                                <Pencil aria-hidden="true" />
                                                {t('clients.edit')}
                                            </Button>
                                        }
                                    />
                                    {client.is_active ? (
                                        <ConfirmDialog
                                            open={confirmOpen}
                                            onOpenChange={setConfirmOpen}
                                            trigger={
                                                <Button variant="outline">
                                                    <PowerOff aria-hidden="true" />
                                                    {t('clients.deactivate')}
                                                </Button>
                                            }
                                            title={t(
                                                'clients.deactivate_title',
                                                {
                                                    name: client.name,
                                                },
                                            )}
                                            description={t(
                                                'clients.deactivate_description',
                                            )}
                                            confirmLabel={t(
                                                'clients.deactivate',
                                            )}
                                            processing={processing}
                                            onConfirm={toggleActive}
                                        />
                                    ) : (
                                        <Button
                                            onClick={toggleActive}
                                            disabled={processing}
                                        >
                                            {processing ? (
                                                <Spinner />
                                            ) : (
                                                <Power aria-hidden="true" />
                                            )}
                                            {t('clients.reactivate')}
                                        </Button>
                                    )}
                                </>
                            ) : null}
                        </div>
                    ) : null}
                </header>

                <section aria-labelledby="client-hours-heading">
                    <h2 id="client-hours-heading" className="sr-only">
                        {t('clients.show.summary')}
                    </h2>
                    <dl className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                        <Stat
                            icon={Clock}
                            label={t('clients.show.month_hours', {
                                month: monthName(hours.month_start),
                            })}
                            value={formatMinutes(hours.month_minutes)}
                        />
                        <Stat
                            icon={Clock}
                            label={t('clients.show.year_hours', {
                                year: hours.year,
                            })}
                            value={formatMinutes(hours.year_minutes)}
                        />
                        <Stat
                            icon={FolderKanban}
                            label={t('clients.show.active_projects')}
                            value={String(activeProjects)}
                        />
                        <Stat
                            icon={Wallet}
                            label={t('clients.show.open_banks')}
                            value={String(hourBanks.length)}
                        />
                    </dl>
                    <p className="mt-2 text-xs text-muted-foreground">
                        {t('clients.show.hours_note')}
                    </p>
                </section>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                <h2 className="text-base font-medium">
                                    {t('clients.show.details')}
                                </h2>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <Detail label={t('clients.form.contact_name')}>
                                    {client.contact_name ?? (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </Detail>
                                <Detail label={t('clients.form.contact_email')}>
                                    {client.contact_email ? (
                                        <a
                                            href={`mailto:${client.contact_email}`}
                                            className={cn(
                                                'rounded-sm text-primary-text hover:underline',
                                                FOCUS_RING,
                                            )}
                                        >
                                            {client.contact_email}
                                        </a>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </Detail>
                                <Detail label={t('clients.form.phone')}>
                                    {client.phone ? (
                                        <a
                                            href={`tel:${client.phone.replace(/\s+/g, '')}`}
                                            className={cn(
                                                'rounded-sm text-primary-text hover:underline',
                                                FOCUS_RING,
                                            )}
                                        >
                                            {client.phone}
                                        </a>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </Detail>
                                <Detail label={t('clients.form.tax_id')}>
                                    {client.tax_id ?? (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </Detail>
                                {showFinancials ? (
                                    <Detail
                                        label={t(
                                            'clients.form.default_hourly_rate',
                                        )}
                                    >
                                        {client.default_hourly_rate ? (
                                            <span className="tabular">
                                                {formatCurrency(
                                                    client.default_hourly_rate,
                                                )}
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                {t('clients.show.no_rate')}
                                            </span>
                                        )}
                                    </Detail>
                                ) : null}
                                <Detail
                                    label={t('clients.form.notes')}
                                    className="sm:col-span-2"
                                >
                                    {client.notes ? (
                                        <span className="whitespace-pre-line">
                                            {client.notes}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </Detail>
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                <h2 className="flex items-center gap-2 text-base font-medium">
                                    <Globe
                                        aria-hidden="true"
                                        className="size-4 text-muted-foreground"
                                    />
                                    {t('clients.show.portal')}
                                </h2>
                            </CardTitle>
                            <CardDescription>
                                {t('clients.show.portal_description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ClientPortalSection
                                clientId={client.id}
                                clientName={client.name}
                                portal={portal}
                            />
                        </CardContent>
                    </Card>
                </div>

                <section
                    className="grid gap-3"
                    aria-labelledby="client-projects-heading"
                >
                    <h2
                        id="client-projects-heading"
                        className="text-lg font-normal"
                    >
                        {t('clients.show.projects', { count: projects.length })}
                    </h2>
                    {projects.length === 0 ? (
                        <EmptyState
                            icon={FolderKanban}
                            title={t('clients.show.no_projects')}
                            description={t(
                                'clients.show.no_projects_description',
                            )}
                        />
                    ) : (
                        <div
                            className={cn(
                                'overflow-x-auto rounded-md border',
                                FOCUS_RING,
                            )}
                            role="region"
                            aria-label={t('clients.show.projects_table')}
                            tabIndex={0}
                        >
                            <table className="w-full min-w-[40rem] text-sm">
                                <caption className="sr-only">
                                    {t('clients.show.projects_table')}
                                </caption>
                                <thead>
                                    <tr className="border-b text-left">
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('clients.show.project')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('clients.show.billing')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('clients.show.owner')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('clients.status_filter')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {projects.map((project) => (
                                        <tr
                                            key={project.id}
                                            className="border-b last:border-b-0 even:bg-muted"
                                            data-test="client-project"
                                        >
                                            <td className="px-3 py-2">
                                                <span className="flex items-center gap-2">
                                                    <span
                                                        aria-hidden="true"
                                                        className="size-2.5 shrink-0 rounded-full"
                                                        style={{
                                                            backgroundColor:
                                                                project.color,
                                                        }}
                                                    />
                                                    <Link
                                                        href={urls.project(
                                                            project.id,
                                                        )}
                                                        className={cn(
                                                            'rounded-sm font-medium hover:underline',
                                                            FOCUS_RING,
                                                        )}
                                                    >
                                                        {project.name}
                                                    </Link>
                                                </span>
                                                <span className="block pl-4.5 text-xs text-muted-foreground">
                                                    {project.code}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2">
                                                {t(
                                                    `project.billing_type.${project.billing_type}`,
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                {project.owner?.name ?? '—'}
                                            </td>
                                            <td className="px-3 py-2">
                                                <ProjectStatusBadge
                                                    status={project.status}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section
                    className="grid gap-3"
                    aria-labelledby="client-banks-heading"
                >
                    <h2
                        id="client-banks-heading"
                        className="text-lg font-normal"
                    >
                        {t('clients.show.banks', { count: hourBanks.length })}
                    </h2>
                    {hourBanks.length === 0 ? (
                        <EmptyState
                            icon={Wallet}
                            title={t('clients.show.no_banks')}
                            description={t('clients.show.no_banks_description')}
                        />
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2">
                            {hourBanks.map((bank) => (
                                <ClientHourBankCard
                                    key={bank.id}
                                    bank={bank}
                                    thresholds={thresholds}
                                />
                            ))}
                        </div>
                    )}
                </section>

                <section
                    className="grid gap-3"
                    aria-labelledby="client-history-heading"
                >
                    <h2
                        id="client-history-heading"
                        className="flex items-center gap-2 text-lg font-normal"
                    >
                        <History
                            aria-hidden="true"
                            className="size-5 text-muted-foreground"
                            strokeWidth={1.5}
                        />
                        {t('clients.show.history')}
                    </h2>
                    {hourBankHistory.length === 0 ? (
                        <EmptyState
                            icon={History}
                            title={t('clients.show.no_history')}
                        />
                    ) : (
                        <div
                            className={cn(
                                'overflow-x-auto rounded-md border',
                                FOCUS_RING,
                            )}
                            role="region"
                            aria-label={t('clients.show.history_table')}
                            tabIndex={0}
                        >
                            <table className="w-full min-w-[44rem] text-sm">
                                <caption className="sr-only">
                                    {t('clients.show.history_table')}
                                </caption>
                                <thead>
                                    <tr className="border-b text-left">
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('clients.show.bank')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('clients.show.period')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 text-right font-medium"
                                        >
                                            {t('clients.show.consumed')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 text-right font-medium"
                                        >
                                            {t('clients.show.overage')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 font-medium"
                                        >
                                            {t('clients.status_filter')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {hourBankHistory.map((bank) => (
                                        <tr
                                            key={bank.id}
                                            className="border-b last:border-b-0 even:bg-muted"
                                            data-test="client-bank-history"
                                        >
                                            <td className="px-3 py-2">
                                                {bank.project ? (
                                                    <Link
                                                        href={urls.hourBank(
                                                            bank.project.id,
                                                            bank.id,
                                                        )}
                                                        className={cn(
                                                            'rounded-sm font-medium hover:underline',
                                                            FOCUS_RING,
                                                        )}
                                                    >
                                                        {bank.name}
                                                    </Link>
                                                ) : (
                                                    bank.name
                                                )}
                                                {bank.project ? (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {bank.project.code} ·{' '}
                                                        {bank.project.name}
                                                    </span>
                                                ) : null}
                                            </td>
                                            <td className="tabular px-3 py-2 whitespace-nowrap">
                                                {formatDate(bank.start_date)}
                                                {bank.end_date
                                                    ? ` – ${formatDate(bank.end_date)}`
                                                    : ''}
                                            </td>
                                            <td className="tabular px-3 py-2 text-right whitespace-nowrap">
                                                {formatMinutes(
                                                    bank.consumed_minutes,
                                                )}{' '}
                                                /{' '}
                                                {formatMinutes(
                                                    bank.total_minutes,
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    'tabular px-3 py-2 text-right whitespace-nowrap',
                                                    bank.overage_minutes > 0 &&
                                                        'font-medium text-danger',
                                                )}
                                            >
                                                {bank.overage_minutes > 0
                                                    ? `+${formatMinutes(bank.overage_minutes)}`
                                                    : '0:00'}
                                            </td>
                                            <td className="px-3 py-2">
                                                <HourBankStatusBadge
                                                    status={bank.status}
                                                />
                                                {bank.status === 'closed' &&
                                                bank.closed_remaining_minutes ? (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {t(
                                                            'clients.show.closed_remaining',
                                                            {
                                                                minutes:
                                                                    formatMinutes(
                                                                        bank.closed_remaining_minutes,
                                                                    ),
                                                            },
                                                        )}
                                                    </span>
                                                ) : null}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}
