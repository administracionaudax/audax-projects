// @vitest-environment jsdom
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { GlobalSearchProvider } from '@/components/global-search';

const visit = vi.fn();

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: { visit: (...args: unknown[]) => visit(...args) },
}));

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
    fetchMock.mockReset();
    visit.mockReset();
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

function renderSearch() {
    return render(
        <GlobalSearchProvider>
            <p>Contenido</p>
        </GlobalSearchProvider>,
    );
}

function requestedUrls(): string[] {
    return fetchMock.mock.calls.map(([input]) =>
        typeof input === 'string'
            ? input
            : input instanceof URL
              ? input.href
              : input.url,
    );
}

describe('búsqueda global', () => {
    it('se abre con Ctrl+K', async () => {
        renderSearch();
        expect(screen.queryByRole('dialog')).toBeNull();

        fireEvent.keyDown(document, { key: 'k', ctrlKey: true });

        expect(await screen.findByRole('dialog')).toBeTruthy();
        expect(
            screen.getByText('Escribe al menos 2 caracteres para buscar.'),
        ).toBeTruthy();
    });

    it('se abre con Cmd+K y se cierra con el mismo atajo', async () => {
        renderSearch();

        fireEvent.keyDown(document, { key: 'k', metaKey: true });
        expect(await screen.findByRole('dialog')).toBeTruthy();

        fireEvent.keyDown(document, { key: 'K', metaKey: true });
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    });

    it('no reacciona a K sin modificador', () => {
        renderSearch();
        fireEvent.keyDown(document, { key: 'k' });

        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('consulta /buscar a partir de 2 caracteres, con debounce', async () => {
        const user = userEvent.setup();
        fetchMock.mockResolvedValue(
            jsonResponse({
                results: [
                    {
                        type: 'page',
                        id: 'projects',
                        title: 'Proyectos',
                        subtitle: null,
                        url: '/proyectos',
                    },
                    {
                        type: 'person',
                        id: 7,
                        title: 'Ana García',
                        subtitle: 'Diseño',
                        url: '/personas/7',
                    },
                ],
            }),
        );

        renderSearch();
        fireEvent.keyDown(document, { key: 'k', ctrlKey: true });
        const input = await screen.findByRole('combobox');

        await user.type(input, 'p');
        await act(() => new Promise((resolve) => setTimeout(resolve, 300)));
        expect(fetchMock).not.toHaveBeenCalled();

        await user.type(input, 'ro');

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(requestedUrls()).toEqual(['/buscar?q=pro']);

        const [, init] = fetchMock.mock.calls[0];
        expect(init?.signal).toBeInstanceOf(AbortSignal);
        expect(new Headers(init?.headers).get('Accept')).toBe(
            'application/json',
        );

        expect(await screen.findByText('Páginas')).toBeTruthy();
        expect(screen.getByText('Personas')).toBeTruthy();
        expect(screen.getByText('Ana García')).toBeTruthy();

        await user.keyboard('{ArrowDown}{Enter}');
        expect(visit).toHaveBeenCalledWith('/personas/7');
    });

    it('cancela la petición anterior si se sigue escribiendo', async () => {
        const signals: AbortSignal[] = [];
        fetchMock.mockImplementation((_input, init) => {
            signals.push(init!.signal!);

            return new Promise<Response>(() => {});
        });

        const user = userEvent.setup();
        renderSearch();
        fireEvent.keyDown(document, { key: 'k', ctrlKey: true });
        const input = await screen.findByRole('combobox');

        await user.type(input, 'ab');
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        await user.type(input, 'c');
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));

        expect(requestedUrls()).toEqual(['/buscar?q=ab', '/buscar?q=abc']);
        expect(signals[0].aborted).toBe(true);
        expect(signals[1].aborted).toBe(false);
        expect(screen.getByText('Buscando…')).toBeTruthy();
    });

    it('muestra el estado vacío y el de error en español', async () => {
        const user = userEvent.setup();
        fetchMock
            .mockResolvedValueOnce(jsonResponse({ results: [] }))
            .mockResolvedValueOnce(jsonResponse({ message: 'Error' }, 500));

        renderSearch();
        fireEvent.keyDown(document, { key: 'k', ctrlKey: true });
        const input = await screen.findByRole('combobox');

        await user.type(input, 'zz');
        expect(
            await screen.findByText('No hay resultados para «zz».'),
        ).toBeTruthy();

        await user.type(input, 'z');
        expect(
            await screen.findByText(
                'No se ha podido completar la búsqueda. Inténtalo de nuevo.',
            ),
        ).toBeTruthy();
    });
});
