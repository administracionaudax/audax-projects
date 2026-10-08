import { Head, setLayoutProps } from '@inertiajs/react';
import { BillingPanel } from '@/components/billing/billing-panel';
import { ProjectShell } from '@/components/projects/project-shell';
import { ReportFilterBar } from '@/components/reports/report-filter-bar';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import type { BillingPanelData, Project, ReportFiltersProps } from '@/types';

type Props = {
    project: Project;
    canManage: boolean;
    filters: ReportFiltersProps;
    panel: BillingPanelData;
};

/**
 * Pestaña Facturación del proyecto (Fase 12, F1; D-392): su «Vendido frente a real» (bolsas, precio
 * cerrado, fee u horas) con el periodo de la URL y, con view-financials, sus facturas de Holded.
 */
export default function ProjectBilling({
    project,
    canManage,
    filters,
    panel,
}: Props) {
    const url = `/proyectos/${project.id}/facturacion`;

    setLayoutProps({
        breadcrumbs: [
            { title: t('nav.projects'), href: urls.projects() },
            { title: project.name, href: url },
        ],
    });

    return (
        <>
            <Head title={`${project.code} · ${t('project_tabs.billing')}`} />
            <ProjectShell
                project={project}
                tab="facturacion"
                canManage={canManage}
            >
                <div className="grid gap-6">
                    <ReportFilterBar
                        filters={filters}
                        show={[]}
                        url={url}
                        compare={false}
                    />
                    <p className="text-sm text-muted-foreground">
                        {t(`billing.project.lead.${project.billing_type}`)}
                    </p>
                    <BillingPanel panel={panel} invoicesHref={undefined} />
                </div>
            </ProjectShell>
        </>
    );
}
