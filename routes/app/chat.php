<?php

use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Chat\ConversationController;
use App\Http\Controllers\Chat\MessageActionController;
use App\Http\Controllers\Chat\MessageController;
use App\Http\Controllers\Chat\MessageTaskController;
use App\Http\Controllers\Chat\ProjectChatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Chat (Agente C1, Fase 6): /chat, conversaciones (proyecto, directas y grupos), mensajes, hilos, edición, borrado, fijados, reacciones, silenciar, crear tarea desde un mensaje, previsualización de enlaces y moderación. Nombres chat.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| Lo que se lee lo autoriza ConversationPolicy::view (D-071); lo que se escribe pasa por
| App\Domain\Chat\MessageWriter (D-069). Las acciones responden JSON (la conversación la gestiona
| el navegador: paginación por cursor y consulta periódica sin tiempo real).
| Las rutas chat/{conversation}… solo aceptan números (whereNumber) para no tapar /chat/buscar ni
| /chat/transcripciones (C3). Cada límite (throttle) lleva su prefijo, como en C2 y C3.
*/

// Páginas: lista de conversaciones y conversación abierta (?mensaje={id} va a ese mensaje).
Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
Route::get('chat/conversaciones', [ChatController::class, 'conversations'])->name('chat.conversations');
Route::get('chat/no-leidos', [ChatController::class, 'unread'])->name('chat.unread');
Route::get('chat/personas', [ChatController::class, 'people'])->name('chat.people');

// Directas y grupos.
Route::post('chat/directas', [ConversationController::class, 'storeDirect'])
    ->middleware('throttle:30,1,chat-conversations')
    ->name('chat.direct.store');
Route::post('chat/grupos', [ConversationController::class, 'storeGroup'])
    ->middleware('throttle:30,1,chat-conversations')
    ->name('chat.groups.store');

// Mensajes sueltos (editar, borrar, reaccionar, fijar, moderar y crear tarea).
Route::get('chat/mensajes/{message}', [MessageController::class, 'show'])
    ->withTrashed()
    ->whereNumber('message')
    ->name('chat.messages.show');
Route::patch('chat/mensajes/{message}', [MessageController::class, 'update'])->whereNumber('message')->name('chat.messages.update');
Route::delete('chat/mensajes/{message}', [MessageController::class, 'destroy'])->whereNumber('message')->name('chat.messages.destroy');
Route::post('chat/mensajes/{message}/reacciones', [MessageActionController::class, 'react'])
    ->whereNumber('message')
    ->middleware('throttle:120,1,chat-react')
    ->name('chat.messages.react');
Route::patch('chat/mensajes/{message}/fijado', [MessageActionController::class, 'pin'])->whereNumber('message')->name('chat.messages.pin');
Route::patch('chat/mensajes/{message}/moderacion', [MessageActionController::class, 'moderate'])->whereNumber('message')->name('chat.messages.moderate');
Route::get('chat/mensajes/{message}/tarea', [MessageTaskController::class, 'options'])->whereNumber('message')->name('chat.messages.task.options');
Route::post('chat/mensajes/{message}/tarea', [MessageTaskController::class, 'store'])
    ->whereNumber('message')
    ->middleware('throttle:30,1,chat-task')
    ->name('chat.messages.task.store');

// Una conversación.
Route::get('chat/{conversation}', [ChatController::class, 'show'])->whereNumber('conversation')->name('chat.show');
Route::get('chat/{conversation}/mensajes', [MessageController::class, 'index'])->whereNumber('conversation')->name('chat.messages.index');
Route::get('chat/{conversation}/novedades', [MessageController::class, 'poll'])->whereNumber('conversation')->name('chat.messages.poll');
Route::post('chat/{conversation}/mensajes', [MessageController::class, 'store'])
    ->whereNumber('conversation')
    ->middleware('throttle:120,1,chat-post')
    ->name('chat.messages.store');
Route::post('chat/{conversation}/leido', [ConversationController::class, 'read'])->whereNumber('conversation')->name('chat.read');
Route::patch('chat/{conversation}/silencio', [ConversationController::class, 'mute'])->whereNumber('conversation')->name('chat.mute');
Route::get('chat/{conversation}/fijados', [ConversationController::class, 'pinned'])->whereNumber('conversation')->name('chat.pinned');

// Pestaña Chat del proyecto.
Route::get('proyectos/{project}/chat', ProjectChatController::class)->name('projects.chat');
