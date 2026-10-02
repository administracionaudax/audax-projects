import { Head } from '@inertiajs/react';
import { FolderOpen } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { KeywordText } from '@/components/keyword-text';
import { PortalProjectCard } from '@/components/portal/projects/portal-projects-card';
import type { PortalProjectsIndexProps } from '@/components/portal/projects/types';
import { t } from '@/lib/i18n';
import { home } from '@/routes/portal';
import { index } from '@/routes/portal/projects';

/**
 * Proyectos del cliente abiertos al portal (/portal/proyectos, D-064): cada uno con su estado, sus
 * fechas, su avance y los enlaces a lo que el equipo ha abierto (tareas y Gantt).
 */
export default function PortalProjectsIndex({
    projects,
}: PortalProjectsIndexProps) {
    return (
        <>
            <Head title={t('portal_projects.index.title')} />

            <div className="grid gap-8">
                <header className="grid gap-1">
                    <h1 className="text-3xl font-normal tracking-tight">
                        <KeywordText
                            text={t('portal_projects.index.heading')}
                        />
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('portal_projects.index.description')}
                    </p>
                </header>

                {projects.length === 0 ? (
                    <EmptyState
                        icon={FolderOpen}
                        title={t('portal_projects.index.empty')}
                        description={t(
                            'portal_projects.index.empty_description',
                        )}
                    />
                ) : (
                    <ul className="grid gap-4 md:grid-cols-2">
                        {projects.map((project) => (
                            <li key={project.id} className="flex">
                                <PortalProjectCard project={project} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

PortalProjectsIndex.layout = {
    breadcrumbs: [
        { title: t('portal_nav.home'), href: home() },
        { title: t('portal_nav.projects'), href: index() },
    ],
};
