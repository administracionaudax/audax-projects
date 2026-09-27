// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HolidayImport } from '@/components/absences/holiday-import';
import type {
    HolidayImportPreview,
    HolidaysPageProps,
} from '@/components/absences/types';
import AdminHolidays from '@/pages/admin/holidays/index';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    Head: () => null,
    router: {
        post: inertia.post,
        delete: inertia.delete,
        on: () => () => {},
    },
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children?: ReactNode;
        [key: string]: unknown;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

const LIMITS: HolidaysPageProps['limits'] = {
    min_year: 2000,
    max_year: 2100,
    max_rows: 500,
    max_kilobytes: 256,
};

function props(overrides: Partial<HolidaysPageProps> = {}): HolidaysPageProps {
    return {
        year: 2026,
        current_year: 2026,
        holidays: [
            { id: 1, date: '2026-01-01', name: 'Año Nuevo' },
            { id: 2, date: '2026-09-08', name: 'Día de Asturias' },
        ],
        national: [
            { date: '2026-01-01', name: 'Año Nuevo', exists: true },
            { date: '2026-01-06', name: 'Epifanía del Señor', exists: false },
            { date: '2026-04-03', name: 'Viernes Santo', exists: false },
        ],
        limits: LIMITS,
        ...overrides,
    };
}

const PREVIEW: HolidayImportPreview = {
    file_name: 'asturias.ics',
    format: 'ics',
    rows: [
        {
            line: 3,
            date: '2026-09-08',
            name: 'Día de Asturias',
            status: 'existing',
            message:
                'Ya hay un festivo ese día: «Día de Asturias». Se deja como está.',
        },
        {
            line: 8,
            date: '2026-09-21',
            name: 'San Mateo',
            status: 'new',
            message: null,
        },
        {
            line: 13,
            date: null,
            name: 'Sin fecha',
            status: 'error',
            message: 'El evento no tiene fecha de inicio (DTSTART).',
        },
    ],
    counts: { new: 1, existing: 1, duplicate: 0, error: 1 },
};

beforeEach(() => {
    inertia.post.mockReset();
    inertia.delete.mockReset();
});

describe('festivos (/admin/festivos)', () => {
    it('lista los festivos del año con su día de la semana y un solo h1', () => {
        render(<AdminHolidays {...props()} />);

        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        const rows = screen.getAllByRole('row').slice(1);
        expect(rows).toHaveLength(2);
        expect(within(rows[1]).getByText('08/09/2026')).toBeTruthy();
        expect(within(rows[1]).getByText('martes')).toBeTruthy();
        expect(
            screen.getByRole('button', {
                name: 'Eliminar el festivo Día de Asturias',
            }),
        ).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: 'Año anterior (2025)' })
                .getAttribute('href'),
        ).toBe('/admin/festivos?anio=2025');
    });

    it('marca los nacionales que faltan (icono y texto) y los añade de una vez', async () => {
        const user = userEvent.setup();
        render(<AdminHolidays {...props()} />);

        const national = screen.getByRole('list', {
            name: 'Festivos nacionales de 2026',
        });
        expect(within(national).getAllByText('Falta')).toHaveLength(2);
        expect(within(national).getAllByText('Ya está')).toHaveLength(1);

        await user.click(
            screen.getByRole('button', { name: 'Añadir los 2 que faltan' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/admin/festivos/nacionales',
            { year: 2026 },
            expect.any(Object),
        );
    });

    it('con todos los nacionales, el botón lo dice y no se puede pulsar', () => {
        render(
            <AdminHolidays
                {...props({
                    national: [
                        { date: '2026-01-01', name: 'Año Nuevo', exists: true },
                    ],
                })}
            />,
        );

        const button = screen.getByRole('button', { name: 'Ya están todos' });
        expect((button as HTMLButtonElement).disabled).toBe(true);
    });

    it('sin festivos, un estado vacío explica qué hacer', () => {
        render(<AdminHolidays {...props({ holidays: [], year: 2027 })} />);

        expect(screen.getByText('No hay festivos en 2027')).toBeTruthy();
        expect(
            screen.getByRole('link', { name: 'Volver a 2026' }),
        ).toBeTruthy();
    });
});

