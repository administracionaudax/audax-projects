<?php

use App\Http\Controllers\Settings\NotificationSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Preferencias de notificación (Agente N, Fase 7, D-073): /ajustes/notificaciones con la matriz
| evento × canal (App\Domain\Notifications\NotificationPreferences::forUser/update), el resumen
| diario y, al integrar la Fase 6, el alta de Web Push de este navegador. Nombres
| notification-settings.*. Solo internos: se carga dentro del grupo auth, active, internal y 2fa.
|--------------------------------------------------------------------------
*/

Route::get('ajustes/notificaciones', [NotificationSettingsController::class, 'edit'])->name('notification-settings.edit');
Route::put('ajustes/notificaciones', [NotificationSettingsController::class, 'update'])
    ->middleware('throttle:30,1,notification-settings.update')
    ->name('notification-settings.update');
