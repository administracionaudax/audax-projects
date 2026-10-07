<?php

use App\Http\Controllers\People\ClockController;
use App\Http\Controllers\People\CorrectionController;
use App\Http\Controllers\People\EmploymentProfileController;
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
