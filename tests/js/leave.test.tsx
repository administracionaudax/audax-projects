// @vitest-environment jsdom
import { router as coreRouter } from '@inertiajs/core';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AbsenceDialog } from '@/components/absences/absence-dialog';
import { LeaveBalanceCards } from '@/components/leave/balance-cards';
import {
    DecideCancellationButtons,
    RequestCancellationDialog,
} from '@/components/leave/cancellation';
import { LeaveDetails } from '@/components/leave/leave-details';
import {
    balanceParts,
    formatDays,
    formatLeaveAmount,
    isoWeekday,
    leaveAmountInput,
    monthDays,
    slotMinutes,
} from '@/lib/leave';
import LeaveCalendar from '@/pages/leave/calendar';
import type {
    AbsenceLeave,
    LeaveBalance,
    LeaveCalendarPageProps,
    LeaveTypeOption,
} from '@/types/leave';

/*
 * Vacaciones y permisos (Fase 11, R3): las cantidades en días y horas, el resumen de un saldo, los
 * saldos, los detalles de una ausencia (franja, coste, segundo nivel, cancelación, avisos y
 * justificantes), pedir y decidir la cancelación, el formulario con el catálogo y la franja, y el
 * calendario laboral.
 */

const page = vi.hoisted(() => ({
    url: '/ausencias',
    props: {} as Record<string, unknown>,
}));

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    get: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => page,
    router: {
        get: inertia.get,
        post: inertia.post,
        delete: inertia.delete,
        on: () => () => {},
    },
    Link: ({ href, children }: { href: string; children?: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

const post = vi.spyOn(coreRouter, 'post').mockImplementation(() => {});

beforeEach(() => {
    page.props = {
        auth: { user: { id: 3, name: 'Elena' }, can: { usePeople: true } },
    };
    post.mockClear();
    inertia.post.mockReset();
});

function type(overrides: Partial<LeaveTypeOption> = {}): LeaveTypeOption {
    return {
        id: 1,
        key: 'vacation',
        name: 'Vacaciones',
        category: 'vacation',
        unit: 'working_days',
        paid: true,
        requires_document: false,
        notice_days: null,
        health_data: false,
        default_amount: null,
        travel_extra: null,
        annual_allowance: 2200,
        allowance_in_days: false,
        allow_without_balance: false,
        second_approval: false,
        respects_blocked_days: true,
        description: 'Días laborables de tu jornada.',
        legal_basis: 'Art. 38 ET.',
        advisor_pending: false,
        active: true,
        ...overrides,
    };
}

function balance(overrides: Partial<LeaveBalance> = {}): LeaveBalance {
    return {
        type: {
            id: 1,
            name: 'Vacaciones',
            unit: 'working_days',
            allow_without_balance: false,
        },
        year: 2026,
        entitled: 2200,
        adjusted: 0,
        total: 2200,
        used: 800,
        taken: 500,
        pending: 300,
        available: 1100,
        carried: [],
        expired: 0,
        expiring: [],
        ...overrides,
    };
}

function leave(overrides: Partial<AbsenceLeave> = {}): AbsenceLeave {
    return {
        type: type(),
        start_time: null,
        end_time: null,
        cost: 500,
        documents_count: 0,
        documents: [],
        warnings: [],
        first_approval: null,
        awaiting_second: false,
        cancellation: null,
        can: {
            upload: true,
            request_cancellation: false,
            decide_cancellation: false,
        },
        ...overrides,
    };
}

describe('cantidades y saldos', () => {
    it('escribe los días como Woffu y las horas en h:mm', () => {
        expect(formatDays(2200)).toBe('22');
        expect(formatDays(1250)).toBe('12,5');
        expect(formatDays(1283)).toBe('12,83');
        expect(formatLeaveAmount(100, 'working_days')).toBe('1 día');
        expect(formatLeaveAmount(50, 'calendar_days')).toBe('0,5 días');
        expect(formatLeaveAmount(-300, 'working_days')).toBe('-3 días');
        expect(formatLeaveAmount(960, 'hours')).toBe('16:00 h');
        expect(leaveAmountInput(1500, 'calendar_days')).toBe('15');
        expect(leaveAmountInput(90, 'hours')).toBe('1:30');
        expect(leaveAmountInput(null, 'hours')).toBe('');
    });

    it('mide la franja y recorre los días de un mes con la semana empezando en lunes', () => {
        expect(slotMinutes('10:00', '12:30')).toBe(150);
        expect(slotMinutes('12:00', '10:00')).toBe(0);
        expect(slotMinutes(null, '10:00')).toBe(0);
        expect(monthDays(2026, 2)).toHaveLength(28);
        expect(monthDays(2028, 2).at(-1)).toBe('2028-02-29');
        expect(isoWeekday('2026-10-05')).toBe(1);
        expect(isoWeekday('2026-10-11')).toBe(7);
    });

    it('resume un saldo: disponible, asignado, disfrutado, por disfrutar, pendiente, arrastre y caducado', () => {
        const parts = balanceParts(
            balance({
                carried: [
                    {
                        year: 2025,
                        remaining: 200,
                        expires_on: '2026-03-31',
                        expired: false,
                    },
                ],
                expiring: [{ amount: 200, expires_on: '2026-03-31' }],
                expired: 100,
            }),
        );

        expect(parts.headline).toBe('11 días disponibles');
        expect(parts.negative).toBe(false);
        expect(parts.details).toEqual([
            '22 días asignados en 2026',
            '5 días disfrutados',
            '3 días aprobados por disfrutar',
            '3 días pendientes de aprobar',
            '2 días de 2025 hasta el 31/03/2026',
            'Caducan 2 días el 31/03/2026',
            '1 día caducados sin disfrutar',
        ]);
    });

    it('las tarjetas marcan con icono y texto un saldo negativo', () => {
        render(
            <LeaveBalanceCards
                title="Tus saldos de 2026"
                balances={[balance({ available: -200 })]}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Tus saldos de 2026' }),
        ).toBeTruthy();
        expect(screen.getByText('-2 días disponibles')).toBeTruthy();
        expect(
            screen.getByText(
                'Has pedido más de lo que tienes: habla con RR. HH.',
            ),
        ).toBeTruthy();
    });
});

describe('detalles de una ausencia', () => {
    it('enseña la franja, lo que cuesta, el segundo nivel pendiente y los avisos de una solicitud', () => {
        render(
            <LeaveDetails
                absenceId={9}
                status="requested"
                leave={leave({
                    type: type({
                        unit: 'hours',
                        requires_document: true,
                        key: 'public_duty',
                        name: 'Deber inexcusable',
                    }),
                    start_time: '10:00',
                    end_time: '12:30',
                    cost: 150,
                    awaiting_second: true,
                    first_approval: { by: 'Raúl', at: '2026-10-07T08:00:00Z' },
                    warnings: [
                        {
                            code: 'short_notice',
                            message: 'Empiezan antes de 2 meses.',
                        },
                        { code: 'document', message: 'Pide justificante.' },
                    ],
                })}
            />,
        );

        expect(screen.getByText('De 10:00 a 12:30')).toBeTruthy();
        expect(screen.getByText('Cuesta 2:30 h')).toBeTruthy();
        expect(
            screen.getByText(
                'Aprobada por Raúl (primer nivel) · falta la aprobación de RR. HH.',
            ),
        ).toBeTruthy();
        const warnings = screen
            .getByText('Empiezan antes de 2 meses.')
            .closest('ul') as HTMLElement;
        // El aviso del justificante no se repite: ya lo dice el bloque de justificantes.
        expect(within(warnings).queryByText('Pide justificante.')).toBeNull();
        expect(
            screen.getByText(
                'Este permiso pide justificante y aún no hay ninguno.',
            ),
        ).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Subir justificante' }),
        ).toBeTruthy();
    });

    it('de un tipo de salud, quien no puede verlo solo sabe que está entregado', () => {
        render(
            <LeaveDetails
                absenceId={9}
                status="approved"
                leave={leave({
                    type: type({ health_data: true, requires_document: true }),
                    documents: null,
                    documents_count: 1,
                    can: {
                        upload: false,
                        request_cancellation: false,
                        decide_cancellation: false,
                    },
                })}
            />,
        );

        expect(
            screen.getByText(
                /Justificante entregado \(por ser un dato de salud/,
            ),
        ).toBeTruthy();
        expect(screen.queryByRole('link')).toBeNull();
    });

    it('lista los justificantes con su descarga y, si no los pide el tipo, solo ofrece adjuntar uno', () => {
        const { rerender } = render(
            <LeaveDetails
                absenceId={9}
                status="approved"
                leave={leave({
                    documents: [
                        {
                            id: 4,
                            name: 'citacion.pdf',
                            size: 2048,
                            created_at: '2026-10-06T10:00:00Z',
                            can_delete: true,
                        },
                    ],
                })}
            />,
        );

        expect(
            screen
                .getByRole('link', { name: 'citacion.pdf' })
                .getAttribute('href'),
        ).toBe('/ausencias/justificantes/4');
        expect(
            screen.getByRole('button', {
                name: 'Borrar el justificante citacion.pdf',
            }),
        ).toBeTruthy();

        rerender(
            <LeaveDetails absenceId={9} status="approved" leave={leave()} />,
        );
        expect(
            screen.getByRole('button', { name: 'Adjuntar un documento' }),
        ).toBeTruthy();
        expect(screen.queryByText(/pide justificante/)).toBeNull();
    });

    it('cuenta la cancelación pedida y la rechazada con su comentario', () => {
        const { rerender } = render(
            <LeaveDetails
                absenceId={9}
                status="approved"
                leave={leave({
                    cancellation: {
                        status: 'requested',
                        reason: 'Vuelvo antes',
                        requested_at: null,
                        comment: null,
                        decided_at: null,
                    },
                })}
            />,
        );
        expect(
            screen.getByText(/Cancelación pedida: «Vuelvo antes»/),
        ).toBeTruthy();

        rerender(
            <LeaveDetails
                absenceId={9}
                status="approved"
                leave={leave({
                    cancellation: {
                        status: 'rejected',
                        reason: 'Vuelvo antes',
                        requested_at: null,
                        comment: 'Ya disfrutada',
                        decided_at: null,
                    },
                })}
            />,
        );
        expect(
            screen.getByText(/Cancelación rechazada: «Ya disfrutada»/),
        ).toBeTruthy();
    });
});

describe('cancelación', { timeout: 20_000 }, () => {
    const absence = {
        id: 12,
        start_date: '2026-10-05',
        end_date: '2026-10-09',
        partial_minutes: null,
    };

    it('la persona la pide con un motivo', async () => {
        const user = userEvent.setup();
        render(<RequestCancellationDialog absence={absence} />);

        await user.click(
            screen.getByRole('button', { name: /Pedir que se cancele/ }),
        );
        const dialog = screen.getByRole('dialog');
        const send = within(dialog).getByRole('button', {
            name: 'Pedir la cancelación',
        });
        expect((send as HTMLButtonElement).disabled).toBe(true);
        await user.type(
            within(dialog).getByLabelText('Motivo'),
            'Me reincorporo antes',
        );
        await user.click(send);

        expect(post).toHaveBeenCalledTimes(1);
        const [url, data] = post.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/ausencias/12/pedir-cancelacion');
        expect(data.reason).toBe('Me reincorporo antes');
    });

    it('quien aprueba la acepta con un clic o la rechaza con un comentario', async () => {
        const user = userEvent.setup();
        render(
            <DecideCancellationButtons absence={absence} personName="Elena" />,
        );

        await user.click(
            screen.getByRole('button', {
                name: /Aceptar la cancelación de la ausencia de Elena/,
            }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/ausencias/12/cancelacion',
            { decision: 'accept' },
            expect.anything(),
        );

        await user.click(
            screen.getByRole('button', {
                name: /Rechazar la cancelación de la ausencia de Elena/,
            }),
        );
        const dialog = screen.getByRole('dialog');
        await user.type(
            within(dialog).getByLabelText('Comentario'),
            'Ya está disfrutada',
        );
        await user.click(
            within(dialog).getByRole('button', {
                name: 'Rechazar la cancelación',
            }),
        );

        const [url, data] = post.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/ausencias/12/cancelacion');
        expect(data).toEqual({
            comment: 'Ya está disfrutada',
            decision: 'reject',
        });
    });
});

describe('formulario con el catálogo', { timeout: 20_000 }, () => {
    const fetchMock = vi.fn();

    beforeEach(() => {
        fetchMock.mockReset();
        fetchMock.mockResolvedValue(
            new Response(
                JSON.stringify({
                    cost: { amount: 150, unit: 'hours', label: '2:30 h' },
                    balance: null,
                    warnings: [
                        {
                            code: 'document',
                            message:
                                'Pide justificante: súbelo cuando lo tengas.',
                        },
                    ],
                    errors: {},
                }),
                { status: 200 },
            ),
        );
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => vi.unstubAllGlobals());

    const types = [
        type(),
        type({
            id: 7,
            key: 'public_duty',
            name: 'Deber inexcusable',
            category: 'leave',
            unit: 'hours',
            requires_document: true,
            annual_allowance: null,
            health_data: false,
        }),
    ];

    it('un tipo por horas pide la franja y se envía con el tipo del catálogo', async () => {
        const user = userEvent.setup();
        render(
            <AbsenceDialog
                mode="request"
                types={['vacation', 'leave']}
                limits={{ from: '2024-10-07', to: '2028-10-07' }}
                leaveTypes={types}
                open
                onOpenChange={() => {}}
            />,
        );

        expect(screen.getByText('Días laborables de tu jornada.')).toBeTruthy();
        await user.selectOptions(screen.getByLabelText('Tipo'), '7');
        expect(screen.getByText(/Retribuido · Pide justificante/)).toBeTruthy();
        await user.click(screen.getByLabelText('Por horas, con su franja'));

        await user.click(screen.getByLabelText('Día'));
        const grid = await screen.findByRole('grid');
        const cell = within(grid)
            .getAllByRole('gridcell')
            .find(
                (candidate) =>
                    candidate.textContent === '15' &&
                    !candidate.hasAttribute('data-outside'),
            );
        await user.click(within(cell as HTMLElement).getByRole('button'));

        await user.type(screen.getByLabelText('De'), '10:00');
        await user.type(screen.getByLabelText('A'), '12:30');

        await waitFor(
            () => expect(screen.getByText('Cuesta 2:30 h')).toBeTruthy(),
            { timeout: 3000 },
        );
        const body = JSON.parse(
            (fetchMock.mock.calls.at(-1) as [string, { body: string }])[1].body,
        ) as Record<string, unknown>;
        expect(body).toMatchObject({
            leave_type_id: 7,
            start_time: '10:00',
            end_time: '12:30',
        });

        await user.click(
            screen.getByRole('button', { name: 'Enviar la solicitud' }),
        );
        const [url, data] = post.mock.calls[0] as unknown as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/ausencias');
        expect(data).toMatchObject({
            leave_type_id: 7,
            start_time: '10:00',
            end_time: '12:30',
            partial_minutes: null,
        });
        expect(data).not.toHaveProperty('type');
        expect(data.end_date).toBe(data.start_date);
    });

    it('sin la franja no se envía', async () => {
        const user = userEvent.setup();
        render(
            <AbsenceDialog
                mode="request"
                types={['vacation', 'leave']}
                limits={{ from: '2024-10-07', to: '2028-10-07' }}
                leaveTypes={types}
                open
                onOpenChange={() => {}}
            />,
        );

        await user.selectOptions(screen.getByLabelText('Tipo'), '7');
        await user.click(screen.getByLabelText('Por horas, con su franja'));
        await user.click(
            screen.getByRole('button', { name: 'Enviar la solicitud' }),
        );

        expect(post).not.toHaveBeenCalled();
        expect(
            screen.getAllByRole('alert').map((alert) => alert.textContent),
        ).toContain('Indica la hora de inicio y la de fin.');
    });
});

describe('calendario laboral', () => {
    function props(
        overrides: Partial<LeaveCalendarPageProps> = {},
    ): LeaveCalendarPageProps {
        return {
            year: 2026,
            current_year: 2026,
            today: '2026-10-07',
            work_center: 'València (Valencia)',
            holidays: [
                {
                    date: '2026-01-22',
                    name: 'San Vicente Mártir',
                    level: 'local',
                    source: 'DOGV núm. 10238',
                },
                {
                    date: '2026-10-09',
                    name: 'Día de la Comunitat Valenciana',
                    level: 'regional',
                    source: 'Decreto 100/2025',
                },
                {
                    date: '2026-12-25',
                    name: 'Navidad',
                    level: null,
                    source: null,
                },
            ],
            special_days: [
                {
                    id: 1,
                    kind: 'half_day',
                    name: 'Nochebuena',
                    start_date: '2026-12-24',
                    end_date: '2026-12-24',
                },
                {
                    id: 2,
                    kind: 'blocked',
                    name: 'Cierre',
                    start_date: '2026-12-28',
                    end_date: '2026-12-30',
                },
            ],
            absences: [
                {
                    id: 3,
                    name: 'Vacaciones',
                    status: 'approved',
                    start_date: '2026-10-13',
                    end_date: '2026-10-15',
                    partial_minutes: null,
                },
            ],
            can: { manage: false, holidays: false },
            ...overrides,
        };
    }

    it('pinta los 12 meses con cada día explicado en texto, sin el nivel de los festivos que no lo tienen', () => {
        render(<LeaveCalendar {...props()} />);

        expect(screen.getAllByRole('table')).toHaveLength(12);
        expect(
            screen.getByLabelText(
                '22/01/2026 · Festivo: San Vicente Mártir (local)',
            ),
        ).toBeTruthy();
        expect(
            screen.getByLabelText('24/12/2026 · Media jornada: Nochebuena'),
        ).toBeTruthy();
        expect(
            screen.getByLabelText(
                '29/12/2026 · Bloqueado para vacaciones: Cierre',
            ),
        ).toBeTruthy();
        expect(
            screen.getByLabelText(
                '14/10/2026 · Tu ausencia aprobada: Vacaciones',
            ),
        ).toBeTruthy();
        expect(
            screen.getByLabelText('25/12/2026 · Festivo: Navidad'),
        ).toBeTruthy();
        expect(screen.getByText('Decreto 100/2025')).toBeTruthy();
        // Sin permisos, ni el formulario de días especiales ni el enlace a los festivos.
        expect(screen.queryByRole('button', { name: 'Añadir' })).toBeNull();
        expect(
            screen.queryByRole('link', { name: 'Gestionar los festivos' }),
        ).toBeNull();
    });

    it('RR. HH. añade y quita días especiales', () => {
        render(
            <LeaveCalendar
                {...props({ can: { manage: true, holidays: true } })}
            />,
        );

        expect(screen.getByRole('button', { name: 'Añadir' })).toBeTruthy();
        expect(
            screen.getByRole('button', {
                name: 'Quitar el día especial Cierre',
            }),
        ).toBeTruthy();
        expect(
            screen.getByRole('link', { name: 'Gestionar los festivos' }),
        ).toBeTruthy();
    });
});
