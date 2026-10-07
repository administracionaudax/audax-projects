import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ChartGantt,
    FolderKanban,
    FolderTree,
    List,
    Plus,
    SearchX,
} from 'lucide-react';
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
import { FilterSelect } from '@/components/projects-list/filter-select';
import { ProjectsTable } from '@/components/projects-list/projects-table';
import {
    ProjectsTree,
    treeExpandedByDefault,
} from '@/components/projects-list/projects-tree';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useAbilities } from '@/hooks/use-auth';
import { useCollapsedGroups } from '@/hooks/use-collapsed-groups';
import { t } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import { create, index } from '@/routes/projects';
import type {
    ProjectListFilters,
    ProjectListSort,
    ProjectListView,
    ProjectsIndexProps,
} from '@/types';

const SORTS: ProjectListSort[] = ['name', 'recent', 'due'];

const SORT_PARAM: Record<ProjectListSort, string> = {
    name: 'nombre',
    recent: 'recientes',
    due: 'fin',
};

/**
 * Listado de proyectos (SPEC §6, D-021): todos los internos ven todos los proyectos; crean los
 * admins y los responsables (D-022). Filtros en la URL para poder compartirla. Por defecto,
 * jerarquizado por cliente con sus bolsas (D-322); «Lista» es la vista plana, con orden y páginas.
 */
export default function ProjectsIndex({
    view,
    sort,
    projects,
    groups,
    company,
    filters,
    options,
}: ProjectsIndexProps) {
    const can = useAbilities();
    // Sin sesión (tests), los plegados no se recuerdan.
    const userId = usePage().props.auth?.user?.id ?? null;
    const thresholds = useHourBankThresholds();

    const visit = useCallback(
        (
            next: ProjectListFilters,
            nextView: ProjectListView,
            nextSort: ProjectListSort,
        ) => {
            router.get(
                index.url({
                    query: {
                        ...projectFiltersQuery(next),
                        ...(nextView === 'list' ? { vista: 'lista' } : {}),
                        ...(nextView === 'list' && nextSort !== 'name'
                            ? { orden: SORT_PARAM[nextSort] }
                            : {}),
                    },
                }),
                undefined,
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
        },
        [],
    );

    const applyFilters = useCallback(
        (next: ProjectListFilters) => visit(next, view, sort),
        [visit, view, sort],
    );

    const filtered = hasActiveProjectFilters(filters);
    const empty =
        view === 'list'
            ? (projects?.meta.total ?? 0) === 0
            : (groups ?? []).length === 0;
    // Con una búsqueda o un cliente, lo plegado no se recuerda: se ve todo lo encontrado.
    const searching = filters.buscar.trim() !== '' || filters.cliente !== null;
    const folds = useCollapsedGroups(
        searching || userId === null
            ? null
            : `audax.projects.tree.${userId}`,
    );

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

                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                value={view}
                                onValueChange={(next) => {
                                    if (next === 'clients' || next === 'list') {
                                        visit(filters, next, sort);
                                    }
                                }}
                                aria-label={t('projects.view.label')}
                                data-test="projects-view"
                            >
                                <ToggleGroupItem
                                    value="clients"
                                    className="gap-1.5 px-3"
                                    data-test="projects-view-clients"
                                >
                                    <FolderTree aria-hidden="true" />
                                    {t('projects.view.clients')}
                                </ToggleGroupItem>
                                <ToggleGroupItem
                                    value="list"
                                    className="gap-1.5 px-3"
                                    data-test="projects-view-list"
                                >
                                    <List aria-hidden="true" />
                                    {t('projects.view.list')}
                                </ToggleGroupItem>
                            </ToggleGroup>
                            {view === 'list' ? (
                                <FilterSelect
                                    id="projects-sort"
                                    label={t('projects.sort.label')}
                                    value={sort}
                                    options={SORTS.map((value) => ({
                                        value,
                                        label: t(`projects.sort.${value}`),
                                    }))}
                                    onChange={(value) =>
                                        visit(
                                            filters,
                                            view,
                                            (value ??
                                                'name') as ProjectListSort,
                                        )
                                    }
                                    className="w-48"
                                />
                            ) : null}
                        </div>

                        {empty ? (
                            <EmptyState
                                icon={SearchX}
                                title={t('projects.index.no_results')}
                                description={t(
                                    'projects.index.no_results_description',
                                )}
                            />
                        ) : view === 'clients' && groups ? (
                            <ProjectsTree
                                groups={groups}
                                company={company}
                                folds={folds}
                                expandedByDefault={treeExpandedByDefault(
                                    groups,
                                    searching,
                                )}
                                thresholds={thresholds}
                            />
                        ) : projects ? (
                            <ProjectsTable
                                projects={projects.data}
                                thresholds={thresholds}
                            />
                        ) : null}

                        {view === 'list' && projects ? (
                            <ListPagination
                                page={projects}
                                label={t('projects.pagination.label')}
                            />
                        ) : null}
                    </>
                )}
            </div>
        </>
    );
}

ProjectsIndex.layout = {
    breadcrumbs: [{ title: t('nav.projects'), href: index() }],
};
