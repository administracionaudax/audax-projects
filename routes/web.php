<?php

use App\Http\Controllers\HealthController;
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

// Estado para el despliegue y la monitorización (sin datos sensibles).
Route::get('health', HealthController::class)->name('health');

// Guía de estilo: pública durante el desarrollo (APP_STYLEGUIDE_PUBLIC=true); después, solo admin.
Route::get('styleguide', StyleguideController::class)->name('styleguide');

Route::middleware(['auth', 'active', 'internal', '2fa'])->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::redirect('dashboard', '/')->name('dashboard');

    // Secciones de la barra lateral: se construyen en las fases 1 a 6.
    Route::inertia('mis-tareas', 'placeholder', ['section' => 'my-tasks'])->name('my-tasks.index');
    Route::inertia('proyectos', 'placeholder', ['section' => 'projects'])->name('projects.index');
    Route::inertia('clientes', 'placeholder', ['section' => 'clients'])->name('clients.index');
    Route::inertia('bolsas', 'placeholder', ['section' => 'hour-banks'])
        ->middleware('can:view-hour-banks')
        ->name('hour-banks.index');
    Route::inertia('horas', 'placeholder', ['section' => 'time'])->name('time.index');
    Route::inertia('carga', 'placeholder', ['section' => 'workload'])->name('workload.index');
    Route::inertia('informes', 'placeholder', ['section' => 'reports'])->name('reports.index');
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
