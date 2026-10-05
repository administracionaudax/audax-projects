// @vitest-environment jsdom
import { router as coreRouter } from '@inertiajs/core';
import { configure, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import fixture from '../fixtures/weeklies/template-render.json';

// La app marca los elementos con data-test.
configure({ testIdAttribute: 'data-test' });

const page = vi.hoisted(() => ({
    url: '/weeklies/avisos',
    props: {
        auth: {
            user: {
                id: 7,
                name: 'Marta Gestora',
                roles: ['department_manager'],
            },
            can: { manageWeeklies: true },
        },
        config: { modules: { weeklies: true, project_status: true } },
    } as Record<string, unknown>,
}));

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
        usePage: () => page,
        router: {
            get: inertia.get,
            post: inertia.post,
            on: () => () => {},
        },
        Link: ({
            href,
            children,
            preserveScroll: _preserveScroll,
            ...rest
        }: {
            href: string | { url: string };
            children?: ReactNode;
            [key: string]: unknown;
        }) => (
            <a href={typeof href === 'string' ? href : href.url} {...rest}>
                {children}
            </a>
        ),
    };
});

import { rulesSummary } from '@/components/weeklies/reminders/reminder-rules-editor';
import { RemindButton } from '@/components/weeklies/reminders/remind-button';
import { isDefaultTemplate } from '@/components/weeklies/reminders/template-editor';
import {
    normalizeWeeklyTemplate,
    renderWeeklyTemplate,
} from '@/lib/weekly-templates';
import WeekliesReminders from '@/pages/weeklies/reminders';
import type { WeeklyRemindersPageProps } from '@/types/weeklies';

const defaults = {
    automatic: {
        subject: 'Recordatorio automático: weekly {semana}',
        body: 'Hola {nombre},\n\nCompleta tu weekly de la semana {semana}.',
    },
    manual: {
        subject: 'Recordatorio: weekly pendiente',
        body: 'Hola {nombre},\n\nAún no has enviado tu weekly.',
    },
    weekly_closed: {
        subject: 'Weekly cerrada: {semana}',
        body: 'Hola {nombre},\n\nPuedes revisarla aquí:\n{weekly_url}',
    },
};

function props(
    overrides: Partial<WeeklyRemindersPageProps> = {},
): WeeklyRemindersPageProps {
    return {
        cycle: {
            id: 12,
            number: 'W41-26',
            label: 'Semana 41 (Lun 05/10 - Vie 09/10)',
            deadline_date: '2026-10-09',
        },
        rules: [
            {
                id: 3,
                channel: 'email',
                day_of_week: 5,
                time: '16:00',
                enabled: true,
                position: 0,
            },
            {
                id: 4,
                channel: 'push',
                day_of_week: 4,
                time: '14:00',
                enabled: false,
                position: 1,
            },
        ],
        templates: {
            automatic: { ...defaults.automatic, is_default: true },
            manual: {
                subject: 'Ojo, {nombre}',
                body: 'Falta tu weekly {semana}',
                is_default: false,
            },
            weekly_closed: { ...defaults.weekly_closed, is_default: true },
        },
        defaults,
        variables: ['nombre', 'semana', 'week_label', 'weekly_url'],
        friday: { weekly: true, hours: true },
        pending: [
            { id: 20, name: 'Ana Pérez', avatar: null } as never,
            { id: 21, name: 'Luis Gil', avatar: null } as never,
        ],
        logs: {
            data: [
                {
                    id: 1,
                    weekly_cycle_id: 12,
                    cycle_number: 'W41-26',
                    user_id: 20,
                    recipient_name: 'Ana Pérez',
                    recipient_email: 'ana@example.com',
                    template: 'manual',
                    channel: 'email',
                    status: 'failed',
                    error: 'El relé rechaza el envío',
                    sent_by_name: 'Marta Gestora',
                    created_at: '2026-10-07T08:00:00Z',
                },
                {
                    id: 2,
                    weekly_cycle_id: 12,
                    cycle_number: 'W41-26',
                    user_id: 21,
                    recipient_name: 'Luis Gil',
                    recipient_email: 'luis@example.com',
                    template: 'friday',
                    channel: 'app',
                    status: 'sent',
                    error: null,
                    sent_by_name: null,
                    created_at: '2026-10-09T11:00:00Z',
                },
            ],
            meta: {
                current_page: 1,
                last_page: 1,
                per_page: 50,
                total: 2,
                from: 1,
                to: 2,
            },
            links: { prev: null, next: null },
        },
        filters: { template: null, status: null },
        push_available: false,
        can: { send: true },
        ...overrides,
    };
}

afterEach(() => {
    vi.restoreAllMocks();
    inertia.get.mockReset();
    inertia.post.mockReset();
});

