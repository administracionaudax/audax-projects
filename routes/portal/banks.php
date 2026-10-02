<?php

use App\Http\Controllers\Portal\Banks\PortalBankController;
use App\Http\Controllers\Portal\Banks\PortalBankPdfController;
use App\Http\Controllers\Portal\Banks\PortalHomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portal · bolsas (Agente P1, Fase 5): inicio del portal con las bolsas, detalle de bolsa con
| consumo por mes, entradas e histórico de renovaciones, y PDF. Nombres portal.banks.* (el grupo
| ya añade el prefijo /portal y el nombre portal.). Middleware: auth, active y portal.
|--------------------------------------------------------------------------
| Todo sale de PortalScope (D-064): una bolsa de otro cliente da 404 en el detalle y en el PDF.
*/

// Inicio del portal (portal.home): las bolsas activas, el resumen y el histórico.
Route::get('/', PortalHomeController::class)->name('home');

Route::prefix('bolsas/{bank}')->whereNumber('bank')->name('banks.')->group(function (): void {
    Route::get('/', [PortalBankController::class, 'show'])->name('show');
    // PDF de consumo en modo portal (D-066): nunca importes.
    Route::get('pdf', PortalBankPdfController::class)
        ->middleware('throttle:30,1')
        ->name('pdf');
});
