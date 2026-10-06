// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({
    url: '/',
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        usePage: () => page,
        Link: ({
            href,
            children,
            prefetch: _prefetch,
            preserveScroll: _preserveScroll,
            ...rest
        }: {
            href: string | { url: string };
            children?: ReactNode;
            prefetch?: boolean;
            preserveScroll?: boolean;
            [key: string]: unknown;
        }) => (
            <a href={typeof href === 'string' ? href : href.url} {...rest}>
                {children}
            </a>
        ),
    };
});

import {
    AppSidebar,
    mainNavItems,
    weeklyNavItems,
} from '@/components/app-sidebar';
import { ModulePreviewBanner } from '@/components/weeklies/module-preview-banner';
import { SidebarProvider } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import { HomeWeeklyCard } from '@/components/weeklies/home-weekly-card';
import { TeamStatusStrip } from '@/components/weeklies/team-status-strip';
import { filterJoinableClients } from '@/components/weeklies/weekly-dialogs';
import { compactWeekLabel } from '@/components/weeklies/weekly-ui';
import type { Abilities, User } from '@/types';
import type { WeeklyTeamMember, WeeklyTeamStatus } from '@/types/weeklies';

function member(
    id: number,
    name: string,
    status: WeeklyTeamMember['status'],
    extra: Partial<WeeklyTeamMember> = {},
): WeeklyTeamMember {
    return {
        user: { id, name, avatar: null, department_id: null, is_active: true },
        status,
        submitted_at: null,
        exemption_reason: null,
        exemption_id: null,
        ...extra,
    };
}

const team: WeeklyTeamStatus = {
    members: [
        member(1, 'Ana García', 'submitted'),
        member(2, 'Bruno Díaz', 'submitted_late'),
        member(3, 'Carla Ruiz', 'overdue'),
        member(4, 'Diego León', 'exempt', { exemption_reason: 'absence' }),
    ],
    counts: { submitted: 2, expected: 3, exempt: 1, pending: 1 },
};

describe('tira de estado del equipo (F-067)', () => {
    it('agrupa enviados, pendientes y exentos, con nombre y estado de cada uno', () => {
        render(
            <TooltipProvider>
                <TeamStatusStrip team={team} />
            </TooltipProvider>,
        );

        const strip = screen.getByRole('group', { name: 'Estado del equipo' });
        const submitted = within(strip).getByRole('list', {
            name: 'Han enviado',
        });
        const pending = within(strip).getByRole('list', { name: 'Faltan' });
        const exempt = within(strip).getByRole('list', { name: 'Exentos' });

        expect(
            within(submitted)
                .getAllByRole('link')
                .map((avatar) => avatar.getAttribute('aria-label')),
        ).toEqual(['Ana García · Enviado', 'Bruno Díaz · Enviado con retraso']);
        expect(
            within(pending).getByRole('link').getAttribute('aria-label'),
        ).toBe('Carla Ruiz · Con retraso');
        expect(
            within(exempt).getByRole('link').getAttribute('aria-label'),
        ).toBe('Diego León · Exento (Ausencia)');
        expect(
            document.querySelector('[data-test="weekly-team-counter"]')
                ?.textContent,
        ).toBe('2 / 3 reportes (1 exento)');

        // Quien falta o está exento se apaga solo en la foto: las iniciales conservan el contraste AA.
        const avatars = document.querySelectorAll(
            '[data-test="weekly-team-member"] [data-slot="avatar"]',
        );
        expect(avatars).toHaveLength(4);
        for (const avatar of avatars) {
            expect(avatar.className).not.toMatch(/(^|\s)opacity-/);
        }
    });

    it('sin nadie que tenga que enviar, lo dice', () => {
        render(
            <TooltipProvider>
                <TeamStatusStrip
                    team={{
                        members: [],
                        counts: {
                            submitted: 0,
                            expected: 0,
                            exempt: 0,
                            pending: 0,
                        },
                    }}
                    showCounter={false}
                />
            </TooltipProvider>,
        );

        expect(
            screen.getByText('Nadie tiene que enviar esta weekly.'),
        ).toBeTruthy();
        expect(
            document.querySelector('[data-test="weekly-team-counter"]'),
        ).toBeNull();
    });

    it('la semana compacta del móvil (F-021)', () => {
        expect(
            compactWeekLabel({
                number: 'W01-27',
                start_date: '2027-01-04',
                end_date: '2027-01-08',
            }),
        ).toBe('Sem. 1 · 04/01 - 08/01');
    });
});

