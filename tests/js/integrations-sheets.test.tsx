// @vitest-environment jsdom
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SheetsExportItem } from '@/components/reports/delivery/sheets-export-item';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { settingsNavItems } from '@/layouts/settings/layout';
import Integrations from '@/pages/settings/integrations';
import type { IntegrationsPageProps } from '@/types/integrations';
import type { ReportRequestData } from '@/types/reports';

/*
| Google Sheets (Fase 9, D-142): el item «Google Sheets» del menú «Exportar ▾» (oculto sin
| credenciales; diálogo hacia Integraciones sin conexión; ventana abierta en el clic, antes de la
| petición, y cerrada si falla) y la página Ajustes → Integraciones.
*/

const page = vi.hoisted(() => ({
    url: '/informes/clientes/3',
    props: {
        auth: { user: { is_client: false, is_collaborator: false } },
        integrations: { google_sheets: true, google_connected: true },
    } as Record<string, unknown>,
}));

const router = vi.hoisted(() => ({
    post: vi.fn(),
    delete: vi.fn(),
    visit: vi.fn(),
}));

const toast = vi.hoisted(() => ({
    loading: vi.fn(() => 'toast-1'),
    success: vi.fn(),
    error: vi.fn(),
}));

vi.mock('sonner', () => ({ toast }));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
        router,
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

const request: ReportRequestData = {
    kind: 'client',
    route_params: { client: 3 },
    query: { periodo: 'mes' },
};

function renderMenu() {
    return render(
        <DropdownMenu>
            <DropdownMenuTrigger>Exportar</DropdownMenuTrigger>
            <DropdownMenuContent>
                <SheetsExportItem request={request} />
            </DropdownMenuContent>
        </DropdownMenu>,
    );
}

async function openMenu(user: ReturnType<typeof userEvent.setup>) {
    await user.click(screen.getByRole('button', { name: 'Exportar' }));
}

type FakeWindow = {
    opener: unknown;
    closed: boolean;
    close: ReturnType<typeof vi.fn>;
    location: { href: string };
    document: { title: string; body: { textContent: string } };
};

function fakeWindow(): FakeWindow {
    return {
        opener: 'la app',
        closed: false,
        close: vi.fn(),
        location: { href: 'about:blank' },
        document: { title: '', body: { textContent: '' } },
    };
}

