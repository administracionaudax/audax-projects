<?php

use App\Http\Controllers\Reports\DepartmentReportController;
use App\Http\Controllers\Reports\DirectionReportController;
use App\Http\Controllers\Reports\PersonReportController;
use App\Http\Controllers\Reports\ReportIndexController;
use App\Http\Controllers\Reports\ReportOptionsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Informes (Fase 2): /informes y sus dashboards. Nombres reports.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| Contrato: docs/PLAN-FASE-2.md. Filtros en la URL (App\Domain\Reports\ReportFilters).
*/

// --- R1 ---
// Índice y dashboards de dirección, departamento y persona. Cada uno exporta con ?formato=xlsx|csv.
Route::get('informes', ReportIndexController::class)->name('reports.index');
Route::get('informes/direccion', DirectionReportController::class)->name('reports.direction');
Route::get('informes/departamentos/{department}', DepartmentReportController::class)->name('reports.department');
Route::get('informes/personas/{user}', PersonReportController::class)->name('reports.person');
// --- fin R1 ---

Route::get('informes/opciones', ReportOptionsController::class)
    ->middleware('throttle:60,1')
    ->name('reports.options');
