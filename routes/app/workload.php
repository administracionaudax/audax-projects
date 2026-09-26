<?php

use App\Http\Controllers\Workload\WorkloadController;
use App\Http\Controllers\Workload\WorkloadTaskController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Carga (Fase 3, W2): /carga (matriz personas × días o semanas; ?celda=persona:fecha abre el panel
| de una celda) y la reasignación rápida de tareas desde el panel y las bandejas. Nombres workload.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| URLs en español y nombres de ruta en inglés (D-016).
*/

Route::get('carga', WorkloadController::class)->name('workload.index');

// Responsable, fechas y estimación (TaskPolicy + alcance de la vista, D-052; siempre con TaskWriter).
Route::patch('carga/tareas/{task}', [WorkloadTaskController::class, 'update'])
    ->middleware('throttle:120,1')
    ->name('workload.tasks.update');
