<?php

use App\Http\Controllers\Tasks\AttachmentController;
use App\Http\Controllers\Tasks\CommentReactionController;
use App\Http\Controllers\Tasks\MyTasksController;
use App\Http\Controllers\Tasks\ProjectFilesController;
use App\Http\Controllers\Tasks\ProjectTasksController;
use App\Http\Controllers\Tasks\TaskBulkController;
use App\Http\Controllers\Tasks\TaskCommentController;
use App\Http\Controllers\Tasks\TaskController;
use App\Http\Controllers\Tasks\TaskMoveController;
use App\Http\Controllers\Tasks\TaskPositionController;
use App\Http\Controllers\Tasks\TaskWatchController;
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

Route::get('mis-tareas', MyTasksController::class)->name('my-tasks.index');

// Pestañas Tareas y Archivos del proyecto.
Route::get('proyectos/{project}/tareas', [ProjectTasksController::class, 'index'])->name('projects.tasks');
Route::get('proyectos/{project}/archivos', ProjectFilesController::class)->name('projects.files');

// Tareas.
Route::post('proyectos/{project}/tareas', [TaskController::class, 'store'])
    ->middleware('throttle:120,1,tasks.store')
    ->name('tasks.store');
Route::patch('proyectos/{project}/tareas/masivo', TaskBulkController::class)->name('tasks.bulk');
Route::get('tareas/{task}', [TaskController::class, 'show'])->name('tasks.show');
Route::patch('tareas/{task}', [TaskController::class, 'update'])->name('tasks.update');
Route::delete('tareas/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
Route::patch('tareas/{task}/posicion', TaskPositionController::class)->name('tasks.position');
Route::post('tareas/{task}/mover', TaskMoveController::class)->name('tasks.move');
Route::post('tareas/{task}/seguir', [TaskWatchController::class, 'store'])->name('tasks.watch');
Route::delete('tareas/{task}/seguir', [TaskWatchController::class, 'destroy'])->name('tasks.unwatch');

// Comentarios y reacciones.
Route::post('tareas/{task}/comentarios', [TaskCommentController::class, 'store'])
    ->middleware('throttle:60,1,tasks.comments.store')
    ->name('tasks.comments.store');
Route::patch('comentarios/{comment}', [TaskCommentController::class, 'update'])->name('tasks.comments.update');
Route::delete('comentarios/{comment}', [TaskCommentController::class, 'destroy'])->name('tasks.comments.destroy');
Route::post('comentarios/{comment}/reacciones', CommentReactionController::class)->name('tasks.comments.react');

// Adjuntos: subida a una tarea, descarga y miniatura con URL firmada (relativa) + política, y borrado.
Route::post('tareas/{task}/adjuntos', [AttachmentController::class, 'store'])
    ->middleware('throttle:30,1,tasks.attachments.store')
    ->name('tasks.attachments.store');
Route::get('adjuntos/{attachment}', [AttachmentController::class, 'show'])
    ->middleware('signed:relative')
    ->name('attachments.show');
Route::get('adjuntos/{attachment}/miniatura', [AttachmentController::class, 'thumbnail'])
    ->middleware('signed:relative')
    ->name('attachments.thumbnail');
Route::delete('adjuntos/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
