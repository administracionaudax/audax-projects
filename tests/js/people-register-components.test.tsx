// @vitest-environment jsdom
import { configure, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { peopleTabs } from '@/components/people/people-ui';
import { CapBar, CloseAnswerCard } from '@/components/people/register-ui';
import InspectionAccessPage from '@/pages/inspection/access';
import ClosesPage from '@/pages/people/closes';
import DocumentsPage from '@/pages/people/documents';
import OvertimePage from '@/pages/people/overtime';
import RegisterPage from '@/pages/people/register';
import ReportsPage from '@/pages/people/reports';
import type { Abilities } from '@/types';
import type {
    ClosesPageProps,
    MonthClose,
    OvertimePageProps,
    RegisterPageProps,
    ReportsPageProps,
    YearSummary,
} from '@/types/people-register';

configure({ testIdAttribute: 'data-test' });
vi.setConfig({ testTimeout: 20_000 });

/*
| Pantallas de R2 del registro de jornada (D-346 a D-359): confirmar el mes o no estar de acuerdo
| (con motivo), la descarga de «Mi registro», el tope de horas extra, clasificar una hora extra,
| desconfirmar un mes, los informes, los documentos con «He leído», las pestañas por permiso y la
| entrada de la Inspección.
*/

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    get: vi.fn(),
    page: { url: '/personas/registro', props: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => inertia.page,
    router: {
        post: inertia.post,
        put: inertia.put,
        get: inertia.get,
        delete: vi.fn(),
        visit: vi.fn(),
        on: () => () => {},
    },
    Link: ({
        href,
        children,
        preserveScroll: _p,
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

const abilities = (overrides: Partial<Abilities> = {}): Abilities =>
    ({
        viewAbsences: true,
        usePeople: true,
        clock: true,
        viewPeopleTeam: false,
        managePeopleRegister: false,
        ...overrides,
    }) as Abilities;

const totals = {
    worked_minutes: 9600,
    expected_minutes: 10080,
    difference_minutes: -480,
    excess_minutes: 90,
    overtime_minutes: 60,
    overtime_compensate_minutes: 60,
    overtime_pay_minutes: 0,
    complementary_minutes: 0,
    flex_minutes: 30,
    unclassified_minutes: 0,
    days_worked: 20,
    incident_days: 2,
    onsite_days: 15,
    remote_days: 5,
    absence_days: 1,
    pending_correction_days: 0,
    disputed_correction_days: 0,
};

function close(overrides: Partial<MonthClose> = {}): MonthClose {
    return {
        id: 11,
        user_id: 3,
        month: '2026-09',
        version: 1,
        status: 'pending',
        worked_minutes: 9600,
        expected_minutes: 10080,
        difference_minutes: -480,
        overtime_minutes: 60,
        totals,
        generated_at: '2026-10-01T04:00:00Z',
        confirmed_at: null,
        disagreed_at: null,
        disagreement_note: null,
        reopened_at: null,
        reopened_by: null,
        reopen_reason: null,
        pdf_sha256: 'b'.repeat(64),
        content_hash: 'c'.repeat(64),
        register_seq: 120,
        can: { confirm: true, disagree: true },
        ...overrides,
    };
}

const year = (overrides: Partial<YearSummary> = {}): YearSummary => ({
    year: 2026,
    overtime_minutes: 600,
    compensate_minutes: 360,
    pay_minutes: 240,
    complementary_minutes: 0,
    cap_minutes: 4800,
    remaining_minutes: 4200,
    level: 'ok',
    ...overrides,
});

beforeEach(() => {
    inertia.post.mockReset();
    inertia.put.mockReset();
    inertia.get.mockReset();
    inertia.page.props = {
        auth: { user: { id: 3, name: 'Elena' }, can: abilities() },
        people: {
            clock: null,
            pending: 0,
            pending_close: { id: 11, month: '2026-09' },
            unread_documents: 1,
        },
        timer: null,
    };
});

describe('resumen del mes', () => {
    it('«Confirmar el mes» lo confirma sin más datos', async () => {
        render(<CloseAnswerCard close={close()} />);

        expect(
            screen.getByRole('heading', { name: /septiembre de 2026/ }),
        ).toBeTruthy();
        await userEvent.click(screen.getByTestId('close-confirm'));

        expect(inertia.post).toHaveBeenCalledWith(
            '/personas/cierres/11/confirmar',
            {},
            expect.anything(),
        );
    });

    it('«No estoy de acuerdo» pide un motivo de al menos 5 caracteres', async () => {
        render(<CloseAnswerCard close={close()} />);

        await userEvent.click(screen.getByTestId('close-disagree'));
        const submit = screen.getByTestId('close-disagree-submit');
        expect((submit as HTMLButtonElement).disabled).toBe(true);

        await userEvent.type(
            screen.getByTestId('close-disagree-note'),
            'El día 2 salí a las 20:00',
        );
        await userEvent.click(submit);

        expect(inertia.post).toHaveBeenCalledWith(
            '/personas/cierres/11/desacuerdo',
            { note: 'El día 2 salí a las 20:00' },
            expect.anything(),
        );
    });

    it('en desacuerdo, se puede confirmar después pero no volver a discrepar', () => {
        render(
            <CloseAnswerCard
                close={close({
                    status: 'disagreed',
                    disagreement_note: 'No cuadra',
                    disagreed_at: '2026-10-02T08:00:00Z',
                    can: { confirm: true, disagree: false },
                })}
            />,
        );

        expect(screen.getByTestId('close-confirm')).toBeTruthy();
        expect(screen.queryByTestId('close-disagree')).toBeNull();
        expect(screen.getByText('No cuadra')).toBeTruthy();
    });
});

describe('Mi registro', () => {
    const props = (
        overrides: Partial<RegisterPageProps> = {},
    ): RegisterPageProps => ({
        subject: { id: 3, name: 'Elena' },
        today: '2026-10-07',
        register_start: '2026-09-01',
        period: { from: '2026-10-01', to: '2026-10-07' },
        max_days: 366,
        closes: [
            close(),
            close({
                id: 10,
                month: '2026-08',
                status: 'confirmed',
                confirmed_at: '2026-09-02T08:00:00Z',
                can: { confirm: false, disagree: false },
            }),
        ],
        overtime: year(),
        decisions: [],
        balance: {
            minutes: 120,
            pending: [
                {
                    date: '2026-09-02',
                    minutes: 120,
                    remaining_minutes: 120,
                    deadline: '2027-01-02',
                    expired: false,
                    kind: 'overtime',
                },
            ],
            movements: [],
        },
        subject_to_register: true,
        ...overrides,
    });

    it('descarga el registro del periodo elegido en PDF, Excel y CSV', async () => {
        render(<RegisterPage {...props()} />);

        expect(
            screen.getByTestId('register-download-pdf').getAttribute('href'),
        ).toBe(
            '/personas/registro/descargar?desde=2026-10-01&hasta=2026-10-07&formato=pdf',
        );

        const from = screen.getByTestId('register-from');
        await userEvent.clear(from);
        await userEvent.type(from, '2026-09-01');

        expect(
            screen.getByTestId('register-download-csv').getAttribute('href'),
        ).toBe(
            '/personas/registro/descargar?desde=2026-09-01&hasta=2026-10-07&formato=csv',
        );
    });

    it('arriba el resumen por confirmar; los cierres con su estado y el saldo de horas', () => {
        render(<RegisterPage {...props()} />);

        expect(
            screen.getByTestId('close-answer').getAttribute('data-month'),
        ).toBe('2026-09');
        const items = screen.getAllByTestId('close-item');
        expect(items.map((item) => item.getAttribute('data-status'))).toEqual([
            'pending',
            'confirmed',
        ]);
        expect(screen.getByTestId('balance-total').textContent).toBe('2:00');
        expect(
            within(screen.getByTestId('balance-pending')).getByText(
                /02\/01\/2027/,
            ),
        ).toBeTruthy();
    });

    it('un periodo al revés no deja descargar', async () => {
        render(
            <RegisterPage
                {...props({ period: { from: '2026-10-07', to: '2026-10-01' } })}
            />,
        );

        expect(screen.queryByTestId('register-download-pdf')).toBeNull();
        expect(screen.getByText(/El periodo no es válido/)).toBeTruthy();
    });
});

describe('tope de horas extra', () => {
    it('la barra lleva el nivel y el texto accesible', () => {
        render(
            <CapBar
                summary={year({
                    overtime_minutes: 65 * 60,
                    remaining_minutes: 15 * 60,
                    level: 'near',
                })}
            />,
        );

        const bar = screen.getByRole('progressbar');
        expect(bar.getAttribute('aria-valuenow')).toBe(String(65 * 60));
        expect(screen.getByTestId('cap-bar').getAttribute('data-level')).toBe(
            'near',
        );
        expect(screen.getByText('Cerca del tope (más de 60 h)')).toBeTruthy();
    });
});

describe('horas extra', () => {
    const props = (
        overrides: Partial<OvertimePageProps> = {},
    ): OvertimePageProps => ({
        month: '2026-10',
        current_month: '2026-10',
        pending: [
            {
                user: { id: 5, name: 'Pablo Ruiz' },
                date: '2026-10-05',
                excess_minutes: 120,
                worked_minutes: 600,
                expected_minutes: 480,
                stale: false,
                previous: null,
                part_time: false,
                month_confirmed: false,
                rest_preview: 160,
            },
        ],
        decided: [],
        people: [
            {
                user: { id: 5, name: 'Pablo Ruiz' },
                part_time: false,
                year: year(),
                balance_minutes: 0,
                expiring: 0,
            },
        ],
        person: null,
        manages_all: false,
        rest_minutes_per_hour: 80,
        cap_minutes: 4800,
        ...overrides,
    });

    it('clasifica 1:30 como hora extra a compensar y el resto como flexibilidad', async () => {
        render(<OvertimePage {...props()} />);

        const minutes = screen.getByTestId('overtime-minutes');
        await userEvent.clear(minutes);
        await userEvent.type(minutes, '1:30');
        expect(
            screen.getByText('El resto, 0:30, es flexibilidad.'),
        ).toBeTruthy();
        expect(screen.getByText('Compensar con descanso (2:00)')).toBeTruthy();

        await userEvent.click(screen.getByTestId('overtime-save'));

        expect(inertia.post).toHaveBeenCalledWith(
            '/personas/horas-extra',
            {
                user_id: 5,
                date: '2026-10-05',
                overtime_minutes: 90,
                destination: 'compensate',
                note: null,
            },
            expect.anything(),
        );
    });

    it('«Todo flexibilidad» manda 0 sin destino; más que el exceso no se puede guardar', async () => {
        render(<OvertimePage {...props()} />);

        const minutes = screen.getByTestId('overtime-minutes');
        await userEvent.clear(minutes);
        await userEvent.type(minutes, '3:00');
        expect(
            (screen.getByTestId('overtime-save') as HTMLButtonElement).disabled,
        ).toBe(true);

        await userEvent.click(screen.getByTestId('overtime-all-flex'));
        await userEvent.click(screen.getByTestId('overtime-save'));

        expect(inertia.post).toHaveBeenCalledWith(
            '/personas/horas-extra',
            expect.objectContaining({ overtime_minutes: 0, destination: null }),
            expect.anything(),
        );
    });

    it('a tiempo parcial no se puede compensar: son horas complementarias que se pagan', () => {
        render(
            <OvertimePage
                {...props({
                    pending: [{ ...props().pending[0], part_time: true }],
                })}
            />,
        );

        expect(screen.getByText('Horas complementarias')).toBeTruthy();
        expect(
            screen
                .getByTestId('overtime-destination-compensate')
                .hasAttribute('disabled'),
        ).toBe(true);
    });

    it('con el mes confirmado no se clasifica', () => {
        render(
            <OvertimePage
                {...props({
                    pending: [{ ...props().pending[0], month_confirmed: true }],
                })}
            />,
        );

        expect(screen.queryByTestId('overtime-save')).toBeNull();
        expect(screen.getByText(/desconfírmalo en «Cierres»/)).toBeTruthy();
    });
});

describe('cierres del equipo', () => {
    it('desconfirmar pide un motivo', async () => {
        inertia.page.props = {
            ...inertia.page.props,
            auth: {
                user: { id: 2, name: 'Raúl' },
                can: abilities({ viewPeopleTeam: true }),
            },
        };
        const props: ClosesPageProps = {
            month: '2026-09',
            current_month: '2026-10',
            rows: [
                {
                    user: {
                        id: 3,
                        name: 'Elena Empleada',
                        department: 'Diseño',
                    },
                    state: 'confirmed',
                    close: close({
                        status: 'confirmed',
                        confirmed_at: '2026-10-02T08:00:00Z',
                    }),
                    versions: 1,
                    can: { generate: false, reopen: true, remind: false },
                },
            ],
            counts: {
                confirmed: 1,
                pending: 0,
                disagreed: 0,
                reopened: 0,
                missing: 0,
            },
            departments: [{ id: 1, name: 'Diseño' }],
            department_id: null,
            manages_all: false,
        };
        render(<ClosesPage {...props} />);

        await userEvent.click(screen.getAllByTestId('close-reopen')[0]);
        expect(
            (screen.getByTestId('close-reopen-submit') as HTMLButtonElement)
                .disabled,
        ).toBe(true);
        await userEvent.type(
            screen.getByTestId('close-reopen-reason'),
            'Hay que corregir el día 1',
        );
        await userEvent.click(screen.getByTestId('close-reopen-submit'));

        expect(inertia.post).toHaveBeenCalledWith(
            '/personas/cierres/11/desconfirmar',
            { reason: 'Hay que corregir el día 1' },
            expect.anything(),
        );
    });
});

describe('informes', () => {
    it('cambia de informe y descarga con el ámbito elegido', async () => {
        inertia.page.props = {
            ...inertia.page.props,
            auth: {
                user: { id: 1, name: 'Ana' },
                can: abilities({
                    viewPeopleTeam: true,
                    managePeopleRegister: true,
                }),
            },
        };
        const props: ReportsPageProps = {
            kinds: [
                {
                    value: 'registro-mensual',
                    title: 'Registro mensual de la jornada',
                    monthly: true,
                },
                { value: 'fichajes', title: 'Fichajes', monthly: false },
            ],
            kind: 'registro-mensual',
            filters: {
                month: '2026-09',
                from: '2026-10-01',
                to: '2026-10-07',
                user_ids: [],
                department_id: null,
            },
            today: '2026-10-07',
            people: [{ id: 3, name: 'Elena Empleada', active: true }],
            departments: [],
            preview: {
                title: 'Registro mensual de la jornada',
                headers: ['Persona', 'Fecha'],
                rows: [['Elena Empleada', '2026-09-01']],
                total: 1,
                content_hash: 'd'.repeat(64),
            },
            exports: [],
        };
        render(<ReportsPage {...props} />);

        expect(
            screen.getByTestId('report-download-pdf').getAttribute('href'),
        ).toBe('/personas/informes/registro-mensual?mes=2026-09&formato=pdf');
        expect(
            within(screen.getByTestId('report-preview')).getByText(
                'Elena Empleada',
            ),
        ).toBeTruthy();

        await userEvent.click(screen.getByTestId('report-kind-fichajes'));
        expect(inertia.get).toHaveBeenCalledWith(
            '/personas/informes?informe=fichajes&desde=2026-10-01&hasta=2026-10-07',
            {},
            expect.anything(),
        );
    });
});

describe('documentos', () => {
    it('«He leído» marca la versión vigente; RR. HH. ve quién lo ha leído', async () => {
        render(
            <DocumentsPage
                documents={[
                    {
                        id: 4,
                        key: 'register_protocol',
                        version: 1,
                        title: 'Registro de jornada',
                        body: '## Para qué\\n\\nTexto.',
                        is_draft: true,
                        published_at: null,
                        published_by: null,
                        read_at: null,
                        readers: [{ id: 3, name: 'Elena', read_at: null }],
                    },
                    {
                        id: 5,
                        key: 'disconnection_policy',
                        version: 2,
                        title: 'Desconexión',
                        body: 'Texto.',
                        is_draft: false,
                        published_at: '2026-10-01T08:00:00Z',
                        published_by: 'Ana',
                        read_at: '2026-10-02T08:00:00Z',
                        readers: null,
                    },
                ]}
                can_publish
                max_length={30000}
            />,
        );

        expect(screen.getByText('Pendiente de asesor')).toBeTruthy();
        expect(screen.getAllByTestId('document-read')).toHaveLength(1);
        expect(screen.getByTestId('document-read-at').textContent).toMatch(
            /versión 2/,
        );
        expect(screen.getByTestId('document-readers').textContent).toMatch(
            /0 de 1/,
        );

        await userEvent.click(screen.getByTestId('document-read'));
        expect(inertia.post).toHaveBeenCalledWith(
            '/personas/documentos/4/leido',
            {},
            expect.anything(),
        );
    });
});

describe('pestañas del registro', () => {
    it('cada persona ve las suyas; el responsable, las del equipo; RR. HH., también informes e Inspección', () => {
        expect(peopleTabs({}, {}).map((tab) => tab.id)).toEqual([
            'workday',
            'register',
            'documents',
        ]);
        expect(
            peopleTabs({ viewPeopleTeam: true }, { pending: 2 }).map(
                (tab) => tab.id,
            ),
        ).toEqual([
            'workday',
            'register',
            'documents',
            'team',
            'pending',
            'closes',
            'overtime',
        ]);
        expect(
            peopleTabs({ viewPeopleTeam: true, managePeopleRegister: true }, {})
                .map((tab) => tab.id)
                .slice(-2),
        ).toEqual(['reports', 'inspection']);
        expect(
            peopleTabs({}, { pendingClose: true, unreadDocuments: 2 }).map(
                (tab) => tab.count,
            ),
        ).toEqual([undefined, 1, 2]);
    });
});

describe('entrada de la Inspección', () => {
    it('pide el código y lo manda con el enlace; si no está disponible, lo dice', async () => {
        const { unmount } = render(
            <InspectionAccessPage
                token={'a'.repeat(48)}
                name="Inspectora Martínez"
                usable
                state="active"
            />,
        );

        expect(screen.getByTestId('read-only-banner')).toBeTruthy();
        await userEvent.type(
            screen.getByTestId('inspection-code'),
            '1234-5678',
        );
        await userEvent.click(screen.getByTestId('inspection-enter'));
        expect(inertia.post).toHaveBeenCalledWith(
            `/inspeccion/acceso/${'a'.repeat(48)}`,
            { code: '1234-5678' },
            expect.anything(),
        );
        unmount();

        render(
            <InspectionAccessPage
                token={'a'.repeat(48)}
                name="Inspectora"
                usable={false}
                state="expired"
            />,
        );
        expect(screen.queryByTestId('inspection-code')).toBeNull();
        expect(screen.getByRole('status').textContent).toMatch(/Caducado/);
    });
});
