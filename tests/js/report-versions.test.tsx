// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useRef, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ScheduleReportDialog } from '@/components/reports/delivery/schedule-report-dialog';
import { SendReportDialog } from '@/components/reports/delivery/send-report-dialog';
import { ExportMenu } from '@/components/reports/export-menu';
import {
    REPORT_VERSIONS,
    reportVersionOf,
    supportsVersions,
    withVersion,
} from '@/components/reports/report-request';
import type { ReportScheduleDetail } from '@/types/report-deliveries';
import type { ReportRequestData } from '@/types/reports';

/*
| Versión del informe de proyecto (D-240 a D-242): en el menú «Exportar ▾» se elige «Interno
| (completo)» o «Para el cliente» y todo lo del menú sale en esa versión (Excel, CSV, PDF,
| Imprimir, Google Sheets); «Enviar por correo» y «Programar envío» la llevan en el informe
| (`request.query.version`) y la dejan cambiar. Sin versiones (otros informes o quien solo ve
| las horas de su equipo), ni selector ni `version=`.
*/

/** Con userEvent se escribe tecla a tecla: con la máquina cargada, 5 s no bastan. */
const SLOW = { timeout: 20_000 };

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
        usePage: () => ({
            url: '/informes/proyectos/7',
            props: {
                integrations: { google_sheets: true, google_connected: false },
            },
        }),
        useForm,
        router: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
    };
});

const PROJECT: ReportRequestData = {
    kind: 'project',
    route_params: { project: 7 },
    query: { periodo: 'mes', fecha: '2026-09-01' },
};

