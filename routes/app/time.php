<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Horas (Agente D): /horas (hoja semanal ?semana=2026-W39), entradas, /temporizador,
| /horas/aprobaciones, /horas/bloqueo y /proyectos/{project}/horas. Nombres time.*, timer.* y projects.time.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| URLs en español y nombres de ruta en inglés (D-016). Cada área tiene su fichero para que
| las entregas en paralelo no se pisen.
*/

Route::inertia('horas', 'placeholder', ['section' => 'time'])->name('time.index');
