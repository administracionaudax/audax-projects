<?php

namespace App\Providers;

use App\Domain\Reports\ReportCache;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Informes (Fase 2): invalida la caché de métricas (D-046) al escribir cualquier dato que las
 * afecte. Las actualizaciones masivas que no disparan eventos (aprobación, bloqueo, recálculo de
 * bolsas) llaman a ReportCache::bump() explícitamente.
 */
class ReportsServiceProvider extends ServiceProvider
{
    /**
     * Limitador de las exportaciones ?formato= de los dashboards y del detallado (SEC-02): 30 por
     * minuto y usuario, común a todos los informes. Solo cuenta las peticiones con formato: ver
     * las páginas no gasta el cupo.
     */
    public const string EXPORT_LIMITER = 'report-exports';

    public const int EXPORTS_PER_MINUTE = 30;

    public function boot(): void
    {
        RateLimiter::for(self::EXPORT_LIMITER, fn (Request $request): Limit => $request->query->has('formato')
            ? Limit::perMinute(self::EXPORTS_PER_MINUTE)->by('user:'.($request->user()?->getAuthIdentifier() ?? $request->ip()))
            : Limit::none());

        $bump = static fn () => ReportCache::bump();

        foreach ([TimeEntry::class, Task::class, HourBank::class, Project::class, Client::class, WorkSchedule::class, Setting::class, User::class] as $model) {
            $model::saved($bump);
            $model::deleted($bump);
        }
    }
}
