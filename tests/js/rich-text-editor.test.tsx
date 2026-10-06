// @vitest-environment jsdom
import { act, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import RichTextEditor from '@/components/rich-text/rich-text-editor';

describe('editor de texto enriquecido: Título, Subtítulo y Divisor (F-154)', () => {
    it('la barra tiene los botones de WeeklySync y producen h3, h4 y hr', async () => {
        // jsdom no mide: ProseMirror pide los rectángulos al desplazar la selección.
        const rects = () =>
            Object.assign([], { item: () => null }) as unknown as DOMRectList;
        Element.prototype.getClientRects = rects;
        Range.prototype.getClientRects = rects;
        Range.prototype.getBoundingClientRect = () => new DOMRect();

        const onChange = vi.fn();
        render(
            <RichTextEditor
                value="<p>Hola</p>"
                onChange={onChange}
                mentionables={[]}
                aria-label="Texto"
            />,
        );

        const toolbar = screen.getByRole('toolbar', {
            name: 'Formato del texto',
        });
        const title = screen.getByRole('button', { name: 'Título' });
        expect(toolbar.contains(title)).toBe(true);

        await act(async () => title.click());
        expect(onChange.mock.lastCall?.[0]).toMatch(/^<h3>Hola<\/h3>/);
        expect(title.getAttribute('aria-pressed')).toBe('true');

        await act(async () =>
            screen.getByRole('button', { name: 'Subtítulo' }).click(),
        );
        expect(onChange.mock.lastCall?.[0]).toMatch(/^<h4>Hola<\/h4>/);

        await act(async () =>
            screen.getByRole('button', { name: 'Divisor' }).click(),
        );
        expect(onChange.mock.lastCall?.[0]).toContain('<hr>');
    });
});
