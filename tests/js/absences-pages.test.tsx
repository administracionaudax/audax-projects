// @vitest-environment jsdom
import { router as coreRouter } from '@inertiajs/core';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AbsenceDialog } from '@/components/absences/absence-dialog';
import { MyAbsencesCard } from '@/components/absences/my-absences-card';
import { PendingAbsencesNotice } from '@/components/absences/pending-absences-notice';
import { TeamCalendarView } from '@/components/absences/team-calendar';
import type {
    AbsenceRow,
    MyAbsencesPageProps,
    PendingAbsence,
    TeamAbsencesPageProps,
    TeamCalendar,
} from '@/components/absences/types';
import MyAbsences from '@/pages/absences/index';
import TeamAbsences from '@/pages/absences/team';

const page = vi.hoisted(() => ({
    url: '/ausencias',
    props: {} as Record<string, unknown>,
}));

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => page,
    router: { get: inertia.get, post: inertia.post, on: () => () => {} },
    Link: ({
        href,
        children,
        preserveScroll: _preserveScroll,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        preserveScroll?: boolean;
        [key: string]: unknown;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

const TODAY = '2026-09-24';
const LIMITS = { from: '2024-09-24', to: '2028-09-24' };
const TYPES: MyAbsencesPageProps['types'] = [
    'vacation',
    'sick',
    'leave',
    'training',
    'other',
];

function row(overrides: Partial<AbsenceRow> = {}): AbsenceRow {
    return {
        id: 1,
        user_id: 7,
        type: 'vacation',
        status: 'requested',
        start_date: '2026-10-05',
        end_date: '2026-10-09',
        partial_minutes: null,
        working_days: 5,
        notes: null,
        review_comment: null,
        reviewed_at: null,
        reviewer: null,
        auto_approved: false,
        can: { cancel: true, review: false },
        ...overrides,
    };
}

function myProps(
    overrides: Partial<MyAbsencesPageProps> = {},
): MyAbsencesPageProps {
    return {
        absences: [
            row({ id: 1 }),
            row({
                id: 2,
                type: 'leave',
                status: 'approved',
                start_date: '2026-11-02',
                end_date: '2026-11-02',
                partial_minutes: 150,
                working_days: null,
                reviewer: { id: 3, name: 'Raúl' },
            }),
            row({
                id: 3,
                status: 'rejected',
                start_date: '2026-09-01',
                end_date: '2026-09-02',
                working_days: 2,
                reviewer: { id: 3, name: 'Raúl' },
                review_comment: 'Es la entrega de ACME',
                can: { cancel: false, review: false },
            }),
        ],
        types: TYPES,
        today: TODAY,
        limits: LIMITS,
        self_approves: false,
        can: { team: false },
        ...overrides,
    };
}

const post = vi.spyOn(coreRouter, 'post').mockImplementation(() => {});

beforeEach(() => {
    page.url = '/ausencias';
    page.props = { auth: { user: { id: 3, name: 'Raúl' }, can: {} } };
    inertia.get.mockReset();
    inertia.post.mockReset();
    post.mockClear();
});

/** Elige el día 15 del mes que muestra el calendario abierto con el botón `label`. */
async function pickDay15(
    user: ReturnType<typeof userEvent.setup>,
    label: string,
) {
    await user.click(screen.getByLabelText(label));
    const grid = await screen.findByRole('grid');
    const cell = within(grid)
        .getAllByRole('gridcell')
        .find(
            (candidate) =>
                candidate.textContent === '15' &&
                !candidate.hasAttribute('data-outside'),
        );
    await user.click(within(cell as HTMLElement).getByRole('button'));
}

describe('mis ausencias (/ausencias)', () => {
    it('separa pendientes, próximas e historial, con estado (icono y texto) y acciones', () => {
        render(<MyAbsences {...myProps()} />);

        const pending = screen.getByRole('region', {
            name: 'Pendientes de aprobar (1)',
        });
        expect(within(pending).getByText('Solicitada')).toBeTruthy();
        expect(within(pending).getByText('5 días laborables')).toBeTruthy();
        expect(
            within(pending).getByText('05/10/2026 – 09/10/2026'),
        ).toBeTruthy();
        expect(
            within(pending).getByRole('button', {
                name: 'Cancelar la ausencia 05/10/2026 – 09/10/2026',
            }),
        ).toBeTruthy();

        const upcoming = screen.getByRole('region', {
            name: 'Próximas y en curso (1)',
        });
        expect(
            within(upcoming).getByText('Parte del día: 2:30 h'),
        ).toBeTruthy();
        expect(within(upcoming).getByText('Aprobada por Raúl')).toBeTruthy();

        const history = screen.getByRole('region', {
            name: 'Historial del último año (1)',
        });
        expect(within(history).getByText('Rechazada')).toBeTruthy();
        expect(
            within(history).getByText(/«Es la entrega de ACME»/),
        ).toBeTruthy();
        expect(within(history).queryByRole('button')).toBeNull();

        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.queryByRole('navigation')).toBeNull();
    });

    it('sin ausencias, un estado vacío invita a solicitar la primera', () => {
        render(<MyAbsences {...myProps({ absences: [] })} />);

        expect(screen.getByText('Aún no tienes ausencias')).toBeTruthy();
        expect(
            screen.getAllByRole('button', { name: 'Solicitar ausencia' }),
        ).toHaveLength(2);
    });

    it('un responsable ve las pestañas del equipo y que las suyas se aprueban solas', () => {
        render(
            <MyAbsences
                {...myProps({ self_approves: true, can: { team: true } })}
            />,
        );

        const tabs = screen.getByRole('navigation', {
            name: 'Secciones de ausencias',
        });
        expect(
            within(tabs)
                .getByRole('link', { name: 'Mis ausencias' })
                .getAttribute('aria-current'),
        ).toBe('page');
        expect(
            within(tabs)
                .getByRole('link', { name: 'Ausencias del equipo' })
                .getAttribute('href'),
        ).toBe('/ausencias/equipo');
        expect(
            screen.getByText(
                'Como responsable, tus ausencias se aprueban solas al registrarlas.',
            ),
        ).toBeTruthy();
    });

    it('con ?solicitar=1 abre el formulario al entrar', () => {
        page.url = '/ausencias?solicitar=1';
        render(<MyAbsences {...myProps()} />);

        expect(
            screen.getByRole('dialog', { name: 'Solicitar una ausencia' }),
        ).toBeTruthy();
    });
});

describe('formulario de una ausencia', () => {
    it('una ausencia de parte del día envía un solo día y sus minutos', async () => {
        const user = userEvent.setup();
        render(
            <AbsenceDialog
                mode="request"
                types={TYPES}
                limits={LIMITS}
                open
                onOpenChange={() => {}}
            />,
        );

        await user.selectOptions(screen.getByLabelText('Tipo'), 'leave');
        await user.click(screen.getByLabelText('Parte de un día'));
        await pickDay15(user, 'Día');
        await user.type(screen.getByLabelText('Horas que faltas'), '2h30');
        expect(screen.getByText('= 2:30')).toBeTruthy();
        await user.click(
            screen.getByRole('button', { name: 'Enviar la solicitud' }),
        );

        expect(post).toHaveBeenCalledTimes(1);
        const [url, data] = post.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/ausencias');
        expect(data.type).toBe('leave');
        expect(String(data.start_date)).toMatch(/^\d{4}-\d{2}-15$/);
        expect(data.end_date).toBe(data.start_date);
        expect(data.partial_minutes).toBe(150);
        expect(data.notes).toBeNull();
        expect(data).not.toHaveProperty('user_id');
    });

    it('en días completos, «Hasta» sigue a «Desde» y no se envían minutos', async () => {
        const user = userEvent.setup();
        render(
            <AbsenceDialog
                mode="request"
                types={TYPES}
                limits={LIMITS}
                selfApproves
                open
                onOpenChange={() => {}}
            />,
        );

        expect(
            screen.getByText(
                'Como responsable, se aprueba sola al guardarla y resta de tu capacidad al momento.',
            ),
        ).toBeTruthy();
        await pickDay15(user, 'Desde');
        await user.type(screen.getByLabelText(/Notas/), 'Viaje');
        await user.click(
            screen.getByRole('button', { name: 'Guardar la ausencia' }),
        );

        const [, data] = post.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(data.end_date).toBe(data.start_date);
        expect(data.partial_minutes).toBeNull();
        expect(data.notes).toBe('Viaje');
    });

    it('registrar una ausencia aprobada envía también la persona', async () => {
        const user = userEvent.setup();
        render(
            <AbsenceDialog
                mode="register"
                types={TYPES}
                limits={LIMITS}
                people={[
                    { id: 7, name: 'Elena' },
                    { id: 9, name: 'Bruno' },
                ]}
                open
                onOpenChange={() => {}}
            />,
        );

        await user.selectOptions(screen.getByLabelText('Persona'), '9');
        await user.selectOptions(screen.getByLabelText('Tipo'), 'sick');
        await pickDay15(user, 'Desde');
        await user.click(
            screen.getByRole('button', { name: 'Registrar la ausencia' }),
        );

        const [url, data] = post.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/ausencias/equipo');
        expect(data.user_id).toBe(9);
        expect(data.type).toBe('sick');
    });
});

const CALENDAR: TeamCalendar = {
    month: '2026-10',
    current: '2026-09',
    previous: '2026-09',
    next: '2026-11',
    days: Array.from(
        { length: 31 },
        (_, index) => `2026-10-${String(index + 1).padStart(2, '0')}`,
    ),
    people: [
        {
            id: 7,
            name: 'Elena',
            department: { id: 1, name: 'Diseño', color: '#0171FF' },
        },
        { id: 9, name: 'Bruno', department: null },
    ],
    absences: [
        {
            id: 1,
            user_id: 7,
            type: 'vacation',
            status: 'approved',
            start_date: '2026-10-05',
            end_date: '2026-10-06',
            partial_minutes: null,
        },
        {
            id: 2,
            user_id: 9,
            type: 'leave',
            status: 'requested',
            start_date: '2026-10-07',
            end_date: '2026-10-07',
            partial_minutes: 120,
        },
    ],
    holidays: [{ date: '2026-10-12', name: 'Fiesta Nacional de España' }],
};

describe('calendario del equipo', () => {
    it('pinta ausencias y festivos con texto accesible en cada celda', () => {
        render(
            <TeamCalendarView
                calendar={CALENDAR}
                today={TODAY}
                monthUrl={(month) => `/ausencias/equipo?mes=${month}`}
            />,
        );

        const table = screen.getByRole('table', {
            name: 'Ausencias y festivos de octubre de 2026',
        });
        const rows = within(table).getAllByRole('row');
        expect(rows).toHaveLength(3);

        const elena = within(table).getByRole('rowheader', { name: 'Elena' })
            .parentElement as HTMLElement;
        expect(
            within(elena).getAllByText('Vacaciones (aprobada)'),
        ).toHaveLength(2);
        expect(
            within(elena).getAllByText('Festivo: Fiesta Nacional de España'),
        ).toHaveLength(1);

        const bruno = within(table).getByRole('rowheader', { name: 'Bruno' })
            .parentElement as HTMLElement;
        expect(
            within(bruno).getByText('Permiso, 2:00 h (solicitada)'),
        ).toBeTruthy();

        expect(
            screen
                .getByRole('link', { name: 'Mes siguiente: noviembre de 2026' })
                .getAttribute('href'),
        ).toBe('/ausencias/equipo?mes=2026-11');
        expect(
            screen
                .getByRole('link', { name: 'Mes actual' })
                .getAttribute('href'),
        ).toBe('/ausencias/equipo?mes=2026-09');
        expect(
            screen.getByRole('list', { name: 'Leyenda' }).textContent,
        ).toContain('Solicitud pendiente');
    });
});

function teamProps(
    overrides: Partial<TeamAbsencesPageProps> = {},
): TeamAbsencesPageProps {
    const pending: PendingAbsence = {
        ...row({ id: 5, can: { cancel: false, review: true } }),
        user: {
            id: 7,
            name: 'Elena',
            department: { id: 1, name: 'Diseño', color: '#0171FF' },
        },
        overlaps: [
            {
                user_name: 'Bruno',
                type: 'training',
                status: 'approved',
                start_date: '2026-10-07',
                end_date: '2026-10-07',
                partial_minutes: null,
            },
        ],
    };

    return {
        pending: [pending],
        upcoming: [
            {
                ...row({
                    id: 6,
                    user_id: 9,
                    status: 'approved',
                    start_date: '2026-11-02',
                    end_date: '2026-11-03',
                    reviewer: { id: 3, name: 'Raúl' },
                    can: { cancel: true, review: false },
                }),
                user: { id: 9, name: 'Bruno', department: null },
            },
            {
                ...row({
                    id: 8,
                    user_id: 3,
                    type: 'sick',
                    status: 'approved',
                    start_date: '2026-12-01',
                    end_date: '2026-12-01',
                    auto_approved: true,
                    can: { cancel: true, review: false },
                }),
                user: { id: 3, name: 'Raúl', department: null },
            },
        ],
        calendar: CALENDAR,
        departments: [{ id: 1, name: 'Diseño', color: '#0171FF' }],
        filters: { department: null },
        register_people: [{ id: 7, name: 'Elena' }],
        types: TYPES,
        today: TODAY,
        limits: LIMITS,
        pending_limit: 100,
        ...overrides,
    };
}

describe('ausencias del equipo (/ausencias/equipo)', () => {
    it('muestra la solicitud con las que coinciden y la aprueba con un clic', async () => {
        const user = userEvent.setup();
        render(<TeamAbsences {...teamProps()} />);

        const pending = screen.getByRole('region', {
            name: 'Pendientes de aprobar (1)',
        });
        expect(
            within(pending).getByText(
                'Bruno · Formación externa · 07/10/2026 (aprobada)',
            ),
        ).toBeTruthy();

        await user.click(
            within(pending).getByRole('button', {
                name: 'Aprobar la ausencia de Elena 05/10/2026 – 09/10/2026',
            }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/ausencias/5/aprobar',
            {},
            expect.any(Object),
        );
    });

    it('rechazar exige un comentario', async () => {
        const user = userEvent.setup();
        render(<TeamAbsences {...teamProps()} />);

        await user.click(
            screen.getByRole('button', {
                name: 'Rechazar la ausencia de Elena 05/10/2026 – 09/10/2026',
            }),
        );
        const dialog = screen.getByRole('dialog', {
            name: 'Rechazar la ausencia de Elena',
        });
        const confirm = within(dialog).getByRole('button', {
            name: 'Rechazar la ausencia',
        }) as HTMLButtonElement;
        expect(confirm.disabled).toBe(true);

        await user.type(
            within(dialog).getByLabelText('Comentario'),
            'Esa semana es la entrega',
        );
        expect(confirm.disabled).toBe(false);
        await user.click(confirm);

        const [url, data] = post.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/ausencias/5/rechazar');
        expect(data).toEqual({ comment: 'Esa semana es la entrega' });
    });

    it('las aprobadas de otros se anulan y las propias se cancelan', () => {
        render(<TeamAbsences {...teamProps()} />);

        const upcoming = screen.getByRole('region', {
            name: 'Próximas ausencias aprobadas (2)',
        });
        expect(
            within(upcoming).getByRole('button', {
                name: 'Anular la ausencia de Bruno 02/11/2026 – 03/11/2026',
            }),
        ).toBeTruthy();
        expect(
            within(upcoming).getByRole('button', {
                name: 'Cancelar la ausencia 01/12/2026',
            }),
        ).toBeTruthy();
        expect(
            within(upcoming).getByText('Aprobada al registrarla'),
        ).toBeTruthy();
    });

    it('sin pendientes lo explica y sin personas no pinta el calendario', () => {
        render(
            <TeamAbsences
                {...teamProps({
                    pending: [],
                    calendar: { ...CALENDAR, people: [], absences: [] },
                })}
            />,
        );

        expect(screen.getByText('No hay solicitudes pendientes')).toBeTruthy();
        expect(screen.getByText('No hay personas en tu ámbito')).toBeTruthy();
        expect(screen.queryByRole('table')).toBeNull();
    });

    it('con varios departamentos, el filtro navega conservando el mes', async () => {
        const user = userEvent.setup();
        render(
            <TeamAbsences
                {...teamProps({
                    departments: [
                        { id: 1, name: 'Diseño', color: '#0171FF' },
                        { id: 2, name: 'Marketing', color: '#FF6B00' },
                    ],
                })}
            />,
        );

        await user.selectOptions(screen.getByLabelText('Departamento'), '2');

        expect(inertia.get).toHaveBeenCalledWith(
            '/ausencias/equipo?mes=2026-10&departamento=2',
            {},
            expect.any(Object),
        );
    });
});

describe('tarjeta «Mis ausencias» de Inicio', () => {
    it('lista pendientes y próximas y enlaza a solicitar', () => {
        render(
            <MyAbsencesCard
                absences={{
                    pending: [
                        {
                            id: 1,
                            type: 'vacation',
                            status: 'requested',
                            start_date: '2026-12-21',
                            end_date: '2026-12-31',
                            partial_minutes: null,
                        },
                    ],
                    upcoming: [],
                }}
            />,
        );

        expect(screen.getByText('Pendientes de aprobar')).toBeTruthy();
        expect(screen.getByText('Solicitada')).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: 'Solicitar una ausencia' })
                .getAttribute('href'),
        ).toBe('/ausencias?solicitar=1');
    });

    it('sin nada, lo dice', () => {
        render(<MyAbsencesCard absences={{ pending: [], upcoming: [] }} />);

        expect(
            screen.getByText('No tienes ausencias próximas ni pendientes.'),
        ).toBeTruthy();
    });
});

