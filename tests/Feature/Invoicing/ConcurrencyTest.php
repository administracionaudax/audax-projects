<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
| T-NUM con concurrencia de verdad (PLAN-EMISION §4.3 y §8.2; D-419 y D-420): varios procesos PHP
| emiten a la vez contra la misma base (una SQLite en fichero, propia de este test). Salen números
| seguidos, sin huecos ni repetidos, y una sola cadena de registros sin bifurcaciones: la instalación
| bloqueada al empezar la emisión pone las emisiones en fila (en PostgreSQL, el bloqueo de la fila;
| en SQLite, el candado de escritura) y el contador solo sube.
*/

it('varias emisiones a la vez reciben números seguidos y una sola cadena', function () {
    if (! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('Necesita pdo_sqlite para la base en fichero de los procesos hijos.');
    }

    $dir = storage_path('framework/testing/concurrency');
    File::ensureDirectoryExists($dir);
    $database = $dir.'/emision-'.getmypid().'-'.bin2hex(random_bytes(3)).'.sqlite';
    touch($database);

    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'REPORTS_PDF_DRIVER' => 'html',
        'HOLDED_DRIVER' => 'fake',
    ];
    $php = PHP_BINARY;
    $script = base_path('tests/Support/invoicing-concurrency.php');

    try {
        (new Process([$php, base_path('artisan'), 'migrate', '--force'], base_path(), $env, null, 300))->mustRun();
        $seed = (new Process([$php, $script, 'seed', '6'], base_path(), $env, null, 120))->mustRun();
        $drafts = json_decode($seed->getOutput(), true)['drafts'];

        // Los seis a la vez.
        $processes = array_map(fn (int $id): Process => new Process([$php, $script, 'issue', (string) $id], base_path(), $env, null, 120), $drafts);
        foreach ($processes as $process) {
            $process->start();
        }
        $numbers = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $numbers[] = trim($process->getOutput());
        }

        sort($numbers);
        $year = substr(now('Europe/Madrid')->format('Y'), 2);
        expect($numbers)->toBe(array_map(fn (int $n): string => sprintf('PRU%s%04d', $year, $n), range(1, 6)));

        config(['database.connections.concurrency' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true]]);
        $records = DB::connection('concurrency')->table('invoice_records')->orderBy('seq')->get(['seq', 'hash', 'previous_hash', 'is_first']);

        expect($records->pluck('seq')->all())->toBe([1, 2, 3, 4, 5, 6])
            ->and($records->where('is_first', 1)->count())->toBe(1);
        foreach ($records->slice(1)->values() as $index => $record) {
            expect($record->previous_hash)->toBe($records[$index]->hash);
        }

        $verify = (new Process([$php, base_path('artisan'), 'app:billing-verify-chain'], base_path(), $env, null, 120))->run();
        expect($verify)->toBe(0);
    } finally {
        DB::purge('concurrency');
        @unlink($database);
        File::deleteDirectory(storage_path('framework/testing/disks/concurrency'));
    }
});
