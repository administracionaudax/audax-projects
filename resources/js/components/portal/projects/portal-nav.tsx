import { Link, usePage } from '@inertiajs/react';
import { FolderOpen, House } from 'lucide-react';
import type {
    PortalNavProject,
    PortalShellProps,
} from '@/components/portal/projects/types';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { home } from '@/routes/portal';
import {
    gantt as projectGantt,
    index as projectsIndex,
    show as projectShow,
} from '@/routes/portal/projects';

/** Prop compartida `portal` (identidad y proyectos abiertos), solo en las páginas del portal. */
export function usePortalShell(): PortalShellProps | null {
    const portal = (usePage().props as { portal?: PortalShellProps | null })
        .portal;

    return portal ?? null;
}

/** Adónde lleva un proyecto del portal: su vista de tareas o, si solo está abierto el Gantt, este. */
export function portalProjectHref(
    project: Pick<PortalNavProject, 'id' | 'view'>,
): string {
    return project.view
        ? projectShow.url(project.id)
        : projectGantt.url(project.id);
}

/**
 * Navegación del portal en la cabecera (sobre el degradado): Inicio y, si el equipo ha abierto
 * algún proyecto al portal, Proyectos. La página actual lleva aria-current y un subrayado (nunca solo
 * el color). En móvil ocupa su propia fila dentro de la cabecera.
 */
export function PortalNav({
    projects,
    className,
}: {
    projects: ReadonlyArray<PortalNavProject>;
    className?: string;
}) {
    const path = new URL(
        usePage().url,
        typeof window !== 'undefined'
            ? window.location.origin
            : 'http://localhost',
    ).pathname.replace(/\/+$/, '');
    const homeUrl = home.url();
    const projectsUrl = projectsIndex.url();

    const items = [
        {
            href: homeUrl,
            label: t('portal_nav.home'),
            icon: House,
            current: path === homeUrl,
        },
        ...(projects.length > 0
            ? [
                  {
                      href: projectsUrl,
                      label: t('portal_nav.projects'),
                      icon: FolderOpen,
                      current:
                          path === projectsUrl ||
                          path.startsWith(`${projectsUrl}/`),
                  },
              ]
            : []),
    ];

    return (
        <nav aria-label={t('portal_nav.label')} className={className}>
            <ul className="flex items-center gap-1">
                {items.map((item) => (
                    <li key={item.href}>
                        <Link
                            href={item.href}
                            aria-current={item.current ? 'page' : undefined}
                            className={cn(
                                'flex items-center gap-2 rounded-md border-b-2 px-3 py-2 text-sm text-foreground hover:bg-muted',
                                item.current
                                    ? 'border-foreground font-medium'
                                    : 'border-transparent',
                                FOCUS_RING,
                            )}
                            data-test={`portal-nav-${item.href === homeUrl ? 'home' : 'projects'}`}
                        >
                            <item.icon
                                aria-hidden="true"
                                className="size-4"
                                strokeWidth={1.5}
                            />
                            {item.label}
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
