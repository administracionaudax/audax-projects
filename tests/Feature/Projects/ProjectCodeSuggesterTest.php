<?php

use App\Domain\Projects\ProjectCodeSuggester;
use App\Models\Project;

/*
| Código sugerido de un proyecto (SPEC §4.2). Mismos casos que tests/js/projects-code.test.ts
| (el gemelo del frontend, resources/js/components/projects-list/project-code.ts).
*/

test('sugiere la primera palabra significativa del cliente y del nombre', function (?string $client, string $name, string $expected) {
    expect(app(ProjectCodeSuggester::class)->suggest($client, $name))->toBe($expected);
})->with([
    'cliente y nombre' => ['Acme', 'Web corporativa', 'ACME-WEB'],
    'sin acentos' => ['Clínica Dental Sonríe', 'App de citas', 'CLINICA-APP'],
    'salta artículos' => ['El Corte Inglés', 'La tienda online', 'CORTE-TIENDA'],
    'como mucho 8 letras' => ['Hoteles Mediterráneo', 'Mantenimiento web', 'HOTELES-MANTENIM'],
    'eñes y cedillas' => ['Peñíscola Açaí', 'Diseño', 'PENISCOL-DISENO'],
    'sin cliente' => [null, 'Formación interna', 'FORMACIO'],
    'palabras repetidas' => ['Acme', 'Acme', 'ACME'],
    'números' => ['Bodegas 1920', 'Campaña 2026', 'BODEGAS-CAMPANA'],
]);

test('sin nada aprovechable, propone PROYECTO', function () {
    expect(app(ProjectCodeSuggester::class)->suggest(null, 'de la'))->toBe('PROYECTO');
});

test('normaliza lo que escribe el usuario', function () {
    expect(ProjectCodeSuggester::normalize(' acme web '))->toBe('ACME-WEB')
        ->and(ProjectCodeSuggester::normalize('diseño'))->toBe('DISENO');
});

test('si el código existe, añade un sufijo libre sin pasar de 20 caracteres', function () {
    $codes = app(ProjectCodeSuggester::class);

    Project::factory()->create(['code' => 'ACME-WEB']);
    Project::factory()->create(['code' => 'ACME-WEB-2']);

    expect($codes->nextFree('ACME-WEB'))->toBe('ACME-WEB-3')
        ->and($codes->nextFree('LIBRE'))->toBe('LIBRE')
        ->and($codes->nextFree('ACME-WEB', ignoreProjectId: Project::query()->where('code', 'ACME-WEB')->value('id')))->toBe('ACME-WEB');

    Project::factory()->create(['code' => 'ABCDEFGHIJKLMNOPQRST']);

    expect(mb_strlen($codes->nextFree('ABCDEFGHIJKLMNOPQRST')))->toBeLessThanOrEqual(20)
        ->and($codes->nextFree('ABCDEFGHIJKLMNOPQRST'))->toBe('ABCDEFGHIJKLMNOP-2');
});
