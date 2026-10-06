<?php

use App\Http\Controllers\Admin\AuditController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auditoría visible (Agente A, Fase 7, D-074): /admin/auditoria con filtros (entidad, persona,
| acción y fechas), el detalle antes y después y la exportación a CSV. Solo admin. Nombres
| admin.audit.*. Se carga dentro del grupo auth, active, internal y 2fa.
|--------------------------------------------------------------------------
| La exportación tiene el mismo límite de peticiones que las de los informes (D-045).
*/

Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
    Route::get('auditoria', [AuditController::class, 'index'])->name('audit.index');
    Route::get('auditoria/exportar', [AuditController::class, 'export'])
        ->middleware('throttle:30,1,audit.export')
        ->name('audit.export');
});
