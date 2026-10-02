// @vitest-environment jsdom
import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    MarkdownText,
    markdownToPlainText,
    mentionsUser,
    parseMarkdown,
    safeHref,
    trimUrl,
} from '@/components/chat/markdown';

/**
 * Markdown ligero del chat (D-069): formato básico, enlaces seguros y menciones, SIEMPRE saneado
 * (se pinta con elementos de React; nunca HTML crudo).
 */

const names = new Map([
    [7, 'Ana Pérez'],
    [9, 'Luis'],
]);

function html(body: string, currentUserId: number | null = null): HTMLElement {
    const { container } = render(
        <MarkdownText
            body={body}
            names={names}
            currentUserId={currentUserId}
        />,
    );

    return container.firstElementChild as HTMLElement;
}

describe('parseMarkdown', () => {
    it('reconoce negrita, cursiva, código y bloques de código', () => {
        expect(parseMarkdown('**hola** y *adiós* con `x = 1`')).toEqual([
            { type: 'strong', children: [{ type: 'text', value: 'hola' }] },
            { type: 'text', value: ' y ' },
            { type: 'em', children: [{ type: 'text', value: 'adiós' }] },
            { type: 'text', value: ' con ' },
            { type: 'code', value: 'x = 1' },
        ]);

        expect(parseMarkdown('antes\n```php\necho 1;\n```\ndespués')).toEqual([
            { type: 'text', value: 'antes\n' },
            { type: 'codeblock', value: 'echo 1;' },
            { type: 'text', value: 'después' },
        ]);
    });

    it('anida el formato y admite __negrita__ y _cursiva_', () => {
        expect(parseMarkdown('**muy _importante_**')).toEqual([
            {
                type: 'strong',
                children: [
                    { type: 'text', value: 'muy ' },
                    {
                        type: 'em',
                        children: [{ type: 'text', value: 'importante' }],
                    },
                ],
            },
        ]);
        expect(parseMarkdown('__sí__')[0]).toMatchObject({ type: 'strong' });
    });

    it('no toma por formato los _ y * de dentro de las palabras, URLs ni código', () => {
        expect(parseMarkdown('snake_case_var y 2*3*4')).toEqual([
            { type: 'text', value: 'snake_case_var y 2*3*4' },
        ]);
        expect(parseMarkdown('ver https://a.es/x_y_z_ ya')).toEqual([
            { type: 'text', value: 'ver ' },
            {
                type: 'link',
                href: 'https://a.es/x_y_z_',
                label: 'https://a.es/x_y_z_',
            },
            { type: 'text', value: ' ya' },
        ]);
        expect(parseMarkdown('`*no*`')).toEqual([
            { type: 'code', value: '*no*' },
        ]);
        expect(parseMarkdown('**sin cerrar')).toEqual([
            { type: 'text', value: '**sin cerrar' },
        ]);
    });

    it('menciones <@ID> y @todos', () => {
        expect(
            parseMarkdown('hola <@7>, @todos a revisar; correo@todos.es no'),
        ).toEqual([
            { type: 'text', value: 'hola ' },
            { type: 'mention', id: 7 },
            { type: 'text', value: ', ' },
            { type: 'everyone' },
            { type: 'text', value: ' a revisar; correo@todos.es no' },
        ]);
    });

    it('enlaces markdown y URLs sueltas sin la puntuación final', () => {
        expect(
            parseMarkdown('[la web](https://audaxstudio.com) y https://a.es.'),
        ).toEqual([
            { type: 'link', href: 'https://audaxstudio.com', label: 'la web' },
            { type: 'text', value: ' y ' },
            { type: 'link', href: 'https://a.es', label: 'https://a.es' },
            { type: 'text', value: '.' },
        ]);
        expect(
            trimUrl('https://es.wikipedia.org/wiki/Valencia_(España))'),
        ).toBe('https://es.wikipedia.org/wiki/Valencia_(España)');
    });
});

