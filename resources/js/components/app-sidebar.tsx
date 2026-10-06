import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Building2,
    CalendarDays,
    CalendarOff,
    CalendarRange,
    Clock,
    FolderKanban,
    House,
    LifeBuoy,
    ListChecks,
    MessagesSquare,
    NotebookPen,
    Settings2,
    Sparkles,
    ClipboardCheck,
    Users,
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
import { index as assistantIndex } from '@/routes/assistant';
import { index as helpIndex } from '@/routes/help';
import { index as calendarIndex } from '@/routes/calendar';
import { index as chatIndex } from '@/routes/chat';
import { index as clientsIndex } from '@/routes/clients';
import { index as hourBanksIndex } from '@/routes/hour-banks';
import { index as mySpaceIndex } from '@/routes/my-space';
import { index as teamIndex } from '@/routes/team';
import { index as myTasksIndex } from '@/routes/my-tasks';
import { index as projectsIndex } from '@/routes/projects';
import { index as reportsIndex } from '@/routes/reports';
import { index as reportSchedulesIndex } from '@/routes/reports/schedules';
import { index as timeIndex } from '@/routes/time';
import { index as weekliesIndex } from '@/routes/weeklies';
import { index as workloadIndex } from '@/routes/workload';
import type { Abilities, NavItem } from '@/types';

/**
 * Navegación principal (SPEC §3), en este orden, con «Ausencias» tras «Carga» (D-091). Bolsas,
 * «Ausencias del equipo» y Administración dependen de `auth.can` (gate view-hour-banks, quien
 * aprueba ausencias y rol admin); el servidor vuelve a comprobarlo en la ruta. Chat lleva el
 * total de mensajes sin leer (Fase 6). El calendario del equipo va tras Mis tareas (D-144).
 * Clientes, Carga, Ausencias e Informes no aparecen a un colaborador externo (D-134), que solo tiene
 * Inicio, Mis tareas, Calendario, Proyectos, Horas y Chat. Las entradas de la Weekly van en su propio
 * bloque (weeklyNavItems, D-239).
 */
export function mainNavItems(
    can: Abilities,
    counters: { chatUnread?: number } = {},
): NavItem[] {
    const chatUnread = counters.chatUnread ?? 0;

    const items: (NavItem | false | undefined)[] = [
        { title: t('nav.home'), href: home(), icon: House },
        { title: t('nav.my_tasks'), href: myTasksIndex(), icon: ListChecks },
        { title: t('nav.calendar'), href: calendarIndex(), icon: CalendarDays },
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
            // Envíos programados (D-141), dentro de Informes.
            items: [
                {
                    title: t('deliveries.nav.schedules'),
                    href: reportSchedulesIndex(),
                },
            ],
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

    // Sin la habilidad (false o, en props antiguas, undefined), la entrada no se pinta.
    return items.filter((item): item is NavItem => Boolean(item));
}

/**
 * Bloque de la Weekly (Fase 10, F-001, D-180 y D-239), bajo una línea fina y con su encabezado:
 * «Mi espacio» (con el contador de mi weekly pendiente, F-003), «Weeklies» y «Equipo» (10.4) con el
 * módulo `weeklies`; el asistente IA (10.6, F-006) con `assistant`; la Ayuda (10.7, F-010) con
 * `help`. Solo para quien escribe la weekly (`auth.can.useWeeklies`), nunca un colaborador externo.
 * Los módulos llegan como los ve cada persona (config.modules): en modo de prueba, un admin ve
 * también los apagados.
 */
export function weeklyNavItems(
    can: Abilities,
    counters: {
        weekliesPending?: number;
        weekliesEnabled?: boolean;
        assistantEnabled?: boolean;
        helpEnabled?: boolean;
    } = {},
): NavItem[] {
    if (can.useWeeklies !== true) {
        return [];
    }

    const weekliesPending = counters.weekliesPending ?? 0;
    const weeklies = counters.weekliesEnabled !== false;

    const items: (NavItem | false)[] = [
        weeklies && {
            title: t('my_space.title'),
            href: mySpaceIndex(),
            icon: NotebookPen,
            badge:
                weekliesPending > 0
                    ? {
                          count: weekliesPending,
                          label: t('weeklies.nav.pending'),
                      }
                    : undefined,
        },
        weeklies && {
            title: t('weeklies.title'),
            href: weekliesIndex(),
            icon: ClipboardCheck,
        },
        // Equipo de la Weekly (10.4, F-001 y F-134): la plantilla con el estado de su weekly.
        weeklies && {
            title: t('weeklies.team.nav'),
            href: teamIndex(),
            icon: Users,
        },
        counters.assistantEnabled !== false && {
            title: t('assistant.title'),
            href: assistantIndex(),
            icon: Sparkles,
        },
        // Centro de ayuda (10.7, F-010 y F-148), con el módulo `help`.
        counters.helpEnabled !== false && {
            title: t('help_center.title'),
            href: helpIndex(),
            icon: LifeBuoy,
        },
    ];

    return items.filter((item): item is NavItem => Boolean(item));
}

export function AppSidebar() {
    const can = useAbilities();
    const chatUnread = useChatUnreadTotal();
    const { props } = usePage();

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
                <NavMain
                    sections={[
                        {
                            id: 'main',
                            items: mainNavItems(can, { chatUnread }),
                        },
                        {
                            id: 'weekly',
                            label: t('weeklies.nav.group'),
                            items: weeklyNavItems(can, {
                                weekliesPending: props.weeklies?.pending ?? 0,
                                weekliesEnabled:
                                    props.config?.modules?.weeklies,
                                assistantEnabled:
                                    props.config?.modules?.assistant,
                                helpEnabled: props.config?.modules?.help,
                            }),
                        },
                    ]}
                />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
