/**
 * Indicador de foco común (UI-03, WCAG 1.4.11 y 2.4.7): anillo de 2 px con el token `--ring`
 * OPACO y separado 2 px del control con el color de fondo.
 *
 * `--ring` (#0171FF en claro, #4A93FF en oscuro) da ≥ 3:1 sobre el fondo y las tarjetas
 * (tests/js/theme-contrast.test.ts) y sobre el degradado de marca con su velo
 * (tests/js/brand-gradient-contrast.test.ts). Con el anillo al 50 % de opacidad bajaba a ≈ 2:1,
 * así que nunca se usa con alfa: tests/js/focus-ring.test.tsx lo vigila en todo resources/js.
 */
export const FOCUS_RING =
    'outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background';