describe('saneado (XSS)', () => {
    it('solo admite enlaces http, https y mailto', () => {
        expect(safeHref('https://a.es')).toBe('https://a.es');
        expect(safeHref('mailto:hola@a.es')).toBe('mailto:hola@a.es');
        expect(safeHref('javascript:alert(1)')).toBeNull();
        expect(safeHref('JaVaScRiPt:alert(1)')).toBeNull();
        expect(safeHref(' javascript:alert(1)')).toBeNull();
        expect(safeHref('data:text/html,<script>alert(1)</script>')).toBeNull();
        expect(safeHref('vbscript:msgbox(1)')).toBeNull();
        expect(safeHref('//evil.es')).toBeNull();
        expect(safeHref('/relativo')).toBeNull();
    });

    it('nunca pinta HTML del mensaje: etiquetas y atributos quedan como texto', () => {
        const element = html(
            '<script>alert(1)</script><img src=x onerror=alert(1)> <b onclick="x()">no</b> &lt;i&gt;',
        );

        expect(element.querySelector('script, img, b, i')).toBeNull();
        expect(element.textContent).toBe(
            '<script>alert(1)</script><img src=x onerror=alert(1)> <b onclick="x()">no</b> &lt;i&gt;',
        );
    });

    it('los enlaces peligrosos se quedan como texto y los buenos llevan rel y target', () => {
        const element = html(
            '[pulsa](javascript:alert(1)) [mal](data:text/html,x) [bien](https://audaxstudio.com) http://a.es/"onmouseover="alert(1)',
        );
        const links = [...element.querySelectorAll('a')];

        expect(links.map((link) => link.getAttribute('href'))).toEqual([
            'https://audaxstudio.com',
            'http://a.es/',
        ]);

        for (const link of links) {
            expect(link.getAttribute('rel')).toBe(
                'noopener noreferrer nofollow',
            );
            expect(link.getAttribute('target')).toBe('_blank');
            expect(
                link
                    .getAttributeNames()
                    .filter((name) => name.startsWith('on')),
            ).toEqual([]);
        }

        expect(element.textContent).toContain('[pulsa](javascript:alert(1))');
    });

    it('un nombre con HTML se pinta como texto en la mención', () => {
        const element = render(
            <MarkdownText
                body="hola <@1>"
                names={new Map([[1, '<img src=x onerror=alert(1)>']])}
            />,
        ).container;

        expect(element.querySelector('img')).toBeNull();
        expect(element.textContent).toBe('hola @<img src=x onerror=alert(1)>');
    });
});

describe('pintado', () => {
    it('pinta formato, código, menciones (la propia resaltada) y @todos', () => {
        const element = html('**Ojo** <@7> <@9> @todos `x` *ya*', 7);

        expect(element.querySelector('strong')?.textContent).toBe('Ojo');
        expect(element.querySelector('strong')?.className).toContain(
            'font-medium',
        );
        expect(element.querySelector('em')?.textContent).toBe('ya');
        expect(element.querySelector('code')?.textContent).toBe('x');

        const mentions = [...element.querySelectorAll('[data-mention]')];
        expect(mentions.map((mention) => mention.textContent)).toEqual([
            '@Ana Pérez',
            '@Luis',
            '@todos',
        ]);
        expect(mentions[0].className).toContain('bg-info-soft');
        expect(mentions[1].className).not.toContain('bg-info-soft');
    });

    it('una mención a alguien desconocido se ve como @persona', () => {
        expect(html('hola <@999>').textContent).toBe('hola @persona');
    });

    it('conserva los saltos de línea', () => {
        const element = html('uno\ndos');

        expect(element.className).toContain('whitespace-pre-wrap');
        expect(element.textContent).toBe('uno\ndos');
    });
});

describe('texto plano y menciones', () => {
    it('quita las marcas y resuelve las menciones', () => {
        expect(
            markdownToPlainText(
                '**Hola** <@7>, mira [esto](https://a.es) y `código`\n\n```\nbloque\n```',
                names,
            ),
        ).toBe('Hola @Ana Pérez, mira esto y código bloque');
    });

    it('sabe si un mensaje menciona a una persona o a todos', () => {
        expect(mentionsUser('hola <@7>', 7)).toBe(true);
        expect(mentionsUser('**hola <@7>**', 7)).toBe(true);
        expect(mentionsUser('hola <@9>', 7)).toBe(false);
        expect(mentionsUser('@todos a la reunión', 7)).toBe(true);
        expect(mentionsUser('`<@7>`', 7)).toBe(false);
        expect(mentionsUser(null, 7)).toBe(false);
    });
});

describe('coste acotado (D-121)', () => {
    const worst = [
        '*a '.repeat(3333),
        '_a '.repeat(3333),
        '**a '.repeat(2500),
        `${'*x http://a.b/' + '_'.repeat(20) + ' '}`.repeat(250),
        '['.repeat(10_000),
        `${'[a](' + 'h'.repeat(40)}`.repeat(200),
        'http://x.es/'.repeat(800),
    ];

    it('10.000 caracteres llenos de marcas sin cerrar se analizan en poco tiempo', () => {
        for (const src of worst) {
            const started = performance.now();
            const nodes = parseMarkdown(src);
            const elapsed = performance.now() - started;

            expect(nodes.length).toBeGreaterThan(0);
            // Antes, ~1,5 s con «*a »; con el presupuesto lineal, unas decenas de ms (con margen
            // para máquinas lentas y la CI).
            expect(elapsed, src.slice(0, 12)).toBeLessThan(250);
        }
    });

    it('el texto no se pierde aunque se agote el presupuesto', () => {
        const src = '*a '.repeat(3333);

        expect(markdownToPlainText(src, names)).toBe(src.trim());
    });

    it('lo normal sigue igual dentro de un mensaje largo', () => {
        const src = `${'texto normal '.repeat(500)}**negrita** y *cursiva*`;
        const nodes = parseMarkdown(src);

        expect(nodes.some((node) => node.type === 'strong')).toBe(true);
        expect(nodes.some((node) => node.type === 'em')).toBe(true);
    });

    it('@todos sale del fichero de textos', () => {
        expect(markdownToPlainText('Aviso @todos', names)).toBe('Aviso @todos');
        expect(html('Aviso @todos').textContent).toBe('Aviso @todos');
    });
});
