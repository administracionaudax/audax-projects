import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarRange,
    ChartGantt,
    FolderOpen,
    ListChecks,
} from 'lucide-react';
import { ProjectStatusBadge } from '@/components/domain/badges';
import { EmptyState } from '@/components/empty-state';
import {
    portalProjectHref,
    usePortalShell,
} from '@/components/portal/projects/portal-nav';
import { projectDates } from '@/components/portal/projects/portal-project-header';
import type { PortalProjectListItem } from '@/components/portal/projects/types';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { gantt, index, show } from '@/routes/portal/projects';

const LINK_CLASS = cn(
    'inline-flex items-center gap-1.5 rounded-sm text-sm font-medium text-primary-text hover:underline',
    FOCUS_RING,
);

/**
 * Tarjeta de un proyecto abierto al portal (/portal/proyectos): estado, fechas, avance de sus tareas
 * (si la vista está abierta) y enlaces a las tareas y al Gantt.
 */
export function PortalProjectCard({
    project,
}: {
    project: PortalProjectListItem;
}) {
    const dates = projectDates(project);
    const progress = project.progress;
    const percent =
        progress && progress.total > 0
            ? Math.round((progress.done / progress.total) * 100)
            : 0;

    return (
        <Card className="flex-1 gap-4" data-test="portal-project-card">
            <CardHeader className="gap-2">
                <p className="text-sm text-muted-foreground">{project.code}</p>
                <CardTitle className="text-lg font-normal">
                    <h2>
                        <Link
                            href={portalProjectHref(project)}
                            className={cn(
                                'rounded-sm hover:underline',
                                FOCUS_RING,
                            )}
                        >
                            {project.name}
                        </Link>
                    </h2>
                </CardTitle>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <ProjectStatusBadge status={project.status} />
                    {dates ? (
                        <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                            <CalendarRange
                                aria-hidden="true"
                                className="size-4"
                                strokeWidth={1.5}
                            />
                            {dates}
                        </span>
                    ) : null}
                </div>
            </CardHeader>

            {progress ? (
                <CardContent className="grid gap-2">
                    <p className="text-sm">
                        {t('portal_projects.index.progress', {
                            done: progress.done,
                            total: progress.total,
                        })}
                    </p>
                    <Progress
                        value={percent}
                        aria-label={t('portal_projects.index.progress_label', {
                            project: project.name,
                        })}
                    />
                </CardContent>
            ) : null}

            <CardFooter className="mt-auto flex flex-wrap gap-x-5 gap-y-2">
                {project.view ? (
                    <Link href={show.url(project.id)} className={LINK_CLASS}>
                        <ListChecks aria-hidden="true" className="size-4" />
                        {t('portal_projects.index.open_tasks')}
                    </Link>
                ) : null}
                {project.gantt ? (
                    <Link href={gantt.url(project.id)} className={LINK_CLASS}>
                        <ChartGantt aria-hidden="true" className="size-4" />
                        {t('portal_projects.index.open_gantt')}
                    </Link>
                ) : null}
            </CardFooter>
        </Card>
    );
}

/**
 * Tarjeta «Tus proyectos» para el Inicio del portal: los proyectos abiertos al portal (prop
 * compartida `portal`), con enlace a cada uno y a la lista completa. La usa pages/portal/home.tsx.
 */
export function PortalProjectsCard({
    headingLevel = 'h2',
}: {
    headingLevel?: 'h2' | 'h3';
}) {
    const projects = usePortalShell()?.projects ?? [];
    const Heading = headingLevel;

    return (
        <Card className="gap-4" data-test="portal-projects-card">
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <FolderOpen
                        aria-hidden="true"
                        className="size-4 text-muted-foreground"
                        strokeWidth={1.5}
                    />
                    <Heading>{t('portal_projects.card.title')}</Heading>
                </CardTitle>
                <CardDescription>
                    {t('portal_projects.card.description')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {projects.length === 0 ? (
                    <EmptyState
                        title={t('portal_projects.index.empty')}
                        description={t(
                            'portal_projects.index.empty_description',
                        )}
                    />
                ) : (
                    <ul className="grid gap-2">
                        {projects.map((project) => (
                            <li key={project.id}>
                                <Link
                                    href={portalProjectHref(project)}
                                    className={cn(
                                        'flex items-center justify-between gap-3 rounded-md border p-3 text-sm hover:bg-muted',
                                        FOCUS_RING,
                                    )}
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate">
                                            {project.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {project.code}
                                        </span>
                                    </span>
                                    <ArrowRight
                                        aria-hidden="true"
                                        className="size-4 shrink-0 text-muted-foreground"
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
            {projects.length > 0 ? (
                <CardFooter>
                    <Link href={index.url()} className={LINK_CLASS}>
                        {t('portal_projects.card.all')}
                        <ArrowRight aria-hidden="true" className="size-4" />
                    </Link>
                </CardFooter>
            ) : null}
        </Card>
    );
}
