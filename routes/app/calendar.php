<?php

use App\Http\Controllers\Calendar\TeamCalendarController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Calendario del equipo (Fase 9, D-144): /calendario?vista=mes|semana|dia&personas=1&fecha=…
| con los filtros en la URL (App\Domain\Calendar\CalendarFilters) y ?tarea={id} para el panel de
| la tarea. Nombre calendar.index.
|--------------------------------------------------------------------------
| Mover una tarea va por schedule.reschedule.* (D-057) y crearla, por tasks.store, como en el
| calendario del proyecto y el Gantt. Se carga desde routes/web.php dentro del grupo interno.
*/

Route::get('calendario', TeamCalendarController::class)->name('calendar.index');
