<?php

use App\Http\Controllers\Projects\ProjectAlertController;
use App\Http\Controllers\Projects\ProjectArchiveController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ProjectMemberController;
use App\Http\Controllers\Projects\ProjectOwnerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Proyectos (Agente B): /proyectos, /proyectos/{project} (resumen) y /proyectos/{project}/ajustes.
| Nombres projects.*. Las pestañas tareas, bolsas, horas y archivos están en sus áreas.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| URLs en español y nombres de ruta en inglés (D-016). Cada área tiene su fichero para que
| las entregas en paralelo no se pisen.
*/

Route::get('proyectos', [ProjectController::class, 'index'])->name('projects.index');
Route::get('proyectos/nuevo', [ProjectController::class, 'create'])->name('projects.create');
Route::post('proyectos', [ProjectController::class, 'store'])->name('projects.store');

Route::prefix('proyectos/{project}')->whereNumber('project')->group(function (): void {
    Route::get('/', [ProjectController::class, 'show'])->name('projects.show');
    Route::put('/', [ProjectController::class, 'update'])->name('projects.update');
    Route::get('ajustes', [ProjectController::class, 'edit'])->name('projects.settings');

    Route::post('archivar', [ProjectArchiveController::class, 'store'])->name('projects.archive');
    Route::post('desarchivar', [ProjectArchiveController::class, 'destroy'])->name('projects.unarchive');

    Route::post('miembros', [ProjectMemberController::class, 'store'])->name('projects.members.store');
    Route::patch('miembros/{user}', [ProjectMemberController::class, 'update'])->whereNumber('user')->name('projects.members.update');
    Route::delete('miembros/{user}', [ProjectMemberController::class, 'destroy'])->whereNumber('user')->name('projects.members.destroy');
    Route::put('miembros/{user}/alertas', [ProjectAlertController::class, 'update'])->whereNumber('user')->name('projects.alerts.update');

    Route::put('gestor-principal', [ProjectOwnerController::class, 'update'])->name('projects.owner.update');
});
