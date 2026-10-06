// @vitest-environment jsdom
import { router as coreRouter } from '@inertiajs/core';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({
    url: '/weeklies',
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
            ...rest
        }: {
            href: string | { url: string };
            children?: ReactNode;
            prefetch?: boolean;
            [key: string]: unknown;
        }) => (
            <a href={typeof href === 'string' ? href : href.url} {...rest}>
                {children}
            </a>
        ),
    };
});

import { TooltipProvider } from '@/components/ui/tooltip';
import { UserInfo } from '@/components/user-info';
import { AwayDialog, awayLabel } from '@/components/weeklies/away-dialog';
import { MyWeeklyCallout } from '@/components/weeklies/my-weekly-callout';
import { ExemptDialog } from '@/components/weeklies/weekly-dialogs';
import type { User } from '@/types';
import type { MyWeeklyStatus, WeeklyCycleSummary } from '@/types/weeklies';

const user: User = {
    id: 7,
    name: 'Elena Empleada',
    email: 'elena@audaxstudio.com',
    avatar: null,
    theme_preference: 'system',
    two_factor_enabled: false,
    roles: ['employee'],
    is_client: false,
    is_collaborator: false,
    weekly_away: null,
};

const cycle = {
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
    has_audio: false,
    submission_count_at_generation: null,
    closed_at: null,
} as WeeklyCycleSummary;

function me(extra: Partial<MyWeeklyStatus> = {}): MyWeeklyStatus {
    return {
        status: 'pending',
        participates: true,
        must_submit: true,
        exemption_reason: null,
        exemption_id: null,
        exemption_until: null,
        waived: false,
        submission_id: null,
        submitted_at: null,
        resubmitted_at: null,
        draft_saved_at: null,
        entries_count: 0,
        ...extra,
    };
}

function setAuth(extra: Partial<User> = {}, can: Record<string, boolean> = {}) {
    page.props = {
        auth: {
            user: { ...user, ...extra },
            can: { useWeeklies: true, viewAbsences: true, ...can },
        },
    };
}

