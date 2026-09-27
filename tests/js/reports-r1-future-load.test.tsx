// @vitest-environment jsdom
import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import {
    R1FutureLoad,
    R1FutureLoadSkeleton,
} from '@/components/reports/r1-future-load';
import type { R1FutureLoad as FutureLoad } from '@/components/reports/r1-types';

/*
 * «Carga futura» del informe de departamento: una fila por persona y una columna por semana con el
 * semáforo de la vista Carga (cifras, porcentaje y nivel, nunca solo color), la fila del equipo y
 * el enlace a /carga con el departamento.
 */

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
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

const week = (from: string, to: string, today = false) => ({
    key: from,
    from,
    to,
    today,
    weekend: false,
});

const load: FutureLoad = {
    columns: [
        week('2026-10-06', '2026-10-11', true),
        week('2026-10-12', '2026-10-18'),
    ],
    people: [
        {
            id: 3,
            name: 'Elena Empleada',
            cells: [
                { planned: 1920, capacity: 1920 },
                { planned: 3000, capacity: 960 },
            ],
            total: { planned: 4920, capacity: 2880 },
        },
        {
            id: 4,
            name: 'Lucía Martín',
            cells: [
                { planned: 0, capacity: 1920 },
                { planned: 60, capacity: 0 },
            ],
            total: { planned: 60, capacity: 1920 },
        },
    ],
    totals: [
        { planned: 1920, capacity: 3840 },
        { planned: 3060, capacity: 960 },
    ],
    total: { planned: 4980, capacity: 4800 },
    url: '/carga?horizonte=4-semanas&departamento=1',
};

describe('carga futura del departamento', () => {
    it('pinta una fila por persona con sus semanas, el total y la fila del equipo', () => {
        render(<R1FutureLoad load={load} />);

        const table = screen.getByRole('table', {
            name: 'Carga planificada de cada persona del departamento en las próximas cuatro semanas',
        });
        const headers = within(table)
            .getAllByRole('columnheader')
            .map((header) => header.textContent);
        expect(headers).toEqual([
            'Persona',
            expect.stringContaining('06/10'),
            expect.stringContaining('12/10'),
            'Total',
        ]);

        const elena = within(table).getByRole('row', {
            name: /Elena Empleada/,
        });
        expect(within(elena).getByText('32:00 / 32:00')).toBeTruthy();
        expect(within(elena).getByText('50:00 / 16:00')).toBeTruthy();
        expect(within(elena).getByText('313 %')).toBeTruthy();

        const team = within(table).getByRole('row', { name: /Equipo/ });
        expect(within(team).getByText('83:00 / 80:00')).toBeTruthy();
    });

    it('sin capacidad no enseña porcentaje, sino las horas planificadas', () => {
        render(<R1FutureLoad load={load} />);

        const lucia = screen.getByRole('row', { name: /Lucía Martín/ });
        expect(within(lucia).getAllByText(/1:00/).length).toBeGreaterThan(0);
    });

    it('enlaza con la vista Carga del departamento', () => {
        render(<R1FutureLoad load={load} />);

        expect(
            screen
                .getByRole('link', { name: 'Ver en Carga' })
                .getAttribute('href'),
        ).toBe('/carga?horizonte=4-semanas&departamento=1');
    });

    it('sin personas, un estado vacío con texto', () => {
        render(
            <R1FutureLoad
                load={{
                    ...load,
                    people: [],
                    totals: [],
                    total: { planned: 0, capacity: 0 },
                }}
            />,
        );

        expect(screen.queryByRole('table')).toBeNull();
        expect(
            screen.getByText(
                'No hay nadie en este departamento con carga que mostrar.',
            ),
        ).toBeTruthy();
    });

    it('mientras carga, lo anuncia a los lectores de pantalla', () => {
        render(<R1FutureLoadSkeleton />);

        expect(
            screen.getByText('Calculando la carga de las próximas semanas…'),
        ).toBeTruthy();
    });
});
