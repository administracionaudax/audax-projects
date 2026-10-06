<?php

use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\AvatarController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SessionsController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// Ajustes personales: disponibles para internos y clientes (el layout lo decide el frontend). Un
// colaborador externo (D-134) también, por la lista de config/collaborators.php.
Route::middleware(['auth', 'active', 'collaborator'])->group(function () {
    Route::redirect('ajustes', '/ajustes/perfil');

    Route::get('ajustes/perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('ajustes/perfil', [ProfileController::class, 'update'])->name('profile.update');
    // Foto de perfil (F-029, D-234): recortada en el navegador; se sirve con URL firmada.
    Route::post('ajustes/perfil/foto', [AvatarController::class, 'update'])->middleware('throttle:10,1,profile.avatar.update')->name('profile.avatar.update');
    Route::delete('ajustes/perfil/foto', [AvatarController::class, 'destroy'])->name('profile.avatar.destroy');
    Route::get('avatares/{user}', [AvatarController::class, 'show'])->whereNumber('user')->middleware('signed:relative')->name('avatars.show');

    Route::get('ajustes/seguridad', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('ajustes/contrasena', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1,user-password.update')
        ->name('user-password.update');

    Route::get('ajustes/apariencia', [AppearanceController::class, 'edit'])->name('appearance.edit');
    Route::patch('ajustes/apariencia', [AppearanceController::class, 'update'])->name('appearance.update');

    Route::get('ajustes/sesiones', [SessionsController::class, 'index'])
        ->middleware(RequirePassword::class)
        ->name('sessions.index');
    Route::delete('ajustes/sesiones/otras', [SessionsController::class, 'destroyOthers'])
        ->middleware([RequirePassword::class, 'throttle:6,1,sessions.destroy-others'])
        ->name('sessions.destroy-others');
    Route::delete('ajustes/sesiones/{session}', [SessionsController::class, 'destroy'])->name('sessions.destroy');
});