const user: User = {
    id: 1,
    name: 'Ana García',
    email: 'ana@audaxstudio.com',
    avatar: null,
    theme_preference: 'system',
    two_factor_enabled: false,
    roles: ['employee'],
    is_client: false,
    is_collaborator: false,
};

const employee: Abilities = {
    viewHourBanks: false,
    viewAdmin: false,
    viewFinancials: false,
    createClients: false,
    createProjects: false,
    approveTime: false,
    lockTime: false,
    manageUsers: false,
    manageSettings: false,
    viewTeamAbsences: false,
    viewClients: true,
    viewWorkload: true,
    viewAbsences: true,
    viewReports: true,
    useWeeklies: true,
};

describe('barra lateral de la Weekly (F-001 y F-003)', () => {
    beforeEach(() => {
        page.url = '/';
        page.props = {
            auth: { user, can: employee },
            sidebarOpen: true,
            name: 'Audax',
            config: { modules: { weeklies: true } },
            weeklies: { pending: 1 },
        };
    });

    it('la Weekly va en su propio bloque, no entre las entradas generales (D-239)', () => {
        expect(mainNavItems(employee).map((item) => item.title)).not.toContain(
            'Mi espacio',
        );
        expect(weeklyNavItems(employee).map((item) => item.title)).toEqual([
            'Mi espacio',
            'Weeklies',
            'Equipo',
            'Asistente IA',
            'Ayuda',
        ]);
    });

    it('el bloque de la Weekly va bajo una línea fina y con su encabezado', () => {
        render(
            <TooltipProvider>
                <SidebarProvider>
                    <AppSidebar />
                </SidebarProvider>
            </TooltipProvider>,
        );

        const nav = screen.getByRole('navigation', {
            name: 'Navegación principal',
        });
        const separators = nav.querySelectorAll('[data-test="nav-separator"]');
        expect(separators).toHaveLength(1);

        const block = within(nav).getByRole('group', { name: 'Weekly' });
        expect(separators[0].nextElementSibling).toBe(block);
        expect(
            within(block)
                .getAllByRole('link')
                .map((link) => link.textContent),
        ).toEqual([
            'Mi espacio',
            'Weeklies',
            'Equipo',
            'Asistente IA',
            'Ayuda',
        ]);
        expect(
            within(nav)
                .getByRole('link', { name: 'Inicio' })
                .closest('[role="group"]'),
        ).toBeNull();
    });

    it('sin entradas de la Weekly no hay bloque ni línea', () => {
        page.props = {
            ...page.props,
            auth: { user, can: { ...employee, useWeeklies: false } },
        };
        render(
            <TooltipProvider>
                <SidebarProvider>
                    <AppSidebar />
                </SidebarProvider>
            </TooltipProvider>,
        );

        const nav = screen.getByRole('navigation', {
            name: 'Navegación principal',
        });
        expect(nav.querySelector('[data-test="nav-separator"]')).toBeNull();
        expect(within(nav).queryByRole('group', { name: 'Weekly' })).toBeNull();
    });

    it('sin el permiso (colaborador externo) o con el módulo apagado, no salen', () => {
        const titles = (can: Abilities, enabled?: boolean) =>
            weeklyNavItems(can, { weekliesEnabled: enabled }).map(
                (item) => item.title,
            );

        expect(titles({ ...employee, useWeeklies: false })).not.toContain(
            'Mi espacio',
        );
        expect(titles({ ...employee, useWeeklies: undefined })).not.toContain(
            'Weeklies',
        );
        expect(titles(employee, false)).not.toContain('Mi espacio');
        expect(titles(employee, false)).not.toContain('Weeklies');
    });

    it('el contador de «Mi espacio» sale con la weekly pendiente y se va al enviarla', () => {
        const { unmount } = render(
            <TooltipProvider>
                <SidebarProvider>
                    <AppSidebar />
                </SidebarProvider>
            </TooltipProvider>,
        );

        const nav = within(
            screen.getByRole('navigation', { name: 'Navegación principal' }),
        );
        const link = nav.getByRole('link', { name: 'Mi espacio' });
        expect(link.getAttribute('href')).toBe('/mi-espacio');
        const badge = document.getElementById(
            link.getAttribute('aria-describedby') ?? '',
        );
        expect(badge?.textContent).toContain('1');
        expect(badge?.textContent).toContain(
            'Tienes la weekly de esta semana pendiente',
        );
        unmount();

        page.props = { ...page.props, weeklies: { pending: 0 } };
        render(
            <TooltipProvider>
                <SidebarProvider>
                    <AppSidebar />
                </SidebarProvider>
            </TooltipProvider>,
        );

        expect(
            screen
                .getByRole('link', { name: 'Mi espacio' })
                .getAttribute('aria-describedby'),
        ).toBeNull();
    });
});

