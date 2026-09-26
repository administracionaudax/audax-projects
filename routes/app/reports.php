<?php

use App\Http\Controllers\Reports\ReportOptionsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Informes (Fase 2): /informes y sus dashboards. Nombres reports.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| Contrato: docs/PLAN-FASE-2.md. Filtros en la URL (App\Domain\Reports\ReportFilters).
*/

// Hasta que exista el índice de informes (R1), /informes sigue siendo la página provisional.
Route::inertia('informes', 'placeholder', ['section' => 'reports'])->name('reports.index');

Route::get('informes/opciones', ReportOptionsController::class)
    ->middleware('throttle:60,1')
    ->name('reports.options');
