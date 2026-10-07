<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureClientUser;
use App\Http\Middleware\EnsureInspectionAccess;
use App\Http\Middleware\EnsureInternalUser;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\RestrictCollaborators;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Responses\ErrorPage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
    // Tiempo real (Fase 6): /broadcasting/auth solo para internos activos y, si el 2FA es
    // obligatorio, con él ya configurado (como el resto de rutas internas; D-120). Un colaborador
    // externo (D-134) entra, pero cada canal filtra lo que ve (routes/channels.php).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth', 'active', 'internal', 'collaborator', '2fa']])
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

        // AuthenticateSession ('auth.session') en todo el grupo web: una sesión abierta con la
        // contraseña anterior deja de valer en cuanto la contraseña cambia (SPEC §15).
        $middleware->authenticateSessions();

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'internal' => EnsureInternalUser::class,
            'portal' => EnsureClientUser::class,
            '2fa' => RequireTwoFactor::class,
            // Colaboradores externos (D-134): rutas internas cerradas salvo config/collaborators.php.
            'collaborator' => RestrictCollaborators::class,
            // Módulos activables de la Fase 10 (F-177, D-151): `module:weeklies`, `module:help`…
            'module' => EnsureModuleEnabled::class,
            // Acceso de solo lectura de la Inspección de Trabajo (Fase 11, R2; D-353).
            'inspection' => EnsureInspectionAccess::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // Quién puede entrar se decide ANTES de buscar los modelos de la URL (SubstituteBindings):
        // así un cliente o una cuenta desactivada no distingue un id que existe (redirección) de
        // uno que no (404). Lo comprueba tests/Feature/Portal/ClientIsolationTest.
        // Van justo antes de los límites de peticiones (throttle), que en la lista de Laravel ya
        // preceden a SubstituteBindings: así un cliente va a su portal sin gastar el cupo de rutas
        // internas. Laravel guarda una sola posición por middleware.
        // El de los colaboradores externos (D-134), igual: 403 antes de saber si el id existe.
        foreach ([EnsureUserIsActive::class, EnsureInternalUser::class, EnsureClientUser::class, RestrictCollaborators::class] as $gate) {
            $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: $gate);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 403, 404, 419, 429, 500 y 503 con la página de error de la app, en español (UX-03).
        $exceptions->respond(fn (SymfonyResponse $response, Throwable $exception, Request $request): SymfonyResponse => ErrorPage::respond($response, $request));
    })->create();
