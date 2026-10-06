import { useState } from 'react';

/**
 * Reinicia un formulario cada vez que su diálogo pasa a abierto, también si lo abre el padre
 * (controlado), cuando Radix no llama a `onOpenChange(true)`. Con Inertia la página no se vuelve a
 * montar al guardar (`preserveState`), así que un formulario iniciado al montar conservaba los datos
 * de la primera carga o del intento anterior (D-310).
 *
 * Estado derivado, sin efecto: el reinicio ocurre en el mismo render en que se abre.
 */
export function useResetOnOpen(open: boolean, reset: () => void): void {
    const [wasOpen, setWasOpen] = useState(open);

    if (open !== wasOpen) {
        setWasOpen(open);

        if (open) {
            reset();
        }
    }
}
