<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Bolsas de horas (Agente B): /bolsas, /proyectos/{project}/bolsas y
| /proyectos/{project}/bolsas/{hourBank}. Nombres hour-banks.* y projects.hour-banks.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| URLs en español y nombres de ruta en inglés (D-016). Cada área tiene su fichero para que
| las entregas en paralelo no se pisen.
*/

Route::inertia('bolsas', 'placeholder', ['section' => 'hour-banks'])
    ->middleware('can:view-hour-banks')
    ->name('hour-banks.index');
