<?php

namespace App\Providers;

use App\Domain\Reports\ReportCache;
use App\Events\MembershipsChanged;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\TimeEntryLock;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Informes (Fase 2): invalida la caché de métricas (D-046) al escribir cualquier dato que las
 * afecte, siempre tras el commit (ReportCache::bumpAfterCommit, PERF-01):
 * - al guardar o borrar entradas, tareas, bolsas, proyectos, clientes, jornadas, ajustes, personas,
 *   estados y tipos de tarea, departamentos, semanas de horas, bloqueos y miembros de proyecto,
 * - al cambiar miembros, gestores o responsables (MembershipsChanged, también los pivotes sin
 *   eventos, como department_managers),
 * - y los servicios con actualizaciones masivas sin eventos por fila (bloquear y desbloquear horas,
 *   el catálogo de estados, mover tareas y reordenar catálogos) la piden ellos mismos (INT-03).
 * Además, la clave lleva el alcance de quien mira (ReportCache::viewerScope, SEC-03).
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

        $bump = static fn () => ReportCache::bumpAfterCommit();

        foreach ([TimeEntry::class, Task::class, HourBank::class, Project::class, Client::class, WorkSchedule::class, Setting::class, User::class,
            TaskStatus::class, TaskType::class, Department::class, TimesheetPeriod::class, TimeEntryLock::class, ProjectMember::class] as $model) {
            $model::saved($bump);
            $model::deleted($bump);
        }

        Event::listen(MembershipsChanged::class, $bump);
    }
}
