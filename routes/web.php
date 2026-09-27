<?php

use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StyleguideController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas web
|--------------------------------------------------------------------------
| URLs visibles en español; nombres de ruta en inglés (CLAUDE.md).
| Middleware (bootstrap/app.php):
|   active   → cierra la sesión de usuarios desactivados
|   internal → solo usuarios internos (un cliente va a /portal)
|   portal   → solo usuarios con rol "client"
|   2fa      → exige 2FA si el ajuste require_2fa está activo
*/

// /health se registra fuera del grupo web (bootstrap/app.php): sin sesión, cookies ni CSRF.

// Aceptar una invitación de alta: fijar la contraseña con el enlace del email (válido 7 días).
Route::middleware('guest')->group(function () {
    Route::get('invitacion/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('invitacion', [InvitationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('invitation.store');
});

// Guía de estilo: pública durante el desarrollo (APP_STYLEGUIDE_PUBLIC=true); después, solo admin.
Route::get('styleguide', StyleguideController::class)->name('styleguide');

Route::middleware(['auth', 'active', 'internal', '2fa'])->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::redirect('dashboard', '/')->name('dashboard');

    // Fase 1: una ruta por área (routes/app/*.php).
    foreach (['admin', 'clients', 'projects', 'hour-banks', 'tasks', 'time', 'notifications', 'reports', 'absences'] as $area) {
        require __DIR__."/app/{$area}.php";
    }

    // Secciones de la barra lateral que se construyen en las fases 2 a 6.
    Route::inertia('carga', 'placeholder', ['section' => 'workload'])->name('workload.index');
    Route::inertia('chat', 'placeholder', ['section' => 'chat'])->name('chat.index');

    Route::inertia('admin', 'admin/index')->middleware('role:admin')->name('admin.index');

    // Búsqueda global (Ctrl/Cmd + K). Respeta los permisos de cada usuario.
    Route::get('buscar', SearchController::class)
        ->middleware('throttle:60,1')
        ->name('search');
});

Route::middleware(['auth', 'active', 'portal'])->prefix('portal')->name('portal.')->group(function () {
    Route::inertia('/', 'portal/home')->name('home');
});

require __DIR__.'/settings.php';
