// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { useRef, useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    describeRecurrence,
    nextOccurrence,
    occurrencesBetween,
} from '@/components/recurring/recurrence';
import type { Recurrence } from '@/components/recurring/recurrence';
import {
    initialRuleForm,
    RecurringRuleDialog,
} from '@/components/recurring/recurring-rule-dialog';
import { RecurringRules } from '@/components/recurring/recurring-rules-section';
import { Button } from '@/components/ui/button';
import type {
    ProjectRecurringSettings,
    RecurringOptions,
    RecurringRuleItem,
} from '@/types/templates';

/** Con userEvent se escribe tecla a tecla: con la máquina cargada, 5 s no bastan. */
const SLOW = { timeout: 20_000 };

/*
| Tareas recurrentes (D-059) en el navegador: la frase legible y las fechas (gemelas del servidor),
| el formulario con su vista previa en vivo y la lista de reglas de los Ajustes.
*/

const forms = vi.hoisted(() => ({
    submitted: [] as { method: string; url: string; data: unknown }[],
}));
const inertia = vi.hoisted(() => ({ put: vi.fn(), delete: vi.fn() }));

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
        usePage: () => ({ url: '/proyectos/1/ajustes', props: {} }),
        useForm,
        router: {
            get: vi.fn(),
            put: inertia.put,
            delete: inertia.delete,
            on: () => () => {},
        },
        Link: ({
            href,
            children,
            ...rest
        }: {
            href: string | { url: string };
            children?: ReactNode;
            [key: string]: unknown;
        }) => (
            <a href={typeof href === 'string' ? href : href.url} {...rest}>
                {children}
            </a>
        ),
    };
});

beforeEach(() => {
    forms.submitted = [];
    inertia.put.mockReset();
    inertia.delete.mockReset();
    // Radix usa la captura del puntero, que jsdom no tiene.
    if (!('hasPointerCapture' in Element.prototype)) {
        Object.assign(Element.prototype, {
            hasPointerCapture: () => false,
            releasePointerCapture: () => {},
        });
    }
});

const TODAY = '2026-10-05'; // lunes

const rule = (overrides: Partial<Recurrence> = {}): Recurrence => ({
    frequency: 'weekly',
    interval: 1,
    weekday: 1,
    month_day: null,
    starts_on: '2026-09-07',
    ends_on: null,
    ...overrides,
});

describe('frase legible (como RecurrenceDescriber)', () => {
    it.each([
        [rule(), 'Cada semana, los lunes'],
        [rule({ interval: 2 }), 'Cada 2 semanas, los lunes'],
        [rule({ interval: 3, weekday: 7 }), 'Cada 3 semanas, los domingos'],
        [rule({ weekday: 6 }), 'Cada semana, los sábados'],
        [
            rule({ frequency: 'monthly', weekday: null, month_day: 15 }),
            'Cada mes, el día 15',
        ],
        [
            rule({ frequency: 'monthly', weekday: null, month_day: 31 }),
            'Cada mes, el día 31 (o el último)',
        ],
        [
            rule({
                frequency: 'monthly',
                weekday: null,
                month_day: 29,
                interval: 3,
            }),
            'Cada 3 meses, el día 29 (o el último)',
        ],
    ])('%o → %s', (recurrence, phrase) => {
        expect(describeRecurrence(recurrence)).toBe(phrase);
    });
});

