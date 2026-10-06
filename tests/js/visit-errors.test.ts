import { beforeEach, describe, expect, it, vi } from 'vitest';
import { toastUnshownErrors } from '@/components/admin/visit-errors';

const error = vi.fn();

vi.mock('sonner', () => ({
    toast: { error: (...args: unknown[]) => error(...args) },
}));

/** D-310: los errores del servidor sin campo donde pintarse se avisan, no se pierden. */
describe('toastUnshownErrors', () => {
    beforeEach(() => error.mockReset());

    it('avisa de los errores que no tienen campo en el formulario', () => {
        toastUnshownErrors(
            {
                name: 'Falta el nombre',
                forecast: 'El previsto está congelado.',
            },
            ['name', 'client_id'],
        );

        expect(error).toHaveBeenCalledWith('El previsto está congelado.');
    });

    it('no avisa si todos los errores ya salen junto a su campo (también los anidados)', () => {
        toastUnshownErrors(
            { name: 'Falta el nombre', 'client_id.0': 'No válido' },
            ['name', 'client_id'],
        );

        expect(error).not.toHaveBeenCalled();
    });
});
