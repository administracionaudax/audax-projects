<?php

use App\Http\Controllers\Portal\Projects\PortalProjectController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portal · proyectos (Agente P2, Fase 5): vista del proyecto (tareas y estados) y Gantt de solo
| lectura, solo si el admin los ha abierto (PortalScope::canViewProject / canViewGantt).
| Nombres portal.projects.*.
|--------------------------------------------------------------------------
| El grupo (routes/web.php) añade /portal, el nombre portal. y los middleware auth, active y portal.
| Un proyecto de otro cliente, uno sin abrir o un Gantt sin abrir dan 404 (no se distingue de uno
| que no existe); un cliente desactivado, 403.
*/

Route::get('proyectos', [PortalProjectController::class, 'index'])->name('projects.index');
Route::get('proyectos/{project}', [PortalProjectController::class, 'show'])->whereNumber('project')->name('projects.show');
Route::get('proyectos/{project}/gantt', [PortalProjectController::class, 'gantt'])->whereNumber('project')->name('projects.gantt');
