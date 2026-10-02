<?php

namespace App\Domain\Reports;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Caché de los informes (D-046): por informe, persona que mira, su alcance (roles, departamentos que
 * dirige y proyectos que gestiona, SEC-03), permiso económico y filtros, con una versión global que
 * se incrementa al escribir cualquier dato que afecte a las métricas (ver ReportsServiceProvider).
 * Así se invalida al momento sin etiquetas. Caducidad de seguridad: 10 minutos.
 *
 * La versión sube DESPUÉS del commit de la transacción en curso (bumpAfterCommit, PERF-01): si
 * subiera antes, una petición podría guardar con la versión nueva lo que aún leía de antes. Y sube
 * con un incremento atómico (Cache::increment), no leyendo y escribiendo.
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
            $scope->viewer->id, $scope->canSeeFinancials() ? 'f' : 'n', self::viewerScope($scope->viewer),
            $scope->filters->cacheKey(),
        ]);

        return Cache::remember($key, self::TTL_SECONDS, $callback);
    }

    /**
     * Huella del alcance de quien mira (SEC-03): sus roles, los departamentos que dirige y los
     * proyectos que gestiona (memorizados en la petición: sin consultas repetidas). Si cambia (p. ej.
     * deja de dirigir un departamento), la clave cambia aunque nada haya invalidado la caché. Un
     * admin lo ve todo: su alcance son solo sus roles.
     */
    public static function viewerScope(User $viewer): string
    {
        $roles = $viewer->getRoleNames()->sort()->values()->all();

        return substr(md5((string) json_encode($viewer->isAdmin()
            ? [$roles]
            : [$roles, $viewer->managedDepartmentIds(), $viewer->managedProjectIds()])), 0, 12);
    }

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /**
     * Nueva versión, ya: incremento atómico (con valor inicial si aún no existe).
     */
    public static function bump(): void
    {
        Cache::add(self::VERSION_KEY, 1);
        Cache::increment(self::VERSION_KEY);
    }

    /**
     * Nueva versión cuando se confirme la transacción en curso (o ya, si no hay ninguna); si se
     * deshace, no cambia.
     */
    public static function bumpAfterCommit(): void
    {
        DB::afterCommit(static fn () => self::bump());
    }
}
