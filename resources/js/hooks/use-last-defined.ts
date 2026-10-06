import { useState } from 'react';

/**
 * El último valor recibido de una prop diferida mientras se vuelve a pedir. Tras guardar (una visita
 * completa) Inertia la deja en `undefined` hasta que llega otra vez: los diálogos y secciones que
 * dependían de ella se desmontaban, perdiendo lo escrito y los errores (D-310).
 */
export function useLastDefined<T>(value: T | undefined): T | undefined {
    const [last, setLast] = useState(value);

    if (value !== undefined && value !== last) {
        setLast(value);
    }

    return value ?? last;
}
