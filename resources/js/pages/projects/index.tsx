import { Head, Link, router } from '@inertiajs/react';
import { ChartGantt, FolderKanban, Plus, SearchX } from 'lucide-react';
import { useCallback } from 'react';
import { EmptyState } from '@/components/empty-state';
import { useHourBankThresholds } from '@/components/hour-banks/hour-bank-actions';
import { ListPagination } from '@/components/projects-list/list-pagination';
import {
    hasActiveProjectFilters,
    ProjectFiltersBar,
    projectFiltersQuery,
} from '@/components/projects-list/project-filters';
import { PageHeader } from '@/components/projects-list/page-header';
import { ProjectsTable } from '@/components/projects-list/projects-table';
import { Button } from '@/components/ui/button';
import { useAbilities } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { create, index } from '@/routes/projects';
import type { ProjectListFilters, ProjectsIndexProps } from '@/types';

/**
 * Listado de proyectos (SPEC §6, D-021): todos los internos ven todos los proyectos; crean los
 * admins y los responsables (D-022). Filtros en la URL para poder compartirla.
 */
export default function ProjectsIndex({
    projects,
    filters,
    options,
}: ProjectsIndexProps) {
    const can = useAbilities();
    const thresholds = useHourBankThresholds();

    const applyFilters = useCallback((next: ProjectListFilters) => {
        router.get(index.url({ query: projectFiltersQuery(next) }), undefined, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, []);

    const filtered = hasActiveProjectFilters(filters);
    const empty = projects.meta.total === 0;

    return (
        <>
            <Head title={t('projects.index.title')} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('projects.index.heading')}
                    description={t('projects.index.description')}
                    actions={
                        <>
                            <Button asChild variant="outline">
                                <Link href={urls.gantt()}>
                                    <ChartGantt aria-hidden="true" />
                                    {t('gantt.index.open')}
                                </Link>
                            </Button>
                            {can.createProjects ? (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus aria-hidden="true" />
                                        {t('projects.index.create')}
                                    </Link>
                                </Button>
                            ) : null}
                        </>
                    }
                />

                {empty && !filtered ? (
                    <EmptyState
                        icon={FolderKanban}
                        title={t('projects.index.empty_title')}
                        description={t(
                            can.createProjects
                                ? 'projects.index.empty_description_can_create'
                                : 'projects.index.empty_description',
                        )}
                    />
                ) : (
                    <>
                        <ProjectFiltersBar
                            filters={filters}
                            options={options}
                            onChange={applyFilters}
                        />

                        {empty ? (
                            <EmptyState
                                icon={SearchX}
                                title={t('projects.index.no_results')}
                                description={t(
                                    'projects.index.no_results_description',
                                )}
                            />
                        ) : (
                            <ProjectsTable
                                projects={projects.data}
                                thresholds={thresholds}
                            />
                        )}

                        <ListPagination
                            page={projects}
                            label={t('projects.pagination.label')}
                        />
                    </>
                )}
            </div>
        </>
    );
}

ProjectsIndex.layout = {
    breadcrumbs: [{ title: t('nav.projects'), href: index() }],
};
