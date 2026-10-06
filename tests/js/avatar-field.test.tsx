// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
    clampCrop,
    CROP_VIEW,
    cropScale,
    cropSource,
} from '@/components/settings/avatar-field';

describe('el recorte de la foto de perfil (D-234)', () => {
    it('la imagen siempre cubre el recuadro: no se puede arrastrar fuera', () => {
        // 1000 x 500: el lado corto (500) llena los 256 px del recuadro.
        expect(cropScale(1000, 500, 1)).toBeCloseTo(CROP_VIEW / 500);
        const clamped = clampCrop(1000, 500, { zoom: 1, x: 50, y: -40 });
        expect(clamped).toEqual({ zoom: 1, x: 0, y: 0 });

        const far = clampCrop(1000, 500, { zoom: 1, x: -9999, y: 0 });
        expect(far.x).toBeCloseTo(CROP_VIEW - 1000 * (CROP_VIEW / 500));
    });

    it('lo que se sube es la parte de la imagen dentro del recuadro', () => {
        // Centrada con zoom 2 en una imagen cuadrada de 1000 px: se ve la mitad central.
        const scale = cropScale(1000, 1000, 2);
        const offset = (CROP_VIEW - 1000 * scale) / 2;
        const source = cropSource(1000, 1000, {
            zoom: 2,
            x: offset,
            y: offset,
        });

        expect(source.size).toBeCloseTo(500);
        expect(source.sx).toBeCloseTo(250);
        expect(source.sy).toBeCloseTo(250);
    });
});
