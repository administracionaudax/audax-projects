import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ProjectStatusBadge } from '@/components/domain/badges';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { urls } from '@/lib/urls';
import type { ProjectTab } from '@/lib/urls';
import { cn } from '@/lib/utils';
import type { Project } from '@/types';

type Tab = {
    id: ProjectTab;
    label: TranslationKey;
};

const TABS: Tab[] = [
    { id: 'resumen', label: 'project_tabs.summary' },
    { id: 'tareas', label: 'project_tabs.tasks' },
    { id: 'gantt', label: 'project_tabs.gantt' },
    { id: 'bolsas', label: 'project_tabs.hour_banks' },
    { id: 'horas', label: 'project_tabs.time' },
    { id: 'chat', label: 'project_tabs.chat' },
    { id: 'archivos', label: 'project_tabs.files' },
    { id: 'ajustes', label: 'project_tabs.settings' },
];

/**
 * Cabecera y pestañas de la ficha de proyecto (SPEC §6). Cada pestaña es su propia página
 * Inertia (/proyectos/{id}/{pestaña}) y envuelve su contenido con este componente.
 * La pestaña Bolsas solo aparece en proyectos de bolsas; Ajustes, si puede gestionarlo.
 */
export function ProjectShell({
    project,
    tab,
    canManage,
    actions,
    children,
}: {
    project: Project;
    tab: ProjectTab;
    canManage: boolean;
    /** Botones de la cabecera (p. ej. «Nueva tarea»). */
    actions?: ReactNode;
    children: ReactNode;
}) {
    const tabs = TABS.filter(
        (item) =>
            (item.id !== 'bolsas' || project.billing_type === 'hour_bank') &&
            (item.id !== 'ajustes' || canManage),
    );

    return (
        <div className="flex flex-col gap-6 p-4 md:p-6">
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex min-w-0 items-start gap-3">
                    <span
                        aria-hidden="true"
                        className="mt-1.5 size-3 shrink-0 rounded-full"
                        style={{ backgroundColor: project.color }}
                    />
                    <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">
                            <span className="font-medium">{project.code}</span>
                            {project.client ? ` · ${project.client.name}` : ''}
                        </p>
                        <h1 className="text-2xl break-words">{project.name}</h1>
                        <div className="mt-2">
                            <ProjectStatusBadge status={project.status} />
                        </div>
                    </div>
                </div>
                {actions ? (
                    <div className="flex flex-wrap gap-2">{actions}</div>
                ) : null}
            </header>

            <nav
                aria-label={t('project_tabs.label')}
                className="-mx-4 overflow-x-auto border-b px-4 md:mx-0 md:px-0"
            >
                <ul className="flex min-w-max gap-1">
                    {tabs.map((item) => {
                        const current = item.id === tab;

                        return (
                            <li key={item.id}>
                                <Link
                                    href={urls.project(project.id, item.id)}
                                    aria-current={current ? 'page' : undefined}
                                    preserveScroll
                                    className={cn(
                                        '-mb-px flex border-b-2 px-3 py-2 text-sm',
                                        current
                                            ? 'border-primary font-medium text-foreground'
                                            : 'border-transparent text-muted-foreground hover:text-foreground',
                                        FOCUS_RING,
                                    )}
                                >
                                    {t(item.label)}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </nav>

            <div>{children}</div>
        </div>
    );
}
