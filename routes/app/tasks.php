<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tareas (Agente C): /mis-tareas, /proyectos/{project}/tareas (lista y kanban, ?tarea= abre el panel),
| /tareas/{task} (redirige al panel), comentarios, reacciones, seguidores, adjuntos (/adjuntos/{attachment})
| y /proyectos/{project}/archivos. Nombres my-tasks.*, tasks.*, attachments.* y projects.files.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| URLs en español y nombres de ruta en inglés (D-016). Cada área tiene su fichero para que
| las entregas en paralelo no se pisen.
*/

Route::inertia('mis-tareas', 'placeholder', ['section' => 'my-tasks'])->name('my-tasks.index');
