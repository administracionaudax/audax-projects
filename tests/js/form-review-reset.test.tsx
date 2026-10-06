// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ForecastProjectDialog } from '@/components/forecast/forecast-project-dialog';
import type { ForecastProject } from '@/types/forecast';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({
        url: '/prevision/proyectos/1',
        props: { auth: { can: {} } },
    }),
}));

function forecast(overrides: Partial<ForecastProject> = {}): ForecastProject {
    return {
        id: 1,
        name: 'Web y branding',
        client: { id: 3, name: 'Hotel Mar Azul' },
        prospect_name: null,
        client_name: 'Hotel Mar Azul',
        color: '#0171FF',
        description: null,
        owner: { id: 1, name: 'Ana' },
        confidence: 'tentative',
        status: 'open',
        lost_reason: null,
        lost_at: null,
        start_date: null,
        end_date: null,
        estimated_minutes: null,
        estimated_amount: null,
        allocated_minutes: null,
        project: null,
        linked_at: null,
        starts_in_past: false,
        can: {
            update: true,
            confirm: true,
            lose: true,
            reopen: true,
            delete: true,
        },
        ...overrides,
    } as ForecastProject;
}

describe('formularios que se reinician al abrir (D-310, H-E1)', () => {
    it('«Editar» un previsto parte de los datos de ahora, no de los de la primera carga', () => {
        const clients = [{ id: 3, name: 'Hotel Mar Azul' }];
        const { rerender } = render(
            <ForecastProjectDialog
                forecast={forecast()}
                clients={clients}
                open={false}
                onOpenChange={() => {}}
            />,
        );

        // Tras «Hacer segura» y otra edición, la ficha trae el previsto nuevo y se abre «Editar».
        rerender(
            <ForecastProjectDialog
                forecast={forecast({ name: 'Web nueva', confidence: 'firm' })}
                clients={clients}
                open
                onOpenChange={() => {}}
            />,
        );

        expect(
            (
                screen.getByRole('textbox', {
                    name: /Nombre/,
                }) as HTMLInputElement
            ).value,
        ).toBe('Web nueva');
        expect(
            screen
                .getByRole('radio', { name: /Segura/ })
                .getAttribute('aria-checked'),
        ).toBe('true');
    });
});