describe('fechas de las tareas (como occurrencesBetween)', () => {
    it('semanal cada 2 semanas desde la semana de inicio', () => {
        expect(
            occurrencesBetween(
                rule({ interval: 2, starts_on: '2026-10-01' }),
                '2026-10-01',
                '2026-11-10',
            ),
        ).toEqual(['2026-10-12', '2026-10-26', '2026-11-09']);
    });

    it('mensual el 31: el último día de los meses cortos, hasta la fecha final', () => {
        expect(
            occurrencesBetween(
                rule({
                    frequency: 'monthly',
                    weekday: null,
                    month_day: 31,
                    starts_on: '2026-01-15',
                    ends_on: '2026-04-30',
                }),
                '2026-01-01',
                '2026-12-31',
            ),
        ).toEqual(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
    });

    it('en un año bisiesto, febrero tiene 29', () => {
        expect(
            occurrencesBetween(
                rule({
                    frequency: 'monthly',
                    weekday: null,
                    month_day: 30,
                    starts_on: '2028-01-01',
                }),
                '2028-02-01',
                '2028-03-31',
            ),
        ).toEqual(['2028-02-29', '2028-03-30']);
    });

    it('la próxima: hoy si toca y aún no se ha creado; si no, la siguiente', () => {
        expect(nextOccurrence(rule(), TODAY)).toBe('2026-10-05');
        expect(nextOccurrence(rule(), TODAY, '2026-10-05')).toBe('2026-10-12');
        expect(nextOccurrence(rule({ weekday: 3 }), TODAY)).toBe('2026-10-07');
        expect(
            nextOccurrence(rule({ ends_on: '2026-09-30' }), TODAY),
        ).toBeNull();
        expect(
            nextOccurrence(
                rule({
                    frequency: 'monthly',
                    weekday: null,
                    month_day: 10,
                    interval: 12,
                    starts_on: '2026-03-10',
                }),
                TODAY,
            ),
        ).toBe('2027-03-10');
    });
});

const options: RecurringOptions = {
    members: [
        { id: 7, name: 'Elena Ruiz' },
        { id: 8, name: 'Pablo Gil' },
    ],
    types: [{ id: 3, name: 'Gestión' }],
    banks: [],
    uses_hour_banks: false,
};

const item = (
    overrides: Partial<RecurringRuleItem> = {},
): RecurringRuleItem => ({
    id: 5,
    project_id: 1,
    project: null,
    title: 'Informe semanal',
    description: null,
    task_type_id: 3,
    assignee_user_id: 7,
    assignee: { id: 7, name: 'Elena Ruiz', is_active: true },
    hour_bank_id: null,
    hour_bank: null,
    estimated_minutes: 60,
    priority: 'normal',
    frequency: 'weekly',
    interval: 1,
    weekday: 1,
    month_day: null,
    due_offset_days: 2,
    starts_on: '2026-09-07',
    ends_on: null,
    is_active: true,
    last_generated_on: '2026-10-05',
    summary: 'Cada semana, los lunes',
    next_date: '2026-10-12',
    warnings: [],
    ...overrides,
});

describe('formulario de una regla', SLOW, () => {
    const openDialog = async (rule?: RecurringRuleItem) => {
        const user = userEvent.setup();
        render(
            <RecurringRuleDialog
                projectId={1}
                options={options}
                today={TODAY}
                rule={rule}
                trigger={<Button>Abrir</Button>}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Abrir' }));

        return { user, dialog: screen.getByRole('dialog') };
    };

    const preview = (dialog: HTMLElement) =>
        dialog.querySelector('[data-test="rule-preview"]')?.textContent ?? '';

    it('por defecto, semanal el día de hoy: la tarea de hoy se crea al guardar', async () => {
        const { user, dialog } = await openDialog();

        expect(preview(dialog)).toContain('Cada semana, los lunes');
        expect(preview(dialog)).toContain(
            'Hoy toca: la tarea de hoy se creará al guardar.',
        );

        await user.selectOptions(
            within(dialog).getByLabelText('Día de la semana'),
            '3',
        );
        expect(preview(dialog)).toContain('Cada semana, los miércoles');
        expect(preview(dialog)).toContain('Próxima tarea: 07/10/2026.');

        await user.click(
            within(dialog).getByRole('switch', { name: 'Activa' }),
        );
        expect(preview(dialog)).toContain('Desactivada: no creará tareas.');
    }, 15_000);

    it('mensual: el día 31 avisa de que se usa el último día y la repetición cuenta', async () => {
        const { user, dialog } = await openDialog();

        await user.click(
            within(dialog).getByRole('radio', { name: 'Mensual' }),
        );
        const day = within(dialog).getByLabelText('Día del mes');
        await user.clear(day);
        await user.type(day, '31');
        expect(preview(dialog)).toContain('Cada mes, el día 31 (o el último)');
        expect(preview(dialog)).toContain('Próxima tarea: 31/10/2026.');

        const interval = within(dialog).getByLabelText('Cada cuántos meses');
        await user.clear(interval);
        await user.type(interval, '2');
        expect(preview(dialog)).toContain(
            'Cada 2 meses, el día 31 (o el último)',
        );
    }, 15_000);

    it(
        'envía la regla con solo el día de la frecuencia elegida',
        { timeout: 15_000 },
        async () => {
            const { user, dialog } = await openDialog();

            await user.type(
                within(dialog).getByLabelText('Título'),
                'Cierre de mes',
            );
            await user.selectOptions(
                within(dialog).getByLabelText('Responsable'),
                '8',
            );
            await user.click(
                within(dialog).getByRole('radio', { name: 'Mensual' }),
            );
            await user.click(
                within(dialog).getByRole('button', {
                    name: 'Crear tarea recurrente',
                }),
            );

            expect(forms.submitted).toHaveLength(1);
            expect(forms.submitted[0].method).toBe('post');
            expect(forms.submitted[0].url).toBe(
                '/proyectos/1/tareas-recurrentes',
            );
            expect(forms.submitted[0].data).toMatchObject({
                title: 'Cierre de mes',
                assignee_user_id: 8,
                frequency: 'monthly',
                weekday: null,
                month_day: 5,
                starts_on: TODAY,
                is_active: true,
            });
        },
    );

    it(
        'al editar, un responsable que ya no es miembro no se conserva y se explica',
        { timeout: 15_000 },
        async () => {
            const lost = item({
                assignee_user_id: 99,
                assignee: { id: 99, name: 'Marta Baja', is_active: false },
            });

            expect(
                initialRuleForm(options, TODAY, lost).assignee_user_id,
            ).toBeNull();

            const { user, dialog } = await openDialog(lost);
            expect(
                within(dialog).getByText(
                    'Marta Baja ya no es miembro activo del proyecto: elige a otra persona o déjala sin responsable.',
                ),
            ).toBeTruthy();

            await user.click(
                within(dialog).getByRole('button', { name: 'Guardar' }),
            );
            expect(forms.submitted[0]).toMatchObject({
                method: 'put',
                url: '/proyectos/1/tareas-recurrentes/5',
            });
        },
    );
});

describe('lista de reglas de los Ajustes', SLOW, () => {
    const settings = (
        overrides: Partial<ProjectRecurringSettings> = {},
    ): ProjectRecurringSettings => ({
        rules: [
            item(),
            item({
                id: 6,
                title: 'Cierre',
                is_active: false,
                next_date: null,
                summary: 'Cada mes, el día 31 (o el último)',
                due_offset_days: 1,
            }),
        ],
        recent: [
            {
                id: 40,
                title: 'Informe semanal',
                occurrence_date: '2026-10-05',
                due_date: '2026-10-07',
                status: {
                    id: 1,
                    name: 'Por hacer',
                    color: '#56667A',
                    category: 'todo',
                },
                assignee: { id: 7, name: 'Elena Ruiz' },
            },
        ],
        options,
        archived: false,
        today: TODAY,
        ...overrides,
    });

    it('enseña la frase, la próxima fecha, el estado con texto y las últimas tareas', () => {
        render(<RecurringRules projectId={1} settings={settings()} />);

        const rules = document.querySelectorAll<HTMLElement>(
            '[data-test="recurring-rule"]',
        );
        expect(rules).toHaveLength(2);
        expect(rules[0].textContent).toContain('Cada semana, los lunes');
        expect(rules[0].textContent).toContain('12/10/2026');
        expect(rules[0].textContent).toContain('Activa');
        expect(rules[0].textContent).toContain('2 días después');
        expect(rules[1].textContent).toContain('Desactivada');
        expect(rules[1].textContent).toContain('1 día después');

        const recent = screen.getByRole('link', { name: 'Informe semanal' });
        expect(recent.getAttribute('href')).toBe(
            '/proyectos/1/tareas?tarea=40',
        );
    });

    it('desactiva una regla recargando solo la sección', async () => {
        const user = userEvent.setup();
        render(<RecurringRules projectId={1} settings={settings()} />);

        await user.click(
            screen.getByRole('button', {
                name: 'Desactivar «Informe semanal»',
            }),
        );

        expect(inertia.put).toHaveBeenCalledWith(
            '/proyectos/1/tareas-recurrentes/5/estado',
            { is_active: false },
            expect.objectContaining({ only: ['recurring'] }),
        );
    });

    it('avisa de lo que impide crear las tareas, con icono y texto', () => {
        render(
            <RecurringRules
                projectId={1}
                settings={settings({
                    rules: [
                        item({
                            warnings: [
                                'Elena Ruiz está de baja: las tareas se crearán sin responsable.',
                            ],
                        }),
                    ],
                })}
            />,
        );

        expect(
            screen.getByText(
                'Elena Ruiz está de baja: las tareas se crearán sin responsable.',
            ),
        ).toBeTruthy();
    });

    it('sin reglas, un estado vacío; en un proyecto archivado, sin «Nueva»', () => {
        const { unmount } = render(
            <RecurringRules
                projectId={1}
                settings={settings({ rules: [], recent: [] })}
            />,
        );
        expect(screen.getByText('Aún no hay tareas recurrentes')).toBeTruthy();
        expect(
            screen.getByRole('button', { name: 'Nueva tarea recurrente' }),
        ).toBeTruthy();
        unmount();

        render(
            <RecurringRules
                projectId={1}
                settings={settings({ archived: true })}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Nueva tarea recurrente' }),
        ).toBeNull();
        expect(
            screen.getByText(
                'El proyecto está archivado: sus tareas recurrentes no crean tareas.',
            ),
        ).toBeTruthy();
    });
});