function jsonResponse(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

beforeEach(() => {
    page.props.integrations = { google_sheets: true, google_connected: true };
    document.cookie = 'XSRF-TOKEN=token-xsrf';
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    toast.loading.mockClear();
    toast.success.mockClear();
    toast.error.mockClear();
});

describe('SheetsExportItem', () => {
    it('sin credenciales (o para un colaborador) no aparece', async () => {
        page.props.integrations = {
            google_sheets: false,
            google_connected: false,
        };
        const user = userEvent.setup();
        renderMenu();
        await openMenu(user);

        expect(
            screen.queryByRole('menuitem', { name: 'Google Sheets' }),
        ).toBeNull();
    });

    it('sin la prop compartida tampoco aparece', async () => {
        page.props.integrations = undefined;
        const user = userEvent.setup();
        renderMenu();
        await openMenu(user);

        expect(screen.queryByRole('menuitem')).toBeNull();
    });

    it('sin la cuenta conectada abre un diálogo con el enlace a Integraciones, sin petición ni ventana', async () => {
        page.props.integrations = {
            google_sheets: true,
            google_connected: false,
        };
        const fetchMock = vi.fn();
        const open = vi.spyOn(window, 'open');
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();
        renderMenu();
        await openMenu(user);

        await user.click(
            screen.getByRole('menuitem', { name: 'Google Sheets' }),
        );

        const dialog = await screen.findByRole('dialog', {
            name: 'Conecta tu cuenta de Google',
        });

        expect(dialog.textContent).toContain('Ajustes → Integraciones');
        expect(
            screen
                .getByRole('link', { name: 'Ir a Integraciones' })
                .getAttribute('href'),
        ).toBe('/ajustes/integraciones');
        expect(fetchMock).not.toHaveBeenCalled();
        expect(open).not.toHaveBeenCalled();

        await user.click(screen.getByRole('button', { name: 'Cancelar' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    });

    it('con conexión abre la ventana en el clic, antes de la petición, y le asigna la URL de la hoja', async () => {
        const popup = fakeWindow();
        const calls: string[] = [];
        vi.spyOn(window, 'open').mockImplementation(() => {
            calls.push('open');

            return popup as unknown as Window;
        });
        const fetchMock = vi.fn(async () => {
            calls.push('fetch');

            return jsonResponse(200, {
                url: 'https://docs.google.com/spreadsheets/d/1AbC/edit',
            });
        });
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();
        renderMenu();
        await openMenu(user);

        await user.click(
            screen.getByRole('menuitem', { name: 'Google Sheets' }),
        );

        await waitFor(() =>
            expect(popup.location.href).toBe(
                'https://docs.google.com/spreadsheets/d/1AbC/edit',
            ),
        );
        expect(calls).toEqual(['open', 'fetch']);
        expect(window.open).toHaveBeenCalledWith('', '_blank');
        // Sin opener: la página de Google no puede tocar la app.
        expect(popup.opener).toBeNull();
        expect(popup.document.body.textContent).toBe('Creando la hoja…');
        expect(toast.loading).toHaveBeenCalledWith('Creando la hoja…');
        expect(toast.success).toHaveBeenCalledWith('Hoja creada en tu Drive.', {
            id: 'toast-1',
        });
        expect(popup.close).not.toHaveBeenCalled();

        const [url, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];

        expect(url).toBe('/informes/sheets');
        expect(init.method).toBe('POST');
        expect(JSON.parse(String(init.body))).toEqual(request);
        expect(init.headers).toMatchObject({
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': 'token-xsrf',
        });
    });

    it('si falla, cierra la ventana y muestra el error del servidor', async () => {
        const popup = fakeWindow();
        vi.spyOn(window, 'open').mockReturnValue(popup as unknown as Window);
        vi.stubGlobal(
            'fetch',
            vi.fn(async () =>
                jsonResponse(502, {
                    message: 'Google no responde ahora mismo.',
                }),
            ),
        );
        const user = userEvent.setup();
        renderMenu();
        await openMenu(user);

        await user.click(
            screen.getByRole('menuitem', { name: 'Google Sheets' }),
        );

        await waitFor(() => expect(popup.close).toHaveBeenCalled());
        expect(popup.location.href).toBe('about:blank');
        expect(toast.error).toHaveBeenCalledWith(
            'Google no responde ahora mismo.',
            { id: 'toast-1' },
        );
    });

    it('si la conexión ya no vale (409), el aviso lleva a Integraciones', async () => {
        const popup = fakeWindow();
        vi.spyOn(window, 'open').mockReturnValue(popup as unknown as Window);
        vi.stubGlobal(
            'fetch',
            vi.fn(async () =>
                jsonResponse(409, {
                    message:
                        'Conecta tu cuenta de Google en Ajustes → Integraciones.',
                }),
            ),
        );
        const user = userEvent.setup();
        renderMenu();
        await openMenu(user);

        await user.click(
            screen.getByRole('menuitem', { name: 'Google Sheets' }),
        );

        await waitFor(() => expect(toast.error).toHaveBeenCalled());
        expect(popup.close).toHaveBeenCalled();

        const [message, options] = toast.error.mock.calls[0] as unknown as [
            string,
            { action: { label: string; onClick: () => void } },
        ];

        expect(message).toBe(
            'Conecta tu cuenta de Google en Ajustes → Integraciones.',
        );
        expect(options.action.label).toBe('Ir a Integraciones');

        act(() => options.action.onClick());
        expect(router.visit).toHaveBeenCalledWith('/ajustes/integraciones');
    });

    it('si el navegador bloquea la ventana, el enlace queda en el aviso', async () => {
        vi.spyOn(window, 'open').mockReturnValue(null);
        vi.stubGlobal(
            'fetch',
            vi.fn(async () =>
                jsonResponse(200, {
                    url: 'https://docs.google.com/spreadsheets/d/1AbC/edit',
                }),
            ),
        );
        const user = userEvent.setup();
        renderMenu();
        await openMenu(user);

        await user.click(
            screen.getByRole('menuitem', { name: 'Google Sheets' }),
        );

        await waitFor(() => expect(toast.success).toHaveBeenCalled());

        const [, options] = toast.success.mock.calls[0] as unknown as [
            string,
            { action: { label: string; onClick: () => void } },
        ];

        expect(options.action.label).toBe('Abrir');

        options.action.onClick();
        expect(window.open).toHaveBeenLastCalledWith(
            'https://docs.google.com/spreadsheets/d/1AbC/edit',
            '_blank',
            'noopener',
        );
    });

    it('sin red, cierra la ventana y da un error genérico', async () => {
        const popup = fakeWindow();
        vi.spyOn(window, 'open').mockReturnValue(popup as unknown as Window);
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => {
                throw new TypeError('Failed to fetch');
            }),
        );
        const user = userEvent.setup();
        renderMenu();
        await openMenu(user);

        await user.click(
            screen.getByRole('menuitem', { name: 'Google Sheets' }),
        );

        await waitFor(() =>
            expect(toast.error).toHaveBeenCalledWith(
                'No se ha podido crear la hoja de cálculo.',
                { id: 'toast-1' },
            ),
        );
        expect(popup.close).toHaveBeenCalled();
    });
});

describe('Ajustes → Integraciones', () => {
    function renderPage(google: IntegrationsPageProps['google']) {
        return render(<Integrations google={google} />);
    }

    beforeEach(() => {
        router.post.mockClear();
        router.delete.mockClear();
    });

    it('sin credenciales dice que no está disponible y no ofrece conectar', () => {
        renderPage({ available: false, connection: null });

        expect(screen.getByRole('status').textContent).toContain(
            'Google Sheets no está disponible',
        );
        expect(
            screen.queryByRole('button', { name: 'Conectar con Google' }),
        ).toBeNull();
    });

    it('sin conexión, «Conectar con Google» hace POST para iniciar OAuth', async () => {
        const user = userEvent.setup();
        renderPage({ available: true, connection: null });

        expect(
            screen.getByRole('heading', { level: 3, name: 'Google Sheets' }),
        ).toBeTruthy();
        expect(
            screen.getByText(
                /solo puede ver y cambiar los archivos que crea ella/,
            ),
        ).toBeTruthy();

        await user.click(
            screen.getByRole('button', { name: 'Conectar con Google' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            '/integraciones/google/conectar',
            {},
            expect.any(Object),
        );
    });

    it('con conexión muestra la cuenta y desconecta tras confirmar', async () => {
        const user = userEvent.setup();
        renderPage({
            available: true,
            connection: {
                email: 'elena@audaxstudio.com',
                connected_at: '2026-10-03T08:00:00Z',
            },
        });

        expect(
            screen.getByText('Conectada como elena@audaxstudio.com'),
        ).toBeTruthy();
        expect(screen.getByText('Desde el 03/10/2026')).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: 'Conectar con Google' }),
        ).toBeNull();

        await user.click(screen.getByRole('button', { name: 'Desconectar' }));

        const dialog = await screen.findByRole('dialog', {
            name: '¿Desconectar tu cuenta de Google?',
        });

        expect(router.delete).not.toHaveBeenCalled();

        await user.click(
            Array.from(dialog.querySelectorAll('button')).find(
                (button) => button.textContent === 'Desconectar',
            ) as HTMLButtonElement,
        );

        expect(router.delete).toHaveBeenCalledWith(
            '/integraciones/google',
            expect.any(Object),
        );
    });
});

describe('navegación de Ajustes', () => {
    const titles = (isClient: boolean, isCollaborator: boolean) =>
        settingsNavItems(isClient, isCollaborator).map((item) => item.title);

    it('la plantilla tiene Integraciones; el portal y los colaboradores externos no', () => {
        expect(titles(false, false)).toContain('Integraciones');
        expect(titles(true, false)).not.toContain('Integraciones');
        expect(titles(false, true)).not.toContain('Integraciones');
    });
});
