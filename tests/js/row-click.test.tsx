// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createPortal } from 'react-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ROW_CLICK_CLASS, rowClickProps } from '@/lib/row-click';

/**
 * Filas clicables enteras (D-324): un clic en la fila pulsa su control principal; los demás
 * controles, lo que abren en un portal y la selección de texto se quedan como estaban.
 */
function Row({
    onOpen,
    onOther,
    link = false,
}: {
    onOpen: () => void;
    onOther: () => void;
    link?: boolean;
}) {
    return (
        <div className={ROW_CLICK_CLASS} {...rowClickProps} data-testid="row">
            {link ? (
                <a
                    href="/proyectos/1"
                    data-row-primary
                    onClick={(event) => {
                        event.preventDefault();
                        onOpen();
                    }}
                >
                    Proyecto
                </a>
            ) : (
                <button type="button" data-row-primary onClick={onOpen}>
                    Tarea
                </button>
            )}
            <span data-testid="text">Texto de la fila</span>
            <input type="checkbox" aria-label="Hecha" />
            <button type="button" onClick={onOther}>
                Temporizador
            </button>
            <div data-row-ignore>
                <span data-testid="ignored">Notas</span>
            </div>
            {createPortal(
                <div role="menuitem" data-testid="portal">
                    Mover
                </div>,
                document.body,
            )}
        </div>
    );
}

afterEach(() => vi.restoreAllMocks());

describe('rowClickProps', () => {
    it('un clic en el texto de la fila pulsa el control principal', async () => {
        const onOpen = vi.fn();
        const user = userEvent.setup();
        render(<Row onOpen={onOpen} onOther={vi.fn()} />);

        await user.click(screen.getByTestId('text'));
        expect(onOpen).toHaveBeenCalledTimes(1);
        expect(screen.getByTestId('row').className).toContain('cursor-pointer');
    });

    it('los controles propios, lo ignorado y lo que sale en un portal no abren la fila', async () => {
        const onOpen = vi.fn();
        const onOther = vi.fn();
        const user = userEvent.setup();
        render(<Row onOpen={onOpen} onOther={onOther} />);

        await user.click(screen.getByRole('checkbox', { name: 'Hecha' }));
        await user.click(screen.getByRole('button', { name: 'Temporizador' }));
        await user.click(screen.getByTestId('ignored'));
        await user.click(screen.getByTestId('portal'));

        expect(onOther).toHaveBeenCalledTimes(1);
        expect(onOpen).not.toHaveBeenCalled();
    });

    it('el control principal se pulsa una sola vez y es el único que enfoca el teclado', async () => {
        const onOpen = vi.fn();
        const user = userEvent.setup();
        render(<Row onOpen={onOpen} onOther={vi.fn()} />);

        await user.click(screen.getByRole('button', { name: 'Tarea' }));
        expect(onOpen).toHaveBeenCalledTimes(1);
        expect(screen.getByTestId('row').tabIndex).toBe(-1);
    });

    it('con texto seleccionado no abre nada', async () => {
        const onOpen = vi.fn();
        const user = userEvent.setup();
        vi.spyOn(window, 'getSelection').mockReturnValue({
            toString: () => 'Texto',
        } as Selection);
        render(<Row onOpen={onOpen} onOther={vi.fn()} />);

        await user.click(screen.getByTestId('text'));
        expect(onOpen).not.toHaveBeenCalled();
    });

    it('con Cmd o Ctrl, una fila con enlace se abre en otra pestaña', async () => {
        const onOpen = vi.fn();
        const open = vi.spyOn(window, 'open').mockReturnValue(null);
        const user = userEvent.setup();
        render(<Row onOpen={onOpen} onOther={vi.fn()} link />);

        await user.keyboard('{Control>}');
        await user.click(screen.getByTestId('text'));
        await user.keyboard('{/Control}');

        expect(open).toHaveBeenCalledWith(
            expect.stringContaining('/proyectos/1'),
            '_blank',
            'noopener',
        );
        expect(onOpen).not.toHaveBeenCalled();
    });
});
