<?php

use App\Http\Controllers\DayPlan\DayPlanCommentController;
use App\Http\Controllers\DayPlan\DayPlanItemController;
use App\Http\Controllers\DayPlan\MyDayController;
use App\Http\Controllers\DayPlan\TeamDayController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Plan del día (docs/PLAN-CARGAS.md, Nivel 1; D-250 a D-256): /dia (Mi día), /dia/equipo (Equipo
| hoy) y /dia/semana (Semana del equipo). Nombres day-plan.*.
|--------------------------------------------------------------------------
| Módulo propio `day_plan` (activado por defecto; apagado, 404 salvo a los admins en modo de prueba,
| D-239) y la gate use-day-plan: la plantilla interna, nunca un colaborador externo (además, sus
| rutas no están en config/collaborators.php). Cada persona escribe solo su plan (DayPlanItemPolicy).
| Se carga desde routes/web.php dentro del grupo interno.
*/

Route::middleware(['module:day_plan', 'can:use-day-plan'])->group(function () {
    Route::get('dia', MyDayController::class)->name('day-plan.show');
    Route::get('dia/equipo', [TeamDayController::class, 'team'])->name('day-plan.team');
    Route::get('dia/semana', [TeamDayController::class, 'week'])->name('day-plan.week');

    Route::middleware('throttle:240,1,day-plan.write')->group(function () {
        Route::post('dia/lineas', [DayPlanItemController::class, 'store'])->name('day-plan.items.store');
        Route::patch('dia/lineas/{item}', [DayPlanItemController::class, 'update'])->name('day-plan.items.update');
        Route::delete('dia/lineas/{item}', [DayPlanItemController::class, 'destroy'])->name('day-plan.items.destroy');
        Route::post('dia/lineas/{item}/estado', [DayPlanItemController::class, 'status'])->name('day-plan.items.status');
        Route::post('dia/lineas/{item}/pasar', [DayPlanItemController::class, 'carry'])->name('day-plan.items.carry');
        Route::post('dia/pendientes/pasar', [DayPlanItemController::class, 'carryPending'])->name('day-plan.pending.carry');
        Route::post('dia/pendientes/no-hechas', [DayPlanItemController::class, 'markPendingNotDone'])->name('day-plan.pending.not-done');
        Route::put('dia/orden', [DayPlanItemController::class, 'reorder'])->name('day-plan.reorder');
        Route::put('dia/nota', [DayPlanItemController::class, 'note'])->name('day-plan.note');
        Route::post('dia/desde-tareas', [DayPlanItemController::class, 'fromTasks'])->name('day-plan.from-tasks');
        Route::post('dia/lineas/{item}/comentarios', [DayPlanCommentController::class, 'store'])->name('day-plan.items.comments.store');
        Route::delete('dia/comentarios/{comment}', [DayPlanCommentController::class, 'destroy'])->name('day-plan.comments.destroy');
    });

    Route::post('dia/equipo/{person}/recordar', [TeamDayController::class, 'remind'])
        ->middleware('throttle:30,1,day-plan.remind')
        ->name('day-plan.team.remind');

    Route::get('dia/tareas-sugeridas', [DayPlanItemController::class, 'suggestions'])
        ->middleware('throttle:120,1,day-plan.suggestions')
        ->name('day-plan.suggestions');
});
