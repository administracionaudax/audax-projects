// @vitest-environment jsdom
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({
    props: {
        config: { max_audio_seconds: 60 },
        auth: { user: { id: 7 } },
        realtime: null,
    } as Record<string, unknown>,
}));

const router = vi.hoisted(() => ({
    post: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        [key: string]: unknown;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

import {
    draftOf,
    MyWeeklyEditor,
} from '@/components/weeklies/my-weekly-editor';
import { AUTOSAVE_DELAY_MS } from '@/components/weeklies/use-weekly-autosave';
import type { MyWeeklyEditor as Editor } from '@/types/weeklies';

const fetchMock = vi.fn<typeof fetch>();

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

const cycle = {
    id: 12,
    number: 'W41-26',
    label: 'Semana 41 (Lun 05/10 - Vie 09/10)',
    start_date: '2026-10-05',
    end_date: '2026-10-09',
    deadline_date: '2026-10-09',
    status: 'active' as const,
    has_report: false,
    report_state: null,
    report_generated_at: null,
    audio_state: null,
    submission_count_at_generation: null,
    closed_at: null,
};

const me = {
    status: 'pending' as const,
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
};

function editor(overrides: Partial<Editor> = {}): Editor {
    return {
        cycle,
        me,
        submission: null,
        clients: {
            proposed: [3],
            catalog: [
                {
                    id: 3,
                    name: 'Ferretería Ruiz',
                    icon: '🔧',
                    is_active: true,
                    projects: [
                        {
                            id: 30,
                            code: 'FER-WEB',
                            name: 'Web',
                            is_mine: true,
                        },
                    ],
                },
                {
                    id: 4,
                    name: 'Hoteles Mirador',
                    icon: null,
                    is_active: true,
                    projects: [],
                },
            ],
        },
        autofill: { '3': '✅ Hecha: Revisar la home (1 h)' },
        read_only: false,
        can: { write: true, waive: false, undo_waiver: false },
        ...overrides,
    };
}

function submission(body: string) {
    return {
        id: 99,
        weekly_cycle_id: 12,
        user_id: 7,
        is_submitted: false,
        submitted_at: null,
        resubmitted_at: null,
        draft_saved_at: '2026-10-05T09:15:00Z',
        entries: [
            {
                id: 1,
                client_id: 3,
                client: { id: 3, name: 'Ferretería Ruiz', icon: '🔧' },
                project_id: null,
                body,
                source: 'text' as const,
                position: 0,
            },
        ],
    };
}

function box(name: RegExp | string) {
    return screen.getByRole('region', { name });
}

function autosaveStatus() {
    return document.querySelector('[data-test="weekly-autosave"]');
}

describe('Mi weekly: una caja por cliente con autoguardado (F-044 y F-051)', () => {
    beforeEach(() => {
        vi.useFakeTimers({
            toFake: [
                'setTimeout',
                'clearTimeout',
                'setInterval',
                'clearInterval',
                'Date',
            ],
        });
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
        router.post.mockReset();
        router.delete.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('propone una caja por cliente más «General / Interno», con la primera desplegada', () => {
        render(<MyWeeklyEditor editor={editor()} />);

        const ruiz = box(/Ferretería Ruiz/);
        const general = box(/General \/ Interno/);

        expect(
            within(ruiz)
                .getByRole('button', { name: /Ferretería Ruiz/ })
                .getAttribute('aria-expanded'),
        ).toBe('true');
        expect(
            within(general)
                .getByRole('button', { name: /General \/ Interno/ })
                .getAttribute('aria-expanded'),
        ).toBe('false');
        expect(within(ruiz).getByLabelText('Avances')).toBeTruthy();
        expect(within(ruiz).getByLabelText('Proyecto (opcional)')).toBeTruthy();
        // No se guarda nada al abrir: lo que llega ya está guardado.
        act(() => {
            vi.advanceTimersByTime(AUTOSAVE_DELAY_MS * 3);
        });
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('guarda el borrador 700 ms después de dejar de escribir y dice «Guardado»', async () => {
        fetchMock.mockResolvedValue(
            json({ submission: submission('Reunión de arranque.') }),
        );
        render(<MyWeeklyEditor editor={editor()} />);

        fireEvent.change(
            within(box(/Ferretería Ruiz/)).getByLabelText('Avances'),
            {
                target: { value: 'Reunión' },
            },
        );
        act(() => {
            vi.advanceTimersByTime(300);
        });
        fireEvent.change(
            within(box(/Ferretería Ruiz/)).getByLabelText('Avances'),
            {
                target: { value: 'Reunión de arranque.' },
            },
        );

        expect(autosaveStatus()?.textContent).toBe('Guardando…');
        act(() => {
            vi.advanceTimersByTime(AUTOSAVE_DELAY_MS - 1);
        });
        expect(fetchMock).not.toHaveBeenCalled();

        await act(async () => {
            vi.advanceTimersByTime(1);
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/mi-espacio/weeklies/12');
        expect(init?.method).toBe('PUT');
        expect(JSON.parse(init?.body as string)).toEqual({
            entries: [
                {
                    client_id: 3,
                    project_id: null,
                    body: 'Reunión de arranque.',
                    source: 'text',
                },
            ],
        });
        expect(autosaveStatus()?.getAttribute('data-status')).toBe('saved');
        expect(autosaveStatus()?.textContent).toMatch(
            /^Guardado el 05\/10\/2026/,
        );
        expect(screen.getByText('1 cliente con texto')).toBeTruthy();
    });

    it('si no se puede guardar, lo dice y deja reintentar', async () => {
        fetchMock.mockResolvedValueOnce(
            json(
                {
                    message: 'La semana ya está cerrada.',
                    errors: { entries: ['La semana ya está cerrada.'] },
                },
                422,
            ),
        );
        render(<MyWeeklyEditor editor={editor()} />);

        fireEvent.change(
            within(box(/Ferretería Ruiz/)).getByLabelText('Avances'),
            {
                target: { value: 'Texto' },
            },
        );
        await act(async () => {
            vi.advanceTimersByTime(AUTOSAVE_DELAY_MS);
        });

        expect(autosaveStatus()?.getAttribute('data-status')).toBe('error');
        expect(autosaveStatus()?.textContent).toContain(
            'La semana ya está cerrada.',
        );

        fetchMock.mockResolvedValueOnce(
            json({ submission: submission('Texto') }),
        );
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }));
        });

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(autosaveStatus()?.getAttribute('data-status')).toBe('saved');
    });

    it('añade otro cliente con el buscador y autocompleta desde mis tareas', async () => {
        fetchMock.mockResolvedValue(json({ submission: submission('x') }));
        render(<MyWeeklyEditor editor={editor()} />);

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Autocompletar desde mis tareas',
            }),
        );
        expect(
            (
                within(box(/Ferretería Ruiz/)).getByLabelText(
                    'Avances',
                ) as HTMLTextAreaElement
            ).value,
        ).toBe('✅ Hecha: Revisar la home (1 h)');

        fireEvent.click(
            screen.getByRole('button', { name: 'Añadir otro cliente' }),
        );
        fireEvent.click(
            screen.getByRole('option', { name: /Hoteles Mirador/ }),
        );

        const regions = screen
            .getAllByRole('region')
            .map((region) => region.getAttribute('data-test'));
        // El añadido va antes de «General / Interno», que siempre cierra la lista.
        expect(regions).toEqual([
            'weekly-entry-3',
            'weekly-entry-4',
            'weekly-entry-general',
        ]);
        expect(
            within(box(/Hoteles Mirador/)).getByRole('button', {
                name: 'Quitar Hoteles Mirador de mi weekly',
            }),
        ).toBeTruthy();
    });

    it('plegar y desplegar todo (F-046)', () => {
        render(<MyWeeklyEditor editor={editor()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Desplegar todo' }));
        expect(
            screen
                .getAllByRole('button', { expanded: true })
                .filter(
                    (button) => button.dataset.test === 'weekly-entry-toggle',
                ),
        ).toHaveLength(2);

        fireEvent.click(screen.getByRole('button', { name: 'Plegar todo' }));
        expect(
            screen.queryAllByRole('button', { expanded: true }),
        ).toHaveLength(0);
    });

    it('envía con los apuntes y, ya enviada, el botón es «Actualizar weekly»', () => {
        const { rerender } = render(
            <MyWeeklyEditor
                editor={editor({ submission: submission('Hecho el diseño.') })}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Enviar weekly' }));

        expect(router.post).toHaveBeenCalledWith(
            '/mi-espacio/weeklies/12/enviar',
            {
                entries: [
                    {
                        client_id: 3,
                        project_id: null,
                        body: 'Hecho el diseño.',
                        source: 'text',
                    },
                ],
            },
            expect.objectContaining({ preserveScroll: true }),
        );

        rerender(
            <MyWeeklyEditor
                editor={editor({
                    submission: {
                        ...submission('Hecho el diseño.'),
                        is_submitted: true,
                        submitted_at: '2026-10-08T10:00:00Z',
                    },
                })}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Actualizar weekly' }),
        ).toBeTruthy();
    });

    it('exento: aviso con la racha y «Quitar mi exención y escribir» (F-032, F-053 y F-054)', () => {
        render(
            <MyWeeklyEditor
                editor={editor({
                    me: {
                        ...me,
                        status: 'exempt',
                        must_submit: false,
                        exemption_reason: 'absence',
                    },
                    read_only: true,
                    can: { write: false, waive: true, undo_waiver: false },
                })}
            />,
        );

        expect(
            screen.getByText('Estás exento de esta weekly por tu ausencia'),
        ).toBeTruthy();
        expect(screen.getByText(/Tu racha no se romperá/)).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: 'Enviar weekly' }),
        ).toBeNull();
        expect(screen.queryByLabelText('Avances')).toBeNull();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Quitar mi exención y escribir',
            }),
        );
        expect(router.post).toHaveBeenCalledWith(
            '/weeklies/12/exenciones/renuncia',
            {},
            expect.anything(),
        );
    });

    it('con la semana cerrada es de solo lectura y enseña lo enviado (F-043)', () => {
        render(
            <MyWeeklyEditor
                editor={editor({
                    cycle: { ...cycle, status: 'closed' },
                    me: { ...me, status: 'submitted' },
                    submission: {
                        ...submission('Entregado el logotipo.'),
                        is_submitted: true,
                        submitted_at: '2026-10-08T10:00:00Z',
                    },
                    clients: { proposed: [], catalog: [] },
                    autofill: {},
                    read_only: true,
                    can: { write: false, waive: false, undo_waiver: false },
                })}
            />,
        );

        expect(
            screen.getByText('Esta semana ya está cerrada: solo lectura.'),
        ).toBeTruthy();
        expect(screen.getByText('Entregado el logotipo.')).toBeTruthy();
        expect(screen.queryByRole('textbox')).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Añadir otro cliente' }),
        ).toBeNull();
        expect(screen.queryByRole('region', { name: /General/ })).toBeNull();
        act(() => {
            vi.advanceTimersByTime(AUTOSAVE_DELAY_MS * 2);
        });
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('draftOf: solo los apuntes con texto, en el orden de las cajas', () => {
        expect(
            draftOf(['3', '4', 'general'], {
                '3': { body: '  ', project_id: null, source: 'text' },
                '4': { body: 'Hoteles', project_id: 40, source: 'dictation' },
                general: {
                    body: 'Formación',
                    project_id: null,
                    source: 'text',
                },
            }),
        ).toEqual({
            entries: [
                {
                    client_id: 4,
                    project_id: 40,
                    body: 'Hoteles',
                    source: 'dictation',
                },
                {
                    client_id: null,
                    project_id: null,
                    body: 'Formación',
                    source: 'text',
                },
            ],
        });
    });
});
