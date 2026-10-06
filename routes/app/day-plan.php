<?php

use App\Http\Controllers\DayPlan\DayPlanCommentController;
use App\Http\Controllers\DayPlan\DayPlanItemController;
use App\Http\Controllers\DayPlan\DayPlanTimeController;
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

    // Escribir el plan (D-250): cada acción con su propio contador (como el resto de la app).
    Route::post('dia/lineas', [DayPlanItemController::class, 'store'])->middleware('throttle:240,1,day-plan.items.store')->name('day-plan.items.store');
    Route::patch('dia/lineas/{item}', [DayPlanItemController::class, 'update'])->middleware('throttle:240,1,day-plan.items.update')->name('day-plan.items.update');
    Route::delete('dia/lineas/{item}', [DayPlanItemController::class, 'destroy'])->middleware('throttle:240,1,day-plan.items.destroy')->name('day-plan.items.destroy');
    Route::post('dia/lineas/{item}/estado', [DayPlanItemController::class, 'status'])->middleware('throttle:240,1,day-plan.items.status')->name('day-plan.items.status');
    Route::post('dia/lineas/{item}/pasar', [DayPlanItemController::class, 'carry'])->middleware('throttle:240,1,day-plan.items.carry')->name('day-plan.items.carry');
    Route::post('dia/pendientes/pasar', [DayPlanItemController::class, 'carryPending'])->middleware('throttle:240,1,day-plan.pending.carry')->name('day-plan.pending.carry');
    Route::post('dia/pendientes/no-hechas', [DayPlanItemController::class, 'markPendingNotDone'])->middleware('throttle:240,1,day-plan.pending.not-done')->name('day-plan.pending.not-done');
    Route::put('dia/orden', [DayPlanItemController::class, 'reorder'])->middleware('throttle:240,1,day-plan.reorder')->name('day-plan.reorder');
    Route::put('dia/nota', [DayPlanItemController::class, 'note'])->middleware('throttle:240,1,day-plan.note')->name('day-plan.note');
    Route::post('dia/desde-tareas', [DayPlanItemController::class, 'fromTasks'])->middleware('throttle:240,1,day-plan.from-tasks')->name('day-plan.from-tasks');
    Route::post('dia/lineas/{item}/comentarios', [DayPlanCommentController::class, 'store'])->middleware('throttle:240,1,day-plan.items.comments.store')->name('day-plan.items.comments.store');
    Route::delete('dia/comentarios/{comment}', [DayPlanCommentController::class, 'destroy'])->middleware('throttle:240,1,day-plan.comments.destroy')->name('day-plan.comments.destroy');
    Route::post('dia/lineas/{item}/temporizador', [DayPlanTimeController::class, 'start'])->middleware('throttle:240,1,day-plan.items.timer')->name('day-plan.items.timer');
    Route::post('dia/lineas/{item}/imputar-previsto', [DayPlanTimeController::class, 'logPlanned'])->middleware('throttle:240,1,day-plan.items.log-planned')->name('day-plan.items.log-planned');
    Route::post('dia/imputar-previsto', [DayPlanTimeController::class, 'logPlannedDay'])->middleware('throttle:240,1,day-plan.log-planned')->name('day-plan.log-planned');
    Route::post('dia/lineas/{item}/vincular', [DayPlanTimeController::class, 'link'])->middleware('throttle:240,1,day-plan.items.link')->name('day-plan.items.link');

    Route::get('dia/lineas/{item}/entradas', [DayPlanTimeController::class, 'entries'])
        ->middleware('throttle:120,1,day-plan.entries')
        ->name('day-plan.items.entries');

    Route::post('dia/equipo/{person}/recordar', [TeamDayController::class, 'remind'])
        ->middleware('throttle:30,1,day-plan.remind')
        ->name('day-plan.team.remind');

    Route::get('dia/tareas-sugeridas', [DayPlanItemController::class, 'suggestions'])
        ->middleware('throttle:120,1,day-plan.suggestions')
        ->name('day-plan.suggestions');
});
