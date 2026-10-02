// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SafeMarkdown } from '@/components/privacy/safe-markdown';
import {
    inlineText,
    parseInline,
    parseMarkdown,
    safeHref,
} from '@/lib/markdown';
import fixture from '../fixtures/privacy-draft.json';

/**
 * Markdown SANEADO del texto de privacidad (D-075): se pinta con elementos de React, sin HTML
 * crudo; los enlaces solo a http(s), mailto y rutas propias; la negrita sin font-bold.
 */

function renderMarkdown(source: string, headingLevel?: 2 | 3) {
    return render(<SafeMarkdown source={source} headingLevel={headingLevel} />);
}

/** Ningún elemento ni atributo peligroso en el DOM pintado. */
function expectNoActiveContent(container: HTMLElement) {
    expect(
        container.querySelectorAll(
            'script, img, iframe, object, embed, svg, style, form, input, button',
        ),
    ).toHaveLength(0);

    for (const element of container.querySelectorAll('*')) {
        for (const attribute of element.getAttributeNames()) {
            expect(attribute.startsWith('on')).toBe(false);
            expect(attribute).not.toBe('style');
        }
    }

    for (const link of container.querySelectorAll('a')) {
        expect(link.getAttribute('href') ?? '').toMatch(
            /^(https?:\/\/|mailto:|\/(?!\/)|#)/i,
        );
    }
}

describe('parseMarkdown', () => {
    it('reconoce encabezados, párrafos, listas, citas y separadores', () => {
        const blocks = parseMarkdown(
            [
                '# Título',
                '',
                'Un párrafo',
                'en dos líneas.',
                '',
                '- uno',
                '- dos',
                '  - dos y medio',
                '',
                '3. tercero',
                '4. cuarto',
                '',
                '> Cita **importante**',
                '',
                '---',
                '## C#',
            ].join('\n'),
        );

        expect(blocks.map((block) => block.type)).toEqual([
            'heading',
            'paragraph',
            'list',
            'list',
            'quote',
            'rule',
            'heading',
        ]);
        expect(blocks[1]).toEqual({
            type: 'paragraph',
            children: [{ type: 'text', text: 'Un párrafo en dos líneas.' }],
        });

        const bullets = blocks[2];
        expect(bullets.type === 'list' && bullets.items).toHaveLength(2);
        expect(
            bullets.type === 'list' && bullets.items[1].map((b) => b.type),
        ).toEqual(['paragraph', 'list']);

        const ordered = blocks[3];
        expect(ordered.type === 'list' && ordered.ordered).toBe(true);
        expect(ordered.type === 'list' && ordered.start).toBe(3);

        const heading = blocks[6];
        expect(heading.type === 'heading' && inlineText(heading.children)).toBe(
            'C#',
        );
    });

    it('énfasis, código, enlaces y saltos de línea en el texto', () => {
        expect(
            parseInline('**negrita** y *cursiva* y _otra_ y `código`'),
        ).toEqual([
            { type: 'strong', children: [{ type: 'text', text: 'negrita' }] },
            { type: 'text', text: ' y ' },
            { type: 'emphasis', children: [{ type: 'text', text: 'cursiva' }] },
            { type: 'text', text: ' y ' },
            { type: 'emphasis', children: [{ type: 'text', text: 'otra' }] },
            { type: 'text', text: ' y ' },
            { type: 'code', text: 'código' },
        ]);
        // El guion bajo no marca énfasis dentro de una palabra; un asterisco rodeado de
        // espacios tampoco.
        expect(parseInline('snake_case_var y 2 * 3 * 4')).toEqual([
            { type: 'text', text: 'snake_case_var y 2 * 3 * 4' },
        ]);
        expect(parseInline('\\*sin cursiva\\*')).toEqual([
            { type: 'text', text: '*sin cursiva*' },
        ]);
        expect(parseInline('línea  \notra')).toEqual([
            { type: 'text', text: 'línea' },
            { type: 'break' },
            { type: 'text', text: 'otra' },
        ]);
        expect(parseInline('[correo de contacto de privacidad]')).toEqual([
            { type: 'text', text: '[correo de contacto de privacidad]' },
        ]);
    });

    it('solo admite enlaces a http(s), mailto, rutas propias y anclas', () => {
        expect(safeHref('https://www.aepd.es')).toBe('https://www.aepd.es');
        expect(safeHref('mailto:privacidad@audaxstudio.com')).toBe(
            'mailto:privacidad@audaxstudio.com',
        );
        expect(safeHref('/ajustes/mis-datos')).toBe('/ajustes/mis-datos');
        expect(safeHref('#derechos')).toBe('#derechos');

        for (const href of [
            'javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            ' javascript:alert(1)',
            'java\u0000script:alert(1)',
            'java\tscript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'vbscript:msgbox(1)',
            '//evil.example.com',
            '/\\evil.example.com',
            'file:///etc/passwd',
            'www.aepd.es',
            '',
        ]) {
            expect(safeHref(href)).toBeNull();
        }
    });
});

describe('SafeMarkdown', () => {
    it('pinta el borrador por defecto con sus encabezados, listas y énfasis', () => {
        const { container } = renderMarkdown(fixture.markdown);

        // El «##» del borrador es el encabezado más alto: pasa a h2 (la página ya tiene el h1).
        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Información sobre el tratamiento de tus datos en Audax Proyectos',
            }),
        ).toBeTruthy();
        expect(container.querySelector('blockquote')?.textContent).toContain(
            'Borrador pendiente de revisión por el asesor.',
        );
        expect(container.querySelectorAll('ul').length).toBeGreaterThanOrEqual(
            5,
        );
        expect(screen.getByText('Responsable:').tagName).toBe('STRONG');
        expectNoActiveContent(container);
    });

    it('la negrita es un énfasis de peso 500, nunca font-bold ni semibold', () => {
        const { container } = renderMarkdown('**Importante** y *matiz*');

        const strong = container.querySelector('strong');
        expect(strong?.className).toContain('font-medium');
        expect(container.querySelector('em')?.className).toContain('italic');
        expect(container.innerHTML).not.toMatch(
            /font-(bold|semibold|extrabold|black)/,
        );
    });

    it('los encabezados empiezan en el nivel indicado y no se saltan niveles', () => {
        renderMarkdown('### Uno\n\n#### Dos\n\n### Tres', 3);

        expect(
            screen.getByRole('heading', { level: 3, name: 'Uno' }),
        ).toBeTruthy();
        expect(
            screen.getByRole('heading', { level: 4, name: 'Dos' }),
        ).toBeTruthy();
        expect(
            screen.getByRole('heading', { level: 3, name: 'Tres' }),
        ).toBeTruthy();
    });

    it('los enlaces llevan rel="noopener noreferrer"; los externos se abren aparte y lo dicen', () => {
        renderMarkdown(
            'Reclama ante la [AEPD](https://www.aepd.es), escribe a <mailto:privacidad@audaxstudio.com> o mira [tus datos](/ajustes/mis-datos).',
        );

        const aepd = screen.getByRole('link', { name: /AEPD/ });
        expect(aepd.getAttribute('href')).toBe('https://www.aepd.es');
        expect(aepd.getAttribute('rel')).toBe('noopener noreferrer');
        expect(aepd.getAttribute('target')).toBe('_blank');
        expect(aepd.textContent).toContain('(se abre en una pestaña nueva)');

        const mail = screen.getByRole('link', {
            name: 'privacidad@audaxstudio.com',
        });
        expect(mail.getAttribute('href')).toBe(
            'mailto:privacidad@audaxstudio.com',
        );
        expect(mail.getAttribute('target')).toBeNull();

        const own = screen.getByRole('link', { name: 'tus datos' });
        expect(own.getAttribute('href')).toBe('/ajustes/mis-datos');
        expect(own.getAttribute('rel')).toBe('noopener noreferrer');
        expect(own.getAttribute('target')).toBeNull();
    });

    it('XSS: <script> y el HTML crudo quedan como texto, sin elementos', () => {
        const { container } = renderMarkdown(
            [
                '<script>alert("xss")</script>',
                '',
                '<img src=x onerror=alert(1)>',
                '',
                '<a href="javascript:alert(1)" onclick="alert(2)">pulsa</a>',
                '',
                '<iframe src="https://evil.example.com"></iframe><style>body{display:none}</style>',
                '',
                '**<svg onload=alert(1)>**',
            ].join('\n'),
        );

        expectNoActiveContent(container);
        expect(container.textContent).toContain(
            '<script>alert("xss")</script>',
        );
        expect(container.textContent).toContain('<img src=x onerror=alert(1)>');
        // Lo único que se enlaza es la dirección https suelta que había en el texto.
        expect(
            [...container.querySelectorAll('a')].map((link) =>
                link.getAttribute('href'),
            ),
        ).toEqual(['https://evil.example.com']);
    });

    it('XSS: los enlaces javascript:, data: y a otros dominios sin esquema se quedan en su texto', () => {
        const { container } = renderMarkdown(
            [
                '[uno](javascript:alert(1))',
                '[dos](JAVASCRIPT:alert(1) "título")',
                '[tres](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)',
                '[cuatro](//evil.example.com)',
                '[cinco](<javascript:alert(1)>)',
                '<javascript:alert(1)>',
                'javascript:alert(1)',
            ].join('\n\n'),
        );

        expect(container.querySelectorAll('a')).toHaveLength(0);
        expect(container.textContent).toContain('uno');
        expect(container.textContent).toContain('cinco');
        expectNoActiveContent(container);
    });

    it('un texto vacío no pinta nada', () => {
        const { container } = renderMarkdown('   \n\n  ');

        expect(container.textContent).toBe('');
    });
});
