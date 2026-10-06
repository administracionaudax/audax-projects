<?php

use App\Http\Controllers\Time\ApprovalController;
use App\Http\Controllers\Time\EntryOptionsController;
use App\Http\Controllers\Time\LoggableTaskController;
use App\Http\Controllers\Time\ProjectTimeController;
use App\Http\Controllers\Time\TimeEntryController;
use App\Http\Controllers\Time\TimeLockController;
use App\Http\Controllers\Time\TimerController;
use App\Http\Controllers\Time\TimesheetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Horas (Agente D): /horas (hoja semanal ?semana=2026-W39), entradas, /temporizador,
| /horas/aprobaciones, /horas/bloqueo y /proyectos/{project}/horas. Nombres time.*, timer.* y projects.time.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| URLs en español y nombres de ruta en inglés (D-016). Cada área tiene su fichero para que
| las entregas en paralelo no se pisen.
*/

// Hoja semanal (?semana=2026-W39&persona=12) y envío o retirada de la semana propia.
Route::get('horas', [TimesheetController::class, 'show'])->name('time.index');
Route::post('horas/semana/enviar', [TimesheetController::class, 'submit'])->name('time.week.submit');
Route::post('horas/semana/retirar', [TimesheetController::class, 'withdraw'])->name('time.week.withdraw');
Route::post('horas/semanas/{period}/reabrir', [ApprovalController::class, 'reopen'])->name('time.week.reopen');

// Entrada manual y celdas de la hoja (siempre con TimeEntryWriter).
Route::post('horas/entradas', [TimeEntryController::class, 'store'])->name('time.entries.store');
Route::put('horas/entradas/{entry}', [TimeEntryController::class, 'update'])->name('time.entries.update');
Route::delete('horas/entradas/{entry}', [TimeEntryController::class, 'destroy'])->name('time.entries.destroy');

// JSON del diálogo de imputación: buscador de tareas y opciones (personas y ajustes).
Route::get('horas/tareas', LoggableTaskController::class)->middleware('throttle:120,1,time.tasks')->name('time.tasks');
Route::get('horas/opciones', EntryOptionsController::class)->middleware('throttle:120,1,time.options')->name('time.options');

// Aprobaciones: responsables y admins (D-020).
Route::middleware('can:approve-time')->group(function () {
    Route::get('horas/aprobaciones', [ApprovalController::class, 'index'])->name('time.approvals.index');
    Route::get('horas/aprobaciones/{period}/entradas', [ApprovalController::class, 'entries'])->middleware('throttle:120,1,time.approvals.entries')->name('time.approvals.entries');
    Route::post('horas/aprobaciones/aprobar', [ApprovalController::class, 'approveMany'])->name('time.approvals.approve-many');
    Route::post('horas/aprobaciones/{period}/aprobar', [ApprovalController::class, 'approve'])->name('time.approvals.approve');
    Route::post('horas/aprobaciones/{period}/devolver', [ApprovalController::class, 'sendBack'])->name('time.approvals.send-back');
});

// Bloqueo al facturar: solo admins (D-034).
Route::middleware('can:lock-time')->group(function () {
    Route::get('horas/bloqueo', [TimeLockController::class, 'index'])->name('time.locks.index');
    Route::get('horas/bloqueo/vista-previa', [TimeLockController::class, 'preview'])->name('time.locks.preview');
    Route::post('horas/bloqueo', [TimeLockController::class, 'store'])->name('time.locks.store');
    Route::delete('horas/bloqueo/{lock}', [TimeLockController::class, 'destroy'])->name('time.locks.destroy');
});

// Temporizador (uno por usuario; URLs en resources/js/lib/urls.ts).
Route::post('temporizador', [TimerController::class, 'start'])->name('timer.start');
Route::post('temporizador/parar', [TimerController::class, 'stop'])->name('timer.stop');
Route::delete('temporizador', [TimerController::class, 'discard'])->name('timer.discard');

// Pestaña Horas del proyecto.
Route::get('proyectos/{project}/horas', ProjectTimeController::class)->name('projects.time');
