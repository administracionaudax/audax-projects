<?php

use App\Http\Controllers\Schedule\DependencyController;
use App\Http\Controllers\Schedule\RescheduleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Planificación: contrato de la Fase 4 (común al Gantt, al calendario y al panel de tarea)
|--------------------------------------------------------------------------
| Dependencias fin-inicio (D-056) y reprogramar una tarea con propuesta de desplazar sus sucesoras
| (D-057). Nombres schedule.*. Se carga desde routes/web.php dentro del grupo interno.
*/

Route::post('proyectos/{project}/dependencias', [DependencyController::class, 'store'])
    ->middleware('throttle:120,1,schedule.dependencies.store')
    ->name('schedule.dependencies.store');
Route::delete('dependencias/{dependency}', [DependencyController::class, 'destroy'])->name('schedule.dependencies.destroy');

Route::post('tareas/{task}/reprogramar/propuesta', [RescheduleController::class, 'preview'])
    ->middleware('throttle:240,1,schedule.reschedule.preview')
    ->name('schedule.reschedule.preview');
Route::post('tareas/{task}/reprogramar', [RescheduleController::class, 'store'])
    ->middleware('throttle:120,1,schedule.reschedule.store')
    ->name('schedule.reschedule.store');
