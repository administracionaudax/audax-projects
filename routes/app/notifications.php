<?php

use App\Http\Controllers\Notifications\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notificaciones (cierre de la Fase 1): /notificaciones. Nombres notifications.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| URLs en español y nombres de ruta en inglés (D-016).
*/

Route::get('notificaciones', [NotificationController::class, 'index'])->name('notifications.index');
Route::get('notificaciones/recientes', [NotificationController::class, 'recent'])
    ->middleware('throttle:120,1,notifications.recent')
    ->name('notifications.recent');
Route::post('notificaciones/leidas', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
Route::post('notificaciones/{notification}/abrir', [NotificationController::class, 'open'])
    ->whereUuid('notification')
    ->name('notifications.open');
Route::post('notificaciones/{notification}/leida', [NotificationController::class, 'markRead'])
    ->whereUuid('notification')
    ->name('notifications.read');
