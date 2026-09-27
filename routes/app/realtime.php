<?php

use App\Http\Controllers\Realtime\ConversationLinkController;
use App\Http\Controllers\Realtime\ConversationViewingController;
use App\Http\Controllers\Realtime\PresenceController;
use App\Http\Controllers\Realtime\ReadReceiptController;
use App\Http\Controllers\Realtime\UnreadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tiempo real y avisos (Agente C2, Fase 6): suscripciones Web Push, preferencias del navegador y lo que haga falta para Echo (presencia, escribiendo, leídos). Nombres realtime.* y push.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa']: un cliente
| nunca llega aquí (ClientIsolationTest). Los canales de Echo están en routes/channels.php.
| Todo funciona también sin Reverb: son las consultas periódicas de los hooks de tiempo real.
| Cada límite lleva su prefijo (tercer parámetro de throttle): sin él, todas las rutas con
| throttle:N,M comparten el mismo contador por usuario y las consultas periódicas agotarían el de
| otras rutas (por ejemplo, el de cambiar la contraseña).
*/

// Contadores de no leídos (useUnreadCounter) y presencia sin tiempo real (usePresence).
Route::get('tiempo-real/no-leidos', UnreadController::class)
    ->middleware('throttle:120,1,realtime-unread')
    ->name('realtime.unread');
Route::post('tiempo-real/presencia', PresenceController::class)
    ->middleware('throttle:60,1,realtime-presence')
    ->name('realtime.presence');

// Conversación abierta (no avisar a quien ya la lee), «leído por» y enlace estable de los avisos.
Route::post('tiempo-real/conversaciones/{conversation}/viendo', [ConversationViewingController::class, 'store'])
    ->whereNumber('conversation')
    ->middleware('throttle:120,1,realtime-viewing')
    ->name('realtime.conversations.viewing');
Route::delete('tiempo-real/conversaciones/{conversation}/viendo', [ConversationViewingController::class, 'destroy'])
    ->whereNumber('conversation')
    ->middleware('throttle:120,1,realtime-viewing')
    ->name('realtime.conversations.leave');
Route::get('tiempo-real/conversaciones/{conversation}/leidos', ReadReceiptController::class)
    ->whereNumber('conversation')
    ->middleware('throttle:120,1,realtime-reads')
    ->name('realtime.conversations.reads');
Route::get('tiempo-real/conversaciones/{conversation}/abrir', ConversationLinkController::class)
    ->whereNumber('conversation')
    ->name('realtime.conversations.open');

