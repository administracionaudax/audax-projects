import { describe, expect, it } from 'vitest';
import { missingDeferredProps } from '@/lib/deferred-props';

/**
 * Red de seguridad de las props diferidas (D-310): si una visita cancela su carga antes de que
 * lleguen (p. ej. invitar al portal en la ficha de cliente nada más abrirla), se vuelven a pedir.
 */
describe('missingDeferredProps', () => {
    const base = {
        props: { errors: {}, client: { id: 5 } } as Record<string, unknown>,
        rescuedProps: [] as string[],
    };

    it('devuelve, por grupo, las diferidas de la carga inicial que siguen sin llegar', () => {
        expect(
            missingDeferredProps({
                ...base,
                props: { ...base.props, portal: { users: [] } },
                initialDeferredProps: {
                    default: ['weekly'],
                    portal: ['portal'],
                    people: ['people'],
                },
            }),
        ).toEqual({ default: ['weekly'], people: ['people'] });
    });

    it('usa deferredProps si la página aún no ha guardado las iniciales', () => {
        expect(
            missingDeferredProps({
                ...base,
                deferredProps: { default: ['clients'] },
            }),
        ).toEqual({ default: ['clients'] });
    });

    it('no vuelve a pedir las que ya se están pidiendo ni las rescatadas', () => {
        expect(
            missingDeferredProps(
                {
                    ...base,
                    rescuedProps: ['portal'],
                    initialDeferredProps: {
                        default: ['weekly'],
                        portal: ['portal'],
                    },
                },
                new Set(['weekly']),
            ),
        ).toEqual({});
    });

    it('un valor null cuenta como llegado (la prop existe aunque esté vacía)', () => {
        expect(
            missingDeferredProps({
                ...base,
                props: { ...base.props, weekly: null },
                initialDeferredProps: { default: ['weekly'] },
            }),
        ).toEqual({});
    });
});
