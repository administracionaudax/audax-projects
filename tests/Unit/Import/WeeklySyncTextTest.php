<?php

use App\Domain\Import\WeeklySync\WeeklySyncText;

/*
| Textos de WeeklySync (10.9b): el saneador de RichText borra las etiquetas que no admite CON su
| texto, así que antes se normalizan. Ningún texto del original se pierde al importar.
*/

test('normaliza el HTML del editor de WeeklySync sin perder texto', function (string $original, string $expected) {
    expect(WeeklySyncText::rich($original))->toBe($expected);
})->with([
    'h1 y h2 → h3' => ['<h1>Título</h1><h2>Subtítulo</h2><p>Texto</p>', '<h3>Título</h3><h3>Subtítulo</h3><p>Texto</p>'],
    'h5 y h6 → h4' => ['<h5>Cinco</h5><h6>Seis</h6>', '<h4>Cinco</h4><h4>Seis</h4>'],
    'b → strong e i → em' => ['<p>Hola <b>negrita</b> <i>cursiva</i></p>', '<p>Hola <strong>negrita</strong> <em>cursiva</em></p>'],
    'strike y del → s' => ['<p><strike>uno</strike> <del>dos</del></p>', '<p><s>uno</s> <s>dos</s></p>'],
    'div → p' => ['<div>línea uno</div><div>línea dos</div>', '<p>línea uno</p><p>línea dos</p>'],
    'contentEditable: texto suelto y divs' => ['Hola<div>segunda línea</div><div><b>tercera</b></div>', '<p>Hola</p><p>segunda línea</p><p><strong>tercera</strong></p>'],
    'div con párrafos dentro se desenvuelve' => ['<div><p>uno</p><p>dos</p></div>', '<p>uno</p><p>dos</p>'],
    'imagen con dirección web → enlace' => ['<p>Mira <img src="https://example.com/a.png" alt="captura"></p>', '<p>Mira <a href="https://example.com/a.png" rel="noopener noreferrer nofollow" target="_blank">captura</a></p>'],
    'imagen incrustada → texto alternativo' => ['<p>Antes <img src="data:image/png;base64,AAAA" alt="logo"> después</p>', '<p>Antes logo después</p>'],
    'imagen sin texto alternativo' => ['<p><img src="data:image/png;base64,AAAA"></p>', '<p>[Imagen]</p>'],
    'font y span sin mención se desenvuelven' => ['<p><font color="red">rojo</font> <span style="x">azul</span></p>', '<p>rojo azul</p>'],
    'tabla → párrafos' => ['<table><tr><td>A</td><td>B</td></tr><tr><td>C</td></tr></table>', '<p>A B </p><p>C </p>'],
    'scripts fuera' => ['<p>Bien</p><script>alert(1)</script>', '<p>Bien</p>'],
    'hr y listas se conservan' => ['<ul><li>uno</li></ul><hr><ol><li>dos</li></ol>', '<ul><li>uno</li></ul><hr /><ol><li>dos</li></ol>'],
]);

test('el Markdown de las sugerencias conserva títulos, negritas e imágenes', function (string $original, string $expected) {
    expect(WeeklySyncText::rich($original))->toBe($expected);
})->with([
    '# y ##' => ["# Título\n\n## Sub\n\nTexto **neg** y *cur*", "<h3>Título</h3>\n<h3>Sub</h3>\n<p>Texto <strong>neg</strong> y <em>cur</em></p>"],
    '### y ####' => ["### Tres\n\n#### Cuatro", "<h3>Tres</h3>\n<h4>Cuatro</h4>"],
    'imagen Markdown' => ['![captura](https://example.com/c.png)', '<p><a href="https://example.com/c.png" rel="noopener noreferrer nofollow" target="_blank">captura</a></p>'],
    'lista y separador' => ["- uno\n- dos\n\n---\n\nfin", "<ul>\n<li>uno</li>\n<li>dos</li>\n</ul>\n<hr />\n<p>fin</p>"],
]);

test('las menciones se conservan al normalizar', function () {
    $html = WeeklySyncText::rich(
        '<div>Hola @[Ana López](user:11111111-1111-1111-1111-111111111111)</div>',
        fn (string $uuid): ?int => 7,
        fn (int $id): ?string => 'Ana López',
    );

    expect($html)->toBe('<p>Hola <span data-type="mention" data-id="7" data-label="Ana López">&#64;Ana López</span></p>');
});

test('un texto plano sigue siendo un párrafo', function () {
    expect(WeeklySyncText::rich("Primera línea\n\nSegunda"))->toBe("<p>Primera línea</p>\n<p>Segunda</p>")
        ->and(WeeklySyncText::rich('   '))->toBeNull();
});
