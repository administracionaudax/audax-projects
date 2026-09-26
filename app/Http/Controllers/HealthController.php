<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * GET /health → {"status":"ok|degraded","checks":{...},"redis_port_ok":bool} con 200 o 503.
 *
 * Sin versiones, rutas, mensajes de error ni secretos: los detalles de un fallo van al log.
 * Cada comprobación vale "ok", "fail" o "skipped" (Redis no se usa en local ni en los tests).
 */
class HealthController extends Controller
{
    /**
     * Espacio libre mínimo en el disco de storage/ para considerarlo sano.
     */
    public const float MIN_FREE_DISK_PERCENT = 10.0;

    /**
     * Puerto del Valkey propio. El 6379 es el Redis compartido del servidor (docs/SERVIDOR.md §5).
     */
    public const int EXPECTED_REDIS_PORT = 16379;

    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->run('database', fn (): bool => DB::connection()->select('select 1 as ok') !== []),
            'redis' => $this->usesRedis()
                ? $this->run('redis', fn (): bool => (bool) Redis::connection()->ping())
                : 'skipped',
            'queue' => $this->run('queue', function (): bool {
                Queue::connection()->size();

                return true;
            }),
            'disk' => $this->run('disk', fn (): bool => $this->freeDiskPercent() >= self::MIN_FREE_DISK_PERCENT),
        ];

        $redisPortOk = $this->redisPortOk();

        $healthy = ! in_array('fail', $checks, true) && $redisPortOk;

        return response()
            ->json([
                'status' => $healthy ? 'ok' : 'degraded',
                'checks' => $checks,
                'redis_port_ok' => $redisPortOk,
            ], $healthy ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @param  callable(): bool  $check
     */
    private function run(string $name, callable $check): string
    {
        try {
            return $check() ? 'ok' : 'fail';
        } catch (Throwable $e) {
            Log::warning("health: la comprobación [{$name}] ha fallado", ['exception' => $e::class, 'message' => $e->getMessage()]);

            return 'fail';
        }
    }

    private function usesRedis(): bool
    {
        return in_array('redis', [
            config('cache.default'),
            config('queue.default'),
            config('session.driver'),
        ], true);
    }

    /**
     * Fuera de local y testing, todas las conexiones de Redis deben apuntar al Valkey propio.
     */
    private function redisPortOk(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        foreach (['default', 'cache'] as $connection) {
            if ((int) config("database.redis.{$connection}.port") !== self::EXPECTED_REDIS_PORT) {
                return false;
            }
        }

        return true;
    }

    private function freeDiskPercent(): float
    {
        $path = storage_path();
        $total = disk_total_space($path);
        $free = disk_free_space($path);

        if ($total === false || $free === false || $total <= 0) {
            return 0.0;
        }

        return $free / $total * 100;
    }
}
