<?php

use App\Http\Controllers\Clients\ClientController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Clientes (Agente A): /clientes y /clientes/{client}. Nombres clients.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| URLs en español y nombres de ruta en inglés (D-016). Cada área tiene su fichero para que
| las entregas en paralelo no se pisen. Permisos: ClientPolicy (verlos, todos los internos;
| crearlos, editarlos y desactivarlos, admins y responsables, D-022). Nunca se borran (D-037).
|
| GET /clientes/opciones → [{id, name}] de los clientes activos (más ?incluir={id}): para el
| selector de cliente de «Nuevo proyecto» y de los filtros de otras áreas.
*/

Route::get('clientes', [ClientController::class, 'index'])->name('clients.index');
Route::post('clientes', [ClientController::class, 'store'])->name('clients.store');
Route::get('clientes/opciones', [ClientController::class, 'options'])->name('clients.options');
Route::get('clientes/{client}', [ClientController::class, 'show'])->whereNumber('client')->name('clients.show');
Route::put('clientes/{client}', [ClientController::class, 'update'])->whereNumber('client')->name('clients.update');
Route::post('clientes/{client}/desactivar', [ClientController::class, 'deactivate'])->whereNumber('client')->name('clients.deactivate');
Route::post('clientes/{client}/reactivar', [ClientController::class, 'reactivate'])->whereNumber('client')->name('clients.reactivate');