describe('plantillas de los avisos (gemelo de WeeklyTemplates::render)', () => {
    it('pasa los casos compartidos con PHP', () => {
        for (const item of fixture.cases) {
            expect(renderWeeklyTemplate(item.text, fixture.values)).toBe(
                item.expected,
            );
        }
    });

    it('normaliza como el servidor y sabe si es la de por defecto', () => {
        expect(normalizeWeeklyTemplate('Hola\r\nadiós  \n\n')).toBe(
            'Hola\nadiós',
        );
        expect(
            isDefaultTemplate(
                {
                    subject: defaults.manual.subject,
                    body: `${defaults.manual.body}\n`,
                },
                defaults.manual,
            ),
        ).toBe(true);
        expect(
            isDefaultTemplate(
                { subject: 'Otro', body: defaults.manual.body },
                defaults.manual,
            ),
        ).toBe(false);
    });

    it('resume las reglas activas por día y hora', () => {
        expect(rulesSummary([])).toBe('Ningún recordatorio activo.');
        expect(
            rulesSummary([
                {
                    id: null,
                    channel: 'email',
                    day_of_week: 5,
                    time: '16:00',
                    enabled: true,
                },
                {
                    id: null,
                    channel: 'app',
                    day_of_week: 4,
                    time: '10:00',
                    enabled: true,
                },
                {
                    id: null,
                    channel: 'push',
                    day_of_week: 1,
                    time: '09:00',
                    enabled: false,
                },
            ]),
        ).toBe(
            '2 recordatorios activos: jueves a las 10:00 (En la app), viernes a las 16:00 (Email).',
        );
    });
});

