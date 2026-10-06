// @vitest-environment jsdom
import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';

/**
 * D-310 (H-D2): la confirmación no controlada se cierra al terminar la acción. Antes seguía abierta
 * tras borrar una semana destacada y pasaba a apuntar a otra; un segundo clic la borraba.
 */
function Harness() {
    const [processing, setProcessing] = useState(false);

    return (
        <>
            <ConfirmDialog
                trigger={<Button>Borrar</Button>}
                title="¿Borrar la semana?"
                description="No se puede deshacer."
                confirmLabel="Sí, borrar"
                processing={processing}
                onConfirm={() => setProcessing(true)}
            />
            <button type="button" onClick={() => setProcessing(false)}>
                terminar
            </button>
        </>
    );
}

describe('ConfirmDialog', () => {
    it('se cierra sola cuando termina la acción confirmada', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        await user.click(screen.getByRole('button', { name: 'Borrar' }));
        await user.click(screen.getByRole('button', { name: 'Sí, borrar' }));
        // Mientras se envía sigue abierta y con el botón desactivado.
        expect(screen.getByRole('dialog')).toBeTruthy();
        expect(screen.getByText('Sí, borrar').closest('button')?.disabled).toBe(
            true,
        );

        // Al terminar, se cierra (el botón «terminar» está fuera del diálogo modal).
        fireEvent.click(
            screen.getByRole('button', { name: 'terminar', hidden: true }),
        );
        expect(screen.queryByRole('dialog')).toBeNull();
    });
});
