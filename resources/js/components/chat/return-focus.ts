import type { RefObject } from 'react';

/**
 * Foco al cerrar un diálogo o un popover que se abrió desde un menú (WCAG 2.4.3): Radix devuelve
 * el foco al elemento que lo tenía al abrir, pero si era una opción de un menú que ya no existe,
 * el foco se pierde en <body>. Con esto vuelve a un elemento que sigue en la página (el botón
 * que abrió el menú, el mensaje…). Se usa como `onCloseAutoFocus`.
 */
export function returnFocusTo(
    target:
        | RefObject<HTMLElement | null>
        | (() => HTMLElement | null | undefined),
): (event: Event) => void {
    return (event) => {
        const element =
            typeof target === 'function' ? target() : target.current;

        if (element && element.isConnected) {
            event.preventDefault();
            element.focus();
        }
    };
}

/** Foco a un mensaje de la lista (su <article>, enfocable con tabIndex -1) o a su menú de acciones. */
export function messageFocusTarget(
    messageId: number,
    prefer: 'message' | 'actions' = 'message',
): HTMLElement | null {
    const item = document.getElementById(`mensaje-${messageId}`);

    if (!item) {
        return null;
    }

    const actions = item.querySelector<HTMLElement>(
        '[data-test="chat-message-actions"]',
    );
    const message = item.querySelector<HTMLElement>('[data-message-focus]');

    return prefer === 'actions' ? (actions ?? message) : (message ?? actions);
}
