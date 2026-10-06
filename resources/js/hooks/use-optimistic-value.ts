import { useState } from 'react';

/**
 * Valor de un filtro que vive en la URL: cambia en cuanto se elige y vuelve a seguir a la prop
 * cuando llega la respuesta. Controlado solo por la prop, el selector volvía al valor anterior
 * mientras cargaba (D-310).
 */
export function useOptimisticValue<T>(prop: T): [T, (value: T) => void] {
    const [value, setValue] = useState(prop);
    const [last, setLast] = useState(prop);

    if (prop !== last) {
        setLast(prop);
        setValue(prop);
    }

    return [value, setValue];
}
