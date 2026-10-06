<?php

use App\Http\Controllers\Planning\DependencyCandidatesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Calendario de tareas e hitos (Agente G2, Fase 4). Nombres planning.*.
|--------------------------------------------------------------------------
| - La vista Calendario es la tercera vista de la pestaña Tareas (D-061):
|   /proyectos/{project}/tareas?vista=calendario&mes=2026-10 (o &semana=2026-10-05), en
|   ProjectTasksController con App\Domain\Planning\TaskCalendar. Mover fechas va por
|   schedule.reschedule.* (propuesta y confirmación, D-057).
| - Dependencias del panel de la tarea (D-056, D-062): se crean y quitan con schedule.dependencies.*;
|   aquí solo el buscador de tareas candidatas (JSON).
| - Próximos hitos: props del resumen del proyecto y de Inicio (App\Domain\Planning\UpcomingMilestones).
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
*/

Route::get('tareas/{task}/dependencias/candidatas', DependencyCandidatesController::class)
    ->middleware('throttle:120,1,planning.dependencies.candidates')
    ->name('planning.dependencies.candidates');