describe('aviso de ausencias en las aprobaciones de horas', () => {
    const fetchMock = vi.fn<typeof fetch>();

    beforeEach(() => {
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('avisa de las solicitudes pendientes', async () => {
        fetchMock.mockResolvedValue(
            new Response(JSON.stringify({ count: 2 }), { status: 200 }),
        );
        render(<PendingAbsencesNotice />);

        await waitFor(() =>
            expect(
                screen.getByText(
                    'Hay 2 solicitudes de ausencia pendientes de aprobar.',
                ),
            ).toBeTruthy(),
        );
        expect(fetchMock.mock.calls[0][0]).toBe('/ausencias/equipo/pendientes');
        expect(
            screen
                .getByRole('link', {
                    name: 'Revisarlas en «Ausencias del equipo»',
                })
                .getAttribute('href'),
        ).toBe('/ausencias/equipo');
    });

    it('sin pendientes o si falla, no muestra nada', async () => {
        fetchMock.mockResolvedValueOnce(
            new Response(JSON.stringify({ count: 0 }), { status: 200 }),
        );
        const { container, unmount } = render(<PendingAbsencesNotice />);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(container.textContent).toBe('');
        unmount();

        fetchMock.mockRejectedValueOnce(new Error('offline'));
        const failed = render(<PendingAbsencesNotice />);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(failed.container.textContent).toBe('');
    });
});
