<?php

use App\Http\Controllers\Auth\GoogleLoginController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\HomeLayoutController;
use App\Http\Controllers\NavSectionsController;
use App\Http\Controllers\PortalAccess\BrandLogoController;
use App\Http\Controllers\Reports\Delivery\ReportDownloadController;
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
|   collaborator → un colaborador externo solo entra en las rutas de config/collaborators.php (D-134)
|   2fa      → exige 2FA si el ajuste require_2fa está activo
*/

// /health se registra fuera del grupo web (bootstrap/app.php): sin sesión, cookies ni CSRF.

// Aceptar una invitación de alta: fijar la contraseña con el enlace del email (válido 7 días).
Route::middleware('guest')->group(function () {
    Route::get('invitacion/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('invitacion', [InvitationController::class, 'store'])
        ->middleware('throttle:6,1,invitation.store')
        ->name('invitation.store');

    // Entrar con Google (D-165). La URI del callback es exactamente la autorizada en Google Cloud
    // (proyecto audax-proyectos, el mismo cliente que Google Sheets):
    // https://projects.audaxstudio.com/login/google/callback
    Route::post('login/google', [GoogleLoginController::class, 'redirect'])
        ->middleware('throttle:google-login')
        ->name('login.google');
    Route::get('login/google/callback', [GoogleLoginController::class, 'callback'])
        ->middleware('throttle:google-login')
        ->name('login.google.callback');
});

// Guía de estilo: pública durante el desarrollo (APP_STYLEGUIDE_PUBLIC=true); después, solo admin.
Route::get('styleguide', StyleguideController::class)->name('styleguide');

// Logo de la empresa (Fase 5, D-067): público porque lo cargan los emails, y sin el grupo web (sin
// sesión ni cookies: cada email abierto no crea una fila en sessions).
Route::get('marca/logo/{version}', BrandLogoController::class)
    ->where('version', '[a-f0-9]{1,40}')
    ->withoutMiddleware('web')
    ->name('brand.logo');

// Informe enviado por correo como enlace (Fase 9, D-141: más de 10 MB): sin sesión, porque lo abre
// también un destinatario externo; solo con la firma de la URL, que caduca a los 7 días.
Route::get('informes/descargas/{download}', ReportDownloadController::class)
    ->whereUuid('download')
    ->middleware(['signed', 'throttle:30,1,reports.downloads.show'])
    ->name('reports.downloads.show');

Route::middleware(['auth', 'active', 'internal', 'collaborator', '2fa'])->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::redirect('dashboard', '/')->name('dashboard');

    // Orden de las tarjetas de Inicio de cada persona (D-138): se guarda al soltar una tarjeta.
    Route::put('inicio/orden', [HomeLayoutController::class, 'update'])
        ->middleware('throttle:60,1,home-layout')
        ->name('home.layout.update');
    Route::delete('inicio/orden', [HomeLayoutController::class, 'destroy'])
        ->middleware('throttle:60,1,home-layout')
        ->name('home.layout.destroy');

    // Secciones plegadas de la barra lateral de cada persona (D-260): se guarda al plegar o desplegar.
    Route::put('menu/secciones', [NavSectionsController::class, 'update'])
        ->middleware('throttle:60,1,nav-sections')
        ->name('nav.sections.update');

    // Una ruta por área (routes/app/*.php), de la Fase 1 a la 10 (weeklies: la Weekly, D-145) y el plan
    // del día (day-plan, D-250) y la previsión (forecast, D-280).
    foreach (['admin', 'clients', 'projects', 'hour-banks', 'tasks', 'time', 'notifications', 'reports', 'absences', 'workload', 'schedule', 'gantt', 'planning', 'templates', 'portal-access', 'chat', 'realtime', 'chat-media', 'notification-settings', 'privacy', 'audit', 'report-deliveries', 'integrations', 'calendar', 'weeklies', 'day-plan', 'forecast'] as $area) {
        require __DIR__."/app/{$area}.php";
    }

    Route::inertia('admin', 'admin/index')->middleware('role:admin')->name('admin.index');

    // Búsqueda global (Ctrl/Cmd + K). Respeta los permisos de cada usuario.
    Route::get('buscar', SearchController::class)
        ->middleware('throttle:60,1,search')
        ->name('search');
});

Route::middleware(['auth', 'active', 'portal'])->prefix('portal')->name('portal.')->group(function () {
    // Fase 5: una ruta por área del portal (routes/portal/*.php). Todo sale de PortalScope (D-064).
    // El inicio (portal.home) está en routes/portal/banks.php: son las bolsas del cliente.
    foreach (['banks', 'projects'] as $area) {
        require __DIR__."/portal/{$area}.php";
    }
});

require __DIR__.'/settings.php';
