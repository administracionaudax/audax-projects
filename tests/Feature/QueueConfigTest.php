<?php

use App\Domain\Weeklies\Ai\AiQueue;
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

/*
| Memoria de las colas (D-141): los supervisores de Horizon, con su `memory` × `maxProcesses`, el
| maestro de Horizon, el transcriptor y Reverb caben en system-audax.slice (deploy/systemd), y los
| workers caben además en el MemoryLimit de audax-horizon.service.
*/

/** MemoryLimit (o MemoryMax) de una unidad de deploy/systemd, en MiB. */
function systemdMemoryMib(string $unit): int
{
    $contents = (string) file_get_contents(base_path("deploy/systemd/{$unit}"));

    expect(preg_match('/^Memory(?:Max|Limit)=(\d+)([MG])$/m', $contents, $match))->toBe(1, "{$unit} sin límite de memoria");

    return (int) $match[1] * ($match[2] === 'G' ? 1024 : 1);
}

/**
 * Supervisores efectivos de cada entorno: los defaults con lo que cambie el entorno, como hace
 * Horizon (ProvisioningPlan).
 *
 * @return array<string, array<string, array<string, mixed>>>
 */
function horizonPlans(): array
{
    $defaults = config('horizon.defaults');

    return collect(config('horizon.environments'))
        ->map(fn (array $supervisors) => array_replace_recursive($defaults, $supervisors))
        ->all();
}

test('la cola mail tiene su propio supervisor de un proceso con 256 MB y default sigue como estaba', function () {
    foreach (horizonPlans() as $environment => $supervisors) {
        expect($supervisors['supervisor-1']['queue'])->toBe(['default'], $environment)
            ->and($supervisors['supervisor-1']['memory'])->toBe(128)
            ->and($supervisors['supervisor-1']['maxProcesses'])->toBe(2)
            ->and($supervisors['supervisor-mail']['queue'])->toBe(['mail'])
            ->and($supervisors['supervisor-mail']['memory'])->toBe(256)
            ->and($supervisors['supervisor-mail']['maxProcesses'])->toBe(1);

        // La cola `ai` de la Weekly (Fase 10, D-146): un proceso de 128 MB y 600 s por Job.
        // D-222: con prioridad, primero la cola del informe y el audio y después la del resto.
        expect($supervisors['supervisor-ai']['queue'])->toBe([AiQueue::HIGH, AiQueue::NAME], $environment)
            ->and($supervisors['supervisor-ai']['balance'])->toBeFalse()
            ->and($supervisors['supervisor-ai']['memory'])->toBe(128)
            ->and($supervisors['supervisor-ai']['maxProcesses'])->toBe(1)
            ->and($supervisors['supervisor-ai']['timeout'])->toBe(AiQueue::TIMEOUT);

        // Cada cola que se usa la atiende exactamente un supervisor (transcriptions va aparte).
        $queues = collect($supervisors)->flatMap(fn (array $supervisor) => (array) $supervisor['queue'])->sort()->values()->all();
        expect($queues)->toBe(['ai', 'ai-high', 'default', 'mail']);
    }

    expect((int) config('queue.connections.redis.retry_after'))->toBeGreaterThan((int) config('horizon.defaults.supervisor-mail.timeout'))
        ->and((int) config('horizon.defaults.supervisor-mail.timeout'))->toBeGreaterThanOrEqual((int) (new ReflectionClass(SendReportDelivery::class))->getProperty('timeout')->getDefaultValue())
        ->and((int) config('queue.connections.redis.retry_after'))->toBeGreaterThan(AiQueue::TIMEOUT);
});

test('la memoria de las colas, el transcriptor y Reverb cabe en system-audax.slice', function () {
    $slice = systemdMemoryMib('system-audax.slice');
    $horizonUnit = systemdMemoryMib('audax-horizon.service');
    $others = systemdMemoryMib('audax-transcriber.service') + systemdMemoryMib('audax-reverb.service');
    $master = (int) config('horizon.memory_limit');

    expect($slice)->toBe(1280);

    foreach (horizonPlans() as $environment => $supervisors) {
        $workers = collect($supervisors)->sum(fn (array $supervisor) => (int) $supervisor['memory'] * (int) $supervisor['maxProcesses']);

        // 2 × 128 (default) + 256 (mail) + 128 (ai, Fase 10).
        expect($workers)->toBe(640, $environment)
            ->and($workers)->toBeLessThanOrEqual($horizonUnit)
            ->and($workers + $master + $others)->toBeLessThanOrEqual($slice);
    }
});

test('el envío de un informe sube el memory_limit de la CLI hasta el del supervisor de mail', function () {
    // Sin ini_set: en la suite en paralelo el proceso ya puede usar más de 128 MB.
    expect(SendReportDelivery::raisedMemoryLimit('128M'))->toBe('256M')
        ->and(SendReportDelivery::raisedMemoryLimit('134217728'))->toBe('256M')
        ->and(SendReportDelivery::raisedMemoryLimit('256M'))->toBeNull()
        // Nunca lo baja.
        ->and(SendReportDelivery::raisedMemoryLimit('512M'))->toBeNull()
        ->and(SendReportDelivery::raisedMemoryLimit('1G'))->toBeNull()
        // Sin límite, se queda sin límite.
        ->and(SendReportDelivery::raisedMemoryLimit('-1'))->toBeNull();
});
