// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RecurringIndex from '@/pages/admin/recurring/index';
import TemplatesIndex from '@/pages/admin/templates/index';
import type {
    RecurringIndexProps,
    RecurringRuleItem,
    TemplateRow,
    TemplatesIndexProps,
} from '@/types/templates';

/** Con userEvent se escribe tecla a tecla: con la máquina cargada, 5 s no bastan. */
const SLOW = { timeout: 20_000 };

/*
| /admin/plantillas y /admin/tareas-recurrentes (D-058, D-059): listados con estado en texto,
| acciones con nombre accesible, papelera y estados vacíos.
*/

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    usePage: () => ({ url: '/admin/plantillas', props: { errors: {} } }),
    router: { ...inertia, on: () => () => {} },
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
}));

beforeEach(() => {
    for (const fn of Object.values(inertia)) {
        fn.mockReset();
    }
});

const page = <T,>(data: T[]) => ({
    data,
    meta: {
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total: data.length,
        from: data.length > 0 ? 1 : null,
        to: data.length > 0 ? data.length : null,
    },
    links: { prev: null, next: null },
});

const row = (overrides: Partial<TemplateRow> = {}): TemplateRow => ({
    id: 3,
    name: 'Web corporativa',
    description: 'Diseño y desarrollo',
    is_active: true,
    stats: {
        tasks: 12,
        subtasks: 4,
        milestones: 2,
        dependencies: 5,
        duration_days: 45,
    },
    updated_at: '2026-10-05T08:00:00Z',
    deleted_at: null,
    ...overrides,
});

