import { Link } from '@inertiajs/react';
import {
    BarChart3,
    Building2,
    CalendarOff,
    CalendarRange,
    Clock,
    FolderKanban,
    House,
    ListChecks,
    MessagesSquare,
    Settings2,
    Wallet,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { useChatUnreadTotal } from '@/components/chat/use-chat-unread';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useAbilities } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';
import { home } from '@/routes';
import { index as absencesIndex } from '@/routes/absences';
import { index as teamAbsencesIndex } from '@/routes/absences/team';
import { index as adminIndex } from '@/routes/admin';
import { index as chatIndex } from '@/routes/chat';
import { index as clientsIndex } from '@/routes/clients';
import { index as hourBanksIndex } from '@/routes/hour-banks';
import { index as myTasksIndex } from '@/routes/my-tasks';
import { index as projectsIndex } from '@/routes/projects';
import { index as reportsIndex } from '@/routes/reports';
import { index as timeIndex } from '@/routes/time';
import { index as workloadIndex } from '@/routes/workload';
import type { Abilities, NavItem } from '@/types';

/**
 * Navegación principal (SPEC §3), en este orden, con «Ausencias» tras «Carga» (D-091). Bolsas,
 * «Ausencias del equipo» y Administración dependen de `auth.can` (gate view-hour-banks, quien
 * aprueba ausencias y rol admin); el servidor vuelve a comprobarlo en la ruta. Chat lleva el
 * total de mensajes sin leer (Fase 6). Clientes, Carga, Ausencias e Informes no aparecen a un
 * colaborador externo (D-134), que solo tiene Inicio, Mis tareas, Proyectos, Horas y Chat.
 */
export function mainNavItems(
    can: Abilities,
    counters: { chatUnread?: number } = {},
): NavItem[] {
    const chatUnread = counters.chatUnread ?? 0;

    const items: (NavItem | false)[] = [
        { title: t('nav.home'), href: home(), icon: House },
        { title: t('nav.my_tasks'), href: myTasksIndex(), icon: ListChecks },
        { title: t('nav.projects'), href: projectsIndex(), icon: FolderKanban },
        can.viewClients && {
            title: t('nav.clients'),
            href: clientsIndex(),
            icon: Building2,
        },
        can.viewHourBanks && {
            title: t('nav.hour_banks'),
            href: hourBanksIndex(),
            icon: Wallet,
        },
        { title: t('nav.time'), href: timeIndex(), icon: Clock },
        can.viewWorkload && {
            title: t('nav.workload'),
            href: workloadIndex(),
            icon: CalendarRange,
        },
        can.viewAbsences && {
            title: t('nav.absences'),
            href: absencesIndex(),
            icon: CalendarOff,
            items: can.viewTeamAbsences
                ? [{ title: t('absences.nav.team'), href: teamAbsencesIndex() }]
                : undefined,
        },
        can.viewReports && {
            title: t('nav.reports'),
            href: reportsIndex(),
            icon: BarChart3,
        },
        {
            title: t('nav.chat'),
            href: chatIndex(),
            icon: MessagesSquare,
            badge:
                chatUnread > 0
                    ? {
                          count: chatUnread,
                          label: t('chat.nav.unread', { count: chatUnread }),
                      }
                    : undefined,
        },
        can.viewAdmin && {
            title: t('nav.admin'),
            href: adminIndex(),
            icon: Settings2,
        },
    ];

    return items.filter((item): item is NavItem => item !== false);
}

export function AppSidebar() {
    const can = useAbilities();
    const chatUnread = useChatUnreadTotal();

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link
                                href={home()}
                                prefetch
                                aria-label={t('brand.home_link')}
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems(can, { chatUnread })} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
