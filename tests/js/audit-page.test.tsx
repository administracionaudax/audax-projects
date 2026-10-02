// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AdminAudit from '@/pages/admin/audit';
import type { AuditEntry, AuditPageProps } from '@/types/audit';

/**
 * Auditoría visible (D-074): filtros en la URL (se aplican al momento), tabla accesible con el
 * detalle del antes y el después desplegable, estados vacío, de carga y de error, y el CSV.
 */

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
    listeners: {} as Record<string, () => void>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const original = await importOriginal<typeof import('@inertiajs/react')>();

    return {
        ...original,
        Head: () => null,
        usePage: () => ({ url: '/admin/auditoria', props: {} }),
        router: {
            get: inertia.get,
            reload: inertia.reload,
            on: (event: string, callback: () => void) => {
                inertia.listeners[event] = callback;

                return () => {
                    delete inertia.listeners[event];
                };
            },
        },
        Link: ({
            href,
            children,
            preserveScroll: _preserveScroll,
            prefetch: _prefetch,
            ...rest
        }: {
            href: string | { url: string };
            children?: ReactNode;
            preserveScroll?: boolean;
            prefetch?: boolean;
            [key: string]: unknown;
        }) => (
            <a href={typeof href === 'string' ? href : href.url} {...rest}>
                {children}
            </a>
        ),
    };
});

beforeEach(() => {
    inertia.get.mockReset();
    inertia.reload.mockReset();
    inertia.listeners = {};
});

const entry = (overrides: Partial<AuditEntry> = {}): AuditEntry => ({
    id: 10,
    created_at: '2026-10-05T08:30:00Z',
    causer: { id: 1, name: 'Ana Admin' },
    entity: { key: 'task', label: 'Tarea' },
    subject: { label: 'Maquetar la portada', url: '/tareas/5', deleted: false },
    event: 'updated',
    event_label: 'Cambio',
    changes: [
        { field: 'status_id', label: 'Estado', from: 'Por hacer', to: 'Hecha' },
        { field: 'hourly_rate', label: 'Tarifa por hora', from: '60,00 €', to: null },
    ],
    ...overrides,
});

const props = (overrides: Partial<AuditPageProps> = {}): AuditPageProps => ({
    entries: [
        entry(),
        entry({
            id: 9,
            causer: null,
            entity: { key: 'holiday', label: 'Festivo' },
            subject: { label: 'Fiesta local', url: null, deleted: true },
            event: 'holiday_deleted',
            event_label: 'Borrado',
            changes: [
                { field: 'date', label: 'Fecha', from: null, to: '09/11/2026' },
                { field: 'name', label: 'Nombre', from: null, to: 'Fiesta local' },
            ],
        }),
        entry({ id: 8, changes: [], event: 'restored', event_label: 'Restauración' }),
    ],
    pagination: { next: '/admin/auditoria?cursor=abc', prev: null },
    filters: { entidad: null, persona: null, accion: null, desde: null, hasta: null },
    options: {
        entities: [
            { value: 'project', label: 'Proyecto' },
            { value: 'task', label: 'Tarea' },
        ],
        actions: [
            { value: 'created', label: 'Altas', basic: true },
            { value: 'updated', label: 'Cambios', basic: true },
            { value: 'submitted', label: 'Semanas enviadas', basic: false },
        ],
        people: [
            { id: 1, name: 'Ana Admin', is_active: true },
            { id: 2, name: 'Bruno Baja', is_active: false },
        ],
    },
    exportUrl: '/admin/auditoria/exportar',
    ...overrides,
});

describe('filtros de la auditoría', () => {
    it('cada filtro se aplica al momento en la URL, sin llenar el historial', async () => {
        render(<AdminAudit {...props()} />);

        const search = screen.getByRole('search', { name: 'Filtros de la auditoría' });
        await userEvent.selectOptions(within(search).getByLabelText('Entidad'), 'task');

        expect(inertia.get).toHaveBeenLastCalledWith(
            '/admin/auditoria',
            { entidad: 'task' },
            expect.objectContaining({ preserveState: true, replace: true, only: ['entries', 'pagination', 'filters', 'exportUrl'] }),
        );

        await userEvent.selectOptions(within(search).getByLabelText('Persona'), 'sistema');
        await userEvent.selectOptions(within(search).getByLabelText('Acción'), 'submitted');

        expect(inertia.get).toHaveBeenLastCalledWith(
            '/admin/auditoria',
            { entidad: 'task', persona: 'sistema', accion: 'submitted' },
            expect.anything(),
        );

        const from = within(search).getByLabelText('Desde');
        await userEvent.type(from, '2026-09-01');

        expect(inertia.get).toHaveBeenLastCalledWith(
            '/admin/auditoria',
            { entidad: 'task', persona: 'sistema', accion: 'submitted', desde: '2026-09-01' },
            expect.anything(),
        );
        expect((within(search).getByLabelText('Hasta') as HTMLInputElement).min).toBe('2026-09-01');

        await userEvent.click(screen.getByRole('button', { name: 'Quitar los filtros' }));
        expect(inertia.get).toHaveBeenLastCalledWith('/admin/auditoria', {}, expect.anything());
    });

    it('las opciones: sistema, personas desactivadas y los eventos propios aparte', () => {
        render(<AdminAudit {...props({ filters: { entidad: 'task', persona: '2', accion: null, desde: null, hasta: null } })} />);

        const person = screen.getByLabelText('Persona') as HTMLSelectElement;
        expect(person.value).toBe('2');
        expect(within(person).getByRole('option', { name: 'Bruno Baja (desactivada)' })).toBeTruthy();
        expect(within(person).getByRole('option', { name: 'Sistema (sin persona)' })).toBeTruthy();

        const action = screen.getByLabelText('Acción');
        const group = within(action).getByRole('group', { name: 'Otros eventos' });
        expect(within(group).getByRole('option', { name: 'Semanas enviadas' })).toBeTruthy();
        expect(within(group).queryByRole('option', { name: 'Altas' })).toBeNull();
    });

    it('el CSV se descarga con los filtros de la página', () => {
        render(<AdminAudit {...props({ exportUrl: '/admin/auditoria/exportar?entidad=task' })} />);

        const link = screen.getByRole('link', { name: 'Exportar CSV' });
        expect(link.getAttribute('href')).toBe('/admin/auditoria/exportar?entidad=task');
        expect(link.hasAttribute('download')).toBe(true);
    });
});

