<?php

use App\Support\JsonArrayStream;

function jsonStreamFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'json-stream-');
    file_put_contents($path, $contents);

    return $path;
}

test('lee los objetos de un array uno a uno, también con trozos diminutos', function (int $chunk) {
    $items = [
        ['id' => 1, 'name' => 'Llaves {} y corchetes [] en "texto"', 'nested' => ['a' => [1, 2, ['b' => '}']]]],
        ['id' => 2, 'name' => 'Barra \\ final \\', 'emoji' => '🍎 ñ', 'empty' => []],
        ['id' => 3, 'name' => 'Escapes \\"] y \\\\"', 'list' => [['x' => 1], ['y' => null]]],
    ];
    $path = jsonStreamFile("  \n".json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");

    expect(iterator_to_array(JsonArrayStream::objects($path, $chunk)))->toBe($items);

    unlink($path);
})->with([1, 2, 7, 1 << 20]);

test('un array vacío no da elementos', function () {
    $path = jsonStreamFile('[]');

    expect(iterator_to_array(JsonArrayStream::objects($path)))->toBe([]);

    unlink($path);
});

test('rechaza lo que no es un array de objetos', function (string $contents) {
    $path = jsonStreamFile($contents);

    try {
        expect(fn () => iterator_to_array(JsonArrayStream::objects($path)))->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
})->with(['{"a": 1}', '["texto"]', '[[1, 2]]']);

test('falla si el fichero no existe', function () {
    expect(fn () => iterator_to_array(JsonArrayStream::objects('/no/existe.json')))->toThrow(RuntimeException::class);
});
