<?php

use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SessionsController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// Ajustes personales: disponibles para internos y clientes (el layout lo decide el frontend).
Route::middleware(['auth', 'active'])->group(function () {
    Route::redirect('ajustes', '/ajustes/perfil');

    Route::get('ajustes/perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('ajustes/perfil', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('ajustes/seguridad', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('ajustes/contrasena', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('ajustes/apariencia', [AppearanceController::class, 'edit'])->name('appearance.edit');
    Route::patch('ajustes/apariencia', [AppearanceController::class, 'update'])->name('appearance.update');

    Route::get('ajustes/sesiones', [SessionsController::class, 'index'])->name('sessions.index');
    Route::delete('ajustes/sesiones/otras', [SessionsController::class, 'destroyOthers'])
        ->middleware(RequirePassword::class)
        ->name('sessions.destroy-others');
    Route::delete('ajustes/sesiones/{session}', [SessionsController::class, 'destroy'])->name('sessions.destroy');
});