describe('/admin/plantillas', SLOW, () => {
    const props = (
        overrides: Partial<TemplatesIndexProps> = {},
    ): TemplatesIndexProps => ({
        templates: page([
            row(),
            row({
                id: 4,
                name: 'Campaña',
                is_active: false,
                description: null,
            }),
        ]),
        filters: { q: '', estado: 'todas', papelera: false },
        trashedCount: 2,
        ...overrides,
    });

    it('lista las plantillas con sus cifras y su estado con texto', () => {
        render(<TemplatesIndex {...props()} />);

        const rows = document.querySelectorAll<HTMLElement>(
            '[data-test="template-row"]',
        );
        expect(rows).toHaveLength(2);
        expect(rows[0].textContent).toContain(
            '12 tareas (4 subtareas) · 2 hitos · 5 dependencias · 45 días',
        );
        expect(rows[0].textContent).toContain('Activa');
        expect(rows[1].textContent).toContain('Desactivada');
        expect(
            screen.getByRole('button', { name: /Papelera \(2\)/ }),
        ).toBeTruthy();

        expect(
            screen
                .getByRole('link', { name: 'Duplicar «Web corporativa»' })
                .getAttribute('href'),
        ).toBe('/admin/plantillas/nueva?desde=3');
        expect(
            screen
                .getByRole('link', {
                    name: 'Exportar «Web corporativa» en JSON',
                })
                .getAttribute('href'),
        ).toBe('/admin/plantillas/3/exportar');
    });

    it('activa y desactiva sin salir de la página', async () => {
        const user = userEvent.setup();
        render(<TemplatesIndex {...props()} />);

        await user.click(
            screen.getByRole('button', {
                name: 'Desactivar «Web corporativa»',
            }),
        );
        expect(inertia.put).toHaveBeenCalledWith(
            '/admin/plantillas/3/estado',
            { is_active: false },
            expect.anything(),
        );

        await user.click(
            screen.getByRole('button', { name: 'Activar «Campaña»' }),
        );
        expect(inertia.put).toHaveBeenLastCalledWith(
            '/admin/plantillas/4/estado',
            { is_active: true },
            expect.anything(),
        );
    });

    it('enviar a la papelera pide confirmación', async () => {
        const user = userEvent.setup();
        render(<TemplatesIndex {...props()} />);

        await user.click(
            screen.getByRole('button', {
                name: 'Enviar «Web corporativa» a la papelera',
            }),
        );
        const dialog = screen.getByRole('dialog');
        await user.click(
            within(dialog).getByRole('button', {
                name: 'Enviar a la papelera',
            }),
        );

        expect(inertia.delete).toHaveBeenCalledWith(
            '/admin/plantillas/3',
            expect.anything(),
        );
    });

    it('en la papelera solo se recupera', async () => {
        const user = userEvent.setup();
        render(
            <TemplatesIndex
                {...props({
                    templates: page([
                        row({ deleted_at: '2026-10-04T10:00:00Z' }),
                    ]),
                    filters: { q: '', estado: 'todas', papelera: true },
                })}
            />,
        );

        expect(screen.getByText('En la papelera')).toBeTruthy();
        expect(screen.queryByRole('link', { name: /Editar/ })).toBeNull();
        await user.click(
            screen.getByRole('button', {
                name: 'Recuperar «Web corporativa» de la papelera',
            }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/admin/plantillas/3/restaurar',
            {},
            expect.anything(),
        );
    });

    it('sin plantillas invita a crear la primera', () => {
        render(<TemplatesIndex {...props({ templates: page([]) })} />);

        expect(screen.getByText('Aún no hay plantillas')).toBeTruthy();
        expect(
            screen.getAllByRole('link', { name: 'Nueva plantilla' }).length,
        ).toBe(2);
    });
});

describe('/admin/tareas-recurrentes', SLOW, () => {
    const rule: RecurringRuleItem = {
        id: 5,
        project_id: 9,
        project: {
            id: 9,
            name: 'Web de Acme',
            code: 'ACME-WEB',
            archived: false,
        },
        title: 'Informe semanal',
        description: null,
        task_type_id: null,
        assignee_user_id: null,
        assignee: null,
        hour_bank_id: null,
        hour_bank: null,
        estimated_minutes: null,
        priority: 'normal',
        frequency: 'weekly',
        interval: 2,
        weekday: 1,
        month_day: null,
        due_offset_days: 0,
        starts_on: '2026-09-07',
        ends_on: null,
        is_active: true,
        last_generated_on: null,
        summary: 'Cada 2 semanas, los lunes',
        next_date: '2026-10-12',
        warnings: [
            'La bolsa «T4» ya no admite tareas: no se crearán hasta que elijas otra.',
        ],
    };

    const props = (
        overrides: Partial<RecurringIndexProps> = {},
    ): RecurringIndexProps => ({
        rules: page([rule]),
        filters: { estado: 'activas', proyecto: null },
        projects: [{ id: 9, name: 'Web de Acme', code: 'ACME-WEB' }],
        ...overrides,
    });

    it('lista las reglas con su proyecto, frase, próxima fecha, avisos y enlace a los Ajustes', () => {
        render(<RecurringIndex {...props()} />);

        const row = document.querySelector<HTMLElement>(
            '[data-test="recurring-row"]',
        ) as HTMLElement;
        expect(row.textContent).toContain('Web de Acme');
        expect(row.textContent).toContain('Cada 2 semanas, los lunes');
        expect(row.textContent).toContain('12/10/2026');
        expect(row.textContent).toContain('Sin responsable');
        expect(row.textContent).toContain('La bolsa «T4» ya no admite tareas');
        expect(
            within(row)
                .getByRole('link', {
                    name: 'Gestionar «Informe semanal» en los Ajustes de Web de Acme',
                })
                .getAttribute('href'),
        ).toBe('/proyectos/9/ajustes');
    });

    it('filtra por estado y proyecto en la URL', async () => {
        const user = userEvent.setup();
        render(<RecurringIndex {...props()} />);

        await user.selectOptions(screen.getByLabelText('Proyecto'), '9');
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/admin/tareas-recurrentes',
            { proyecto: '9' },
            expect.anything(),
        );

        await user.selectOptions(screen.getByLabelText('Estado'), 'todas');
        expect(inertia.get).toHaveBeenLastCalledWith(
            '/admin/tareas-recurrentes',
            { estado: 'todas', proyecto: '9' },
            expect.anything(),
        );
    });

    it('sin reglas, un estado vacío', () => {
        render(<RecurringIndex {...props({ rules: page([]) })} />);

        expect(screen.getByText('Aún no hay tareas recurrentes')).toBeTruthy();
    });
});
