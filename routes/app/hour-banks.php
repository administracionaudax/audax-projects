<?php

use App\Http\Controllers\HourBanks\HourBankController;
use App\Http\Controllers\HourBanks\HourBankOverviewController;
use App\Http\Controllers\HourBanks\HourBankRenewalController;
use App\Http\Controllers\HourBanks\HourBankStatusController;
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

Route::get('bolsas', HourBankOverviewController::class)
    ->middleware('can:view-hour-banks')
    ->name('hour-banks.index');

// {hourBank} siempre del proyecto de la URL (scopeBindings): otra bolsa da 404.
Route::prefix('proyectos/{project}/bolsas')
    ->whereNumber('project')
    ->scopeBindings()
    ->name('projects.hour-banks.')
    ->group(function (): void {
        Route::get('/', [HourBankController::class, 'index'])->name('index');
        Route::post('/', [HourBankController::class, 'store'])->name('store');

        Route::prefix('{hourBank}')->whereNumber('hourBank')->group(function (): void {
            Route::get('/', [HourBankController::class, 'show'])->name('show');
            Route::put('/', [HourBankController::class, 'update'])->name('update');
            Route::delete('/', [HourBankController::class, 'destroy'])->name('destroy');
            Route::post('renovar', HourBankRenewalController::class)->name('renew');
            Route::post('cerrar', [HourBankStatusController::class, 'close'])->name('close');
            Route::post('reabrir', [HourBankStatusController::class, 'reopen'])->name('reopen');
        });
    });
