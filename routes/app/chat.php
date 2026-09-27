<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Chat (Agente C1, Fase 6): /chat, conversaciones (proyecto, directas y grupos), mensajes, hilos, edición, borrado, fijados, reacciones, silenciar, crear tarea desde un mensaje, previsualización de enlaces y moderación. Nombres chat.*.
|--------------------------------------------------------------------------
*/

// Marcador hasta que C1 construya /chat (se sustituye por su controlador con el mismo nombre).
Route::inertia('chat', 'placeholder', ['section' => 'chat'])->name('chat.index');
