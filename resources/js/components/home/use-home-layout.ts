import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { orderCards, sameOrder } from '@/components/home/home-layout';
import type { HomeCardId } from '@/components/home/home-layout';
import {
    resetHomeLayout,
    saveHomeLayout,
} from '@/components/home/home-layout-requests';
import { t } from '@/lib/i18n';

/**
 * Orden de las tarjetas de Inicio con guardado optimista (D-138): el orden cambia al soltar y se
 * guarda después; si el servidor no lo guarda, vuelve al último orden guardado con un aviso.
 *
 * - `available`: las tarjetas que se pintan, en su orden por defecto.
 * - `saved`: el orden guardado (prop `home_layout`); null = el orden por defecto.
 */
export function useHomeLayout(
    available: HomeCardId[],
    saved: string[] | null | undefined,
) {
    const [layout, setLayout] = useState<string[] | null>(saved ?? null);
    const [source, setSource] = useState(saved);
    // Último orden que el servidor tiene guardado y número de la última petición: si falla una
    // petición antigua cuando ya hay otra más nueva, no se deshace el cambio nuevo.
    const confirmed = useRef<string[] | null>(saved ?? null);
    const latest = useRef(0);

    // Si llega otro orden del servidor (otra visita a Inicio), manda ese (estado derivado de las
    // props, sin efectos).
    if (saved !== source) {
        setSource(saved);
        setLayout(saved ?? null);
    }

    useEffect(() => {
        confirmed.current = saved ?? null;
    }, [saved]);

    const persist = (next: string[] | null, request: () => Promise<void>) => {
        const id = ++latest.current;
        setLayout(next);

        request().then(
            () => {
                confirmed.current = next;

                if (next === null && id === latest.current) {
                    toast.success(t('home_layout.reset_done'));
                }
            },
            () => {
                if (id === latest.current) {
                    setLayout(confirmed.current);
                }

                toast.error(
                    next === null
                        ? t('home_layout.reset_failed')
                        : t('home_layout.save_failed'),
                );
            },
        );
    };

    /** Guarda el orden nuevo; si coincide con el de por defecto, borra el guardado. */
    const move = (next: HomeCardId[]) => {
        if (sameOrder(next, available)) {
            persist(null, resetHomeLayout);

            return;
        }

        persist(next, () => saveHomeLayout(next));
    };

    const reset = () => persist(null, resetHomeLayout);

    return {
        order: orderCards(available, layout),
        hasSaved: layout !== null,
        move,
        reset,
    };
}
