// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { MyWorkload } from '@/components/workload/my-workload-card';
import type { MyWorkloadData } from '@/components/workload/types';
import { byTest } from './workload-fixtures';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
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

const data: MyWorkloadData = {
    weeks: [
        {
            key: 'semana-actual',
            from: '2026-10-06',
            to: '2026-10-11',
            planned: 300,
            capacity: 1920,
            reason: null,
            reduced: null,
        },
        {
            key: 'semana-que-viene',
            from: '2026-10-12',
            to: '2026-10-18',
            planned: 2400,
            capacity: 1920,
            reason: null,
            reduced: {
                holidays: 1,
                absence_days: 0,
                partial_minutes: 0,
                absence_label: null,
            },
        },
    ],
    overdue: 1,
    unplanned: 2,
};

const text = (value: string | null | undefined) =>
    (value ?? '').replace(/\s/g, ' ');

describe('«Mi carga» en Inicio', () => {
    it('esta semana y la que viene frente a mi capacidad, con el nivel en texto', () => {
        render(<MyWorkload workload={data} />);

        const card = byTest('my-workload');

        expect(text(card.textContent)).toContain('Esta semana');
        expect(text(card.textContent)).toContain('Del 06/10 al 11/10');
        expect(
            screen.getByText(
                (_, element) =>
                    element?.className === 'sr-only' &&
                    text(element.textContent) ===
                        '40:00 planificadas de 32:00 de capacidad (125 %): Sobrecarga',
            ),
        ).toBeTruthy();
        expect(
            screen.getByText('Capacidad reducida: festivos: 1'),
        ).toBeTruthy();
    });

    it('una semana de un solo día (hoy es domingo) lo dice así', () => {
        render(
            <MyWorkload
                workload={{
                    ...data,
                    weeks: [
                        {
                            ...data.weeks[0],
                            from: '2026-10-11',
                            to: '2026-10-11',
                            planned: 0,
                            capacity: 0,
                            reason: { type: 'off', label: null },
                        },
                    ],
                }}
            />,
        );

        const card = byTest('my-workload');
        expect(text(card.textContent)).toContain('Solo el 11/10');
        // El motivo va en la celda y en el texto accesible, sin repetirse al lado.
        expect(screen.getAllByText('No laborable')).toHaveLength(1);
        expect(
            screen.getByText(
                (_, element) =>
                    element?.className === 'sr-only' &&
                    element.textContent ===
                        'Sin capacidad, 0:00 planificadas. No laborable',
            ),
        ).toBeTruthy();
    });

    it('cuenta mis tareas vencidas y sin planificar y enlaza a la vista Carga', () => {
        render(<MyWorkload workload={data} />);

        expect(
            screen.getByText('Tareas vencidas: 1 (su restante cuenta hoy)'),
        ).toBeTruthy();
        expect(screen.getByText('Tareas sin planificar: 2')).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: 'Ver la carga' })
                .getAttribute('href'),
        ).toBe('/carga');
    });

    it('mientras llega la prop diferida, un esqueleto con su estado', () => {
        render(<MyWorkload />);

        expect(
            screen.getByRole('status', { name: 'Calculando tu carga…' }),
        ).toBeTruthy();
    });
});
