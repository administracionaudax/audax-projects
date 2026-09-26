// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AccountStatusBadge, RoleBadge } from '@/components/admin/badges';
import { ColorPicker } from '@/components/admin/color-picker';
import { IconPicker } from '@/components/admin/icon-picker';
import { Pagination } from '@/components/admin/pagination';
import { ReorderButtons } from '@/components/admin/reorder-buttons';
import { WeekMinutesInput } from '@/components/admin/week-minutes-input';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    router: { post: inertia.post },
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

const PALETTE = ['#0171FF', '#179FA5', '#5E2DAD'];

beforeEach(() => {
    inertia.post.mockReset();
});

describe('selector de color', () => {
    function Harness({ initial = '#0171FF' }: { initial?: string }) {
        const [color, setColor] = useState(initial);

        return (
            <ColorPicker
                value={color}
                onChange={setColor}
                palette={PALETTE}
                legend="Color"
            />
        );
    }

    it('es un grupo de radios con el nombre de cada color y anuncia el elegido', async () => {
        render(<Harness />);

        const group = screen.getByRole('group', { name: 'Color' });
        expect(within(group).getAllByRole('radio')).toHaveLength(3);
        expect(
            (screen.getByRole('radio', { name: 'Azul' }) as HTMLInputElement)
                .checked,
        ).toBe(true);
        expect(screen.getByText('Color elegido: Azul')).toBeTruthy();

        await userEvent.click(screen.getByRole('radio', { name: 'Violeta' }));

        expect(
            (screen.getByRole('radio', { name: 'Violeta' }) as HTMLInputElement)
                .checked,
        ).toBe(true);
        expect(screen.getByText('Color elegido: Violeta')).toBeTruthy();
    });

    it('conserva un color antiguo fuera de la paleta como una opción más', () => {
        render(<Harness initial="#000000" />);

        expect(screen.getAllByRole('radio')).toHaveLength(4);
        expect(
            (screen.getByRole('radio', { name: '#000000' }) as HTMLInputElement)
                .checked,
        ).toBe(true);
    });
});

describe('selector de icono', () => {
    it('ofrece «Sin icono» y los iconos con nombre accesible', async () => {
        const onChange = vi.fn();
        render(
            <IconPicker
                value={null}
                onChange={onChange}
                icons={['palette', 'bug']}
                legend="Icono"
            />,
        );

        expect(
            (
                screen.getByRole('radio', {
                    name: 'Sin icono',
                }) as HTMLInputElement
            ).checked,
        ).toBe(true);

        await userEvent.click(screen.getByRole('radio', { name: 'Error' }));
        expect(onChange).toHaveBeenCalledWith('bug');
    });
});

describe('botones de orden', () => {
    it('suben y bajan con nombres que incluyen el elemento y envían la dirección', async () => {
        render(
            <ReorderButtons
                name="Diseño UI"
                url="/admin/tipos-de-tarea/3/mover"
                isFirst={false}
                isLast={false}
            />,
        );

        await userEvent.click(
            screen.getByRole('button', { name: 'Subir «Diseño UI»' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/admin/tipos-de-tarea/3/mover',
            { direction: 'up' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('en los extremos no hacen nada pero siguen siendo enfocables (aria-disabled)', async () => {
        render(<ReorderButtons name="Bug" url="/x" isFirst isLast={false} />);

        const up = screen.getByRole('button', { name: 'Subir «Bug»' });
        expect(up.getAttribute('aria-disabled')).toBe('true');
        expect((up as HTMLButtonElement).disabled).toBe(false);

        up.focus();
        await userEvent.keyboard('{Enter}');
        expect(inertia.post).not.toHaveBeenCalled();
        expect(document.activeElement).toBe(up);
    });
});

describe('jornada semanal', () => {
    function Harness() {
        const [week, setWeek] = useState<(number | null)[]>([
            480, 480, 480, 480, 480, 0, 0,
        ]);

        return (
            <WeekMinutesInput
                value={week}
                onChange={setWeek}
                legend="Horas de cada día"
                errorPrefix="week"
            />
        );
    }

    it('tiene un campo por día, empezando en lunes, y suma la semana', async () => {
        render(<Harness />);

        expect(screen.getByText('Total semanal: 40:00')).toBeTruthy();

        const friday = screen.getByLabelText('Viernes');
        await userEvent.clear(friday);
        await userEvent.type(friday, '6,5');

        expect(screen.getByText('= 6:30')).toBeTruthy();
        expect(screen.getByText('Total semanal: 38:30')).toBeTruthy();
    });

    it('avisa si un día no es válido', async () => {
        render(<Harness />);

        const monday = screen.getByLabelText('Lunes');
        await userEvent.clear(monday);
        await userEvent.type(monday, '30h');

        expect(monday.getAttribute('aria-invalid')).toBe('true');
        expect(screen.getByText(/Revisa los días marcados/)).toBeTruthy();
    });

    it('muestra los errores del servidor junto a su día', () => {
        render(
            <WeekMinutesInput
                value={[480, 480, 480, 480, 480, 0, 0]}
                onChange={() => {}}
                legend="Jornada"
                errorPrefix="default_work_minutes"
                errors={{
                    'default_work_minutes.2':
                        'Cada día tiene que estar entre 0:00 y 24:00.',
                }}
            />,
        );

        const wednesday = screen.getByLabelText('Miércoles');
        expect(wednesday.getAttribute('aria-describedby')).toContain('error');
        expect(screen.getByRole('alert').textContent).toContain(
            'entre 0:00 y 24:00',
        );
    });
});

describe('etiquetas de estado', () => {
    it('rol y estado de la cuenta siempre con texto', () => {
        render(
            <>
                <RoleBadge role="department_manager" />
                <AccountStatusBadge isActive pending />
                <AccountStatusBadge isActive={false} />
                <AccountStatusBadge isActive />
            </>,
        );

        expect(screen.getByText('Responsable de departamento')).toBeTruthy();
        expect(screen.getByText('Invitación pendiente')).toBeTruthy();
        expect(screen.getByText('Desactivada')).toBeTruthy();
        expect(screen.getByText('Activa')).toBeTruthy();
    });
});

describe('paginación', () => {
    const meta = {
        current_page: 1,
        from: 1,
        last_page: 2,
        path: '/admin/usuarios',
        per_page: 25,
        to: 25,
        total: 31,
    };

    it('resume el rango y enlaza a la página siguiente conservando los filtros', () => {
        render(
            <Pagination
                label="Páginas de usuarios"
                page={{
                    meta,
                    links: {
                        first: null,
                        last: null,
                        prev: null,
                        next: '/admin/usuarios?rol=employee&page=2',
                    },
                }}
            />,
        );

        const nav = screen.getByRole('navigation', {
            name: 'Páginas de usuarios',
        });
        expect(within(nav).getByText('1–25 de 31')).toBeTruthy();
        expect(
            within(nav)
                .getByRole('link', { name: 'Siguiente' })
                .getAttribute('href'),
        ).toBe('/admin/usuarios?rol=employee&page=2');
        expect(
            (
                within(nav).getByRole('button', {
                    name: 'Anterior',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });

    it('sin resultados no pinta nada', () => {
        const { container } = render(
            <Pagination
                label="Páginas"
                page={{
                    meta: {
                        ...meta,
                        total: 0,
                        from: null,
                        to: null,
                        last_page: 1,
                    },
                    links: { first: null, last: null, prev: null, next: null },
                }}
            />,
        );

        expect(container.innerHTML).toBe('');
    });
});
