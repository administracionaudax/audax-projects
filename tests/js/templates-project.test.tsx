// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { useRef, useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ProjectTemplating } from '@/components/templates/project-template-section';
import {
    emptyTemplateStart,
    TemplateStartFields,
    templateStartPayload,
} from '@/components/templates/template-start-fields';
import type { TemplateStartData } from '@/components/templates/template-start-fields';
import ProjectCreate from '@/pages/projects/create';
import type { Abilities, BillingType, ProjectCreateProps } from '@/types';
import type {
    ProjectTemplatingSettings,
    TemplateOption,
} from '@/types/templates';

/*
| Plantillas en los proyectos (D-058): «Desde plantilla» en el alta (con la primera bolsa si es de
| bolsas) y la sección «Plantilla» de los Ajustes (aplicar con confirmación y guardar).
*/

const forms = vi.hoisted(() => ({
    submitted: [] as {
        method: string;
        url: string;
        data: unknown;
        options: unknown;
    }[],
}));
const inertia = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    function useForm<T extends Record<string, unknown>>(initial: T) {
        const [data, setDataState] = useState<T>(initial);
        const transform = useRef<(data: T) => unknown>((value) => value);
        const send =
            (method: string) =>
            (url: string | { url: string }, options?: unknown) =>
                forms.submitted.push({
                    method,
                    url: typeof url === 'string' ? url : url.url,
                    data: transform.current(data),
                    options,
                });

        return {
            data,
            errors: {},
            processing: false,
            setData: (key: keyof T | ((data: T) => T), value?: unknown) =>
                setDataState((current) =>
                    typeof key === 'function'
                        ? key(current)
                        : { ...current, [key]: value },
                ),
            transform: (fn: (data: T) => unknown) => {
                transform.current = fn;
            },
            submit: (
                route: { url: string; method: string },
                options?: unknown,
            ) => send(route.method)(route.url, options),
            put: send('put'),
            post: send('post'),
            reset: () => setDataState(initial),
            clearErrors: () => {},
        };
    }

    return {
        ...original,
        Head: () => null,
        usePage: () => ({ url: '/', props: inertia.props }),
        useForm,
        router: { get: vi.fn(), on: () => () => {} },
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

const can = (overrides: Partial<Abilities> = {}): Abilities => ({
    viewHourBanks: true,
    viewAdmin: false,
    viewFinancials: false,
    createClients: true,
    createProjects: true,
    approveTime: true,
    lockTime: false,
    manageUsers: false,
    manageSettings: false,
    ...overrides,
});

beforeEach(() => {
    forms.submitted = [];
    inertia.props = { auth: { user: null, can: can() }, errors: {} };
    if (!('hasPointerCapture' in Element.prototype)) {
        Object.assign(Element.prototype, {
            hasPointerCapture: () => false,
            releasePointerCapture: () => {},
        });
    }
});

const templates: TemplateOption[] = [
    {
        id: 3,
        name: 'Web corporativa',
        description: 'Diseño y desarrollo',
        stats: {
            tasks: 12,
            subtasks: 4,
            milestones: 2,
            dependencies: 5,
            duration_days: 45,
        },
    },
    {
        id: 4,
        name: 'Campaña',
        description: null,
        stats: {
            tasks: 1,
            subtasks: 0,
            milestones: 0,
            dependencies: 0,
            duration_days: 1,
        },
    },
];

describe('«Desde plantilla» en el alta', () => {
    function Harness({ billingType }: { billingType: BillingType }) {
        const [data, setData] = useState<TemplateStartData>(
            emptyTemplateStart('2026-10-05'),
        );

        return (
            <TemplateStartFields
                data={data}
                onChange={setData}
                errors={{}}
                templates={templates}
                billingType={billingType}
                projectStart="2026-11-02"
                today="2026-10-05"
                departments={[{ id: 1, name: 'Diseño' }]}
                overageDefault="allow"
                canViewFinancials={false}
            />
        );
    }

    it('al elegir «Desde plantilla» ofrece la plantilla, sus cifras y el día 1 (el inicio del proyecto)', async () => {
        const user = userEvent.setup();
        render(<Harness billingType="time_and_materials" />);

        expect(screen.queryByLabelText('Plantilla')).toBeNull();
        await user.click(
            screen.getByRole('radio', { name: 'Desde plantilla' }),
        );

        const select = screen.getByLabelText('Plantilla') as HTMLSelectElement;
        expect(select.value).toBe('3');
        expect(
            screen.getByText(
                '12 tareas (4 subtareas) · 2 hitos · 5 dependencias · 45 días',
            ),
        ).toBeTruthy();
        expect(screen.getByText('Diseño y desarrollo')).toBeTruthy();
        expect(
            screen.getByLabelText('Día 1 de la plantilla').textContent,
        ).toContain('02/11/2026');
        expect(
            screen.queryByRole('region', { name: 'Primera bolsa de horas' }),
        ).toBeNull();

        await user.selectOptions(select, '4');
        expect(
            screen.getByText('1 tarea · 0 hitos · 0 dependencias · 1 día'),
        ).toBeTruthy();
    });

    it('en un proyecto de bolsas pide los datos de la primera bolsa', async () => {
        const user = userEvent.setup();
        render(<Harness billingType="hour_bank" />);

        await user.click(
            screen.getByRole('radio', { name: 'Desde plantilla' }),
        );

        const bank = screen.getByRole('region', {
            name: 'Primera bolsa de horas',
        });
        expect(within(bank).getByLabelText('Nombre')).toBeTruthy();
        expect(within(bank).getByLabelText('Total de horas')).toBeTruthy();
    });

    it('lo que se envía: nada desde cero; la plantilla y, si es de bolsas, la bolsa', () => {
        const data = emptyTemplateStart('2026-10-05');

        expect(templateStartPayload(data, 'hour_bank', false)).toEqual({});
        expect(
            templateStartPayload(
                { ...data, template_id: 3 },
                'internal',
                false,
            ),
        ).toEqual({ template_id: 3, template_start: null });

        const withBank = templateStartPayload(
            { ...data, template_id: 3, template_start: '2026-11-02' },
            'hour_bank',
            false,
        );
        expect(withBank.template_start).toBe('2026-11-02');
        expect(withBank.hour_bank?.start_date).toBe('2026-10-05');
        expect(withBank.hour_bank).not.toHaveProperty('price_amount');
    });

    it(
        'la página de alta envía la plantilla con el proyecto',
        { timeout: 15_000 },
        async () => {
            const user = userEvent.setup();
            const props: ProjectCreateProps = {
                clients: [{ id: 3, name: 'Acme', is_active: true }],
                people: [{ id: 7, name: 'Laura Gómez', department: 'Diseño' }],
                defaults: {
                    color: '#179FA5',
                    owner_user_id: 7,
                    status: 'active',
                    billing_type: 'internal',
                },
                templates,
                departments: [],
                overageDefault: 'allow',
            };
            render(<ProjectCreate {...props} />);

            await user.type(screen.getByLabelText('Nombre'), 'Formación');
            await user.click(
                screen.getByRole('radio', { name: 'Desde plantilla' }),
            );
            await user.click(
                screen.getByRole('button', { name: 'Crear el proyecto' }),
            );

            expect(forms.submitted).toHaveLength(1);
            expect(forms.submitted[0].data).toMatchObject({
                name: 'Formación',
                template_id: 3,
                template_start: null,
            });
            expect(forms.submitted[0].data).not.toHaveProperty('template');
            expect(forms.submitted[0].data).not.toHaveProperty('hour_bank');
        },
    );

    it('sin plantillas activas, «Desde plantilla» no se puede elegir y se explica', () => {
        render(
            <TemplateStartFields
                data={emptyTemplateStart('2026-10-05')}
                onChange={() => {}}
                errors={{}}
                templates={[]}
                billingType="internal"
                projectStart={null}
                today="2026-10-05"
                departments={[]}
                overageDefault="allow"
                canViewFinancials={false}
            />,
        );

        expect(
            screen
                .getByRole('radio', { name: 'Desde plantilla' })
                .hasAttribute('disabled'),
        ).toBe(true);
        expect(screen.getByText('No hay plantillas activas.')).toBeTruthy();
    });
});

describe('sección «Plantilla» de los Ajustes', () => {
    const settings = (
        overrides: Partial<ProjectTemplatingSettings> = {},
    ): ProjectTemplatingSettings => ({
        templates,
        banks: [],
        uses_hour_banks: false,
        default_start: '2026-10-05',
        task_count: 7,
        archived: false,
        max_tasks: 500,
        ...overrides,
    });

    it('aplicar pide confirmación con las tareas que se crearán', async () => {
        const user = userEvent.setup();
        render(
            <ProjectTemplating
                projectId={1}
                projectName="Web de Acme"
                settings={settings()}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Aplicar plantilla…' }),
        );
        const dialog = screen.getByRole('dialog');
        expect(dialog.textContent).toContain('¿Aplicar «Web corporativa»?');
        expect(dialog.textContent).toContain(
            'Se crearán 12 tareas, 2 hitos y 5 dependencias desde el 05/10/2026. Las tareas que ya tiene el proyecto no cambian.',
        );

        await user.click(
            within(dialog).getByRole('button', {
                name: 'Crear las tareas (12)',
            }),
        );
        expect(forms.submitted[0]).toMatchObject({
            method: 'post',
            url: '/proyectos/1/plantilla/aplicar',
            data: {
                template_id: 3,
                start_date: '2026-10-05',
                hour_bank_id: null,
            },
            options: expect.objectContaining({ only: ['templating'] }),
        });
    });

    it('en un proyecto de bolsas sin bolsas abiertas no se puede aplicar y se explica', () => {
        render(
            <ProjectTemplating
                projectId={1}
                projectName="Web"
                settings={settings({ uses_hour_banks: true, banks: [] })}
            />,
        );

        expect(
            (
                screen.getByRole('button', {
                    name: 'Aplicar plantilla…',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        expect(
            screen.getByText(
                'El proyecto no tiene bolsas abiertas: crea una en la pestaña Bolsas.',
            ),
        ).toBeTruthy();
    });

    it('guarda como plantilla con el nombre del proyecto por defecto', async () => {
        const user = userEvent.setup();
        render(
            <ProjectTemplating
                projectId={1}
                projectName="Web de Acme"
                settings={settings()}
            />,
        );

        expect(screen.getByText('Se guardarán 7 tareas.')).toBeTruthy();
        await user.click(
            screen.getByRole('button', { name: 'Guardar como plantilla' }),
        );

        expect(forms.submitted[0]).toMatchObject({
            method: 'post',
            url: '/proyectos/1/plantilla/guardar',
            data: { name: 'Web de Acme', description: '' },
        });
    });

    it('sin tareas no se guarda; sin plantillas, un estado vacío; archivado, no se aplica', () => {
        const { unmount } = render(
            <ProjectTemplating
                projectId={1}
                projectName="Web"
                settings={settings({ task_count: 0, templates: [] })}
            />,
        );

        expect(
            screen.getByText('El proyecto aún no tiene tareas que guardar.'),
        ).toBeTruthy();
        expect(screen.getByText('No hay plantillas activas')).toBeTruthy();
        expect(
            screen.queryByRole('button', { name: 'Guardar como plantilla' }),
        ).toBeNull();
        unmount();

        render(
            <ProjectTemplating
                projectId={1}
                projectName="Web"
                settings={settings({ archived: true })}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Aplicar plantilla…' }),
        ).toBeNull();
        expect(
            screen.getByText(
                'El proyecto está archivado: recupéralo para añadirle tareas de una plantilla.',
            ),
        ).toBeTruthy();
    });
});