beforeEach(() => {
    setAuth();
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-06T10:00:00Z'));
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('«Estoy fuera» (D-228)', () => {
    it('el texto del estado, con y sin vuelta', () => {
        expect(
            awayLabel({ reason: 'vacation', since: null, until: '2026-10-16' }),
        ).toBe('De vacaciones hasta el 16/10/2026');
        expect(awayLabel({ reason: 'absent', since: null, until: null })).toBe(
            'Ausente o de baja',
        );
    });

    it('«Mi weekly» pendiente ofrece marcarse fuera y solicitar la ausencia', async () => {
        const put = vi.spyOn(coreRouter, 'put').mockImplementation(() => {});
        render(<MyWeeklyCallout cycle={cycle} me={me()} />);

        const box = document.querySelector<HTMLElement>(
            '[data-test="my-weekly-away"]',
        )!;
        expect(
            within(box)
                .getByRole('link', { name: 'Solicitar ausencia' })
                .getAttribute('href'),
        ).toBe('/ausencias?solicitar=1');

        await userEvent.click(
            within(box).getByRole('button', { name: 'Marcar que estoy fuera' }),
        );
        const dialog = screen.getByRole('dialog', { name: 'Estoy fuera' });
        expect(
            within(dialog).getByText(/no recibes recordatorios/),
        ).toBeTruthy();
        await userEvent.click(
            within(dialog).getByRole('radio', { name: 'Ausente o de baja' }),
        );
        await userEvent.click(
            within(dialog).getByRole('button', { name: 'Guardar' }),
        );

        expect(put).toHaveBeenCalledWith(
            '/equipo/7/fuera',
            { reason: 'absent', until: null, request_absence: false },
            expect.anything(),
        );
    });

    it('exento por estar fuera: dice hasta cuándo y deja cambiarlo o volver', async () => {
        const destroy = vi
            .spyOn(coreRouter, 'delete')
            .mockImplementation(() => {});
        const away = {
            reason: 'vacation' as const,
            since: '2026-10-05',
            until: '2026-10-16',
        };
        setAuth({ weekly_away: away });
        render(
            <MyWeeklyCallout
                cycle={cycle}
                me={me({
                    status: 'exempt',
                    exemption_reason: 'away',
                    exemption_until: '2026-10-16',
                })}
            />,
        );

        expect(screen.getByText('Weekly exenta: estás fuera')).toBeTruthy();
        expect(
            screen.getByText('Hasta el 16/10/2026 (incluido).'),
        ).toBeTruthy();
        await userEvent.click(
            screen.getByRole('button', { name: 'Cambiar mi estado' }),
        );
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText(
                'Ahora: De vacaciones hasta el 16/10/2026.',
            ),
        ).toBeTruthy();
        await userEvent.click(
            within(dialog).getByRole('button', {
                name: 'Vuelvo a estar disponible',
            }),
        );

        expect(destroy).toHaveBeenCalledWith(
            '/equipo/7/fuera',
            expect.anything(),
        );
    });

    it('la ausencia a la vez solo se puede pedir con la vuelta', async () => {
        const put = vi.spyOn(coreRouter, 'put').mockImplementation(() => {});
        render(
            <AwayDialog
                person={user}
                current={null}
                self
                trigger={<button type="button">Abrir</button>}
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: 'Abrir' }));
        const checkbox = screen.getByRole('checkbox', {
            name: /Solicitar también la ausencia/,
        });
        expect(checkbox.hasAttribute('disabled')).toBe(true);
        expect(
            screen.getByText('Indica hasta cuándo para poder solicitarla.'),
        ).toBeTruthy();
        await userEvent.click(screen.getByRole('button', { name: 'Guardar' }));

        expect(put).toHaveBeenCalledWith(
            '/equipo/7/fuera',
            { reason: 'vacation', until: null, request_absence: false },
            expect.anything(),
        );
    });

    it('quien gestiona exime «solo esta semana» o marca fuera varias semanas, con enlace a Ausencias del equipo', async () => {
        const post = vi.spyOn(coreRouter, 'post').mockImplementation(() => {});
        const put = vi.spyOn(coreRouter, 'put').mockImplementation(() => {});
        setAuth({}, { viewTeamAbsences: true });
        const person = {
            id: 9,
            name: 'Bruno Díaz',
            avatar: null,
            department_id: null,
            is_active: true,
        };
        render(
            <ExemptDialog
                cycle={cycle}
                person={person}
                trigger={<button type="button">Eximir</button>}
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: 'Eximir' }));
        let dialog = screen.getByRole('dialog');
        expect(
            within(dialog)
                .getByRole('link', {
                    name: 'Registrar la ausencia en Ausencias del equipo',
                })
                .getAttribute('href'),
        ).toBe('/ausencias/equipo');
        await userEvent.type(
            within(dialog).getByLabelText(/Nota/),
            'Formación',
        );
        await userEvent.click(
            within(dialog).getByRole('button', { name: 'Eximir' }),
        );
        expect(post).toHaveBeenCalledWith(
            '/weeklies/12/exenciones',
            { user_id: 9, note: 'Formación' },
            expect.anything(),
        );

        dialog = screen.getByRole('dialog');
        await userEvent.click(
            within(dialog).getByRole('radio', { name: /De vacaciones/ }),
        );
        expect(within(dialog).queryByLabelText(/Nota/)).toBeNull();
        expect(within(dialog).getByText('¿Hasta cuándo?')).toBeTruthy();
        await userEvent.click(
            within(dialog).getByRole('button', { name: 'Marcar fuera' }),
        );
        expect(put).toHaveBeenCalledWith(
            '/equipo/9/fuera',
            { reason: 'vacation', until: null },
            expect.anything(),
        );
    });

    it('el pie de la barra enseña el rol y la insignia de fuera (F-008)', () => {
        render(
            <TooltipProvider>
                <UserInfo
                    user={{
                        ...user,
                        roles: ['admin'],
                        weekly_away: {
                            reason: 'absent',
                            since: null,
                            until: null,
                        },
                    }}
                    showRole
                />
            </TooltipProvider>,
        );

        expect(screen.getByText('Administración')).toBeTruthy();
        expect(
            document.querySelector('[data-test="user-away-badge"]')
                ?.textContent,
        ).toBe('Fuera: Ausente o de baja');
    });
});
