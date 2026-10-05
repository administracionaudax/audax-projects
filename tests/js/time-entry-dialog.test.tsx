// @vitest-environment jsdom
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TimeEntryDialog } from '@/components/time/time-entry-dialog';
import type { TimeEntry, User } from '@/types';

type VisitOptions = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

const server = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
}));

const me: User = {
    id: 7,
    name: 'Ana García',
    email: 'ana@audaxstudio.com',
    avatar: null,
    theme_preference: 'system',
    two_factor_enabled: false,
    roles: ['employee'],
    is_client: false,
};

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({ url: '/horas', props: { auth: { user: me, can: {} } } }),
    router: {
        post: (...args: unknown[]) => server.post(...args),
        put: (...args: unknown[]) => server.put(...args),
        delete: (...args: unknown[]) => server.delete(...args),
        on: () => () => {},
    },
}));

function jsonResponse(body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}

const fetchMock = vi.fn<typeof fetch>();

function requestUrl(input: RequestInfo | URL): string {
    if (typeof input === 'string') {
        return input;
    }

    return input instanceof URL ? input.href : input.url;
}

function options(people = [me]) {
    return {
        people: people.map((person) => ({
            id: person.id,
            name: person.name,
            avatar: null,
            department_id: null,
            is_active: true,
        })),
        settings: {
            today: '2026-09-25',
            allow_future: false,
            description_required: false,
        },
    };
}

beforeEach(() => {
    server.post.mockReset();
    server.put.mockReset();
    server.delete.mockReset();
    fetchMock.mockReset();
    fetchMock.mockImplementation(async (input) => {
        const url = requestUrl(input);

        if (url.startsWith('/horas/opciones')) {
            return jsonResponse(options());
        }

        return jsonResponse({ tasks: [] });
    });
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
});

const task = { id: 12, title: 'Maquetar la home', project_id: 3 };

const entry: TimeEntry = {
    id: 99,
    user_id: 7,
    task: { id: 12, title: 'Maquetar la home' },
    task_id: 12,
    project: { id: 3, code: 'ACME-WEB', name: 'Web', color: '#0171FF' },
    project_id: 3,
    hour_bank_id: null,
    date: '2026-09-24',
    minutes: 90,
    overage_minutes: 0,
    in_bank_minutes: 90,
    started_at: null,
    ended_at: null,
    description: 'Cabecera',
    is_billable: true,
    status: 'draft',
    approved_at: null,
    created_by: 7,
    logged_on_behalf: false,
};

