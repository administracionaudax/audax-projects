<?php

use App\Support\RichText;

it('elimina scripts, estilos, atributos peligrosos e imágenes', function () {
    $html = '<p onclick="x()">Hola <script>alert(1)</script><img src="x" onerror="y()"><strong style="color:red">ya</strong></p>';

    expect(RichText::sanitize($html))->toBe('<p>Hola <strong>ya</strong></p>');
});

it('solo permite enlaces http, https y mailto, y los abre en otra pestaña sin opener', function () {
    expect(RichText::sanitize('<p><a href="javascript:alert(1)">x</a></p>'))->not->toContain('javascript')
        ->and(RichText::sanitize('<p><a href="https://audaxstudio.com">web</a></p>'))
        ->toContain('href="https://audaxstudio.com"')
        ->toContain('rel="noopener noreferrer nofollow"')
        ->toContain('target="_blank"');
});

it('conserva las menciones y extrae los ids mencionados sin repetir', function () {
    $html = '<p>Hola <span data-type="mention" data-id="7" data-label="Ana">@Ana</span> y '
        .'<span data-type="mention" data-id="9" data-label="Luis" class="x">@Luis</span> '
        .'<span data-type="mention" data-id="7" data-label="Ana">@Ana</span></p>';

    $clean = RichText::sanitize($html);

    expect($clean)->toContain('data-type="mention"')
        ->not->toContain('class=')
        ->and(RichText::mentionedUserIds($clean))->toBe([7, 9]);
});

it('trata como vacío el HTML sin texto', function () {
    expect(RichText::sanitize('<p>  </p><p><br></p>'))->toBeNull()
        ->and(RichText::sanitize(null))->toBeNull();
});

it('convierte a texto plano con límite', function () {
    expect(RichText::toPlainText('<p>Primera</p><p>segunda &amp; tercera</p>'))->toBe('Primera segunda & tercera')
        ->and(RichText::toPlainText('<p>abcdefghij</p>', 6))->toBe('abcde…');
});
