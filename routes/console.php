<?php

use App\Models\Setting;
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

// Tareas recurrentes (Fase 4, D-059): cada día a las 06:00 de Madrid.
Schedule::command('tasks:generate-recurring')
    ->dailyAt('06:00')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping()
    ->onOneServer();

// Resumen semanal de productividad (D-047): los lunes a las 08:00 de Madrid, sobre la semana
// anterior, si el ajuste weekly_digest_enabled está activado (el comando también lo comprueba).
Schedule::command('reports:weekly-digest')
    ->weeklyOn(1, '08:00')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn (): bool => (bool) Setting::get('weekly_digest_enabled', true));

/*
| Fase 7 (D-073 a D-076). Los comandos los crean los agentes N y A: mientras no existan, cada tarea
| se omite en lugar de fallar.
*/
$exists = fn (string $command): Closure => fn (): bool => array_key_exists($command, Artisan::all());

// Resumen diario por email de lo no leído (D-073), para quien lo haya activado.
Schedule::command('notifications:daily-digest')
    ->dailyAt('08:00')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping()
    ->onOneServer()
    ->when($exists('notifications:daily-digest'));

// Recordatorio de enviar la semana (SPEC §13, D-073): los viernes a las 13:00 de Madrid.
Schedule::command('time:remind-week')
    ->weeklyOn(5, '13:00')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn (): bool => $exists('time:remind-week')() && (bool) Setting::get('week_reminder_enabled', true));

// Plazos de retención (D-075): antes de la copia nocturna de las 03:40.
Schedule::command('app:prune-data')
    ->dailyAt('03:10')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping()
    ->onOneServer()
    ->when($exists('app:prune-data'));

// Disco y adjuntos por encima del umbral, y copias atrasadas o fallidas (D-076): aviso al admin.
Schedule::command('app:check-storage')
    ->dailyAt('09:00')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping()
    ->onOneServer()
    ->when($exists('app:check-storage'));
