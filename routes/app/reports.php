<?php

use App\Http\Controllers\Reports\ClientReportController;
use App\Http\Controllers\Reports\Exports\BillingReportController;
use App\Http\Controllers\Reports\Exports\HourBankPdfController;
use App\Http\Controllers\Reports\ProjectReportController;
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

// --- R2 ---
// Dashboards de cliente y de proyecto (con ?formato=xlsx|csv&tabla=…), exportación para facturar y
// PDF de consumo de bolsa (la bolsa siempre del proyecto de la URL: scopeBindings). Permisos en
// ClientPolicy::viewReport / viewBilling, ProjectPolicy::viewReport y HourBankPolicy::downloadPdf.
Route::get('informes/clientes/{client}', ClientReportController::class)
    ->whereNumber('client')
    ->name('reports.client');

Route::get('informes/proyectos/{project}', ProjectReportController::class)
    ->whereNumber('project')
    ->name('reports.project');

Route::get('informes/facturacion', BillingReportController::class)
    ->name('reports.billing');

Route::get('proyectos/{project}/bolsas/{hourBank}/pdf', HourBankPdfController::class)
    ->whereNumber(['project', 'hourBank'])
    ->scopeBindings()
    ->middleware('throttle:30,1')
    ->name('reports.hour-bank-pdf');
// --- fin R2 ---
