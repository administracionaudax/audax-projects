<?php

use App\Http\Controllers\Reports\Delivery\ReportDeliveryController;
use App\Http\Controllers\Reports\Delivery\ReportScheduleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Enviar informes por correo y envíos programados (Fase 9, D-141). Nombres reports.deliveries.* y
| reports.schedules.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', 'collaborator',
| '2fa']: ni clientes ni colaboradores externos (fuera de config/collaborators.php, D-134).
| Permisos: ReportAccess (quien envía o programa tiene que ver el informe) y ReportSchedulePolicy
| (cada programación, su propietario y los admins). La descarga de los adjuntos grandes
| (reports.downloads.show) es pública con firma: está en routes/web.php.
*/

Route::post('informes/enviar', [ReportDeliveryController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('reports.deliveries.send');

Route::get('informes/envios/personas', [ReportDeliveryController::class, 'people'])
    ->middleware('throttle:60,1')
    ->name('reports.deliveries.people');

Route::get('informes/envios', [ReportScheduleController::class, 'index'])->name('reports.schedules.index');
Route::post('informes/envios', [ReportScheduleController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('reports.schedules.store');

Route::prefix('informes/envios/{schedule}')->whereNumber('schedule')->group(function (): void {
    Route::get('/', [ReportScheduleController::class, 'show'])->name('reports.schedules.show');
    Route::put('/', [ReportScheduleController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('reports.schedules.update');
    Route::delete('/', [ReportScheduleController::class, 'destroy'])->name('reports.schedules.destroy');
    Route::post('pausar', [ReportScheduleController::class, 'pause'])->name('reports.schedules.pause');
    Route::post('reanudar', [ReportScheduleController::class, 'resume'])->name('reports.schedules.resume');
    Route::post('enviar-ahora', [ReportScheduleController::class, 'sendNow'])
        ->middleware('throttle:10,1')
        ->name('reports.schedules.send-now');
});
