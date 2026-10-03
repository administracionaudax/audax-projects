<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/*
| Registros estructurados (D-077): el canal daily_json escribe una línea JSON por mensaje, con el
| contexto, para poder filtrarlos en el servidor.
*/

test('el canal daily_json escribe una línea JSON con el mensaje y su contexto', function () {
    $directory = storage_path('framework/testing/logs-'.uniqid());
    config(['logging.channels.daily_json.path' => $directory.'/laravel.json.log']);

    Log::channel('daily_json')->warning('Copia atrasada {horas} h', ['horas' => 40, 'motivo' => 'prueba']);

    $files = File::glob($directory.'/laravel.json*.log');
    expect($files)->toHaveCount(1);

    $line = json_decode(trim((string) file_get_contents($files[0])), true, flags: JSON_THROW_ON_ERROR);

    expect($line['message'])->toBe('Copia atrasada 40 h')
        ->and($line['level_name'])->toBe('WARNING')
        ->and($line['context'])->toMatchArray(['horas' => 40, 'motivo' => 'prueba']);

    File::deleteDirectory($directory);
});
