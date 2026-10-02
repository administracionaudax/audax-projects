<?php

/*
| Búsqueda global (Ctrl/Cmd + K) de las páginas de la Fase 7: «Auditoría» y «Privacidad
| (administración)» solo para el admin; «Privacidad» y «Mis datos» para toda la plantilla.
*/

beforeEach(function () {
    $this->titles = fn (string $role, string $query): array => array_column(
        $this->actingAs(userWithRole($role))->getJson('/buscar?q='.urlencode($query))->assertOk()->json('results'),
        'title',
    );
});

test('el admin encuentra la auditoría y la privacidad de la administración', function () {
    expect(($this->titles)('admin', 'auditoria'))->toContain('Auditoría')
        ->and(($this->titles)('admin', 'privacidad'))->toContain('Privacidad (administración)')
        ->and(($this->titles)('admin', 'privacidad'))->toContain('Privacidad')
        ->and(($this->titles)('admin', 'retencion'))->toContain('Privacidad (administración)');
});

test('el resto de la plantilla solo encuentra Privacidad y Mis datos', function (string $role) {
    expect(($this->titles)($role, 'auditoria'))->not->toContain('Auditoría')
        ->and(($this->titles)($role, 'privacidad'))->toContain('Privacidad')
        ->and(($this->titles)($role, 'privacidad'))->not->toContain('Privacidad (administración)')
        ->and(($this->titles)($role, 'mis datos'))->toContain('Mis datos')
        ->and(($this->titles)($role, 'exportar'))->toContain('Mis datos');
})->with(['department_manager', 'employee']);

test('cada página lleva a su URL', function () {
    $results = collect($this->actingAs(userWithRole('admin'))->getJson('/buscar?q=privacidad')->json('results'))->keyBy('id');

    expect($results['privacy.show']['url'])->toBe('/privacidad')
        ->and($results['admin.privacy.edit']['url'])->toBe('/admin/privacidad');

    $myData = collect($this->actingAs(userWithRole('employee'))->getJson('/buscar?q=mis+datos')->json('results'))->keyBy('id');

    expect($myData['privacy.exports.index']['url'])->toBe('/ajustes/mis-datos');
});
