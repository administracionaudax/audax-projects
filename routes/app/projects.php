<?php

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

Route::inertia('proyectos', 'placeholder', ['section' => 'projects'])->name('projects.index');