describe('/weeklies/avisos (F-101 a F-110)', () => {
    it('enseña las reglas, la pestaña «Avisos», la semana activa, las pendientes y el registro', () => {
        render(<WeekliesReminders {...props()} />);

        expect(
            screen
                .getByRole('link', { name: 'Avisos' })
                .getAttribute('aria-current'),
        ).toBe('page');
        expect(screen.getByTestId('reminders-cycle').textContent).toContain(
            'Semana 41',
        );

        const rules = screen.getAllByTestId('reminder-rule');
        expect(rules).toHaveLength(2);
        expect(
            (
                within(rules[0]).getByLabelText(
                    'Hora (Madrid)',
                ) as HTMLInputElement
            ).value,
        ).toBe('16:00');
        expect(
            (within(rules[1]).getByLabelText('Canal') as HTMLSelectElement)
                .value,
        ).toBe('push');
        expect(screen.getByTestId('reminder-rules-summary').textContent).toBe(
            '1 recordatorio activo: viernes a las 16:00 (Email).',
        );
        // Una regla del navegador sin Web Push configurado: se avisa.
        expect(
            screen.getByText(/una regla del navegador no llegará a nadie/),
        ).toBeTruthy();

        expect(screen.getByTestId('reminders-pending').textContent).toBe(
            '2 personas pendientes.',
        );

        const log = screen.getByTestId('reminder-log');
        const rows = within(log).getAllByTestId('reminder-log-row');
        expect(rows).toHaveLength(2);
        expect(within(rows[0]).getByText('Fallido')).toBeTruthy();
        expect(
            within(rows[0]).getByText('El relé rechaza el envío'),
        ).toBeTruthy();
        expect(
            within(rows[0]).getByText('Enviado por Marta Gestora'),
        ).toBeTruthy();
        expect(
            within(rows[1]).getByText('Recordatorio de los viernes'),
        ).toBeTruthy();
        expect(within(rows[1]).getByText('Automático')).toBeTruthy();
    });

    it('la vista previa usa el nombre de quien edita y la semana activa, y se actualiza al escribir', async () => {
        render(<WeekliesReminders {...props()} />);

        const manual = screen.getByTestId('template-manual');
        const preview = within(manual).getByTestId('template-manual-preview');
        expect(preview.textContent).toContain('Ojo, Marta Gestora');
        expect(preview.textContent).toContain('Falta tu weekly W41-26');
        expect(within(manual).getByText('Personalizada')).toBeTruthy();

        const subject = within(manual).getByLabelText('Asunto');
        await userEvent.clear(subject);
        // En userEvent, «{{» escribe una llave: el asunto queda «Hola {nombre} y {otra}».
        await userEvent.type(subject, 'Hola {{nombre} y {{otra}');
        expect(preview.textContent).toContain('Hola Marta Gestora y {otra}');

        const closed = within(
            screen.getByTestId('template-weekly_closed'),
        ).getByTestId('template-weekly_closed-preview');
        expect(closed.textContent).toContain('/weeklies/12');
    });

    it('«Restaurar por defecto» vuelve al texto de por defecto y guardar envía todo', async () => {
        const put = vi.spyOn(coreRouter, 'put').mockImplementation(() => {});
        render(<WeekliesReminders {...props()} />);

        const manual = screen.getByTestId('template-manual');
        const restore = within(manual).getByRole('button', {
            name: 'Restaurar por defecto',
        });
        await userEvent.click(restore);
        expect(
            (within(manual).getByLabelText('Asunto') as HTMLInputElement).value,
        ).toBe('Recordatorio: weekly pendiente');
        expect(within(manual).getByText('Por defecto')).toBeTruthy();
        expect(
            (
                within(screen.getByTestId('template-automatic')).getByRole(
                    'button',
                    {
                        name: 'Restaurar por defecto',
                    },
                ) as HTMLButtonElement
            ).disabled,
        ).toBe(true);

        // Añadir una regla (jueves 10:00 por email), quitar la del navegador y cambiar la hora.
        await userEvent.click(
            screen.getByRole('button', { name: 'Añadir recordatorio' }),
        );
        await userEvent.click(
            screen.getByRole('button', { name: 'Quitar el recordatorio 2' }),
        );
        await userEvent.click(
            screen.getByLabelText(
                'Incluir la weekly en el recordatorio de los viernes',
            ),
        );

        await userEvent.click(
            screen.getByRole('button', { name: 'Guardar los avisos' }),
        );

        expect(put).toHaveBeenCalledTimes(1);
        const [url, data] = put.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/weeklies/avisos');
        expect(data.rules).toEqual([
            {
                id: 3,
                channel: 'email',
                day_of_week: 5,
                time: '16:00',
                enabled: true,
            },
            {
                id: null,
                channel: 'email',
                day_of_week: 4,
                time: '10:00',
                enabled: true,
            },
        ]);
        expect(
            (data.templates as Record<string, { subject: string }>).manual
                .subject,
        ).toBe('Recordatorio: weekly pendiente');
        expect(data.friday_reminder).toBe(false);
    });

    it('filtra el registro con la URL', async () => {
        render(<WeekliesReminders {...props()} />);

        await userEvent.selectOptions(
            screen.getByLabelText('Estado'),
            'failed',
        );

        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(inertia.get.mock.calls[0][0]).toBe(
            '/weeklies/avisos?estado=failed',
        );
    });

    it('sin semana activa no se puede enviar y sin registros lo dice', () => {
        render(
            <WeekliesReminders
                {...props({
                    cycle: null,
                    pending: [],
                    can: { send: false },
                    logs: {
                        data: [],
                        meta: {
                            current_page: 1,
                            last_page: 1,
                            per_page: 50,
                            total: 0,
                            from: null,
                            to: null,
                        },
                        links: { prev: null, next: null },
                    },
                })}
            />,
        );

        expect(screen.queryByTestId('reminders-send-open')).toBeNull();
        expect(screen.getByTestId('reminder-log-empty').textContent).toBe(
            'Aún no se ha enviado ningún aviso.',
        );
        expect(
            screen.getAllByText(/No hay ninguna semana activa/).length,
        ).toBeGreaterThan(0);
    });

    it('el envío manual elige personas, texto y canales', async () => {
        const post = vi.spyOn(coreRouter, 'post').mockImplementation(() => {});
        render(<WeekliesReminders {...props()} />);

        await userEvent.click(
            screen.getByRole('button', { name: 'Enviar recordatorio…' }),
        );
        const dialog = screen.getByRole('dialog');
        await userEvent.click(
            within(dialog).getByRole('radio', { name: 'Solo a algunas' }),
        );
        await userEvent.click(within(dialog).getByLabelText('Luis Gil'));
        await userEvent.click(within(dialog).getByLabelText('Email'));
        await userEvent.selectOptions(
            within(dialog).getByLabelText('Texto'),
            'automatic',
        );
        await userEvent.click(
            within(dialog).getByRole('button', { name: 'Enviar' }),
        );

        expect(post).toHaveBeenCalledTimes(1);
        const [url, data] = post.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/weeklies/avisos/enviar');
        expect(data).toEqual({
            recipients: 'users',
            user_ids: [21],
            template: 'automatic',
            channels: ['app'],
        });
    });
});

describe('«Recordar» (F-037 y F-110)', () => {
    it('manda el recordatorio de esa persona y dice a quién', async () => {
        render(
            <RemindButton
                cycleId={12}
                person={{ id: 20, name: 'Ana Pérez' }}
            />,
        );

        await userEvent.click(
            screen.getByRole('button', {
                name: 'Recordar a Ana Pérez que envíe su weekly',
            }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/weeklies/12/recordar',
            { user_id: 20 },
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