describe('diálogo de imputación', () => {
    it('crea una entrada con la tarea, la fecha, la duración y la descripción', async () => {
        const user = userEvent.setup();
        const onOpenChange = vi.fn();
        server.post.mockImplementation(
            (_url: string, _data: unknown, visit: VisitOptions) => {
                visit.onStart?.();
                visit.onSuccess?.();
                visit.onFinish?.();
            },
        );

        render(
            <TimeEntryDialog
                open
                onOpenChange={onOpenChange}
                task={task}
                date="2026-09-24"
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Añadir horas' }),
        ).toBeTruthy();
        expect(
            screen.getByRole('combobox', { name: 'Tarea' }).textContent,
        ).toContain('Maquetar la home');

        await user.type(screen.getByLabelText('Duración'), '1h30');
        expect(screen.getByText('= 1:30')).toBeTruthy();
        await user.type(
            screen.getByLabelText('Descripción (opcional)'),
            ' Cabecera y menú ',
        );
        await user.click(screen.getByRole('button', { name: 'Guardar horas' }));

        expect(server.post).toHaveBeenCalledTimes(1);
        const [url, data, visit] = server.post.mock.calls[0] as [
            string,
            Record<string, unknown>,
            VisitOptions & { errorBag: string },
        ];
        expect(url).toBe('/horas/entradas');
        expect(data).toEqual({
            task_id: 12,
            user_id: 7,
            date: '2026-09-24',
            minutes: 90,
            description: 'Cabecera y menú',
        });
        expect(visit.errorBag).toBe('timeEntry');
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('valida antes de enviar: sin duración no se guarda', async () => {
        const user = userEvent.setup();

        render(<TimeEntryDialog open onOpenChange={vi.fn()} task={task} />);

        await user.click(screen.getByRole('button', { name: 'Guardar horas' }));

        expect(
            screen.getByText('Escribe la duración, por ejemplo 1:30.'),
        ).toBeTruthy();
        expect(server.post).not.toHaveBeenCalled();
    });

    it('propone la duración escrita en la hoja semanal (celda que necesita descripción)', async () => {
        const user = userEvent.setup();
        server.post.mockImplementation(
            (_url: string, _data: unknown, visit: VisitOptions) => {
                visit.onSuccess?.();
                visit.onFinish?.();
            },
        );

        render(
            <TimeEntryDialog
                open
                onOpenChange={vi.fn()}
                task={task}
                date="2026-09-23"
                minutes={90}
            />,
        );

        expect(
            (screen.getByLabelText('Duración') as HTMLInputElement).value,
        ).toBe('1:30');
        await user.type(
            screen.getByLabelText('Descripción (opcional)'),
            'Cabecera',
        );
        await user.click(screen.getByRole('button', { name: 'Guardar horas' }));

        expect(server.post.mock.calls[0][1]).toEqual({
            task_id: 12,
            user_id: 7,
            date: '2026-09-23',
            minutes: 90,
            description: 'Cabecera',
        });
    });

    it('sin tarea pide elegirla', async () => {
        const user = userEvent.setup();

        render(<TimeEntryDialog open onOpenChange={vi.fn()} />);

        await user.type(screen.getByLabelText('Duración'), '2');
        await user.click(screen.getByRole('button', { name: 'Guardar horas' }));

        expect(screen.getByText('Elige la tarea.')).toBeTruthy();
        expect(server.post).not.toHaveBeenCalled();
    });

    it('muestra los errores del servidor junto a su campo y los generales arriba', async () => {
        const user = userEvent.setup();
        server.post.mockImplementation(
            (_url: string, _data: unknown, visit: VisitOptions) => {
                visit.onError?.({
                    minutes:
                        'La bolsa «Q3» no admite exceso. Saldo disponible: 0:30.',
                    date: 'La semana del 21/09/2026 está enviada. Hay que reabrirla para cambiar sus horas.',
                    timer: 'Algo general',
                });
                visit.onFinish?.();
            },
        );

        render(<TimeEntryDialog open onOpenChange={vi.fn()} task={task} />);

        await user.type(screen.getByLabelText('Duración'), '1:00');
        await user.click(screen.getByRole('button', { name: 'Guardar horas' }));

        expect(
            screen.getByText(
                'La bolsa «Q3» no admite exceso. Saldo disponible: 0:30.',
            ),
        ).toBeTruthy();
        expect(screen.getByText(/La semana del 21\/09\/2026/u)).toBeTruthy();
        expect(screen.getByText('Algo general')).toBeTruthy();
    });

    it('edita una entrada (PUT) y la borra tras confirmar', async () => {
        const user = userEvent.setup();

        render(<TimeEntryDialog open onOpenChange={vi.fn()} entry={entry} />);

        expect(
            screen.getByRole('heading', { name: 'Editar horas' }),
        ).toBeTruthy();
        const duration = screen.getByLabelText('Duración') as HTMLInputElement;
        expect(duration.value).toBe('1:30');

        await user.clear(duration);
        await user.type(duration, '2');
        await user.click(
            screen.getByRole('button', { name: 'Guardar cambios' }),
        );

        expect(server.put).toHaveBeenCalledTimes(1);
        const [url, data] = server.put.mock.calls[0] as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/horas/entradas/99');
        expect(data).toMatchObject({
            task_id: 12,
            user_id: 7,
            date: '2026-09-24',
            minutes: 120,
            description: 'Cabecera',
            is_billable: true,
        });

        await user.click(
            screen.getByRole('button', { name: 'Eliminar la entrada' }),
        );
        expect(screen.getByText('¿Eliminar esta entrada?')).toBeTruthy();
        await user.click(screen.getByRole('button', { name: 'Eliminar' }));

        expect(server.delete).toHaveBeenCalledWith(
            '/horas/entradas/99',
            expect.objectContaining({ errorBag: 'timeEntry' }),
        );
    });

    it('solo muestra el selector de persona si puede imputar por otras', async () => {
        const { unmount } = render(
            <TimeEntryDialog open onOpenChange={vi.fn()} task={task} />,
        );

        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(screen.queryByText('Persona')).toBeNull();
        unmount();

        fetchMock.mockImplementation(async (input) =>
            requestUrl(input).startsWith('/horas/opciones')
                ? jsonResponse(
                      options([me, { ...me, id: 8, name: 'Pablo Ruiz' }]),
                  )
                : jsonResponse({ tasks: [] }),
        );

        render(<TimeEntryDialog open onOpenChange={vi.fn()} task={task} />);

        expect(await screen.findByText('Persona')).toBeTruthy();
        expect(
            screen.getByRole('combobox', { name: 'Persona' }).textContent,
        ).toContain('Ana García (tú)');
    });

    it('en nombre de otra persona envía su id', async () => {
        const user = userEvent.setup();

        render(
            <TimeEntryDialog
                open
                onOpenChange={vi.fn()}
                task={task}
                userId={8}
            />,
        );

        await user.type(screen.getByLabelText('Duración'), '45m');
        await user.click(screen.getByRole('button', { name: 'Guardar horas' }));

        expect(
            (server.post.mock.calls[0] as [string, Record<string, unknown>])[1],
        ).toMatchObject({ user_id: 8, minutes: 45 });
    });

    it('con hora de inicio y fin calcula la duración y envía la franja (D-162)', async () => {
        const user = userEvent.setup();
        server.post.mockImplementation(
            (_url: string, _data: unknown, visit: VisitOptions) => {
                visit.onSuccess?.();
                visit.onFinish?.();
            },
        );

        render(
            <TimeEntryDialog
                open
                onOpenChange={vi.fn()}
                task={task}
                date="2026-09-24"
            />,
        );

        await user.click(
            screen.getByRole('radio', { name: 'Con hora de inicio y fin' }),
        );
        expect(screen.queryByLabelText('Duración')).toBeNull();
        fireEvent.change(screen.getByLabelText('Inicio'), {
            target: { value: '09:00' },
        });
        fireEvent.change(screen.getByLabelText('Fin'), {
            target: { value: '11:30' },
        });
        expect(screen.getByText('Duración: 2:30')).toBeTruthy();

        await user.click(screen.getByRole('button', { name: 'Guardar horas' }));

        expect(server.post.mock.calls[0][1]).toEqual({
            task_id: 12,
            user_id: 7,
            date: '2026-09-24',
            minutes: null,
            start_time: '09:00',
            end_time: '11:30',
            description: null,
        });
    });

    it('no envía una franja que cruza la medianoche y explica cómo registrarla', async () => {
        const user = userEvent.setup();

        render(<TimeEntryDialog open onOpenChange={vi.fn()} task={task} />);

        await user.click(
            screen.getByRole('radio', { name: 'Con hora de inicio y fin' }),
        );
        await user.click(screen.getByRole('button', { name: 'Guardar horas' }));
        expect(screen.getByText('Escribe la hora de inicio.')).toBeTruthy();
        expect(screen.getByText('Escribe la hora de fin.')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Inicio'), {
            target: { value: '22:00' },
        });
        fireEvent.change(screen.getByLabelText('Fin'), {
            target: { value: '02:00' },
        });
        await user.click(screen.getByRole('button', { name: 'Guardar horas' }));

        expect(
            screen.getByText(/regístralo en dos entradas: hasta/u),
        ).toBeTruthy();
        expect(server.post).not.toHaveBeenCalled();
    });

    it('al editar una entrada con franja exacta abre en modo franja con sus horas (Madrid)', () => {
        render(
            <TimeEntryDialog
                open
                onOpenChange={vi.fn()}
                entry={{
                    ...entry,
                    minutes: 90,
                    started_at: '2026-09-24T07:00:00Z',
                    ended_at: '2026-09-24T08:30:00Z',
                }}
            />,
        );

        expect(
            screen
                .getByRole('radio', { name: 'Con hora de inicio y fin' })
                .getAttribute('aria-checked'),
        ).toBe('true');
        expect(
            (screen.getByLabelText('Inicio') as HTMLInputElement).value,
        ).toBe('09:00');
        expect((screen.getByLabelText('Fin') as HTMLInputElement).value).toBe(
            '10:30',
        );
    });

    it('una entrada del temporizador (minutos redondeados) se edita por duración', () => {
        render(
            <TimeEntryDialog
                open
                onOpenChange={vi.fn()}
                entry={{
                    ...entry,
                    minutes: 90,
                    started_at: '2026-09-24T07:00:00Z',
                    ended_at: '2026-09-24T08:27:00Z',
                }}
            />,
        );

        expect(
            (screen.getByLabelText('Duración') as HTMLInputElement).value,
        ).toBe('1:30');
    });
});