describe('tabla y detalle de la auditoría', () => {
    it('una tabla con cabeceras: cuándo y quién, qué elemento (con enlace si existe) y qué acción', () => {
        render(<AdminAudit {...props()} />);

        const table = screen.getByRole('table', { name: 'Cambios registrados, de lo más reciente a lo más antiguo' });
        expect(within(table).getAllByRole('columnheader').map((th) => th.textContent)).toEqual([
            'Fecha y persona',
            'Elemento',
            'Acción',
        ]);

        const rows = within(table).getAllByRole('row').slice(1);
        expect(rows).toHaveLength(3);
        expect(rows[0].textContent).toContain('05/10/2026 10:30');
        expect(rows[0].textContent).toContain('Ana Admin');
        expect(within(rows[0]).getByRole('link', { name: 'Maquetar la portada' }).getAttribute('href')).toBe('/tareas/5');

        // Sin persona: el sistema. Lo borrado no enlaza y lo dice.
        expect(rows[1].textContent).toContain('Sistema');
        expect(within(rows[1]).queryByRole('link')).toBeNull();
        expect(rows[1].textContent).toContain('Borrado');

        // Sin cambios que mostrar, no hay botón de detalle.
        expect(within(rows[2]).queryByRole('button')).toBeNull();
    });

    it('el detalle se despliega con el teclado y muestra el antes y el después de cada campo', async () => {
        render(<AdminAudit {...props()} />);

        const toggle = screen.getAllByRole('button', { name: 'Ver cambios (2)' })[0];
        expect(toggle.getAttribute('aria-expanded')).toBe('false');

        toggle.focus();
        await userEvent.keyboard('{Enter}');

        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(toggle.textContent).toContain('Ocultar cambios (2)');

        const detail = screen.getByRole('table', { name: 'Cambios de Maquetar la portada' });
        expect(toggle.getAttribute('aria-controls')).toBe(detail.parentElement?.id);
        expect(within(detail).getAllByRole('columnheader').map((th) => th.textContent)).toEqual(['Campo', 'Antes', 'Después']);

        const [status, rate] = within(detail).getAllByRole('row').slice(1);
        expect(within(status).getByRole('rowheader').textContent).toBe('Estado');
        expect(status.textContent).toContain('Por hacer');
        expect(status.textContent).toContain('Hecha');
        expect(rate.textContent).toContain('60,00 €');
        expect(rate.textContent).toContain('—');

        await userEvent.keyboard('{Enter}');
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(screen.queryByRole('table', { name: 'Cambios de Maquetar la portada' })).toBeNull();
    });

    it('al crear o borrar, el detalle solo lleva el valor', async () => {
        render(<AdminAudit {...props()} />);

        await userEvent.click(screen.getAllByRole('button', { name: 'Ver cambios (2)' })[1]);

        const detail = screen.getByRole('table', { name: 'Cambios de Fiesta local' });
        expect(within(detail).getAllByRole('columnheader').map((th) => th.textContent)).toEqual(['Campo', 'Valor']);
        expect(detail.textContent).toContain('09/11/2026');
    });

    it('pagina por cursor: más antiguos y más recientes', () => {
        render(<AdminAudit {...props({ pagination: { next: '/admin/auditoria?cursor=abc', prev: null } })} />);

        const nav = screen.getByRole('navigation', { name: 'Páginas de la auditoría' });
        expect(within(nav).getByRole('link', { name: 'Más antiguos' }).getAttribute('href')).toBe('/admin/auditoria?cursor=abc');
        expect((within(nav).getByRole('button', { name: 'Más recientes' }) as HTMLButtonElement).disabled).toBe(true);
    });
});

describe('estados de la auditoría', () => {
    it('vacía, sin filtros y con filtros', () => {
        const { rerender } = render(<AdminAudit {...props({ entries: [], pagination: { next: null, prev: null } })} />);
        expect(screen.getByText('Aún no hay cambios registrados')).toBeTruthy();
        expect(screen.queryByRole('table')).toBeNull();

        rerender(
            <AdminAudit
                {...props({
                    entries: [],
                    pagination: { next: null, prev: null },
                    filters: { entidad: 'task', persona: null, accion: null, desde: null, hasta: null },
                })}
            />,
        );
        expect(screen.getByText('No hay cambios con estos filtros')).toBeTruthy();
    });

    it('cargando y con error, con «Reintentar»', async () => {
        render(<AdminAudit {...props()} />);

        inertia.listeners.start?.();
        expect((await screen.findByRole('status')).textContent).toContain('Cargando…');
        expect(screen.getByRole('region', { name: 'Entradas de la auditoría' }).getAttribute('aria-busy')).toBe('true');

        inertia.listeners.networkError?.();
        inertia.listeners.finish?.();

        const alert = await screen.findByRole('alert');
        expect(alert.textContent).toContain('No se ha podido cargar la auditoría.');

        await userEvent.click(within(alert).getByRole('button', { name: 'Reintentar' }));
        expect(inertia.reload).toHaveBeenCalledTimes(1);
    });
});
