import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { ProjectStatusView } from '@/components/weeklies/insights/project-status-view';
import { weekliesTabs } from '@/components/weeklies/insights/weeklies-tabs';
import { WeeklyTabs } from '@/components/weeklies/weekly-tabs';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { index as weekliesIndex, projectStatus } from '@/routes/weeklies';
import type { ProjectStatusPageProps } from '@/types/weekly-insights';

/**
 * /weeklies/estado-proyectos (D-148, F-064 y F-119 a F-121): la tercera pestaña de las weeklies, con
 * la cartera de proyectos abiertos calculada con los datos reales de Audax (sin capturas ni OCR).
 */
export default function WeekliesProjectStatus({
    clients,
    reference_date,
}: ProjectStatusPageProps) {
    return (
        <>
            <Head title={t('weeklies.project_status.title')} />
            <div className="mx-auto flex w-full max-w-6xl min-w-0 flex-col gap-6 p-4 md:p-6">
                <Heading
                    as="h1"
                    title={t('weeklies.heading')}
                    description={t('weeklies.description')}
                />
                <WeeklyTabs
                    label={t('weeklies.tabs.label')}
                    tabs={weekliesTabs(true)}
                    current="estado-proyectos"
                />
                <section
                    aria-labelledby="project-status-heading"
                    className="grid min-w-0 gap-4"
                >
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <div className="space-y-1">
                            <h2 id="project-status-heading" className="text-lg">
                                {t('weeklies.project_status.heading')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('weeklies.project_status.description')}
                            </p>
                        </div>
                        <p
                            className="text-xs text-muted-foreground"
                            data-test="project-status-reference"
                        >
                            {t('weeklies.project_status.reference', {
                                date: formatDate(reference_date),
                            })}
                        </p>
                    </div>
                    <ProjectStatusView clients={clients} />
                </section>
            </div>
        </>
    );
}

WeekliesProjectStatus.layout = {
    breadcrumbs: [
        { title: t('weeklies.title'), href: weekliesIndex() },
        { title: t('weeklies.project_status.title'), href: projectStatus() },
    ],
};