describe('tarjeta «Weekly» de Inicio', () => {
    it('mi weekly pendiente, la racha y, para quien gestiona, quién falta', () => {
        render(
            <HomeWeeklyCard
                card={{
                    cycle: {
                        id: 12,
                        number: 'W41-26',
                        label: 'Semana 41 (Lun 05/10 - Vie 09/10)',
                        start_date: '2026-10-05',
                        end_date: '2026-10-09',
                        deadline_date: '2026-10-09',
                        status: 'active',
                        has_report: false,
                        report_state: null,
                        report_generated_at: null,
                        audio_state: null,
                        submission_count_at_generation: null,
                        closed_at: null,
                    },
                    me: {
                        status: 'pending',
                        participates: true,
                        must_submit: true,
                        exemption_reason: null,
                        exemption_id: null,
                        waived: false,
                        submission_id: null,
                        submitted_at: null,
                        resubmitted_at: null,
                        draft_saved_at: null,
                        entries_count: 0,
                    },
                    streak: { submitted: 10, on_time: 9, streak: 4 },
                    team: {
                        counts: {
                            submitted: 2,
                            expected: 3,
                            exempt: 0,
                            pending: 1,
                        },
                        pending: [
                            {
                                id: 3,
                                name: 'Carla Ruiz',
                                avatar: null,
                                department_id: null,
                                is_active: true,
                            },
                        ],
                    },
                    can: { manage: true, open: true },
                }}
            />,
        );

        expect(
            screen.getByText('Tu weekly de esta semana está pendiente'),
        ).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: /Empezar mi weekly/ })
                .getAttribute('href'),
        ).toBe('/mi-espacio?semana=12');
        expect(screen.getByText('Racha de 4 semanas')).toBeTruthy();
        expect(screen.getByText('Por enviar: 1')).toBeTruthy();
        expect(
            within(
                screen.getByRole('list', {
                    name: 'Personas que faltan por enviar',
                }),
            ).getByText('Carla Ruiz'),
        ).toBeTruthy();
    });
});

describe('«Unirme a clientes» (F-034, D-221)', () => {
    it('busca por el nombre del cliente y por el código o el nombre de sus proyectos, en orden', () => {
        const clients = [
            {
                id: 2,
                name: 'Peras',
                icon: null,
                projects: [{ id: 9, code: 'PER-BH1', name: 'Bolsa' }],
            },
            { id: 1, name: 'Ácaros', icon: null, projects: [] },
            {
                id: 3,
                name: 'Manzanas',
                icon: null,
                projects: [{ id: 7, code: 'MAN-WE1', name: 'Web corporativa' }],
            },
        ];

        expect(filterJoinableClients(clients, '').map((c) => c.id)).toEqual([
            1, 3, 2,
        ]);
        expect(
            filterJoinableClients(clients, 'per-bh').map((c) => c.id),
        ).toEqual([2]);
        expect(
            filterJoinableClients(clients, 'CORPORATIVA').map((c) => c.id),
        ).toEqual([3]);
        expect(filterJoinableClients(clients, 'nada')).toEqual([]);
    });
});

describe('aviso del modo de prueba (D-239)', () => {
    it('sale solo en las páginas de un módulo que el admin ve por la prueba', () => {
        page.props = { ...page.props, module_preview: true };
        const { unmount } = render(<ModulePreviewBanner />);

        expect(
            screen.getByRole('complementary', { name: 'Modo de prueba' })
                .textContent,
        ).toBe('Modo de prueba: solo lo ven los admins; no se envían avisos.');
        unmount();

        page.props = { ...page.props, module_preview: false };
        render(<ModulePreviewBanner />);
        expect(screen.queryByRole('complementary')).toBeNull();
    });
});
