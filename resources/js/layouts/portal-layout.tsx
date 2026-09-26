import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { AudaxWordmark } from '@/components/app-logo';
import { Breadcrumbs } from '@/components/breadcrumbs';
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
import { t } from '@/lib/i18n';
import { home } from '@/routes/portal';
import type { BreadcrumbItem } from '@/types';

/**
 * Layout del portal de cliente (SPEC §11): cabecera con el degradado de marca y el logotipo,
 * sin la barra lateral interna. El menú de usuario da acceso a los ajustes y a cerrar sesión.
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

    return (
        <div className="flex min-h-dvh flex-col bg-background">
            <ThemeSync />
            <SkipLink />
            <header className="dark text-foreground bg-brand-gradient">
                <div className="mx-auto flex h-16 w-full max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                    <Link
                        href={home()}
                        className="flex items-baseline gap-3 rounded-md focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        aria-label={t('portal.home_link')}
                    >
                        <AudaxWordmark tone="inverse" className="h-4" />
                        <span className="hidden text-sm text-muted-foreground sm:inline">
                            {t('portal.name')}
                        </span>
                    </Link>

                    {user && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-foreground hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
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
                                        className="size-4 text-muted-foreground"
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