beforeEach(() => {
    forms.submitted = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve(
                new Response(JSON.stringify({ people: [] }), {
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

const href = (name: string) =>
    (screen.getByRole('menuitem', { name }) as HTMLAnchorElement).getAttribute(
        'href',
    ) ?? '';

describe('versiones del informe', () => {
    it('la versión de un informe es la de su `version=` o la interna', () => {
        expect(reportVersionOf(PROJECT)).toBe('interno');
        expect(reportVersionOf(withVersion(PROJECT, 'cliente'))).toBe(
            'cliente',
        );
        expect(
            reportVersionOf({ ...PROJECT, query: { version: 'otra' } }),
        ).toBe('interno');
        expect(supportsVersions('project')).toBe(true);
        expect(supportsVersions('client')).toBe(false);
        expect(REPORT_VERSIONS).toEqual(['interno', 'cliente']);
    });
});

describe('ExportMenu con versiones', () => {
    it(
        'elige la versión sin cerrar el menú y todo sale en ella',
        SLOW,
        async () => {
            const user = userEvent.setup();
            render(
                <ExportMenu
                    request={PROJECT}
                    title="Informe de NAN-WEB"
                    versions={REPORT_VERSIONS}
                />,
            );

            await user.click(screen.getByRole('button', { name: 'Exportar' }));

            const interno = screen.getByRole('menuitemradio', {
                name: 'Interno (completo)',
            });
            const cliente = screen.getByRole('menuitemradio', {
                name: 'Para el cliente',
            });
            expect(interno.getAttribute('aria-checked')).toBe('true');
            expect(href('Excel (.xlsx)')).toContain(
                'version=interno&formato=xlsx',
            );

            await user.click(cliente);

            // El menú sigue abierto, con la versión para el cliente marcada.
            expect(cliente.getAttribute('aria-checked')).toBe('true');
            expect(href('Excel (.xlsx)')).toContain(
                'version=cliente&formato=xlsx',
            );
            expect(href('CSV (.csv)')).toContain('version=cliente&formato=csv');
            expect(href('PDF')).toContain('version=cliente&formato=pdf');
            expect(href('Imprimir')).toContain(
                'version=cliente&formato=imprimir',
            );
        },
    );

    it('sin versiones (otro informe o solo las horas del equipo), ni selector ni `version=`', async () => {
        const user = userEvent.setup();
        render(<ExportMenu request={PROJECT} title="Informe de NAN-WEB" />);

        await user.click(screen.getByRole('button', { name: 'Exportar' }));

        expect(screen.queryByRole('menuitemradio')).toBeNull();
        expect(href('PDF')).not.toContain('version=');
    });

    it(
        '«Enviar por correo…» lleva la versión elegida y deja cambiarla',
        SLOW,
        async () => {
            const user = userEvent.setup();
            render(
                <ExportMenu
                    request={PROJECT}
                    title="Informe de NAN-WEB"
                    versions={REPORT_VERSIONS}
                />,
            );

            await user.click(screen.getByRole('button', { name: 'Exportar' }));
            await user.click(
                screen.getByRole('menuitemradio', { name: 'Para el cliente' }),
            );
            await user.click(
                screen.getByRole('menuitem', { name: 'Enviar por correo…' }),
            );

            const dialog = await screen.findByRole('dialog');
            const field = within(dialog).getByRole('radiogroup', {
                name: 'Versión del informe',
            });
            expect(
                within(field)
                    .getByRole('radio', { name: 'Para el cliente' })
                    .getAttribute('aria-checked'),
            ).toBe('true');

            await user.type(
                within(dialog).getByLabelText('Correos externos'),
                'cliente@example.com',
            );
            await user.click(
                within(dialog).getByRole('button', { name: 'Enviar' }),
            );

            expect(forms.submitted).toHaveLength(1);
            expect(forms.submitted[0].url).toBe('/informes/enviar');
            expect(
                (forms.submitted[0].data as { request: ReportRequestData })
                    .request.query,
            ).toEqual({
                periodo: 'mes',
                fecha: '2026-09-01',
                version: 'cliente',
            });
        },
    );
});

describe('diálogos con versión', () => {
    it(
        '«Enviar por correo»: se cambia a la interna antes de enviar',
        SLOW,
        async () => {
            const user = userEvent.setup();
            render(
                <SendReportDialog
                    open
                    onOpenChange={() => {}}
                    request={withVersion(PROJECT, 'cliente')}
                    title="Informe de NAN-WEB"
                    versions={REPORT_VERSIONS}
                />,
            );

            const dialog = screen.getByRole('dialog');
            await user.click(
                within(dialog).getByRole('radio', {
                    name: 'Interno (completo)',
                }),
            );
            await user.type(
                within(dialog).getByLabelText('Correos externos'),
                'equipo@example.com',
            );
            await user.click(
                within(dialog).getByRole('button', { name: 'Enviar' }),
            );

            expect(
                (forms.submitted[0].data as { request: ReportRequestData })
                    .request.query.version,
            ).toBe('interno');
        },
    );

    it(
        'sin versiones, el diálogo no pregunta ni añade `version=`',
        SLOW,
        async () => {
            const user = userEvent.setup();
            render(
                <SendReportDialog
                    open
                    onOpenChange={() => {}}
                    request={PROJECT}
                    title="Informe de NAN-WEB"
                />,
            );

            const dialog = screen.getByRole('dialog');
            expect(within(dialog).queryByRole('radiogroup')).toBeNull();
            await user.type(
                within(dialog).getByLabelText('Correos externos'),
                'equipo@example.com',
            );
            await user.click(
                within(dialog).getByRole('button', { name: 'Enviar' }),
            );

            expect(
                (forms.submitted[0].data as { request: ReportRequestData })
                    .request.query,
            ).toEqual(PROJECT.query);
        },
    );

    it(
        '«Programar envío» de uno guardado: parte de su versión y guarda la elegida',
        SLOW,
        async () => {
            const user = userEvent.setup();
            const schedule = {
                id: 5,
                title: 'Informe de NAN-WEB',
                kind: 'project',
                version: 'cliente',
                owner: { id: 1, name: 'Admin' },
                formats: ['pdf'],
                relative_period: 'previous',
                frequency: 'monthly',
                run_date: null,
                weekday: null,
                month_day: 1,
                time: '08:00',
                recipient_count: 1,
                external_count: 1,
                is_active: true,
                paused_reason: null,
                paused_reason_label: null,
                next_run_at: null,
                last_run_at: null,
                last_status: null,
                request: withVersion(PROJECT, 'cliente'),
                recipient_user_ids: [],
                recipient_users: [],
                recipient_emails: ['cliente@example.com'],
                subject: null,
                message: null,
                deliveries: [],
            } as unknown as ReportScheduleDetail;

            render(
                <ScheduleReportDialog
                    open
                    onOpenChange={() => {}}
                    request={schedule.request}
                    title={schedule.title}
                    schedule={schedule}
                    versions={REPORT_VERSIONS}
                />,
            );

            const dialog = screen.getByRole('dialog');
            expect(
                within(dialog)
                    .getByRole('radio', { name: 'Para el cliente' })
                    .getAttribute('aria-checked'),
            ).toBe('true');

            await user.click(
                within(dialog).getByRole('radio', {
                    name: 'Interno (completo)',
                }),
            );
            await user.click(
                within(dialog).getByRole('button', { name: 'Guardar' }),
            );

            expect(forms.submitted[0].method).toBe('put');
            expect(
                (forms.submitted[0].data as { request: ReportRequestData })
                    .request.query.version,
            ).toBe('interno');
        },
    );
});
