<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Clientes (Agente A): /clientes y /clientes/{client}. Nombres clients.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| URLs en español y nombres de ruta en inglés (D-016). Cada área tiene su fichero para que
| las entregas en paralelo no se pisen.
*/

Route::inertia('clientes', 'placeholder', ['section' => 'clients'])->name('clients.index');
