import { Head, Link, setLayoutProps, usePage } from '@inertiajs/react';
import { ArrowLeft, ChartColumn, Info, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { HourBankMeter } from '@/components/charts/hour-bank-meter';
import { HourBankStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import {
    HourBankActions,
    useHourBankThresholds,
} from '@/components/hour-banks/hour-bank-actions';
import { HourBankBreakdownTable } from '@/components/hour-banks/hour-bank-breakdown';
import { bankDates } from '@/components/hour-banks/hour-bank-card';
import { HourBankEntriesTable } from '@/components/hour-banks/hour-bank-entries-table';
import { HourBankTasksTable } from '@/components/hour-banks/hour-bank-tasks-table';
import { HourBankWeeklyChart } from '@/components/hour-banks/hour-bank-weekly-chart';
import { ProjectShell } from '@/components/projects/project-shell';
import { ListPagination } from '@/components/projects-list/list-pagination';
import { PageSection } from '@/components/projects-list/page-section';
import { R2BankPdfMenu } from '@/components/reports/r2-bank-pdf-menu';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useAbilities } from '@/hooks/use-auth';
import { FOCUS_RING } from '@/lib/focus-ring';
import { formatCurrency, formatDate, formatMinutes } from '@/lib/format';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { cn } from '@/lib/utils';
import { index as projectsIndex, show as projectShow } from '@/routes/projects';
import { index as banksIndex, show } from '@/routes/projects/hour-banks';
import type { HourBankShowProps } from '@/types';

/**
 * Detalle de una bolsa (SPEC §8, UI): cifras y barra, consumo por semana (dentro de la bolsa
 * frente a exceso), reparto por persona (solo gestores, responsables y admins) y por tipo de
 * tarea, tareas con lo comprometido y entradas (cada cual ve las que le tocan, D-021).
 */
export default function HourBankShow({
    project,
    canManage,
    bank,
    weekly,
    byPerson,
    byType,
    entries,
    tasks,
    departments,
    overageDefault,
}: HourBankShowProps) {
    const can = useAbilities();
    const errors = usePage().props.errors as Record<string, string> | undefined;
    const thresholds = useHourBankThresholds();

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.projects'), href: projectsIndex() },
            { title: project.name, href: projectShow(project.id) },
            {
                title: t('project_tabs.hour_banks'),
                href: banksIndex(project.id),
            },
            {
                title: bank.name,
                href: show({ project: project.id, hourBank: bank.id }),
            },
        ],
    });

    return (
        <>
            <Head
                title={t('hour_banks.detail.title', {
                    bank: bank.name,
                    project: project.name,
                })}
            />

            <ProjectShell project={project} tab="bolsas" canManage={canManage}>
                <div className="grid gap-8">
                    <Link
                        href={urls.project(project.id, 'bolsas')}
                        className={cn(
                            'inline-flex w-fit items-center gap-1.5 rounded-[3px] text-sm text-primary-text hover:underline',
                            FOCUS_RING,
                        )}
                    >
                        <ArrowLeft aria-hidden="true" className="size-4" />
                        {t('hour_banks.detail.back')}
                    </Link>

                    {errors?.hour_bank ? (
                        <Alert variant="destructive" role="alert">
                            <TriangleAlert aria-hidden="true" />
                            <AlertDescription>
                                {errors.hour_bank}
                            </AlertDescription>
                        </Alert>
                    ) : null}

                    <section
                        aria-labelledby="bank-heading"
                        className="grid gap-5 rounded-md border bg-card p-5"
                    >
                        <header className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0 space-y-1">
                                <h2
                                    id="bank-heading"
                                    className="text-xl font-normal"
                                >
                                    {bank.name}
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {bankDates(bank)}
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <HourBankStatusBadge status={bank.status} />
                                {/*
                                 * PDF de consumo para el cliente (Fase 2, R2; D-045): lleva las
                                 * entradas con la persona, así que solo con el detalle por persona.
                                 */}
                                {byPerson !== null ? (
                                    <R2BankPdfMenu
                                        projectId={project.id}
                                        bankId={bank.id}
                                        bankName={bank.name}
                                        size="sm"
                                    />
                                ) : null}
                            </div>
                        </header>

                        <HourBankMeter
                            name={bank.name}
                            consumed={bank.consumed_minutes}
                            total={bank.total_minutes}
                            overage={bank.overage_minutes}
                            committed={bank.committed_minutes}
                            thresholds={thresholds}
                        />

                        <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                            <Detail label={t('hour_banks.detail.department')}>
                                {bank.department?.name ??
                                    t('hour_banks.card.any_department')}
                            </Detail>
                            <Detail label={t('hour_banks.detail.policy')}>
                                {t(`hour_bank.policy.${bank.overage_policy}`)}
                                <span className="block text-xs text-muted-foreground">
                                    {t(
                                        `hour_banks.card.policy_${bank.effective_overage_policy}`,
                                    )}
                                </span>
                            </Detail>
                            <Detail
                                label={t('hour_banks.detail.invoice_reference')}
                            >
                                {bank.invoice_reference ??
                                    t('hour_banks.detail.none')}
                            </Detail>
                            {can.viewFinancials ? (
                                <>
                                    <Detail
                                        label={t('hour_banks.form.hourly_rate')}
                                    >
                                        {bank.hourly_rate
                                            ? formatCurrency(bank.hourly_rate)
                                            : t('hour_banks.detail.inherited')}
                                    </Detail>
                                    <Detail label={t('hour_banks.form.price')}>
                                        {bank.price_amount
                                            ? formatCurrency(bank.price_amount)
                                            : t('hour_banks.detail.none')}
                                    </Detail>
                                </>
                            ) : null}
                            {bank.status === 'closed' ? (
                                <Detail label={t('hour_banks.detail.closed')}>
                                    {t('hour_banks.detail.closed_by', {
                                        date: formatDate(bank.closed_at),
                                        name:
                                            bank.closed_by?.name ??
                                            t('projects.activity.system'),
                                    })}
                                    <span className="block text-xs text-muted-foreground">
                                        {t(
                                            'hour_banks.detail.closed_remaining',
                                            {
                                                remaining: formatMinutes(
                                                    bank.closed_remaining_minutes ??
                                                        0,
                                                ),
                                            },
                                        )}
                                    </span>
                                </Detail>
                            ) : null}
                            {bank.renewed_from ? (
                                <Detail
                                    label={t('hour_banks.detail.renewed_from')}
                                >
                                    <BankLink
                                        projectId={project.id}
                                        bank={bank.renewed_from}
                                    />
                                </Detail>
                            ) : null}
                            {bank.renewal ? (
                                <Detail label={t('hour_banks.detail.renewal')}>
                                    <BankLink
                                        projectId={project.id}
                                        bank={bank.renewal}
                                    />
                                </Detail>
                            ) : null}
                        </dl>

                        {bank.notes ? (
                            <p className="rounded-[3px] bg-muted px-3 py-2 text-sm whitespace-pre-line">
                                {bank.notes}
                            </p>
                        ) : null}

                        <HourBankActions
                            projectId={project.id}
                            bank={bank}
                            departments={departments}
                            overageDefault={overageDefault}
                        />
                    </section>

                    {weekly.length === 0 ? (
                        <PageSection title={t('hour_banks.detail.weekly')}>
                            <EmptyState
                                icon={ChartColumn}
                                title={t('hour_banks.detail.no_time')}
                                description={t(
                                    'hour_banks.detail.no_time_description',
                                )}
                            />
                        </PageSection>
                    ) : (
                        // La gráfica ya lleva su título (figcaption): sin h2 repetido.
                        <section aria-label={t('hour_banks.detail.weekly')}>
                            <HourBankWeeklyChart weeks={weekly} />
                        </section>
                    )}

                    <div className="grid gap-8 lg:grid-cols-2">
                        {byPerson !== null ? (
                            <PageSection
                                title={t('hour_banks.detail.by_person')}
                            >
                                <HourBankBreakdownTable
                                    caption={t('hour_banks.detail.by_person')}
                                    firstColumn={t('hour_banks.detail.person')}
                                    rows={byPerson.map((row) => ({
                                        id: String(row.user.id),
                                        label: row.user.name,
                                        minutes: row.minutes,
                                        overage_minutes: row.overage_minutes,
                                    }))}
                                />
                            </PageSection>
                        ) : null}
                        <PageSection title={t('hour_banks.detail.by_type')}>
                            <HourBankBreakdownTable
                                caption={t('hour_banks.detail.by_type')}
                                firstColumn={t('hour_banks.detail.task_type')}
                                rows={byType.map((row) => ({
                                    id: String(row.type?.id ?? 'none'),
                                    label: row.type ? (
                                        <span className="inline-flex items-center gap-2">
                                            <span
                                                aria-hidden="true"
                                                className="size-2.5 shrink-0 rounded-full"
                                                style={{
                                                    backgroundColor:
                                                        row.type.color,
                                                }}
                                            />
                                            {row.type.name}
                                        </span>
                                    ) : (
                                        t('hour_banks.detail.no_type')
                                    ),
                                    minutes: row.minutes,
                                    overage_minutes: row.overage_minutes,
                                }))}
                            />
                        </PageSection>
                    </div>

                    <PageSection
                        title={t('hour_banks.detail.tasks')}
                        description={t('hour_banks.detail.tasks_description')}
                    >
                        <HourBankTasksTable
                            projectId={project.id}
                            tasks={tasks}
                        />
                    </PageSection>

                    <PageSection title={t('hour_banks.detail.entries')}>
                        {byPerson === null ? (
                            <p className="flex items-start gap-2 text-sm text-muted-foreground">
                                <Info
                                    aria-hidden="true"
                                    className="mt-0.5 size-4 shrink-0"
                                />
                                {t('hour_banks.detail.own_entries_only')}
                            </p>
                        ) : null}
                        <HourBankEntriesTable
                            entries={entries.data}
                            ownOnly={byPerson === null}
                        />
                        <ListPagination
                            page={entries}
                            label={t('hour_banks.detail.entries_pages')}
                        />
                    </PageSection>
                </div>
            </ProjectShell>
        </>
    );
}

HourBankShow.layout = {
    breadcrumbs: [{ title: t('nav.projects'), href: projectsIndex() }],
};

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

function BankLink({
    projectId,
    bank,
}: {
    projectId: number;
    bank: {
        id: number;
        name: string;
        status: HourBankShowProps['bank']['status'];
    };
}) {
    return (
        <span className="flex flex-wrap items-center gap-2">
            <Link
                href={urls.hourBank(projectId, bank.id)}
                className={cn(
                    'rounded-[3px] text-primary-text hover:underline',
                    FOCUS_RING,
                )}
            >
                {bank.name}
            </Link>
            <HourBankStatusBadge status={bank.status} />
        </span>
    );
}
