<?php

use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TaskStatusController;
use App\Http\Controllers\Admin\TaskTypeController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserDeactivationController;
use App\Http\Controllers\Admin\UserInvitationController;
use App\Http\Controllers\Admin\WorkScheduleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Administración (Agente A): /admin/usuarios, /admin/departamentos, /admin/tipos-de-tarea,
| /admin/estados y /admin/ajustes. Nombres de ruta admin.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| URLs en español y nombres de ruta en inglés (D-016). Cada área tiene su fichero para que
| las entregas en paralelo no se pisen. /admin (admin.index) está en routes/web.php.
| Usuarios: gate manage-users. Catálogos y ajustes: gate manage-settings. Los controladores
| vuelven a comprobarlo y aplican las reglas finas (UserGuard: solo un admin gestiona admins…).
*/

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('can:manage-users')->group(function () {
        Route::get('usuarios', [UserController::class, 'index'])->name('users.index');
        Route::post('usuarios', [UserController::class, 'store'])->name('users.store');
        Route::get('usuarios/{user}', [UserController::class, 'edit'])->whereNumber('user')->name('users.edit');
        Route::put('usuarios/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');

        Route::post('usuarios/{user}/invitacion', [UserInvitationController::class, 'store'])
            ->whereNumber('user')
            ->middleware('throttle:10,1')
            ->name('users.invitation');

        Route::get('usuarios/{user}/baja', [UserDeactivationController::class, 'create'])->whereNumber('user')->name('users.deactivation');
        Route::post('usuarios/{user}/baja', [UserDeactivationController::class, 'store'])->whereNumber('user')->name('users.deactivate');
        Route::post('usuarios/{user}/reactivar', [UserDeactivationController::class, 'reactivate'])->whereNumber('user')->name('users.reactivate');

        Route::scopeBindings()->group(function () {
            Route::post('usuarios/{user}/jornadas', [WorkScheduleController::class, 'store'])->whereNumber('user')->name('users.schedules.store');
            Route::put('usuarios/{user}/jornadas/{workSchedule}', [WorkScheduleController::class, 'update'])->whereNumber(['user', 'workSchedule'])->name('users.schedules.update');
            Route::delete('usuarios/{user}/jornadas/{workSchedule}', [WorkScheduleController::class, 'destroy'])->whereNumber(['user', 'workSchedule'])->name('users.schedules.destroy');
        });
    });

    Route::middleware('can:manage-settings')->group(function () {
        Route::get('departamentos', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('departamentos', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('departamentos/{department}', [DepartmentController::class, 'update'])->whereNumber('department')->name('departments.update');
        Route::delete('departamentos/{department}', [DepartmentController::class, 'destroy'])->whereNumber('department')->name('departments.destroy');

        Route::get('tipos-de-tarea', [TaskTypeController::class, 'index'])->name('task-types.index');
        Route::post('tipos-de-tarea', [TaskTypeController::class, 'store'])->name('task-types.store');
        Route::put('tipos-de-tarea/{taskType}', [TaskTypeController::class, 'update'])->whereNumber('taskType')->name('task-types.update');
        Route::post('tipos-de-tarea/{taskType}/mover', [TaskTypeController::class, 'move'])->whereNumber('taskType')->name('task-types.move');
        Route::delete('tipos-de-tarea/{taskType}', [TaskTypeController::class, 'destroy'])->whereNumber('taskType')->name('task-types.destroy');

        Route::get('estados', [TaskStatusController::class, 'index'])->name('statuses.index');
        Route::post('estados', [TaskStatusController::class, 'store'])->name('statuses.store');
        Route::put('estados/{taskStatus}', [TaskStatusController::class, 'update'])->whereNumber('taskStatus')->name('statuses.update');
        Route::post('estados/{taskStatus}/mover', [TaskStatusController::class, 'move'])->whereNumber('taskStatus')->name('statuses.move');
        Route::delete('estados/{taskStatus}', [TaskStatusController::class, 'destroy'])->whereNumber('taskStatus')->name('statuses.destroy');

        Route::get('ajustes', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('ajustes', [SettingsController::class, 'update'])->name('settings.update');
    });
});
