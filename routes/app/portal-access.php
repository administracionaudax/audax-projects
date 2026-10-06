<?php

use App\Http\Controllers\Admin\IdentityController;
use App\Http\Controllers\PortalAccess\ClientPortalSettingsController;
use App\Http\Controllers\PortalAccess\PortalUserController;
use App\Http\Controllers\PortalAccess\ProjectPortalSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Acceso al portal, lado interno (Agente P2, Fase 5): usuarios del portal de cada cliente
| (invitar, reenviar, revocar y reactivar), ajustes del portal del cliente y de cada proyecto, e
| identidad de la empresa (/admin/identidad). Nombres clients.portal.*, projects.portal.* y
| admin.identity.*.
|--------------------------------------------------------------------------
| Se carga desde routes/web.php dentro del grupo ['auth', 'active', 'internal', '2fa'].
| - Usuarios del portal (D-063): ClientPolicy::managePortal. {portalUser} tiene que ser del portal
|   de ESE cliente (scopeBindings con Client::portalUsers): si no, 404.
| - Ajustes del portal del cliente (D-064, D-065): ClientPolicy::update.
| - Portal del proyecto (D-064): ProjectPolicy::update (quien gestiona el proyecto).
| - Identidad (D-067): solo admin. El logo se sirve aparte, sin sesión: brand.logo en routes/web.php.
*/

Route::prefix('clientes/{client}/portal')->whereNumber('client')->name('clients.portal.')->group(function () {
    Route::post('usuarios', [PortalUserController::class, 'store'])
        ->middleware('throttle:10,1,users.store')
        ->name('users.store');

    Route::scopeBindings()->whereNumber('portalUser')->group(function () {
        Route::post('usuarios/{portalUser}/invitacion', [PortalUserController::class, 'resend'])
            ->middleware('throttle:10,1,clients.portal.users.invitation')
            ->name('users.invitation');
        Route::post('usuarios/{portalUser}/revocar', [PortalUserController::class, 'revoke'])->name('users.revoke');
        Route::post('usuarios/{portalUser}/reactivar', [PortalUserController::class, 'reactivate'])->name('users.reactivate');
    });

    Route::put('ajustes', [ClientPortalSettingsController::class, 'update'])->name('settings.update');
});

Route::put('proyectos/{project}/portal', [ProjectPortalSettingsController::class, 'update'])
    ->whereNumber('project')
    ->name('projects.portal.update');

Route::prefix('admin/identidad')->middleware('role:admin')->name('admin.identity.')->group(function () {
    Route::get('/', [IdentityController::class, 'edit'])->name('edit');
    Route::post('/', [IdentityController::class, 'update'])->name('update');
    Route::delete('logo', [IdentityController::class, 'destroyLogo'])->name('logo.destroy');
});
