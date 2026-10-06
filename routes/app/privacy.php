<?php

use App\Http\Controllers\Admin\PrivacySettingsController;
use App\Http\Controllers\Admin\UserPersonalDataExportController;
use App\Http\Controllers\Privacy\PersonalDataExportController;
use App\Http\Controllers\Privacy\PrivacyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Privacidad y RGPD (Agente A, Fase 7, D-075): /privacidad (texto informativo, lectura y plazos de
| retención), /ajustes/mis-datos (exportación de los datos personales propios) y, para el admin,
| /admin/privacidad (texto y retención) y la exportación de los datos de cualquier persona.
| Nombres privacy.* y admin.privacy.*. Se carga dentro del grupo auth, active, internal y 2fa.
|--------------------------------------------------------------------------
| La descarga de una exportación lleva URL firmada (relativa, hasta que caduca) Y la política
| PersonalDataExportPolicy: la propia persona o un admin.
*/

Route::get('privacidad', [PrivacyController::class, 'show'])->name('privacy.show');
Route::post('privacidad/lectura', [PrivacyController::class, 'acknowledge'])
    ->middleware('throttle:10,1,privacy.acknowledge')
    ->name('privacy.acknowledge');

Route::get('ajustes/mis-datos', [PersonalDataExportController::class, 'index'])->name('privacy.exports.index');
Route::post('ajustes/mis-datos', [PersonalDataExportController::class, 'store'])
    ->middleware('throttle:3,10,privacy.exports.store')
    ->name('privacy.exports.store');
Route::get('datos-personales/{export}/descargar', [PersonalDataExportController::class, 'download'])
    ->whereNumber('export')
    ->middleware(['signed:relative', 'throttle:30,1,privacy.exports.download'])
    ->name('privacy.exports.download');

Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
    Route::get('privacidad', [PrivacySettingsController::class, 'edit'])->name('privacy.edit');
    Route::put('privacidad', [PrivacySettingsController::class, 'update'])->name('privacy.update');

    Route::post('usuarios/{user}/datos-personales', [UserPersonalDataExportController::class, 'store'])
        ->whereNumber('user')
        ->middleware('throttle:10,1,admin.privacy.exports.store')
        ->name('privacy.exports.store');
});
