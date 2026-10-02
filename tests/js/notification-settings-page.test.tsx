// @vitest-environment jsdom
import { router as coreRouter } from '@inertiajs/core';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SettingsLayout from '@/layouts/settings/layout';
import NotificationSettingsPage, {
    initialForm,
} from '@/pages/settings/notifications';
import type {
    NotificationChannel,
    NotificationEventPreference,
    NotificationSettings,
} from '@/types/notification-settings';

/*
| /ajustes/notificaciones (D-073): matriz evento × canal con interruptores accesibles
| («<evento>: <canal>»), grupos con fieldset/legend, obligatorios bloqueados con candado, Web Push
| desactivado con su explicación, resumen diario, teclado y envío con el formulario de Inertia.
*/

const page = vi.hoisted(() => ({
    url: '/ajustes/notificaciones',
    props: {
        auth: { user: { is_client: false } },
    } as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
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

function channels(
    values: Partial<Record<NotificationChannel, [boolean, boolean]>>,
): NotificationEventPreference['channels'] {
    const pair = (channel: NotificationChannel) => {
        const [offered, enabled] = values[channel] ?? [false, false];

        return { offered, enabled };
    };

    return { app: pair('app'), email: pair('email'), push: pair('push') };
}

function settings(
    overrides: Partial<NotificationSettings> = {},
): NotificationSettings {
    const pushAvailable = overrides.push_available ?? false;

    return {
        daily_digest: false,
        push_available: pushAvailable,
        groups: [
            {
                key: 'tasks',
                label: 'Tareas',
                events: [
                    {
                        kind: 'task.assigned',
                        label: 'Te asignan una tarea',
                        description:
                            'Cuando alguien te hace responsable de una tarea.',
                        mandatory: false,
                        channels: channels({
                            app: [true, true],
                            email: [true, false],
                            push: [pushAvailable, false],
                        }),
                    },
                    {
                        kind: 'task.due',
                        label: 'Tareas que vencen',
                        description:
                            'Tus tareas que vencen mañana o ya han vencido.',
                        mandatory: false,
                        channels: channels({
                            app: [true, true],
                            email: [true, true],
                            push: [pushAvailable, false],
                        }),
                    },
                ],
            },
            {
                key: 'chat',
                label: 'Chat',
                events: [
                    {
                        kind: 'chat.direct',
                        label: 'Mensajes directos',
                        description:
                            'Cuando alguien te escribe por mensaje directo.',
                        mandatory: false,
                        channels: channels({
                            app: [true, true],
                            email: [true, false],
                            push: [pushAvailable, pushAvailable],
                        }),
                    },
                ],
            },
            {
                key: 'system',
                label: 'Sistema',
                events: [
                    {
                        kind: 'system.disk_space',
                        label: 'Espacio en disco',
                        description:
                            'Cuando el disco o los adjuntos pasan del umbral.',
                        mandatory: true,
                        channels: channels({
                            app: [true, true],
                            email: [true, true],
                        }),
                    },
                ],
            },
        ],
        ...overrides,
    };
}

function toggle(name: string): HTMLButtonElement {
    return screen.getByRole('switch', { name }) as HTMLButtonElement;
}

function describedText(element: Element): string {
    return (element.getAttribute('aria-describedby') ?? '')
        .split(' ')
        .filter(Boolean)
        .map((id) => document.getElementById(id)?.textContent ?? '')
        .join(' ');
}

afterEach(() => {
    vi.restoreAllMocks();
    page.props = { auth: { user: { is_client: false } } };
});

describe('/ajustes/notificaciones', () => {
    it('agrupa los eventos en fieldset con su legend y un interruptor por canal con su nombre', () => {
        render(<NotificationSettingsPage settings={settings()} />);

        const tasks = screen.getByRole('group', { name: 'Tareas' });
        expect(tasks.tagName).toBe('FIELDSET');
        expect(
            within(tasks)
                .getAllByRole('switch')
                .map((element) => element.getAttribute('aria-label')),
        ).toEqual([
            'Te asignan una tarea: En la app',
            'Te asignan una tarea: Email',
            'Te asignan una tarea: Avisos del navegador',
            'Tareas que vencen: En la app',
            'Tareas que vencen: Email',
            'Tareas que vencen: Avisos del navegador',
        ]);
        expect(screen.getByRole('group', { name: 'Chat' })).toBeTruthy();
        expect(screen.getByRole('group', { name: 'Sistema' })).toBeTruthy();

        expect(
            toggle('Te asignan una tarea: En la app').getAttribute(
                'aria-checked',
            ),
        ).toBe('true');
        expect(
            toggle('Te asignan una tarea: Email').getAttribute('aria-checked'),
        ).toBe('false');
        expect(
            toggle('Tareas que vencen: Email').getAttribute('aria-checked'),
        ).toBe('true');
        // Nombre y descripción de cada evento a la vista.
        expect(screen.getByText('Tareas que vencen')).toBeTruthy();
        expect(
            screen.getByText('Tus tareas que vencen mañana o ya han vencido.'),
        ).toBeTruthy();
    });

    it('en móvil cada interruptor lleva su canal a la vista (solo se oculta en la rejilla ancha)', () => {
        render(<NotificationSettingsPage settings={settings()} />);

        const row = screen
            .getByText('Tareas que vencen')
            .closest('li') as HTMLElement;
        const email = toggle('Tareas que vencen: Email');
        const label = row.querySelector(
            `label[for="${email.id}"]`,
        ) as HTMLLabelElement;

        expect(label.textContent).toBe('Email');
        expect(label.className).toContain('@lg:sr-only');
        expect(label.className.split(/\s+/)).not.toContain('sr-only');
    });

    it('muestra los obligatorios desactivados, con candado y «Obligatorio»', () => {
        render(<NotificationSettingsPage settings={settings()} />);

        const app = toggle('Espacio en disco: En la app');
        const email = toggle('Espacio en disco: Email');

        expect(app.disabled).toBe(true);
        expect(email.disabled).toBe(true);
        expect(app.getAttribute('aria-checked')).toBe('true');
        expect(describedText(app)).toBe('Obligatorio');

        const mandatory = screen.getByText('Obligatorio');
        expect(mandatory.querySelector('svg.lucide-lock')).toBeTruthy();
        expect(
            mandatory.querySelector('svg')?.getAttribute('aria-hidden'),
        ).toBe('true');
    });

    it('sin Web Push configurado, la columna del navegador está desactivada y lo explica', () => {
        render(<NotificationSettingsPage settings={settings()} />);

        expect(
            screen.queryByRole('switch', {
                name: 'Activar avisos en este navegador',
            }),
        ).toBeNull();

        const note = screen.getByText(/no están activados en este servidor/);
        expect(note).toBeTruthy();

        for (const name of [
            'Te asignan una tarea: Avisos del navegador',
            'Mensajes directos: Avisos del navegador',
            'Espacio en disco: Avisos del navegador',
        ]) {
            const push = toggle(name);
            expect(push.disabled).toBe(true);
            expect(push.getAttribute('aria-checked')).toBe('false');
            expect(describedText(push)).toContain(
                'no están activados en este servidor',
            );
        }
    });

    it('con Web Push configurado, se puede activar donde se ofrece y lo demás dice «no disponible»', () => {
        render(
            <NotificationSettingsPage
                settings={settings({ push_available: true })}
            />,
        );

        expect(
            screen.queryByText(/no están activados en este servidor/),
        ).toBeNull();

        // El interruptor del navegador de la Fase 6 (D-072), con su explicación.
        expect(
            screen.getByRole('switch', {
                name: 'Activar avisos en este navegador',
            }),
        ).toBeTruthy();
        expect(
            screen.getByText(/llegan solo a los navegadores donde los actives/),
        ).toBeTruthy();

        const direct = toggle('Mensajes directos: Avisos del navegador');
        expect(direct.disabled).toBe(false);
        expect(direct.getAttribute('aria-checked')).toBe('true');

        const system = screen.getByRole('group', { name: 'Sistema' });
        expect(
            within(system).queryByRole('switch', {
                name: 'Espacio en disco: Avisos del navegador',
            }),
        ).toBeNull();
        expect(
            within(system).getByText('Avisos del navegador: no disponible'),
        ).toBeTruthy();
    });

    it('explica el resumen diario y avisa de lo que cambia al activarlo', async () => {
        const user = userEvent.setup();
        render(<NotificationSettingsPage settings={settings()} />);

        const digest = toggle('Resumen diario por email');
        expect(digest.getAttribute('aria-checked')).toBe('false');
        expect(describedText(digest)).toBe(
            'Recibirás un solo email a las 08:00 con lo que no hayas leído; mientras esté activo, no recibirás emails sueltos.',
        );
        expect(screen.queryByText(/llegan juntos en el resumen/)).toBeNull();

        await user.click(digest);

        expect(digest.getAttribute('aria-checked')).toBe('true');
        expect(screen.getByText(/llegan juntos en el resumen/)).toBeTruthy();
        expect(screen.getByText('Tienes cambios sin guardar.')).toBeTruthy();
    });

    it('se maneja con el teclado: Tab recorre los interruptores (salta los desactivados) y Espacio o Intro los cambian', async () => {
        const user = userEvent.setup();
        render(<NotificationSettingsPage settings={settings()} />);

        await user.tab();
        expect(document.activeElement).toBe(toggle('Resumen diario por email'));

        await user.tab();
        expect(document.activeElement).toBe(
            toggle('Te asignan una tarea: En la app'),
        );

        await user.tab();
        const email = toggle('Te asignan una tarea: Email');
        expect(document.activeElement).toBe(email);

        await user.keyboard(' ');
        expect(email.getAttribute('aria-checked')).toBe('true');

        await user.keyboard('{Enter}');
        expect(email.getAttribute('aria-checked')).toBe('false');

        // El del navegador está desactivado: el siguiente es «Tareas que vencen: En la app».
        await user.tab();
        expect(document.activeElement).toBe(
            toggle('Tareas que vencen: En la app'),
        );

        // Los obligatorios no reciben el foco: tras el último del chat llega «Guardar».
        await user.tab();
        await user.tab();
        await user.tab();
        expect(document.activeElement).toBe(toggle('Mensajes directos: Email'));
        await user.tab();
        expect(document.activeElement).toBe(
            screen.getByRole('button', { name: 'Guardar' }),
        );
    });

    it('guarda con el formulario de Inertia solo lo que se puede cambiar', async () => {
        const user = userEvent.setup();
        // useForm envía con el router de @inertiajs/core.
        const put = vi.spyOn(coreRouter, 'put').mockImplementation(() => {});
        render(<NotificationSettingsPage settings={settings()} />);

        await user.click(toggle('Tareas que vencen: Email'));
        await user.click(toggle('Te asignan una tarea: Email'));
        await user.click(toggle('Resumen diario por email'));
        await user.click(screen.getByRole('button', { name: 'Guardar' }));

        expect(put).toHaveBeenCalledTimes(1);
        const [url, data, options] = put.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
            { preserveScroll?: boolean },
        ];
        expect(url).toBe('/ajustes/notificaciones');
        expect(data).toEqual({
            events: {
                'task.assigned': { app: true, email: true },
                'task.due': { app: true, email: false },
                'chat.direct': { app: true, email: false },
            },
            daily_digest: true,
        });
        expect(options.preserveScroll).toBe(true);
    });

    it('mientras guarda, el botón se desactiva y lo dice', async () => {
        const user = userEvent.setup();
        vi.spyOn(coreRouter, 'put').mockImplementation(
            (_url, _data, options) => {
                options?.onStart?.({} as never);
            },
        );
        render(<NotificationSettingsPage settings={settings()} />);

        await user.click(screen.getByRole('button', { name: 'Guardar' }));

        const saving = screen.getByRole('button', { name: /Guardando…/ });
        expect((saving as HTMLButtonElement).disabled).toBe(true);
    });

    it('si el servidor rechaza los datos, muestra los errores', async () => {
        const user = userEvent.setup();
        vi.spyOn(coreRouter, 'put').mockImplementation(
            (_url, _data, options) => {
                options?.onError?.({
                    events: 'Los canales solo pueden ser «En la app», «Email» y «Avisos del navegador».',
                });
            },
        );
        render(<NotificationSettingsPage settings={settings()} />);

        await user.click(screen.getByRole('button', { name: 'Guardar' }));

        const alert = screen.getByRole('alert');
        expect(alert.textContent).toContain(
            'No se han podido guardar las preferencias',
        );
        expect(alert.textContent).toContain(
            'Los canales solo pueden ser «En la app», «Email» y «Avisos del navegador».',
        );
    });

    it('el formulario de partida deja fuera los obligatorios y lo que no se ofrece', () => {
        expect(initialForm(settings({ daily_digest: true }))).toEqual({
            events: {
                'task.assigned': { app: true, email: false },
                'task.due': { app: true, email: true },
                'chat.direct': { app: true, email: false },
            },
            daily_digest: true,
        });
        expect(
            initialForm(settings({ push_available: true })).events[
                'chat.direct'
            ],
        ).toEqual({ app: true, email: false, push: true });
    });

    it('dentro de los ajustes, «Ajustes» es el único h1 y la navegación lleva a Notificaciones', () => {
        const { container } = render(
            <SettingsLayout>
                <NotificationSettingsPage settings={settings()} />
            </SettingsLayout>,
        );

        const headings = [
            ...container.querySelectorAll('h1, h2, h3, h4, h5, h6'),
        ].map((heading) => heading.tagName);
        expect(headings).toEqual(['H1', 'H2']);
        expect(container.querySelector('h1')?.textContent).toBe('Ajustes');
        expect(container.querySelector('h2')?.textContent).toBe(
            'Preferencias de notificación',
        );

        const nav = screen.getByRole('navigation', { name: 'Ajustes' });
        const link = within(nav).getByRole('link', { name: 'Notificaciones' });
        expect(link.getAttribute('href')).toBe('/ajustes/notificaciones');
        expect(link.getAttribute('aria-current')).toBe('page');
    });

    it('a un cliente no se le ofrece en la navegación de ajustes', () => {
        page.props = { auth: { user: { is_client: true } } };
        render(
            <SettingsLayout>
                <p>Perfil</p>
            </SettingsLayout>,
        );

        const nav = screen.getByRole('navigation', { name: 'Ajustes' });
        expect(
            within(nav).queryByRole('link', { name: 'Notificaciones' }),
        ).toBeNull();
        expect(
            within(nav).getByRole('link', { name: 'Sesiones' }),
        ).toBeTruthy();
    });
});
