<?php

use App\Http\Controllers\Absences\MyAbsenceController;
use App\Http\Controllers\Absences\TeamAbsenceController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Leave\AbsenceDocumentController;
use App\Http\Controllers\Leave\LeaveBalanceController;
use App\Http\Controllers\Leave\LeaveCalendarController;
use App\Http\Controllers\Leave\LeaveRequestController;
use App\Http\Controllers\Leave\LeaveTypeController;
use App\Models\Absence;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Festivos y ausencias (Fase 3, área W1, D-049 y D-050): /ausencias, /ausencias/equipo y
| /admin/festivos. Nombres de ruta absences.* y admin.holidays.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| URLs en español y nombres de ruta en inglés (D-016). Las acciones sobre una ausencia las
| autorizan AbsencePolicy (en el controlador y otra vez en AbsenceService).
*/

// Mis ausencias: cada persona interna solicita y cancela las suyas.
Route::get('ausencias', [MyAbsenceController::class, 'index'])->name('absences.index');
Route::post('ausencias', [MyAbsenceController::class, 'store'])->middleware('throttle:30,1,absences.store')->name('absences.store');
Route::post('ausencias/{absence}/cancelar', [MyAbsenceController::class, 'cancel'])->whereNumber('absence')->name('absences.cancel');

// Ausencias del equipo: responsables (su departamento) y admins.
Route::middleware('can:viewTeam,'.Absence::class)->group(function () {
    Route::get('ausencias/equipo', [TeamAbsenceController::class, 'index'])->name('absences.team.index');
    Route::get('ausencias/equipo/pendientes', [TeamAbsenceController::class, 'pending'])->middleware('throttle:120,1,absences.team.pending')->name('absences.team.pending');
    Route::post('ausencias/equipo', [TeamAbsenceController::class, 'store'])->name('absences.team.store');
    Route::post('ausencias/{absence}/aprobar', [TeamAbsenceController::class, 'approve'])->whereNumber('absence')->name('absences.approve');
    Route::post('ausencias/{absence}/rechazar', [TeamAbsenceController::class, 'reject'])->whereNumber('absence')->name('absences.reject');
    Route::put('ausencias/{absence}', [TeamAbsenceController::class, 'update'])->whereNumber('absence')->name('absences.update');
});

/*
| Vacaciones y permisos (Fase 11, R3; D-360 a D-379): solo con el módulo `people` (apagado, 404;
| /ausencias sigue como en la Fase 3). Simular una solicitud, pedir y decidir su cancelación,
| justificantes, saldos (responsables y RR. HH.; los movimientos, solo RR. HH.), el catálogo de tipos
| (RR. HH.) y el calendario laboral (toda la plantilla; sus días especiales, RR. HH.).
*/
Route::middleware('module:people')->group(function () {
    Route::post('ausencias/simular', [LeaveRequestController::class, 'simulate'])->middleware('throttle:120,1,leave.simulate')->name('absences.simulate');
    Route::post('ausencias/{absence}/pedir-cancelacion', [LeaveRequestController::class, 'requestCancellation'])->whereNumber('absence')->middleware('throttle:30,1,leave.cancellation')->name('absences.cancellation.request');
    Route::post('ausencias/{absence}/cancelacion', [LeaveRequestController::class, 'decideCancellation'])->whereNumber('absence')->middleware('throttle:60,1,leave.cancellation-decide')->name('absences.cancellation.decide');

    Route::post('ausencias/{absence}/justificantes', [AbsenceDocumentController::class, 'store'])->whereNumber('absence')->middleware('throttle:30,1,leave.documents.store')->name('absences.documents.store');
    Route::get('ausencias/justificantes/{document}', [AbsenceDocumentController::class, 'show'])->whereNumber('document')->middleware('throttle:60,1,leave.documents.show')->name('absences.documents.show');
    Route::delete('ausencias/justificantes/{document}', [AbsenceDocumentController::class, 'destroy'])->whereNumber('document')->middleware('throttle:30,1,leave.documents.destroy')->name('absences.documents.destroy');

    Route::get('ausencias/calendario', [LeaveCalendarController::class, 'index'])->name('absences.calendar.index');

    Route::middleware('can:viewTeam,'.Absence::class)->group(function () {
        Route::get('ausencias/saldos', [LeaveBalanceController::class, 'index'])->name('absences.balances.index');
    });

    Route::middleware('can:manage-people-register')->group(function () {
        Route::post('ausencias/saldos/movimientos', [LeaveBalanceController::class, 'adjust'])->middleware('throttle:60,1,leave.balances.adjust')->name('absences.balances.adjust');
        Route::post('ausencias/saldos/arrastres', [LeaveBalanceController::class, 'carryOver'])->middleware('throttle:30,1,leave.balances.carry')->name('absences.balances.carry-over');
        Route::post('ausencias/saldos/recalcular', [LeaveBalanceController::class, 'sync'])->middleware('throttle:10,1,leave.balances.sync')->name('absences.balances.sync');

        Route::get('ausencias/tipos', [LeaveTypeController::class, 'index'])->name('absences.types.index');
        Route::post('ausencias/tipos', [LeaveTypeController::class, 'store'])->middleware('throttle:30,1,leave.types.store')->name('absences.types.store');
        Route::put('ausencias/tipos/{leaveType}', [LeaveTypeController::class, 'update'])->whereNumber('leaveType')->middleware('throttle:60,1,leave.types.update')->name('absences.types.update');

        Route::post('ausencias/calendario/dias', [LeaveCalendarController::class, 'store'])->middleware('throttle:30,1,leave.calendar.store')->name('absences.calendar.store');
        Route::delete('ausencias/calendario/dias/{day}', [LeaveCalendarController::class, 'destroy'])->whereNumber('day')->middleware('throttle:30,1,leave.calendar.destroy')->name('absences.calendar.destroy');
    });
});

// Festivos (gate manage-settings).
Route::prefix('admin')->name('admin.')->middleware('can:manage-settings')->group(function () {
    Route::get('festivos', [HolidayController::class, 'index'])->name('holidays.index');
    Route::post('festivos', [HolidayController::class, 'store'])->name('holidays.store');
    Route::put('festivos/{holiday}', [HolidayController::class, 'update'])->whereNumber('holiday')->name('holidays.update');
    Route::delete('festivos/{holiday}', [HolidayController::class, 'destroy'])->whereNumber('holiday')->name('holidays.destroy');
    Route::post('festivos/nacionales', [HolidayController::class, 'national'])->name('holidays.national');
    Route::post('festivos/valencia', [HolidayController::class, 'valencia'])->name('holidays.valencia');
    Route::post('festivos/importar/vista-previa', [HolidayController::class, 'preview'])->middleware('throttle:30,1,holidays.preview')->name('holidays.preview');
    Route::post('festivos/importar', [HolidayController::class, 'import'])->name('holidays.import');
});
