<?php

use App\Http\Controllers\Inspection\InspectionPortalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Acceso de solo lectura de la Inspección de Trabajo (Fase 11, R2; D-353)
|--------------------------------------------------------------------------
| Fuera del grupo interno: no es una cuenta de la app. Se entra con el enlace y el código que da
| RR. HH. (InspectionAccesses) y la sesión solo abre estas páginas (middleware `inspection`). Con el
| módulo `people` o el acceso de la Inspección apagados, todo da 404. Nombres inspection.*.
*/

Route::get('inspeccion/acceso/{token}', [InspectionPortalController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware('throttle:30,1,inspection.access')
    ->name('inspection.access');
Route::post('inspeccion/acceso/{token}', [InspectionPortalController::class, 'login'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware('throttle:10,1,inspection.login')
    ->name('inspection.login');

Route::middleware(['inspection', 'throttle:120,1,inspection.pages'])->group(function () {
    Route::get('inspeccion', [InspectionPortalController::class, 'index'])->name('inspection.index');
    Route::get('inspeccion/personas/{person}', [InspectionPortalController::class, 'person'])->whereNumber('person')->name('inspection.person');
    Route::get('inspeccion/exportar', [InspectionPortalController::class, 'export'])
        ->middleware('throttle:10,1,inspection.export')
        ->name('inspection.export');
    Route::post('inspeccion/salir', [InspectionPortalController::class, 'logout'])->name('inspection.logout');
});
