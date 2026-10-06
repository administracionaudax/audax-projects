<?php

use App\Domain\Import\WeeklySync\Dump\RestWeeklySyncSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Origen de WeeklySync por la API REST con la clave secreta (D-237): pagina por id, distingue la
// tabla que no existe y nunca deja la clave en los errores.

it('lee todas las filas por páginas, ordenadas por id, con la clave en las cabeceras', function () {
    $total = RestWeeklySyncSource::PAGE + 3;
    Http::fake(function (Request $request) use ($total) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        expect($request->header('apikey'))->toBe(['sb_secret_prueba'])
            ->and($request->header('Authorization'))->toBe(['Bearer sb_secret_prueba'])
            ->and($q['order'] ?? null)->toBe('id.asc');
        $offset = (int) ($q['offset'] ?? 0);
        $rows = [];
        for ($i = $offset; $i < min($offset + (int) $q['limit'], $total); $i++) {
            $rows[] = ['id' => $i + 1, 'datos' => ['a' => $i]];
        }

        return Http::response($rows);
    });

    $source = new RestWeeklySyncSource('https://demo.supabase.co/', 'sb_secret_prueba');
    $rows = iterator_to_array($source->rows('clients'), false);

    expect($rows)->toHaveCount($total)
        ->and($rows[0])->toBe(['id' => 1, 'datos' => ['a' => 0]])
        ->and(end($rows)['id'])->toBe($total);
    Http::assertSentCount(2);
});

it('sabe si una tabla existe', function () {
    Http::fake([
        'demo.supabase.co/rest/v1/clients*' => Http::response([]),
        'demo.supabase.co/rest/v1/no_existe*' => Http::response(['code' => 'PGRST205', 'message' => 'Could not find the table'], 404),
    ]);

    $source = new RestWeeklySyncSource('https://demo.supabase.co', 'sb_secret_prueba');

    expect($source->has('clients'))->toBeTrue()
        ->and($source->has('no_existe'))->toBeFalse();
});

it('un error no enseña la clave y rechaza nombres de tabla raros', function () {
    Http::fake(['*' => Http::response('Invalid API key sb_secret_prueba', 401)]);
    $source = new RestWeeklySyncSource('https://demo.supabase.co', 'sb_secret_prueba');

    try {
        $source->has('clients');
        $this->fail('Debía fallar');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('sb_secret_prueba')->toContain('401');
    }

    expect(fn () => iterator_to_array($source->rows('clients; drop table x')))->toThrow(RuntimeException::class);
});

it('una tabla sin columna id se lee sin orden si cabe en una página; si no, avisa', function () {
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        if (isset($q['order'])) {
            return Http::response(['code' => '42703', 'message' => 'column t.id does not exist'], 400);
        }

        return str_contains($request->url(), '/grande')
            ? Http::response(array_fill(0, RestWeeklySyncSource::PAGE, ['a' => 1]))
            : Http::response([['client_id' => 1, 'user_id' => 2]]);
    });

    $source = new RestWeeklySyncSource('https://demo.supabase.co', 'sb_secret_prueba');

    expect(iterator_to_array($source->rows('client_team_members'), false))->toBe([['client_id' => 1, 'user_id' => 2]])
        ->and(fn () => iterator_to_array($source->rows('grande'), false))->toThrow(RuntimeException::class, 'no tiene columna id');
});
