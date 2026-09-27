// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { useRef, useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TemplateEditor } from '@/components/templates/template-editor';
import type { EditorRow } from '@/components/templates/template-editor-state';
import {
    addRow,
    addSubtask,
    conflictsOf,
    mapErrors,
    moveRow,
    removeRow,
    rowsFromStructure,
    setParent,
    structureFromRows,
    toggleDependency,
    totalDays,
    updateRow,
    wouldCreateCycle,
} from '@/components/templates/template-editor-state';
import TemplateEdit from '@/pages/admin/templates/edit';
import type { TemplateEditProps, TemplateStructure } from '@/types/templates';

/*
| Editor de plantillas (D-058): filas, subtareas de un solo nivel, dependencias sin ciclos, errores
| del servidor junto a cada campo y envío de la estructura.
*/

const forms = vi.hoisted(() => ({
    errors: {} as Record<string, string>,
    submitted: [] as { method: string; url: string; data: unknown }[],
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    /** useForm mínimo: guarda los datos y registra lo que se enviaría (con transform). */
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
            errors: forms.errors,
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
            put: send('put'),
            post: send('post'),
            reset: () => setDataState(initial),
            clearErrors: () => {},
        };
    }

    return {
        ...original,
        Head: () => null,
        setLayoutProps: () => {},
        usePage: () => ({ url: '/admin/plantillas', props: {} }),
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

beforeEach(() => {
    forms.errors = {};
    forms.submitted = [];
});

const structure: TemplateStructure = {
    tasks: [
        {
            ref: 'dis',
            parent_ref: null,
            title: 'Diseño',
            task_type_id: 1,
            priority: 'normal',
            estimated_minutes: 600,
            is_milestone: false,
            start_offset_days: 0,
            duration_days: 5,
        },
        {
            ref: 'dev',
            parent_ref: null,
            title: 'Desarrollo',
            task_type_id: null,
            priority: 'high',
            estimated_minutes: null,
            is_milestone: false,
            start_offset_days: 3,
            duration_days: 10,
        },
        {
            ref: 'home',
            parent_ref: 'dis',
            title: 'Home',
            task_type_id: null,
            priority: 'normal',
            estimated_minutes: 120,
            is_milestone: false,
            start_offset_days: 0,
            duration_days: 2,
        },
        {
            ref: 'go',
            parent_ref: null,
            title: 'Publicación',
            task_type_id: null,
            priority: 'normal',
            estimated_minutes: null,
            is_milestone: true,
            start_offset_days: 13,
            duration_days: 1,
        },
    ],
    dependencies: [
        { from_ref: 'dis', to_ref: 'dev' },
        { from_ref: 'dev', to_ref: 'go' },
    ],
};

const refs = (rows: EditorRow[]) => rows.map((row) => row.ref);

const byTest = (name: string) =>
    document.querySelectorAll<HTMLElement>(`[data-test="${name}"]`);

describe('estado del editor', () => {
    it('ordena cada subtarea bajo su tarea y lleva las dependencias a la fila que depende', () => {
        const rows = rowsFromStructure(structure);

        expect(refs(rows)).toEqual(['dis', 'home', 'dev', 'go']);
        expect(rows.find((row) => row.ref === 'go')?.depends_on).toEqual([
            'dev',
        ]);
        expect(structureFromRows(rows).dependencies).toEqual([
            { from_ref: 'dis', to_ref: 'dev' },
            { from_ref: 'dev', to_ref: 'go' },
        ]);
        expect(totalDays(rows)).toBe(14);
    });

    it('añade tareas encadenadas y subtareas al final de las de su tarea', () => {
        let rows = rowsFromStructure(structure);
        rows = addRow(rows);
        const added = rows.at(-1) as EditorRow;

        // Empieza donde acaba la última tarea de primer nivel (un hito no ocupa días).
        expect(added.start_offset_days).toBe(13);
        expect(added.parent_ref).toBeNull();

        rows = addSubtask(rows, 'dis');
        expect(refs(rows).slice(0, 3)).toEqual(['dis', 'home', rows[2].ref]);
        expect(rows[2].parent_ref).toBe('dis');
        expect(new Set(refs(rows)).size).toBe(rows.length);

        // Una subtarea no puede tener subtareas.
        expect(addSubtask(rows, 'home')).toBe(rows);
    });

    it('solo hay un nivel de subtareas', () => {
        const rows = rowsFromStructure(structure);

        // «Diseño» tiene subtareas: no puede colgar de otra.
        expect(setParent(rows, 'dis', 'dev')).toBe(rows);
        // Nadie cuelga de una subtarea.
        expect(setParent(rows, 'dev', 'home')).toBe(rows);

        const moved = setParent(rows, 'go', 'dev');
        expect(refs(moved)).toEqual(['dis', 'home', 'dev', 'go']);
        expect(moved.find((row) => row.ref === 'go')?.parent_ref).toBe('dev');

        const promoted = setParent(rows, 'home', null);
        expect(refs(promoted)).toEqual(['dis', 'home', 'dev', 'go']);
        expect(
            promoted.find((row) => row.ref === 'home')?.parent_ref,
        ).toBeNull();
    });

    it('quitar una tarea quita sus subtareas y las dependencias que la nombran', () => {
        const rows = removeRow(rowsFromStructure(structure), 'dis');

        expect(refs(rows)).toEqual(['dev', 'go']);
        expect(rows.find((row) => row.ref === 'dev')?.depends_on).toEqual([]);
    });

    it('no deja crear ciclos, ni directos ni indirectos', () => {
        const rows = rowsFromStructure(structure);

        expect(wouldCreateCycle(rows, 'go', 'dis')).toBe(true);
        expect(wouldCreateCycle(rows, 'dev', 'dis')).toBe(true);
        expect(wouldCreateCycle(rows, 'home', 'home')).toBe(true);
        expect(wouldCreateCycle(rows, 'home', 'go')).toBe(false);

        expect(toggleDependency(rows, 'dis', 'go')).toBe(rows);
        const linked = toggleDependency(rows, 'go', 'home');
        expect(linked.find((row) => row.ref === 'go')?.depends_on).toEqual([
            'dev',
            'home',
        ]);
        // Otra vez: la quita.
        expect(
            toggleDependency(linked, 'go', 'home').find(
                (row) => row.ref === 'go',
            )?.depends_on,
        ).toEqual(['dev']);
    });

    it('sube y baja las tareas con sus subtareas', () => {
        const rows = rowsFromStructure(structure);

        expect(refs(moveRow(rows, 'dev', 'up'))).toEqual([
            'dev',
            'dis',
            'home',
            'go',
        ]);
        expect(moveRow(rows, 'dis', 'up')).toBe(rows);
        expect(moveRow(rows, 'home', 'down')).toBe(rows);
    });

    it('un hito no lleva horas y dura un día', () => {
        const rows = updateRow(rowsFromStructure(structure), 'dev', {
            is_milestone: true,
        });
        const dev = rows.find((row) => row.ref === 'dev') as EditorRow;

        expect(dev.estimated_minutes).toBeNull();
        expect(dev.duration_days).toBe(1);
    });

    it('avisa de las tareas que empiezan antes de que acabe su predecesora', () => {
        const rows = rowsFromStructure(structure);

        expect(conflictsOf(rows, 'dev').map((row) => row.ref)).toEqual(['dis']);
        expect(conflictsOf(rows, 'go')).toEqual([]);
    });

    it('reparte los errores del servidor por fila y campo', () => {
        const rows = rowsFromStructure(structure);
        const errors = mapErrors(
            {
                'structure.tasks.1.title': 'Escribe el título de la tarea.',
                'structure.tasks.3.depends_on':
                    'Estas dependencias forman un ciclo.',
                'structure.tasks':
                    'La plantilla necesita entre 1 y 500 tareas.',
                name: 'El campo nombre es obligatorio.',
            },
            rows,
        );

        expect(errors.rows.home?.title).toBe('Escribe el título de la tarea.');
        expect(errors.rows.go?.depends_on).toBe(
            'Estas dependencias forman un ciclo.',
        );
        expect(errors.general).toEqual([
            'La plantilla necesita entre 1 y 500 tareas.',
        ]);
    });
});

describe('tabla del editor', () => {
    function Harness({ initial }: { initial: EditorRow[] }) {
        const [rows, setRows] = useState(initial);

        return (
            <TemplateEditor
                rows={rows}
                onChange={setRows}
                errors={mapErrors(forms.errors, initial)}
                types={[
                    { id: 1, name: 'Diseño UI', is_active: true },
                    { id: 2, name: 'Antiguo', is_active: false },
                ]}
                priorities={['low', 'normal', 'high', 'urgent']}
                maxTasks={500}
                maxDays={3650}
            />
        );
    }

    it('añade y quita filas y subtareas', async () => {
        const user = userEvent.setup();
        render(<Harness initial={rowsFromStructure(structure)} />);

        expect(byTest('template-row')).toHaveLength(4);
        expect(screen.getByText('Tareas: 4 de 500')).toBeTruthy();

        await user.click(screen.getByRole('button', { name: 'Añadir tarea' }));
        expect(byTest('template-row')).toHaveLength(5);
        expect(
            (screen.getByLabelText('Título de la tarea 4') as HTMLInputElement)
                .value,
        ).toBe('');

        await user.click(
            screen.getByRole('button', {
                name: 'Añadir una subtarea a «1. Diseño»',
            }),
        );
        expect(screen.getByLabelText('Título de la tarea 1.2')).toBeTruthy();

        await user.click(
            screen.getByRole('button', {
                name: 'Quitar «1. Diseño» y sus subtareas (2)',
            }),
        );
        expect(byTest('template-row')).toHaveLength(3);
        expect(screen.queryByDisplayValue('Diseño')).toBeNull();
    });

    it('una tarea con subtareas no puede pasar a subtarea y solo se cuelga de tareas de primer nivel', () => {
        render(<Harness initial={rowsFromStructure(structure)} />);

        const designParent = screen.getByLabelText(
            'De qué tarea es subtarea «1. Diseño»',
        ) as HTMLSelectElement;
        expect(designParent.disabled).toBe(true);
        expect(
            screen.getByText('Tiene subtareas (1): se queda en primer nivel.'),
        ).toBeTruthy();

        const goParent = screen.getByLabelText(
            'De qué tarea es subtarea «3. Publicación»',
        ) as HTMLSelectElement;
        const options = [...goParent.options].map((option) => option.text);
        expect(options).toEqual([
            '— Primer nivel —',
            '1. Diseño',
            '2. Desarrollo',
        ]);
    });

    it('hace subtarea una tarea y la coloca bajo su tarea', async () => {
        const user = userEvent.setup();
        render(<Harness initial={rowsFromStructure(structure)} />);

        await user.selectOptions(
            screen.getByLabelText('De qué tarea es subtarea «3. Publicación»'),
            'dev',
        );

        expect(screen.getByLabelText('Título de la tarea 2.1')).toBeTruthy();
        expect(
            (
                screen.getByLabelText(
                    'Título de la tarea 2.1',
                ) as HTMLInputElement
            ).value,
        ).toBe('Publicación');
    });

    it('en «Depende de…» las tareas que crearían un ciclo están desactivadas y lo dicen', async () => {
        const user = userEvent.setup();
        render(<Harness initial={rowsFromStructure(structure)} />);

        await user.click(
            screen.getByRole('button', {
                name: '«1. Diseño» depende de: Ninguna',
            }),
        );

        const dialog = screen.getByRole('group', {
            name: '«1. Diseño» depende de…',
        });
        const publication = within(dialog).getByRole('checkbox', {
            name: /3\. Publicación/,
        });
        expect(publication.hasAttribute('disabled')).toBe(true);
        expect(within(dialog).getAllByText('Crearía un ciclo').length).toBe(2);

        // «1.1 Home» sí se puede.
        await user.click(
            within(dialog).getByRole('checkbox', { name: /1\.1\. Home/ }),
        );
        expect(
            screen.getByRole('button', {
                name: '«1. Diseño» depende de: Tareas: 1',
            }),
        ).toBeTruthy();
    });

    it('enseña los errores del servidor junto al campo, con aria-invalid', () => {
        forms.errors = {
            'structure.tasks.1.title': 'Escribe el título de la tarea.',
            'structure.tasks.3.depends_on':
                'Estas dependencias forman un ciclo: una tarea acabaría dependiendo de sí misma.',
            structure: 'La plantilla no es válida.',
        };
        render(<Harness initial={rowsFromStructure(structure)} />);

        const home = screen.getByLabelText('Título de la tarea 1.1');
        expect(home.getAttribute('aria-invalid')).toBe('true');
        const describedBy = home.getAttribute('aria-describedby') ?? '';
        expect(document.getElementById(describedBy)?.textContent).toBe(
            'Escribe el título de la tarea.',
        );
        expect(
            screen.getByText(
                'Estas dependencias forman un ciclo: una tarea acabaría dependiendo de sí misma.',
            ),
        ).toBeTruthy();
        expect(screen.getByText('La plantilla no es válida.')).toBeTruthy();
    });

    it('un hito no pide horas ni duración', () => {
        render(<Harness initial={rowsFromStructure(structure)} />);

        expect(
            screen.queryByLabelText('Estimación de «3. Publicación»'),
        ).toBeNull();
        expect(
            screen.queryByLabelText('Días que dura «3. Publicación»'),
        ).toBeNull();
        expect(screen.getByLabelText('Estimación de «1. Diseño»')).toBeTruthy();
    });

    it('los tipos desactivados solo aparecen si la tarea ya los usa', () => {
        const rows = rowsFromStructure(structure);
        rows[0] = { ...rows[0], task_type_id: 2 };
        render(<Harness initial={rows} />);

        const design = screen.getByLabelText(
            'Tipo de «1. Diseño»',
        ) as HTMLSelectElement;
        expect([...design.options].map((option) => option.text)).toContain(
            'Antiguo (desactivado)',
        );

        const dev = screen.getByLabelText(
            'Tipo de «2. Desarrollo»',
        ) as HTMLSelectElement;
        expect([...dev.options].map((option) => option.text)).not.toContain(
            'Antiguo (desactivado)',
        );
    });
});

describe('página del editor', () => {
    const props = (
        overrides: Partial<TemplateEditProps> = {},
    ): TemplateEditProps => ({
        template: {
            id: 7,
            name: 'Web',
            description: null,
            is_active: true,
            structure,
        },
        types: [{ id: 1, name: 'Diseño UI', is_active: true }],
        priorities: ['low', 'normal', 'high', 'urgent'],
        limits: { max_tasks: 500, max_days: 3650 },
        ...overrides,
    });

    it('guarda la estructura con las dependencias de cada fila', async () => {
        const user = userEvent.setup();
        render(<TemplateEdit {...props()} />);

        await user.clear(screen.getByLabelText('Título de la tarea 2'));
        await user.type(
            screen.getByLabelText('Título de la tarea 2'),
            'Maquetación',
        );
        await user.click(
            screen.getByRole('button', { name: 'Guardar plantilla' }),
        );

        expect(forms.submitted).toHaveLength(1);
        const [submission] = forms.submitted;
        expect(submission.method).toBe('put');
        expect(submission.url).toBe('/admin/plantillas/7');
        const data = submission.data as {
            name: string;
            structure: TemplateStructure;
        };
        expect(data.name).toBe('Web');
        expect(data.structure.tasks.map((task) => task.title)).toEqual([
            'Diseño',
            'Home',
            'Maquetación',
            'Publicación',
        ]);
        expect(data.structure.dependencies).toEqual([
            { from_ref: 'dis', to_ref: 'dev' },
            { from_ref: 'dev', to_ref: 'go' },
        ]);
    });

    it('una copia sin guardar se crea como plantilla nueva', async () => {
        const user = userEvent.setup();
        render(
            <TemplateEdit
                {...props({
                    template: {
                        id: null,
                        name: 'Copia de Web',
                        description: null,
                        is_active: true,
                        structure,
                    },
                })}
            />,
        );

        expect(screen.getByRole('heading', { level: 1 }).textContent).toContain(
            'Copia de una plantilla',
        );
        await user.click(
            screen.getByRole('button', { name: 'Crear plantilla' }),
        );

        expect(forms.submitted[0].method).toBe('post');
        expect(forms.submitted[0].url).toBe('/admin/plantillas');
    });

    it('una plantilla nueva empieza vacía y enseña el cronograma al añadir tareas', async () => {
        const user = userEvent.setup();
        render(<TemplateEdit {...props({ template: null })} />);

        expect(
            screen.getByText(
                'La plantilla aún no tiene tareas. Añade la primera.',
            ),
        ).toBeTruthy();
        expect(
            screen.getByText('Añade tareas para ver el cronograma.'),
        ).toBeTruthy();

        await user.click(screen.getByRole('button', { name: 'Añadir tarea' }));
        expect(byTest('template-timeline')[0].textContent).toContain(
            'Tareas: 1 · Hitos: 0 · Duración (días): 1',
        );
    });
});
