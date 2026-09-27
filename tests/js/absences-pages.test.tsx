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
    replace: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => page,
    router: {
        get: inertia.get,
        post: inertia.post,
        replace: inertia.replace,
        on: () => () => {},
    },
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
        can: { cancel: true, review: false, update: false },
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
                can: { cancel: false, review: false, update: false },
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
const put = vi.spyOn(coreRouter, 'put').mockImplementation(() => {});

beforeEach(() => {
    page.url = '/ausencias';
    page.props = { auth: { user: { id: 3, name: 'Raúl' }, can: {} } };
    inertia.get.mockReset();
    inertia.post.mockReset();
    inertia.replace.mockReset();
    post.mockClear();
    put.mockClear();
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

// Formularios con Radix y userEvent: margen de tiempo para la CI cargada.
describe('mis ausencias (/ausencias)', { timeout: 20_000 }, () => {
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

    it('con ?solicitar=1 abre el formulario al entrar y quita el parámetro de la URL', () => {
        page.url = '/ausencias?solicitar=1';
        render(<MyAbsences {...myProps()} />);

        expect(
            screen.getByRole('dialog', { name: 'Solicitar una ausencia' }),
        ).toBeTruthy();
        // Al recargar, al volver desde el historial o tras enviar no se vuelve a abrir solo.
        expect(inertia.replace).toHaveBeenCalledTimes(1);
        expect(inertia.replace).toHaveBeenCalledWith({
            url: '/ausencias',
            preserveState: true,
            preserveScroll: true,
        });
    });

    it('sin ?solicitar=1 ni abre el formulario ni toca la URL', () => {
        render(<MyAbsences {...myProps()} />);

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(inertia.replace).not.toHaveBeenCalled();
    });
});

// Formularios con Radix y userEvent: margen de tiempo para la CI cargada.
describe('formulario de una ausencia', { timeout: 20_000 }, () => {
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

    it('una parte del día sin horas no se envía: lo pide junto al campo', async () => {
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

        await user.click(screen.getByLabelText('Parte de un día'));
        await pickDay15(user, 'Día');
        await user.click(
            screen.getByRole('button', { name: 'Enviar la solicitud' }),
        );

        expect(post).not.toHaveBeenCalled();
        expect(screen.getByRole('alert').textContent).toBe(
            'Indica cuántas horas faltas ese día (por ejemplo, 2:30).',
        );
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

describe('modificar una ausencia aprobada', { timeout: 20_000 }, () => {
    it('una parte del día sale marcada con sus horas y se guarda igual si no se toca', async () => {
        const user = userEvent.setup();
        render(
            <AbsenceDialog
                mode="edit"
                types={TYPES}
                limits={LIMITS}
                absence={row({
                    id: 12,
                    type: 'leave',
                    status: 'approved',
                    start_date: '2026-11-02',
                    end_date: '2026-11-02',
                    partial_minutes: 150,
                })}
                personName="Elena"
                open
                onOpenChange={() => {}}
            />,
        );

        expect(
            (screen.getByLabelText('Parte de un día') as HTMLButtonElement)
                .dataset.state,
        ).toBe('checked');
        expect(
            (screen.getByLabelText('Horas que faltas') as HTMLInputElement)
                .value,
        ).toBe('2:30');

        await user.click(
            screen.getByRole('button', { name: 'Guardar los cambios' }),
        );

        const [url, data] = put.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/ausencias/12');
        expect(data).toEqual({
            type: 'leave',
            start_date: '2026-11-02',
            end_date: '2026-11-02',
            partial_minutes: 150,
            notes: null,
        });
    });

    it('muestra el error de una ausencia que ya no se puede modificar', async () => {
        const user = userEvent.setup();
        put.mockImplementationOnce((_url, _data, options) => {
            options?.onError?.({
                absence:
                    'Esta ausencia ya no se puede modificar: está cancelada.',
            });
        });
        render(
            <AbsenceDialog
                mode="edit"
                types={TYPES}
                limits={LIMITS}
                absence={row({ id: 12, status: 'approved' })}
                personName="Elena"
                open
                onOpenChange={() => {}}
            />,
        );
        expect(screen.queryByRole('alert')).toBeNull();

        await user.click(
            screen.getByRole('button', { name: 'Guardar los cambios' }),
        );

        expect((await screen.findByRole('alert')).textContent).toBe(
            'Esta ausencia ya no se puede modificar: está cancelada.',
        );
    });
});

function teamProps(
    overrides: Partial<TeamAbsencesPageProps> = {},
): TeamAbsencesPageProps {
    const pending: PendingAbsence = {
        ...row({ id: 5, can: { cancel: false, review: true, update: false } }),
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
                    can: { cancel: true, review: false, update: false },
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
                    can: { cancel: true, review: false, update: false },
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

// Formularios con Radix y userEvent: margen de tiempo para la CI cargada.
describe(
    'ausencias del equipo (/ausencias/equipo)',
    { timeout: 20_000 },
    () => {
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

        it('quien aprueba modifica una aprobada de otra persona: el formulario sale con sus datos y se envía con PUT', async () => {
            const user = userEvent.setup();
            const props = teamProps();
            props.upcoming[0] = {
                ...props.upcoming[0],
                type: 'sick',
                notes: 'Gripe',
                can: { cancel: true, review: false, update: true },
            };
            render(<TeamAbsences {...props} />);

            const upcoming = screen.getByRole('region', {
                name: 'Próximas ausencias aprobadas (2)',
            });
            // Las propias (aprobadas solas) no se modifican desde aquí.
            expect(
                within(upcoming).getAllByRole('button', { name: /^Modificar/ }),
            ).toHaveLength(1);

            await user.click(
                within(upcoming).getByRole('button', {
                    name: 'Modificar la ausencia de Bruno 02/11/2026 – 03/11/2026',
                }),
            );
            const dialog = screen.getByRole('dialog', {
                name: 'Modificar la ausencia de Bruno',
            });
            expect(
                within(dialog).getByText(
                    /Sigue aprobada y avisamos a Bruno de lo que cambia\./,
                ),
            ).toBeTruthy();
            expect(
                (within(dialog).getByLabelText('Tipo') as HTMLSelectElement)
                    .value,
            ).toBe('sick');
            expect(
                (within(dialog).getByLabelText(/Notas/) as HTMLTextAreaElement)
                    .value,
            ).toBe('Gripe');

            await user.selectOptions(
                within(dialog).getByLabelText('Tipo'),
                'leave',
            );
            await user.click(
                within(dialog).getByRole('button', {
                    name: 'Guardar los cambios',
                }),
            );

            expect(put).toHaveBeenCalledTimes(1);
            const [url, data] = put.mock.calls[0] as unknown as [
                string,
                Record<string, unknown>,
            ];
            expect(url).toBe('/ausencias/6');
            expect(data).toEqual({
                type: 'leave',
                start_date: '2026-11-02',
                end_date: '2026-11-03',
                partial_minutes: null,
                notes: 'Gripe',
            });
            expect(post).not.toHaveBeenCalled();
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

            expect(
                screen.getByText('No hay solicitudes pendientes'),
            ).toBeTruthy();
            expect(
                screen.getByText('No hay personas en tu ámbito'),
            ).toBeTruthy();
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

            await user.selectOptions(
                screen.getByLabelText('Departamento'),
                '2',
            );

            expect(inertia.get).toHaveBeenCalledWith(
                '/ausencias/equipo?mes=2026-10&departamento=2',
                {},
                expect.any(Object),
            );
        });
    },
);

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