// userEvent con subida de ficheros: margen de tiempo para la CI cargada.
describe('importación de festivos', { timeout: 20_000 }, () => {
    it('pide un fichero antes de la vista previa', async () => {
        const user = userEvent.setup();
        render(<HolidayImport limits={LIMITS} year={2027} />);

        await user.click(
            screen.getByRole('button', { name: 'Ver la vista previa' }),
        );

        expect(screen.getByRole('alert').textContent).toBe(
            'Elige un fichero antes de ver la vista previa.',
        );
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('sube el fichero y pinta la vista previa que llega en el flash', async () => {
        const user = userEvent.setup();
        inertia.post.mockImplementation(
            (
                _url: string,
                _data: unknown,
                options: { onFlash?: (flash: unknown) => void },
            ) => options.onFlash?.({ holiday_import: PREVIEW }),
        );
        render(<HolidayImport limits={LIMITS} year={2027} />);

        const file = new File(['BEGIN:VCALENDAR'], 'asturias.ics', {
            type: 'text/calendar',
        });
        await user.upload(screen.getByLabelText('Fichero .ics o .csv'), file);
        await user.click(
            screen.getByRole('button', { name: 'Ver la vista previa' }),
        );

        // Con el año de la página: en él se toman los eventos que se repiten cada año.
        expect(inertia.post).toHaveBeenCalledWith(
            '/admin/festivos/importar/vista-previa',
            { file, year: 2027 },
            expect.objectContaining({ forceFormData: true }),
        );
        expect(screen.getByText('Vista previa de «asturias.ics»')).toBeTruthy();
        expect(screen.getByText('Se añaden: 1')).toBeTruthy();
        expect(screen.getByText('Con errores: 1')).toBeTruthy();
        expect(
            screen.getByText('El evento no tiene fecha de inicio (DTSTART).'),
        ).toBeTruthy();
    });

    it('confirma solo los festivos nuevos', async () => {
        const user = userEvent.setup();
        render(
            <HolidayImport
                limits={LIMITS}
                year={2027}
                initialPreview={PREVIEW}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Añadir 1 festivo' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/admin/festivos/importar',
            { rows: [{ date: '2026-09-21', name: 'San Mateo' }] },
            expect.any(Object),
        );
    });

    it('explica en qué año se toman los festivos que se repiten', () => {
        render(<HolidayImport limits={LIMITS} year={2027} />);

        expect(
            screen.getByText(
                /Los festivos del \.ics que se repiten cada año se toman en 2027\./,
            ),
        ).toBeTruthy();
    });

    it('un evento de varios días pinta una fila por día y los confirma todos', async () => {
        const user = userEvent.setup();
        const note =
            'Evento de 2 días (del 24/12/2026 al 25/12/2026): se añade un festivo por día.';
        const errors = vi.spyOn(console, 'error').mockImplementation(() => {});
        render(
            <HolidayImport
                limits={LIMITS}
                year={2026}
                initialPreview={{
                    file_name: 'navidad.ics',
                    format: 'ics',
                    rows: [
                        {
                            line: 3,
                            date: '2026-12-24',
                            name: 'Navidad',
                            status: 'new',
                            message: note,
                        },
                        {
                            line: 3,
                            date: '2026-12-25',
                            name: 'Navidad',
                            status: 'new',
                            message: note,
                        },
                    ],
                    counts: { new: 2, existing: 0, duplicate: 0, error: 0 },
                }}
            />,
        );

        expect(
            document.querySelectorAll('[data-test="holiday-preview-row"]'),
        ).toHaveLength(2);
        expect(screen.getAllByText(note)).toHaveLength(2);
        // Sin claves repetidas en la tabla (React lo avisaría por consola).
        expect(errors).not.toHaveBeenCalled();
        errors.mockRestore();

        await user.click(
            screen.getByRole('button', { name: 'Añadir 2 festivos' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/admin/festivos/importar',
            {
                rows: [
                    { date: '2026-12-24', name: 'Navidad' },
                    { date: '2026-12-25', name: 'Navidad' },
                ],
            },
            expect.any(Object),
        );
    });

    it('muestra el error del fichero que devuelve el servidor', async () => {
        const user = userEvent.setup();
        inertia.post.mockImplementation(
            (
                _url: string,
                _data: unknown,
                options: { onError?: (errors: Record<string, string>) => void },
            ) => options.onError?.({ file: 'El fichero está vacío.' }),
        );
        render(<HolidayImport limits={LIMITS} year={2027} />);

        await user.upload(
            screen.getByLabelText('Fichero .ics o .csv'),
            new File([''], 'vacio.csv', { type: 'text/csv' }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Ver la vista previa' }),
        );

        expect(screen.getByRole('alert').textContent).toBe(
            'El fichero está vacío.',
        );
    });
});
