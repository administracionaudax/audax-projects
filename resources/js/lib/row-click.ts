import type { MouseEvent } from 'react';

/**
 * Filas y tarjetas clicables enteras (D-324): toda la caja de una tarea la abre, no solo su
 * título, sin romper los controles que lleva dentro.
 *
 * - Cada fila tiene un único control principal (el enlace o botón del título) con
 *   `data-row-primary`: es lo que se enfoca con el teclado y lo que lee el lector de pantalla.
 * - Un clic en cualquier otro punto de la fila «pulsa» ese control. Los clics en otros controles
 *   (casilla, temporizador, menú, campos…) o en lo que abren en un portal (menús, diálogos: los
 *   eventos de React suben por el portal aunque no estén dentro de la fila en el DOM) se quedan en
 *   su control.
 * - Si se está seleccionando texto, no se abre nada.
 * - Con Cmd/Ctrl o el botón central sobre una fila cuyo principal es un enlace, se abre en otra
 *   pestaña, como en el propio enlace.
 */
export const ROW_PRIMARY = 'data-row-primary';

/** Clases de la fila clicable: cursor de mano y fondo al pasar por encima. */
export const ROW_CLICK_CLASS = 'cursor-pointer hover:bg-accent/60';

const INTERACTIVE = [
    'a[href]',
    'button',
    'input',
    'select',
    'textarea',
    'label',
    'summary',
    '[contenteditable="true"]',
    '[role="button"]',
    '[role="checkbox"]',
    '[role="switch"]',
    '[role="menuitem"]',
    '[role="option"]',
    '[role="combobox"]',
    '[role="textbox"]',
    '[data-row-ignore]',
].join(',');

/** ¿El clic cae en un control de la fila (o fuera de ella, en un portal)? */
export function isOwnControlClick(
    row: HTMLElement,
    target: EventTarget | null,
) {
    if (!(target instanceof Element) || !row.contains(target)) {
        return true;
    }

    const control = target.closest(INTERACTIVE);

    return control !== null && control !== row && row.contains(control);
}

function selectingText(): boolean {
    const selection =
        typeof window !== 'undefined' ? window.getSelection() : null;

    return selection !== null && selection.toString().trim() !== '';
}

/**
 * Manejador de `onClick` (y `onAuxClick`) para la fila: pulsa su control principal.
 */
export function clickRowPrimary(event: MouseEvent<HTMLElement>): void {
    const row = event.currentTarget;

    if (
        event.defaultPrevented ||
        event.button > 1 ||
        isOwnControlClick(row, event.target) ||
        selectingText()
    ) {
        return;
    }

    const primary = row.querySelector<HTMLElement>(`[${ROW_PRIMARY}]`);

    if (!primary) {
        return;
    }

    const newTab = event.metaKey || event.ctrlKey || event.button === 1;

    if (primary instanceof HTMLAnchorElement && newTab) {
        window.open(primary.href, '_blank', 'noopener');

        return;
    }

    if (event.button === 1) {
        return;
    }

    primary.click();
}

/** Props de una fila clicable entera. */
export const rowClickProps = {
    onClick: clickRowPrimary,
    onAuxClick: clickRowPrimary,
} as const;
