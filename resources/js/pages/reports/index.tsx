import { Head, Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ArrowRight,
    Building2,
    CalendarClock,
    Receipt,
    Table2,
    UserRound,
    Scale,
} from 'lucide-react';
import { PageHeader } from '@/components/projects-list/page-header';
import { PageSection } from '@/components/projects-list/page-section';
import { R1LinkList } from '@/components/reports/r1-link-list';
import type { ReportIndexProps } from '@/components/reports/r1-types';
import { reportUrls } from '@/components/reports/r1-urls';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index } from '@/routes/reports';
import { index as schedulesIndex } from '@/routes/reports/schedules';

function DashboardCard({
    icon: Icon,
    title,
    description,
    href,
    test,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
    href: string;
    test: string;
}) {
    return (
        <li>
            <Link
                href={href}
                data-test={test}
                className={cn(
                    'group flex h-full items-start gap-3 rounded-md border bg-card p-4 hover:bg-muted',
                    FOCUS_RING,
                )}
            >
                <span className="flex size-9 shrink-0 items-center justify-center rounded-md border bg-muted">
                    <Icon
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                        strokeWidth={1.5}
                    />
                </span>
                <span className="grid min-w-0 flex-1 gap-0.5">
                    <span className="text-base">{title}</span>
                    <span className="text-sm text-muted-foreground">
                        {description}
                    </span>
                </span>
                <ArrowRight
                    aria-hidden="true"
                    className="mt-1 size-4 shrink-0 text-muted-foreground group-hover:text-foreground"
                />
            </Link>
        </li>
    );
}

/**
 * Índice de informes (SPEC §10, D-044): los dashboards a los que tiene acceso quien mira. Siempre
 * su informe personal y el detallado; dirección y departamentos para admins y responsables;
 * clientes y proyectos para admins, responsables y gestores; y las personas de su equipo.
 */
export default function ReportsIndex({
    me,
    direction,
    billing,
    departments,
    clients,
    projects,
    people,
}: ReportIndexProps) {
    // Vendido frente a real (Fase 12, D-390), con el módulo Facturación.
    const soldVsActual = usePage().props.auth?.can?.viewSoldVsActual === true;
    const team = people.filter((person) => person.id !== me.id);

    return (
        <>
            <Head title={t('reports.title')} />

            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                <PageHeader
                    title={t('reports_r1.index.title')}
                    description={t('reports_r1.index.description')}
                />

                <PageSection title={t('reports_r1.index.dashboards')}>
                    <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <DashboardCard
                            icon={UserRound}
                            title={t('reports_r1.index.mine')}
                            description={t('reports_r1.index.mine_description')}
                            href={reportUrls.person(me.id)}
                            test="r1-index-mine"
                        />
                        {direction ? (
                            <DashboardCard
                                icon={Building2}
                                title={t('reports_r1.direction.title')}
                                description={t(
                                    'reports_r1.index.direction_description',
                                )}
                                href={reportUrls.direction()}
                                test="r1-index-direction"
                            />
                        ) : null}
                        <DashboardCard
                            icon={Table2}
                            title={t('reports_r1.index.detail')}
                            description={t(
                                'reports_r1.index.detail_description',
                            )}
                            href={reportUrls.detail()}
                            test="r1-index-detail"
                        />
                        {billing ? (
                            <DashboardCard
                                icon={Receipt}
                                title={t('reports_r1.index.billing')}
                                description={t(
                                    'reports_r1.index.billing_description',
                                )}
                                href="/informes/facturacion"
                                test="r1-index-billing"
                            />
                        ) : null}
                        {soldVsActual ? (
                            <DashboardCard
                                icon={Scale}
                                title={t('billing.report.title')}
                                description={t(
                                    'billing.report.index_description',
                                )}
                                href="/informes/vendido-frente-a-real"
                                test="r1-index-sold-vs-actual"
                            />
                        ) : null}
                        <DashboardCard
                            icon={CalendarClock}
                            title={t('deliveries.list.title')}
                            description={t('deliveries.index.description')}
                            href={schedulesIndex().url}
                            test="r1-index-schedules"
                        />
                    </ul>
                </PageSection>

                {departments.length > 0 ? (
                    <PageSection
                        title={t('reports_r1.index.departments')}
                        description={t(
                            'reports_r1.index.departments_description',
                        )}
                    >
                        <R1LinkList
                            label={t('reports_r1.index.departments')}
                            emptyLabel={t('reports_r1.index.no_departments')}
                            items={departments.map((department) => ({
                                id: department.id,
                                label: department.name,
                                color: department.color,
                                href: reportUrls.department(department.id),
                            }))}
                        />
                    </PageSection>
                ) : null}

                {clients !== null ? (
                    <PageSection
                        title={t('reports_r1.index.clients')}
                        description={t('reports_r1.index.clients_description')}
                    >
                        <R1LinkList
                            label={t('reports_r1.index.clients')}
                            emptyLabel={t('reports_r1.index.no_clients')}
                            items={clients.map((client) => ({
                                id: client.id,
                                label: client.name,
                                muted: client.is_active
                                    ? null
                                    : t('reports_r1.index.inactive_client'),
                                href: reportUrls.client(client.id),
                            }))}
                        />
                    </PageSection>
                ) : null}

                {projects !== null ? (
                    <PageSection
                        title={t('reports_r1.index.projects')}
                        description={t('reports_r1.index.projects_description')}
                    >
                        <R1LinkList
                            label={t('reports_r1.index.projects')}
                            emptyLabel={t('reports_r1.index.no_projects')}
                            items={projects.map((project) => ({
                                id: project.id,
                                label: `${project.code} · ${project.name}`,
                                meta:
                                    project.client ??
                                    t('reports_r1.index.internal'),
                                color: project.color,
                                muted: project.archived
                                    ? t('reports_r1.index.archived')
                                    : null,
                                href: reportUrls.project(project.id),
                            }))}
                        />
                    </PageSection>
                ) : null}

                {team.length > 0 ? (
                    <PageSection
                        title={t('reports_r1.index.people')}
                        description={t('reports_r1.index.people_description')}
                    >
                        <R1LinkList
                            label={t('reports_r1.index.people')}
                            emptyLabel={t('reports_r1.index.no_people')}
                            items={team.map((person) => ({
                                id: person.id,
                                label: person.name,
                                meta: person.department,
                                muted: person.is_active
                                    ? null
                                    : t('reports_r1.inactive'),
                                href: reportUrls.person(person.id),
                            }))}
                        />
                    </PageSection>
                ) : null}
            </div>
        </>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [{ title: t('nav.reports'), href: index() }],
};
