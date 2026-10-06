<?php

use App\Http\Controllers\Recurring\RecurringOverviewController;
use App\Http\Controllers\Recurring\RecurringRuleController;
use App\Http\Controllers\Templates\ProjectTemplateController;
use App\Http\Controllers\Templates\TemplateController;
use App\Http\Controllers\Templates\TemplateTransferController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Plantillas de proyecto y tareas recurrentes (Agente G3, Fase 4). Nombres templates.* y
| recurring.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'] (un cliente
| nunca llega). URLs en español y nombres de ruta en inglés (D-016). Cada controlador comprueba
| su Policy (ProjectTemplatePolicy, RecurringTaskRulePolicy):
| - /admin/plantillas y /admin/tareas-recurrentes: solo admin (D-058, D-059),
| - aplicar y guardar plantilla y las reglas de un proyecto: quien lo gestiona, desde Ajustes.
| El alta de proyecto «desde plantilla» va por projects.store (StoreProjectRequest).
*/

Route::prefix('admin')->group(function (): void {
    Route::get('plantillas', [TemplateController::class, 'index'])->name('templates.index');
    Route::get('plantillas/nueva', [TemplateController::class, 'create'])->name('templates.create');
    Route::post('plantillas', [TemplateController::class, 'store'])
        ->middleware('throttle:60,1,templates.store')
        ->name('templates.store');
    Route::post('plantillas/importar', [TemplateTransferController::class, 'import'])
        ->middleware('throttle:20,1,templates.import')
        ->name('templates.import');

    Route::prefix('plantillas/{template}')->whereNumber('template')->group(function (): void {
        Route::get('editar', [TemplateController::class, 'edit'])->name('templates.edit');
        Route::put('/', [TemplateController::class, 'update'])
            ->middleware('throttle:60,1,templates.update')
            ->name('templates.update');
        Route::put('estado', [TemplateController::class, 'status'])->name('templates.status');
        Route::delete('/', [TemplateController::class, 'destroy'])->name('templates.destroy');
        Route::post('restaurar', [TemplateController::class, 'restore'])->name('templates.restore');
        Route::get('exportar', [TemplateTransferController::class, 'export'])->name('templates.export');
    });

    Route::get('tareas-recurrentes', RecurringOverviewController::class)->name('recurring.index');
});

Route::prefix('proyectos/{project}')->whereNumber('project')->group(function (): void {
    Route::post('plantilla/aplicar', [ProjectTemplateController::class, 'apply'])
        ->middleware('throttle:20,1,templates.apply')
        ->name('templates.apply');
    Route::post('plantilla/guardar', [ProjectTemplateController::class, 'capture'])
        ->middleware('throttle:20,1,templates.capture')
        ->name('templates.capture');

    Route::post('tareas-recurrentes', [RecurringRuleController::class, 'store'])->name('recurring.store');

    // La regla tiene que ser del proyecto de la URL (lo comprueba el controlador: 404 si no).
    Route::put('tareas-recurrentes/{rule}', [RecurringRuleController::class, 'update'])->whereNumber('rule')->name('recurring.update');
    Route::put('tareas-recurrentes/{rule}/estado', [RecurringRuleController::class, 'status'])->whereNumber('rule')->name('recurring.status');
    Route::delete('tareas-recurrentes/{rule}', [RecurringRuleController::class, 'destroy'])->whereNumber('rule')->name('recurring.destroy');
});
