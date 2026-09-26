<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureClientUser;
use App\Http\Middleware\EnsureInternalUser;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // /health (estado para el despliegue y la monitorización) va fuera del grupo web: sin sesión,
        // cookies ni CSRF. Con SESSION_DRIVER=database cada sondeo crearía una fila en sessions.
        then: function (): void {
            Route::get('health', HealthController::class)->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Cabeceras de seguridad y noindex en todas las respuestas (SPEC §15).
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            // Máximo 6 precargas en la cabecera Link: con todas (22) las cabeceras superaban los 4 KB
            // del búfer del proxy nginx de Plesk y /login daba 502. El resto se precarga desde el HTML.
            AddLinkHeadersForPreloadedAssets::using(6),
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'internal' => EnsureInternalUser::class,
            'portal' => EnsureClientUser::class,
            '2fa' => RequireTwoFactor::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
