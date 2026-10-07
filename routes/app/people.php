<?php

use App\Http\Controllers\People\ClockController;
use App\Http\Controllers\People\CorrectionController;
use App\Http\Controllers\People\EmploymentProfileController;
use App\Http\Controllers\People\InspectionController;
use App\Http\Controllers\People\MonthCloseController;
use App\Http\Controllers\People\OvertimeController;
use App\Http\Controllers\People\PeopleDocumentController;
use App\Http\Controllers\People\RegisterController;
use App\Http\Controllers\People\RegisterReportController;
use App\Http\Controllers\People\TeamWorkdayController;
use App\Http\Controllers\People\WorkdayController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Personas: registro de jornada (Fase 11, R1; docs/PLAN-FASE-11.md; D-330 a D-345)
|--------------------------------------------------------------------------
| POST /fichar (la cabecera), /personas/jornada (Mi jornada), /personas/equipo (Jornada del
| equipo) y /personas/equipo/{persona}, /personas/pendientes (la bandeja del responsable y de
| RR. HH.) y las correcciones (/personas/correcciones…). Nombres people.*.
|--------------------------------------------------------------------------
| Módulo propio `people` (apagado por defecto; apagado, 404 salvo a los admins en modo de prueba,
| D-239) y la gate use-people: la plantilla interna, nunca un colaborador externo (sus rutas no
| están en config/collaborators.php) ni un cliente. Fichar exige además estar sujeto al registro
| (gate clock). Quién ve y corrige el registro de quién: PeopleAccess. Los datos laborales
| (`/admin/usuarios/{user}/laboral`) no dependen del módulo: los edita quien tiene manage-people.
| Se carga desde routes/web.php dentro del grupo interno.
|
| R2 (D-346 a D-359): /personas/registro (Mi registro y su descarga), /personas/cierres (los
| cierres del mes; confirmar y no estar de acuerdo, la persona; desconfirmar, su responsable o
| RR. HH.), /personas/horas-extra y /personas/saldo, /personas/documentos, y para RR. HH.
| (manage-people-register) /personas/informes y /personas/inspeccion. El acceso de la propia
| Inspección va aparte, fuera del grupo interno: routes/app/inspection.php.
*/

