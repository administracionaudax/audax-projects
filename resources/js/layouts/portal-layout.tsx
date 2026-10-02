import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { CompanyLogo } from '@/components/portal/projects/company-logo';
import {
    PortalNav,
    usePortalShell,
} from '@/components/portal/projects/portal-nav';
import { SkipLink } from '@/components/skip-link';
import { ThemeSync } from '@/components/theme-sync';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useUser } from '@/hooks/use-auth';
import { useInitials } from '@/hooks/use-initials';
import { FOCUS_RING } from '@/lib/focus-ring';
import { cn } from '@/lib/utils';
import { t } from '@/lib/i18n';
import { home } from '@/routes/portal';
import type { BreadcrumbItem } from '@/types';

/**
 * Layout del portal de cliente (SPEC §11): cabecera con el degradado de marca y el logotipo,
 * sin la barra lateral interna. El menú de usuario da acceso a los ajustes y a cerrar sesión.
 * Fase 5 (D-067): el logo y el nombre de la empresa salen de /admin/identidad, y la navegación
 * lleva a Inicio y a los proyectos abiertos al portal (prop compartida `portal`).
 */
export default function PortalLayout({
    children,
    breadcrumbs = [],
}: {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}) {
    const user = useUser();
    const getInitials = useInitials();
    const portal = usePortalShell();
    const company = portal?.company.name ?? t('brand.company');

    return (
        <div className="flex min-h-dvh flex-col bg-background">
            <ThemeSync />
            <SkipLink />
            <header className="dark text-foreground bg-brand-gradient">
                <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center gap-x-6 px-4 sm:px-6">
                    <Link
                        href={home()}
                        className={cn(
                            'my-3.5 flex h-9 min-w-0 items-center gap-3 rounded-md',
                            FOCUS_RING,
                        )}
                        aria-label={t('portal_nav.home_link', { company })}
                    >
                        <CompanyLogo company={portal?.company} />
                        <span className="hidden text-sm text-on-gradient-muted sm:inline">
                            {t('portal.name')}
                        </span>
                    </Link>

                    <PortalNav
                        projects={portal?.projects ?? []}
                        className="order-last -mx-3 w-full pb-2 md:order-none md:mx-0 md:w-auto md:pb-0"
                    />

                    {user && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    className="ml-auto flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                                    data-test="portal-user-menu"
                                >
                                    <Avatar className="size-8 overflow-hidden rounded-full">
                                        <AvatarImage
                                            src={user.avatar ?? undefined}
                                            alt=""
                                        />
                                        <AvatarFallback className="rounded-full bg-muted text-xs font-medium text-foreground">
                                            {getInitials(user.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span className="hidden max-w-40 truncate sm:inline">
                                        {user.name}
                                    </span>
                                    <span className="sr-only">
                                        {t('user_menu.open')}
                                    </span>
                                    <ChevronDown
                                        aria-hidden="true"
                                        className="size-4 text-on-gradient-muted"
                                    />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent className="w-56" align="end">
                                <UserMenuContent user={user} />
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>
            </header>

            {breadcrumbs.length > 1 && (
                <div className="border-b">
                    <div className="mx-auto flex h-11 w-full max-w-6xl items-center px-4 sm:px-6">
                        <Breadcrumbs breadcrumbs={breadcrumbs} />
                    </div>
                </div>
            )}

            <main
                id="contenido"
                tabIndex={-1}
                className="mx-auto w-full max-w-6xl flex-1 px-4 py-8 focus:outline-none sm:px-6"
            >
                {children}
            </main>
        </div>
    );
}
