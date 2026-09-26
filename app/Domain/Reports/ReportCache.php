<?php

namespace App\Domain\Reports;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caché de los informes (D-046): por informe, persona que mira, permiso económico y filtros, con
 * una versión global que se incrementa al escribir cualquier dato que afecte a las métricas
 * (entradas, tareas, bolsas, proyectos, clientes, jornadas y ajustes; ver ReportsServiceProvider).
 * Así se invalida al momento sin etiquetas. Caducidad de seguridad: 10 minutos.
 */
final class ReportCache
{
    public const string VERSION_KEY = 'reports:version';

    public const int TTL_SECONDS = 600;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function remember(ReportScope $scope, string $name, Closure $callback): mixed
    {
        $key = implode(':', [
            'reports', 'v'.self::version(), $name,
            $scope->viewer->id, $scope->canSeeFinancials() ? 'f' : 'n',
            $scope->filters->cacheKey(),
        ]);

        return Cache::remember($key, self::TTL_SECONDS, $callback);
    }

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public static function bump(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }
}
