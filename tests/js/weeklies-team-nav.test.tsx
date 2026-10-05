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

import { AppSidebar, mainNavItems } from '@/components/app-sidebar';
import { SidebarProvider } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import { HomeWeeklyCard } from '@/components/weeklies/home-weekly-card';
import { TeamStatusStrip } from '@/components/weeklies/team-status-strip';
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

    it('«Mi espacio» y «Weeklies» van tras Mis tareas para quien escribe la weekly', () => {
        const titles = mainNavItems(employee).map((item) => item.title);

        expect(titles.slice(0, 4)).toEqual([
            'Inicio',
            'Mis tareas',
            'Mi espacio',
            'Weeklies',
        ]);
    });

    it('sin el permiso (colaborador externo) o con el módulo apagado, no salen', () => {
        const titles = (can: Abilities, enabled?: boolean) =>
            mainNavItems(can, { weekliesEnabled: enabled }).map(
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
