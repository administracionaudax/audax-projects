<?php

use App\Http\Controllers\Gantt\GanttController;
use App\Http\Controllers\Gantt\ProjectGanttController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Gantt (Agente G1, Fase 4): pestaña Gantt del proyecto y Gantt multiproyecto. Nombres gantt.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| Mover, redimensionar y enlazar usan las rutas comunes schedule.* (routes/app/schedule.php) y
| crear tareas, tasks.store: aquí solo están las páginas.
*/

Route::get('gantt', GanttController::class)->name('gantt.index');
Route::get('proyectos/{project}/gantt', ProjectGanttController::class)
    ->whereNumber('project')
    ->name('gantt.project');
