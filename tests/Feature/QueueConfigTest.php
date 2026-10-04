<?php

use App\Jobs\BuildPersonalDataExport;
use App\Jobs\SendReportDelivery;
use App\Jobs\TranscribeAudioMessage;

/*
| La cola espera más que el job más largo antes de darlo por perdido y repetirlo (retry_after): si
| no, la exportación de los datos personales (600 s) podría ejecutarse dos veces a la vez.
*/

test('retry_after de cada conexión de Redis supera el timeout de sus jobs largos', function () {
    $timeout = fn (string $job): int => (int) (new ReflectionClass($job))->getProperty('timeout')->getDefaultValue();

    expect((int) config('queue.connections.redis.retry_after'))->toBeGreaterThan($timeout(BuildPersonalDataExport::class))
        // El envío de un informe por correo (D-141) genera el PDF y el Excel en la cola `mail`.
        ->and((int) config('queue.connections.redis.retry_after'))->toBeGreaterThan($timeout(SendReportDelivery::class))
        ->and((int) config('queue.connections.redis.retry_after'))->toBeGreaterThan((int) config('horizon.defaults.supervisor-1.timeout', 120))
        ->and((int) config('queue.connections.redis-transcriptions.retry_after'))->toBeGreaterThan($timeout(TranscribeAudioMessage::class));
});
