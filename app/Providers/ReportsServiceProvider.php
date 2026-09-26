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
use Illuminate\Support\ServiceProvider;

/**
 * Informes (Fase 2): invalida la caché de métricas (D-046) al escribir cualquier dato que las
 * afecte. Las actualizaciones masivas que no disparan eventos (aprobación, bloqueo, recálculo de
 * bolsas) llaman a ReportCache::bump() explícitamente.
 */
class ReportsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $bump = static fn () => ReportCache::bump();

        foreach ([TimeEntry::class, Task::class, HourBank::class, Project::class, Client::class, WorkSchedule::class, Setting::class, User::class] as $model) {
            $model::saved($bump);
            $model::deleted($bump);
        }
    }
}
