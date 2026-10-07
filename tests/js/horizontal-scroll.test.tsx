// @vitest-environment jsdom
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { HorizontalScroll } from '@/components/horizontal-scroll';

/** jsdom no maqueta: anchos de una tabla de 1000 px en una caja de 600 px. */
function mockWidths(scrollWidth: number, clientWidth: number) {
    vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockReturnValue(
        scrollWidth,
    );
    vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(
        clientWidth,
    );
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('scroll horizontal que se nota (D-329)', () => {
    it('con contenido oculto a la derecha, sombra a la derecha; al desplazarse, también a la izquierda', () => {
        mockWidths(1000, 600);
        render(
            <HorizontalScroll role="region" aria-label="Tareas">
                <table />
            </HorizontalScroll>,
        );

        const region = screen.getByRole('region', { name: 'Tareas' });
        expect(region.dataset.scrollRight).toBe('true');
        expect(region.dataset.scrollLeft).toBeUndefined();

        region.scrollLeft = 400;
        fireEvent.scroll(region);
        expect(region.dataset.scrollLeft).toBe('true');
        expect(region.dataset.scrollRight).toBeUndefined();
    });

    it('si todo cabe, ninguna sombra', () => {
        mockWidths(600, 600);
        render(
            <HorizontalScroll role="region" aria-label="Tareas">
                <table />
            </HorizontalScroll>,
        );

        const region = screen.getByRole('region', { name: 'Tareas' });
        expect(region.dataset.scrollRight).toBeUndefined();
        expect(region.dataset.scrollLeft).toBeUndefined();
    });
});
