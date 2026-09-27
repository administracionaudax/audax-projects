<?php

use App\Http\Controllers\Reports\ClientReportController;
use App\Http\Controllers\Reports\DepartmentReportController;
use App\Http\Controllers\Reports\DetailReportController;
use App\Http\Controllers\Reports\DirectionReportController;
use App\Http\Controllers\Reports\Exports\BillingReportController;
use App\Http\Controllers\Reports\Exports\HourBankPdfController;
use App\Http\Controllers\Reports\HoursExportController;
use App\Http\Controllers\Reports\PersonReportController;
use App\Http\Controllers\Reports\ProjectReportController;
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
// --- R3 ---
// Informe detallado (tabla dinámica, SPEC §10.6) con su exportación (?formato=xlsx|csv), exportación
// de horas con los filtros globales y la de la pestaña Horas del proyecto (D-021, D-045).
Route::get('informes/detalle', DetailReportController::class)->name('reports.detail');

Route::get('informes/horas/exportar', HoursExportController::class)
    ->middleware('throttle:30,1')
    ->name('reports.hours.export');

Route::get('proyectos/{project}/horas/exportar', [HoursExportController::class, 'project'])
    ->middleware('throttle:30,1')
    ->name('projects.time.export');
// --- fin R3 ---
