<?php

use App\Http\Controllers\Absences\MyAbsenceController;
use App\Http\Controllers\Absences\TeamAbsenceController;
use App\Http\Controllers\Admin\HolidayController;
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

// Festivos (gate manage-settings).
Route::prefix('admin')->name('admin.')->middleware('can:manage-settings')->group(function () {
    Route::get('festivos', [HolidayController::class, 'index'])->name('holidays.index');
    Route::post('festivos', [HolidayController::class, 'store'])->name('holidays.store');
    Route::put('festivos/{holiday}', [HolidayController::class, 'update'])->whereNumber('holiday')->name('holidays.update');
    Route::delete('festivos/{holiday}', [HolidayController::class, 'destroy'])->whereNumber('holiday')->name('holidays.destroy');
    Route::post('festivos/nacionales', [HolidayController::class, 'national'])->name('holidays.national');
    Route::post('festivos/importar/vista-previa', [HolidayController::class, 'preview'])->middleware('throttle:30,1,holidays.preview')->name('holidays.preview');
    Route::post('festivos/importar', [HolidayController::class, 'import'])->name('holidays.import');
});
