<?php

use App\Http\Controllers\Integrations\IntegrationsController;
use App\Http\Controllers\Reports\SheetsExportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Integraciones (Fase 9, D-142): Ajustes → Integraciones y la exportación a Google Sheets.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', 'collaborator',
| '2fa']: solo la plantilla. Los colaboradores externos (D-134) no tienen estas rutas (no están en
| config/collaborators.php) y el portal nunca.
|
| La URI del callback es exactamente la autorizada en Google Cloud (proyecto audax-proyectos):
| https://projects.audaxstudio.com/integraciones/google/callback
*/

Route::get('ajustes/integraciones', [IntegrationsController::class, 'edit'])->name('integrations.edit');

Route::post('integraciones/google/conectar', [IntegrationsController::class, 'connect'])
    ->middleware('throttle:10,1,google-oauth')
    ->name('integrations.google.connect');

Route::get('integraciones/google/callback', [IntegrationsController::class, 'callback'])
    ->middleware('throttle:10,1,google-oauth')
    ->name('integrations.google.callback');

Route::delete('integraciones/google', [IntegrationsController::class, 'destroy'])
    ->middleware('throttle:10,1,google-oauth')
    ->name('integrations.google.destroy');

Route::post('informes/sheets', SheetsExportController::class)
    ->middleware('throttle:10,1,google-sheets')
    ->name('reports.sheets.store');
