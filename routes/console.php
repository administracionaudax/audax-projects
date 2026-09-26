<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tareas programadas (audax-scheduler.service ejecuta schedule:work, D-018)
|--------------------------------------------------------------------------
| Las horas de las tareas diarias son de Madrid, no UTC.
*/

// Temporizadores de más de timer_warning_hours: aviso en la app, una vez por temporizador (SPEC §7).
Schedule::command('timers:warn')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Tareas que vencen mañana o vencidas (SPEC §13). El comando lo crea el área de Tareas: mientras
// no exista, la tarea se omite en lugar de fallar cada día.
Schedule::command('app:notify-due-tasks')
    ->dailyAt('08:00')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn (): bool => array_key_exists('app:notify-due-tasks', Artisan::all()));

// Tareas recurrentes (Fase 4, D-056): cada día a las 06:00 de Madrid.
Schedule::command('tasks:generate-recurring')
    ->dailyAt('06:00')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping()
    ->onOneServer();