Route::middleware(['module:people', 'can:use-people'])->group(function () {
    Route::post('fichar', [ClockController::class, 'store'])
        ->middleware(['can:clock', 'throttle:30,1,people.clock'])
        ->name('people.clock');

    Route::get('personas/jornada', [WorkdayController::class, 'mine'])->name('people.workday.index');

    Route::middleware('can:view-people-team')->group(function () {
        Route::get('personas/equipo', [TeamWorkdayController::class, 'index'])->name('people.team.index');
        Route::get('personas/pendientes', [TeamWorkdayController::class, 'pending'])->name('people.pending.index');
    });

    // La jornada de una persona: ella misma, su responsable o RR. HH. (PeopleAccess::seesRegisterOf).
    Route::get('personas/equipo/{person}', [WorkdayController::class, 'show'])->whereNumber('person')->name('people.team.show');
    Route::get('personas/equipo/{person}/filas', [WorkdayController::class, 'rows'])
        ->whereNumber('person')
        ->middleware('throttle:120,1,people.rows')
        ->name('people.rows');

    Route::post('personas/correcciones', [CorrectionController::class, 'store'])
        ->middleware('throttle:30,1,people.corrections.store')
        ->name('people.corrections.store');
    Route::post('personas/correcciones/aceptar', [CorrectionController::class, 'acceptMany'])
        ->middleware('throttle:30,1,people.corrections.accept-many')
        ->name('people.corrections.accept-many');

    // R2: Mi registro, cierres, horas extra, saldo y documentos (D-346 a D-354).
    Route::get('personas/registro', [RegisterController::class, 'index'])->name('people.register.index');
    Route::get('personas/registro/descargar', [RegisterController::class, 'download'])
        ->middleware('throttle:20,1,people.register.download')
        ->name('people.register.download');

    Route::get('personas/cierres/{close}/pdf', [MonthCloseController::class, 'pdf'])
        ->whereNumber('close')
        ->middleware('throttle:30,1,people.closes.pdf')
        ->name('people.closes.pdf');
    Route::post('personas/cierres/{close}/confirmar', [MonthCloseController::class, 'confirm'])
        ->whereNumber('close')
        ->middleware('throttle:30,1,people.closes.confirm')
        ->name('people.closes.confirm');
    Route::post('personas/cierres/{close}/desacuerdo', [MonthCloseController::class, 'disagree'])
        ->whereNumber('close')
        ->middleware('throttle:30,1,people.closes.disagree')
        ->name('people.closes.disagree');

    Route::get('personas/documentos', [PeopleDocumentController::class, 'index'])->name('people.documents.index');
    Route::post('personas/documentos/{document}/leido', [PeopleDocumentController::class, 'read'])
        ->whereNumber('document')
        ->middleware('throttle:30,1,people.documents.read')
        ->name('people.documents.read');

    Route::middleware('can:view-people-team')->group(function () {
        Route::get('personas/cierres', [MonthCloseController::class, 'index'])->name('people.closes.index');
        Route::post('personas/cierres/generar', [MonthCloseController::class, 'generate'])
            ->middleware('throttle:10,1,people.closes.generate')
            ->name('people.closes.generate');
        Route::post('personas/cierres/{close}/desconfirmar', [MonthCloseController::class, 'reopen'])
            ->whereNumber('close')
            ->middleware('throttle:30,1,people.closes.reopen')
            ->name('people.closes.reopen');
        Route::post('personas/cierres/{close}/recordar', [MonthCloseController::class, 'remind'])
            ->whereNumber('close')
            ->middleware('throttle:30,1,people.closes.remind')
            ->name('people.closes.remind');

        Route::get('personas/horas-extra', [OvertimeController::class, 'index'])->name('people.overtime.index');
        Route::post('personas/horas-extra', [OvertimeController::class, 'store'])
            ->middleware('throttle:60,1,people.overtime.store')
            ->name('people.overtime.store');
        Route::post('personas/saldo', [OvertimeController::class, 'balance'])
            ->middleware('throttle:30,1,people.balance.store')
            ->name('people.balance.store');
    });

    Route::middleware('can:manage-people-register')->group(function () {
        Route::get('personas/informes', [RegisterReportController::class, 'index'])->name('people.reports.index');
        Route::get('personas/informes/{report}', [RegisterReportController::class, 'download'])
            ->middleware('throttle:30,1,people.reports.download')
            ->name('people.reports.download');

        Route::get('personas/inspeccion', [InspectionController::class, 'index'])->name('people.inspection.index');
        Route::get('personas/inspeccion/exportar', [InspectionController::class, 'export'])
            ->middleware('throttle:10,1,people.inspection.export')
            ->name('people.inspection.export');
        Route::post('personas/inspeccion/verificar', [InspectionController::class, 'check'])
            ->middleware('throttle:6,1,people.inspection.check')
            ->name('people.inspection.check');
        Route::post('personas/inspeccion/comprobar', [InspectionController::class, 'verifyFile'])
            ->middleware('throttle:20,1,people.inspection.verify-file')
            ->name('people.inspection.verify-file');
        Route::put('personas/inspeccion/ajustes', [InspectionController::class, 'toggle'])
            ->middleware('throttle:10,1,people.inspection.toggle')
            ->name('people.inspection.toggle');
        Route::post('personas/inspeccion/accesos', [InspectionController::class, 'store'])
            ->middleware('throttle:10,1,people.inspection.store')
            ->name('people.inspection.store');
        Route::post('personas/inspeccion/accesos/{access}/revocar', [InspectionController::class, 'revoke'])
            ->whereNumber('access')
            ->middleware('throttle:30,1,people.inspection.revoke')
            ->name('people.inspection.revoke');

        Route::put('personas/documentos/{key}', [PeopleDocumentController::class, 'update'])
            ->middleware('throttle:20,1,people.documents.update')
            ->name('people.documents.update');
    });

    Route::prefix('personas/correcciones/{correction}')->whereNumber('correction')->group(function (): void {
        Route::post('aceptar', [CorrectionController::class, 'accept'])->middleware('throttle:60,1,people.corrections.accept')->name('people.corrections.accept');
        Route::post('rechazar', [CorrectionController::class, 'reject'])->middleware('throttle:60,1,people.corrections.reject')->name('people.corrections.reject');
        Route::post('retirar', [CorrectionController::class, 'withdraw'])->middleware('throttle:60,1,people.corrections.withdraw')->name('people.corrections.withdraw');
    });
});

Route::put('admin/usuarios/{user}/laboral', [EmploymentProfileController::class, 'update'])
    ->whereNumber('user')
    ->middleware(['can:manage-people', 'throttle:60,1,admin.users.employment'])
    ->name('admin.users.employment.update');
