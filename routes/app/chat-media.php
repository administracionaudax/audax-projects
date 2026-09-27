<?php

use App\Http\Controllers\Admin\TranscriptionController;
use App\Http\Controllers\Chat\Media\AudioController;
use App\Http\Controllers\Chat\Media\ChatSearchController;
use App\Http\Controllers\Chat\Media\MessageMediaController;
use App\Http\Controllers\Chat\Media\TranscriptionStatusController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Audios, adjuntos y búsqueda del chat (Agente C3, Fase 6): reproducir y descargar audios y adjuntos del chat, transcripciones (ver, copiar, relanzar; /admin/transcripciones) y búsqueda de mensajes, archivos y transcripciones. Nombres chat.media.*, chat.search y admin.transcriptions.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| - POST /chat/{conversación}/multimedia: publicar con adjuntos y/o un audio (JSON, MessageWriter),
| - GET /chat/audios/{adjunto}: el audio con Range, con URL firmada relativa + AttachmentPolicy
|   (las imágenes y los archivos del chat se sirven por attachments.show y attachments.thumbnail),
| - GET /chat/transcripciones?mensajes=1,2: estado de las transcripciones (consulta periódica),
| - GET /chat/buscar?q=: búsqueda del chat (?conversacion=, ?tipo=, ?antes=),
| - /admin/transcripciones: estado de las transcripciones y «Relanzar» (solo admin).
| Cada límite (throttle) lleva su prefijo: sin él, todas las rutas con throttle comparten el
| contador de la persona y otras acciones agotarían el de estas.
*/

Route::get('chat/buscar', ChatSearchController::class)
    ->middleware('throttle:60,1,chat-search')
    ->name('chat.search');

Route::post('chat/{conversation}/multimedia', [MessageMediaController::class, 'store'])
    ->whereNumber('conversation')
    ->middleware('throttle:30,1,chat-media-store')
    ->name('chat.media.store');

Route::get('chat/audios/{attachment}', AudioController::class)
    ->whereNumber('attachment')
    ->middleware('signed:relative')
    ->name('chat.media.audio');

Route::get('chat/transcripciones', TranscriptionStatusController::class)
    ->middleware('throttle:120,1,chat-media-transcriptions')
    ->name('chat.media.transcriptions');

Route::prefix('admin/transcripciones')->name('admin.transcriptions.')->middleware('role:admin')->group(function () {
    Route::get('/', [TranscriptionController::class, 'index'])->name('index');
    Route::post('relanzar-fallidas', [TranscriptionController::class, 'retryFailed'])
        ->middleware('throttle:10,1,admin-transcriptions-retry-failed')
        ->name('retry-failed');
    Route::post('{transcription}/relanzar', [TranscriptionController::class, 'retry'])
        ->whereNumber('transcription')
        ->middleware('throttle:60,1,admin-transcriptions-retry')
        ->name('retry');
});
