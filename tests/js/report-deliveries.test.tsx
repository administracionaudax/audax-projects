// @vitest-environment jsdom
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useRef, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    schedulePayload,
    ScheduleReportDialog,
    tomorrowInMadrid,
} from '@/components/reports/delivery/schedule-report-dialog';
import {
    describeSchedule,
    describeWhen,
    relativeLabel,
    reportPeriodOf,
} from '@/components/reports/delivery/schedule-preview';
import { SendReportDialog } from '@/components/reports/delivery/send-report-dialog';
import type { ReportScheduleDetail } from '@/types/report-deliveries';
import type { ReportRequestData } from '@/types/reports';

/** Con userEvent se escribe tecla a tecla: con la máquina cargada, 5 s no bastan. */
const SLOW = { timeout: 20_000 };

/*
| Envío por correo y envíos programados (D-141) en el navegador: la frase de la vista previa, el
| diálogo «Enviar por correo» y el de «Programar envío» (lo que envían al servidor).
*/

const forms = vi.hoisted(() => ({
    submitted: [] as { method: string; url: string; data: unknown }[],
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    function useForm<T extends Record<string, unknown>>(initial: T) {
        const [data, setDataState] = useState<T>(initial);
        const transform = useRef<(data: T) => unknown>((value) => value);
        const send = (method: string) => (url: string) =>
            forms.submitted.push({
                method,
                url,
                data: transform.current(data),
            });

        return {
            data,
            errors: {},
            processing: false,
            setData: (key: keyof T | T | ((data: T) => T), value?: unknown) =>
                setDataState((current) =>
                    typeof key === 'function'
                        ? key(current)
                        : typeof key === 'object'
                          ? key
                          : { ...current, [key]: value },
                ),
            transform: (fn: (data: T) => unknown) => {
                transform.current = fn;
            },
            put: send('put'),
            post: send('post'),
            reset: () => setDataState(initial),
            clearErrors: () => {},
        };
    }

    return {
        ...original,
        usePage: () => ({ url: '/informes/detalle', props: {} }),
        useForm,
        router: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
    };
});

const PEOPLE = [
    { id: 3, name: 'Berta Gómez' },
    { id: 4, name: 'Carlos Ruiz' },
];

beforeEach(() => {
    forms.submitted = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve(
                new Response(JSON.stringify({ people: PEOPLE }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            ),
        ),
    );
    if (!('hasPointerCapture' in Element.prototype)) {
        Object.assign(Element.prototype, {
            hasPointerCapture: () => false,
            releasePointerCapture: () => {},
        });
    }
});

afterEach(() => {
    vi.unstubAllGlobals();
});

function preview(dialog: HTMLElement): HTMLElement {
    const element = dialog.querySelector<HTMLElement>(
        '[data-test="schedule-preview"]',
    );

    if (!element) {
        throw new Error('Sin vista previa');
    }

    return element;
}

const REQUEST: ReportRequestData = {
    kind: 'client',
    route_params: { client: 12 },
    query: { periodo: 'mes', fecha: '2026-09-01' },
};

describe('vista previa en lenguaje natural', () => {
    const monthly = {
        frequency: 'monthly' as const,
        run_date: null,
        weekday: null,
        month_day: 1,
        time: '08:00',
    };

    it('describe el envío mensual con el informe del mes anterior', () => {
        expect(describeSchedule(monthly, 'previous', 'mes')).toBe(
            'El día 1 de cada mes a las 08:00, con el informe del mes anterior',
        );
    });

    it('describe el último día, cada semana y una vez', () => {
        expect(describeWhen({ ...monthly, month_day: 0 })).toBe(
            'El último día de cada mes a las 08:00',
        );
        expect(
            describeSchedule(
                { ...monthly, frequency: 'weekly', weekday: 1, time: '09:30' },
                'current',
                'semana',
            ),
        ).toBe('Cada lunes a las 09:30, con el informe de la semana en curso');
        expect(
            describeSchedule(
                { ...monthly, frequency: 'once', run_date: '2026-10-05' },
                'fixed',
                'mes',
            ),
        ).toBe(
            'El 05/10/2026 a las 08:00, con el informe del periodo guardado',
        );
        expect(
            describeSchedule(
                { ...monthly, frequency: 'weekly', weekday: 7 },
                'previous',
                'trimestre',
            ),
        ).toBe(
            'Cada domingo a las 08:00, con el informe del trimestre anterior',
        );
    });

    it('etiqueta el periodo relativo según el periodo del informe (por defecto, el mes)', () => {
        expect(relativeLabel('previous', 'anio')).toBe('El año anterior');
        expect(relativeLabel('current', 'semana')).toBe('La semana en curso');
        expect(relativeLabel('fixed', 'mes')).toBe('Fijo: el periodo de ahora');
        expect(reportPeriodOf({ periodo: 'trimestre' })).toBe('trimestre');
        expect(reportPeriodOf({ periodo: 'otro' })).toBe('mes');
        expect(reportPeriodOf({})).toBe('mes');
    });

    it('calcula mañana en Madrid aunque en UTC aún sea hoy', () => {
        // 31/12 a las 23:30 UTC ya es 1 de enero en Madrid: mañana es el 2.
        expect(tomorrowInMadrid(new Date('2026-12-31T23:30:00Z'))).toBe(
            '2027-01-02',
        );
    });
});

describe('SendReportDialog', () => {
    it(
        'envía el informe con sus filtros a personas y correos externos, y avisa si sale de la empresa',
        SLOW,
        async () => {
            const user = userEvent.setup();
            const onOpenChange = vi.fn();

            render(
                <SendReportDialog
                    open
                    onOpenChange={onOpenChange}
                    request={REQUEST}
                    title="Informe de cliente · Montó"
                />,
            );

            const dialog = screen.getByRole('dialog', {
                name: 'Enviar por correo',
            });
            expect(
                within(dialog).getByText(/Informe de cliente · Montó/),
            ).toBeTruthy();
            expect(
                within(dialog).queryByText(
                    'Este informe saldrá de la empresa.',
                ),
            ).toBeNull();

            // Excel además del PDF.
            await user.click(
                within(dialog).getByRole('checkbox', { name: 'Excel' }),
            );

            // Una persona de la app con el buscador.
            const people = await within(dialog).findByRole('combobox', {
                name: /Personas de Audax: Nadie/,
            });
            await user.click(people);
            await user.click(
                await screen.findByRole('option', { name: 'Berta Gómez' }),
            );
            await user.keyboard('{Escape}');

            // Un correo externo con Intro.
            const email = within(dialog).getByLabelText('Correos externos');
            await user.type(email, 'Cliente@Example.com{Enter}');

            expect(
                within(dialog).getByText('Este informe saldrá de la empresa.'),
            ).toBeTruthy();
            expect(
                within(dialog).getByText('cliente@example.com'),
            ).toBeTruthy();

            await user.type(
                within(dialog).getByLabelText(/Mensaje/),
                'Te lo adjunto.',
            );
            await user.click(
                within(dialog).getByRole('button', { name: 'Enviar' }),
            );

            expect(forms.submitted).toHaveLength(1);
            expect(forms.submitted[0]).toMatchObject({
                method: 'post',
                url: '/informes/enviar',
                data: {
                    request: REQUEST,
                    title: 'Informe de cliente · Montó',
                    formats: ['pdf', 'xlsx'],
                    recipient_user_ids: [3],
                    recipient_emails: ['cliente@example.com'],
                    subject: '',
                    message: 'Te lo adjunto.',
                },
            });
        },
    );

    it(
        'añade el correo que queda escrito al enviar y no envía uno mal escrito',
        SLOW,
        async () => {
            const user = userEvent.setup();

            render(
                <SendReportDialog
                    open
                    onOpenChange={() => {}}
                    request={REQUEST}
                    title="Informe"
                />,
            );

            const dialog = screen.getByRole('dialog');
            const email = within(dialog).getByLabelText('Correos externos');

            await user.type(email, 'no-es-correo');
            await user.click(
                within(dialog).getByRole('button', { name: 'Enviar' }),
            );

            expect(forms.submitted).toHaveLength(0);
            expect(
                within(dialog).getAllByText(
                    '«no-es-correo» no parece un correo válido.',
                ).length,
            ).toBeGreaterThan(0);

            await user.clear(email);
            await user.type(email, 'otra@example.com');
            await user.click(
                within(dialog).getByRole('button', { name: 'Enviar' }),
            );

            expect(forms.submitted).toHaveLength(1);
            expect(forms.submitted[0].data).toMatchObject({
                recipient_emails: ['otra@example.com'],
                formats: ['pdf'],
            });
        },
    );

    it('no permite enviar sin formato', async () => {
        const user = userEvent.setup();

        render(
            <SendReportDialog
                open
                onOpenChange={() => {}}
                request={REQUEST}
                title="Informe"
            />,
        );

        const dialog = screen.getByRole('dialog');
        await user.click(within(dialog).getByRole('checkbox', { name: 'PDF' }));

        expect(
            (
                within(dialog).getByRole('button', {
                    name: 'Enviar',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });
});

describe('ScheduleReportDialog', () => {
    it(
        'programa un envío con la vista previa en vivo y solo los campos de la frecuencia',
        SLOW,
        async () => {
            const user = userEvent.setup();

            render(
                <ScheduleReportDialog
                    open
                    onOpenChange={() => {}}
                    request={REQUEST}
                    title="Informe de cliente · Montó"
                />,
            );

            const dialog = screen.getByRole('dialog', {
                name: 'Programar envío',
            });
            const sentence = preview(dialog);

            expect(sentence.textContent).toContain(
                'El día 1 de cada mes a las 08:00, con el informe del mes anterior',
            );

            await user.selectOptions(
                within(dialog).getByLabelText('Día del mes'),
                'Último día del mes',
            );
            expect(sentence.textContent).toContain(
                'El último día de cada mes a las 08:00',
            );

            await user.click(
                within(dialog).getByRole('radio', { name: 'Cada semana' }),
            );
            await user.selectOptions(
                within(dialog).getByLabelText('Día de la semana'),
                'viernes',
            );
            await user.selectOptions(
                within(dialog).getByLabelText(/Periodo del informe/),
                'El mes en curso',
            );
            expect(sentence.textContent).toContain(
                'Cada viernes a las 08:00, con el informe del mes en curso',
            );

            await user.type(
                within(dialog).getByLabelText('Correos externos'),
                'cliente@example.com{Enter}',
            );
            await user.click(
                within(dialog).getByRole('button', { name: 'Programar' }),
            );

            expect(forms.submitted).toHaveLength(1);
            expect(forms.submitted[0]).toMatchObject({
                method: 'post',
                url: '/informes/envios',
                data: {
                    request: REQUEST,
                    frequency: 'weekly',
                    weekday: 5,
                    month_day: null,
                    run_date: null,
                    time: '08:00',
                    relative_period: 'current',
                    recipient_emails: ['cliente@example.com'],
                },
            });
        },
    );

    it(
        'edita uno programado con sus datos y lo guarda con PUT',
        SLOW,
        async () => {
            const user = userEvent.setup();
            const schedule = {
                id: 5,
                title: 'Dirección mensual',
                kind: 'direction',
                owner: { id: 1, name: 'Ana' },
                formats: ['xlsx'],
                relative_period: 'previous',
                frequency: 'monthly',
                run_date: null,
                weekday: null,
                month_day: 0,
                time: '18:00',
                recipient_count: 2,
                external_count: 1,
                is_active: true,
                paused_reason: null,
                paused_reason_label: null,
                next_run_at: '2026-10-31T17:00:00Z',
                last_run_at: null,
                last_status: null,
                request: {
                    kind: 'direction',
                    route_params: {},
                    query: { periodo: 'mes' },
                },
                recipient_user_ids: [4],
                recipient_users: [{ id: 4, name: 'Carlos Ruiz' }],
                recipient_emails: ['cliente@example.com'],
                subject: 'Cierre',
                message: null,
                deliveries: [],
            } satisfies ReportScheduleDetail;

            render(
                <ScheduleReportDialog
                    open
                    onOpenChange={() => {}}
                    request={schedule.request}
                    title={schedule.title}
                    schedule={schedule}
                />,
            );

            const dialog = screen.getByRole('dialog', {
                name: 'Editar envío programado',
            });
            expect(preview(dialog).textContent).toContain(
                'El último día de cada mes a las 18:00, con el informe del mes anterior',
            );
            expect(
                within(dialog).getByText('Este informe saldrá de la empresa.'),
            ).toBeTruthy();
            await waitFor(() =>
                expect(
                    within(dialog).getByRole('button', {
                        name: 'Quitar Carlos Ruiz',
                    }),
                ).toBeTruthy(),
            );

            await user.click(
                within(dialog).getByRole('button', { name: 'Guardar' }),
            );

            expect(forms.submitted[0]).toMatchObject({
                method: 'put',
                url: '/informes/envios/5',
                data: {
                    request: schedule.request,
                    formats: ['xlsx'],
                    month_day: 0,
                    time: '18:00',
                    subject: 'Cierre',
                    recipient_user_ids: [4],
                },
            });
        },
    );

    it('el informe de una bolsa no tiene periodo: siempre fijo, sin selector', () => {
        render(
            <ScheduleReportDialog
                open
                onOpenChange={() => {}}
                request={{
                    kind: 'hour_bank',
                    route_params: { project: 1, hourBank: 2 },
                    query: {},
                }}
                title="Bolsa"
            />,
        );

        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).queryByLabelText(/Periodo del informe/),
        ).toBeNull();
        expect(preview(dialog).textContent).toContain(
            'con el informe del periodo guardado',
        );
    });

    it('schedulePayload deja solo el día de la frecuencia elegida', () => {
        const payload = schedulePayload(
            {
                title: 'X',
                formats: ['pdf'],
                recipient_user_ids: [],
                recipient_emails: [],
                subject: '',
                message: '',
                relative_period: 'previous',
                frequency: 'once',
                run_date: '2026-10-05',
                weekday: 3,
                month_day: 7,
                time: '10:00',
            },
            REQUEST,
        );

        expect(payload).toMatchObject({
            run_date: '2026-10-05',
            weekday: null,
            month_day: null,
            request: REQUEST,
        });
    });
});
