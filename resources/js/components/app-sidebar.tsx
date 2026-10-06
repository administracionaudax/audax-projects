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
    SlidersHorizontal,
    Sparkles,
    UserCog,
    ClipboardCheck,
    Users,
    Wallet,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { useChatUnreadTotal } from '@/components/chat/use-chat-unread';
import { NavMain } from '@/components/nav-main';
import type { NavSection } from '@/components/nav-main';
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
import { edit as settingsEdit } from '@/routes/admin/settings';
import { index as usersIndex } from '@/routes/admin/users';
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
 * Entradas fijas de arriba, fuera de las secciones plegables (D-260): Inicio y Chat, con el total
 * de mensajes sin leer (Fase 6). La búsqueda global va en la cabecera.
 */
export function pinnedNavItems(
    counters: { chatUnread?: number } = {},
): NavItem[] {
    const chatUnread = counters.chatUnread ?? 0;

    return [
        { title: t('nav.home'), href: home(), icon: House },
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
    ];
}

/**
 * Sección «Proyectos» (D-260): la gestión de proyectos en el orden del SPEC §3. El calendario del
 * equipo va tras Mis tareas (D-144). Bolsas depende de `auth.can.viewHourBanks` (responsables y
 * gestores); Clientes, Carga e Informes no aparecen a un colaborador externo (D-134), que solo
 * tiene Mis tareas, Calendario, Proyectos y Horas. El servidor vuelve a comprobarlo en la ruta.
 */
export function projectsNavItems(can: Abilities): NavItem[] {
    const items: (NavItem | false | undefined)[] = [
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
    ];

    // Sin la habilidad (false o, en props antiguas, undefined), la entrada no se pinta.
    return items.filter((item): item is NavItem => Boolean(item));
}

/**
 * Sección «Personas» (D-260, nombre provisional del futuro módulo de RR. HH.): de momento, las
 * Ausencias (D-091), con «Ausencias del equipo» para quien las aprueba (D-049). Un colaborador
 * externo no las tiene (D-134), así que no ve la sección.
 */
export function peopleNavItems(can: Abilities): NavItem[] {
    if (!can.viewAbsences) {
        return [];
    }

    return [
        {
            title: t('nav.absences'),
            href: absencesIndex(),
            icon: CalendarOff,
            items: can.viewTeamAbsences
                ? [{ title: t('absences.nav.team'), href: teamAbsencesIndex() }]
                : undefined,
        },
    ];
}

/**
 * Sección «Facturación» (D-260): preparada para el futuro módulo. Sin entradas, no se pinta.
 */
export function billingNavItems(_can: Abilities): NavItem[] {
    return [];
}

/**
 * Sección «Administración» (D-260), solo para el rol admin: el panel con todas las áreas (activo
 * solo en /admin), Usuarios (`manageUsers`) y Ajustes (`manageSettings`).
 */
export function adminNavItems(can: Abilities): NavItem[] {
    if (!can.viewAdmin) {
        return [];
    }

    const items: (NavItem | false | undefined)[] = [
        {
            title: t('nav.admin_panel'),
            href: adminIndex(),
            icon: Settings2,
            exact: true,
        },
        can.manageUsers && {
            title: t('admin.areas.users.title'),
            href: usersIndex(),
            icon: UserCog,
        },
        can.manageSettings && {
            title: t('nav.admin_settings'),
            href: settingsEdit(),
            icon: SlidersHorizontal,
        },
    ];

    return items.filter((item): item is NavItem => Boolean(item));
}

/**
 * Sección «Weekly» (Fase 10, F-001, D-180, D-239 y D-260), plegable y con su encabezado:
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

export type NavCounters = {
    chatUnread?: number;
    weekliesPending?: number;
    weekliesEnabled?: boolean;
    assistantEnabled?: boolean;
    helpEnabled?: boolean;
};

/**
 * Bloques de la barra lateral (D-260), en este orden: las entradas fijas (Inicio y Chat) y las
 * secciones plegables Proyectos, Weekly, Personas, Facturación y Administración. Cada entrada sale
 * según el rol y los módulos (D-134, D-145, D-151 y D-239); una sección sin entradas no se pinta.
 */
export function navSections(
    can: Abilities,
    counters: NavCounters = {},
): NavSection[] {
    return [
        { id: 'main', items: pinnedNavItems(counters) },
        {
            id: 'projects',
            label: t('nav.sections.projects'),
            items: projectsNavItems(can),
        },
        {
            id: 'weekly',
            label: t('weeklies.nav.group'),
            items: weeklyNavItems(can, counters),
        },
        {
            id: 'people',
            label: t('nav.sections.people'),
            items: peopleNavItems(can),
        },
        {
            id: 'billing',
            label: t('nav.sections.billing'),
            items: billingNavItems(can),
        },
        {
            id: 'admin',
            label: t('nav.sections.admin'),
            items: adminNavItems(can),
        },
    ];
}

export function AppSidebar() {
    const can = useAbilities();
    const chatUnread = useChatUnreadTotal();
    const { props } = usePage();
    const counters: NavCounters = {
        chatUnread,
        weekliesPending: props.weeklies?.pending ?? 0,
        weekliesEnabled: props.config?.modules?.weeklies,
        assistantEnabled: props.config?.modules?.assistant,
        helpEnabled: props.config?.modules?.help,
    };

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
                <NavMain sections={navSections(can, counters)} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
