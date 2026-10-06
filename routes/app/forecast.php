<?php

use App\Http\Controllers\Forecast\AllocationController;
use App\Http\Controllers\Forecast\ForecastBoardController;
use App\Http\Controllers\Forecast\ForecastLinkController;
use App\Http\Controllers\Forecast\ForecastProjectController;
use App\Http\Controllers\Forecast\ProjectPlanningController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Previsión (docs/PLAN-CARGAS.md, Nivel 2; D-280 a D-289): /prevision (la carga del equipo por
| departamento y persona), /prevision/mi-carga (JSON: la mía, P8), /prevision/proyectos (los
| previstos y su ficha), las asignaciones y la pestaña Planificación de un proyecto real
| (/proyectos/{project}/planificacion). Nombres forecast.* y projects.planning / projects.allocations.*.
|--------------------------------------------------------------------------
| Módulo propio `forecast` (apagado por defecto; apagado, 404 salvo a los admins en modo de prueba,
| D-239). Los permisos van en las gates view-forecast y use-forecast y en las políticas
| (ForecastProjectPolicy, AllocationPolicy, ProjectPolicy::viewPlanning y manageAllocations). Ninguna
| ruta está en config/collaborators.php: un colaborador externo nunca entra. Se carga desde
| routes/web.php dentro del grupo interno.
*/

Route::middleware('module:forecast')->group(function () {
    Route::get('prevision', [ForecastBoardController::class, 'index'])->name('forecast.index');
    Route::get('prevision/mi-carga', [ForecastBoardController::class, 'mine'])
        ->middleware('throttle:60,1,forecast.mine')
        ->name('forecast.mine');

    Route::get('prevision/disponibilidad', [ForecastBoardController::class, 'availability'])
        ->middleware('throttle:120,1,forecast.availability')
        ->name('forecast.availability');

    Route::get('prevision/proyectos', [ForecastProjectController::class, 'index'])->name('forecast.projects.index');
    Route::post('prevision/proyectos', [ForecastProjectController::class, 'store'])
        ->middleware('throttle:60,1,forecast.projects.store')
        ->name('forecast.projects.store');

    Route::prefix('prevision/proyectos/{forecast}')->whereNumber('forecast')->group(function (): void {
        Route::get('/', [ForecastProjectController::class, 'show'])->name('forecast.projects.show');
        Route::put('/', [ForecastProjectController::class, 'update'])->middleware('throttle:120,1,forecast.projects.update')->name('forecast.projects.update');
        Route::delete('/', [ForecastProjectController::class, 'destroy'])->middleware('throttle:60,1,forecast.projects.destroy')->name('forecast.projects.destroy');
        Route::post('confirmar', [ForecastProjectController::class, 'confirm'])->middleware('throttle:60,1,forecast.projects.confirm')->name('forecast.projects.confirm');
        Route::post('perdido', [ForecastProjectController::class, 'lose'])->middleware('throttle:60,1,forecast.projects.lose')->name('forecast.projects.lose');
        Route::post('reabrir', [ForecastProjectController::class, 'reopen'])->middleware('throttle:60,1,forecast.projects.reopen')->name('forecast.projects.reopen');

        Route::post('vincular', [ForecastLinkController::class, 'link'])->middleware('throttle:30,1,forecast.projects.link')->name('forecast.projects.link');
        Route::post('crear-proyecto', [ForecastLinkController::class, 'createProject'])->middleware('throttle:30,1,forecast.projects.create-project')->name('forecast.projects.create-project');
        Route::delete('vinculo', [ForecastLinkController::class, 'unlink'])->middleware('throttle:30,1,forecast.projects.unlink')->name('forecast.projects.unlink');

        Route::post('asignaciones', [AllocationController::class, 'storeForecast'])->middleware('throttle:240,1,forecast.allocations.store')->name('forecast.allocations.store');
    });

    // Una asignación, de un previsto o de un proyecto real (AllocationPolicy mira su contenedor).
    Route::prefix('prevision/asignaciones/{allocation}')->whereNumber('allocation')->group(function (): void {
        Route::put('/', [AllocationController::class, 'update'])->middleware('throttle:240,1,forecast.allocations.update')->name('forecast.allocations.update');
        Route::delete('/', [AllocationController::class, 'destroy'])->middleware('throttle:240,1,forecast.allocations.destroy')->name('forecast.allocations.destroy');
        Route::post('asignar', [AllocationController::class, 'assign'])->middleware('throttle:240,1,forecast.allocations.assign')->name('forecast.allocations.assign');
    });

    // Pestaña Planificación del proyecto real (quien lo gestiona, D-022).
    Route::get('proyectos/{project}/planificacion', ProjectPlanningController::class)->whereNumber('project')->name('projects.planning');
    Route::post('proyectos/{project}/asignaciones', [AllocationController::class, 'storeProject'])
        ->whereNumber('project')
        ->middleware('throttle:240,1,projects.allocations.store')
        ->name('projects.allocations.store');
});
