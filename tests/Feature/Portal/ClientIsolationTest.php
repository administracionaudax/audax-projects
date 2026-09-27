<?php

use App\Http\Middleware\EnsureInternalUser;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/*
| Aislamiento del portal (SPEC §5, §11 y aceptación de la F5): un usuario cliente nunca llega a la
| aplicación interna. Se recorren TODAS las rutas registradas, así que cualquier ruta nueva de
| cualquier fase queda cubierta sin tocar este test.
*/

beforeEach(function () {
    $this->client = User::factory()->client()->create();
});

/**
 * La URI con sus parámetros rellenos con ids que no existen (el middleware debe cortar antes de
 * buscarlos) y que cumplen las restricciones de la ruta (whereUuid, whereNumber…).
 */
$uriFor = function (Route $route): string {
    $uri = (string) preg_replace_callback('/\{([^}?]+)\??\}/', function (array $match) use ($route): string {
        $pattern = $route->wheres[$match[1]] ?? null;

        return is_string($pattern) && str_contains($pattern, 'a-f') ? '00000000-0000-4000-8000-000000000000' : '999999';
    }, $route->uri());

    return '/'.ltrim($uri, '/');
};

$isInternal = function (Route $route): bool {
    $middleware = $route->gatherMiddleware();

    return in_array('internal', $middleware, true) || in_array(EnsureInternalUser::class, $middleware, true);
};

it('toda ruta interna manda al cliente a su portal y no ejecuta nada', function () use ($uriFor, $isInternal) {
    $failures = [];
    $checked = 0;

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        if (! $isInternal($route)) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            $checked++;
            $response = $this->actingAs($this->client)->call($method, $uriFor($route));
            $location = $response->headers->get('Location');
            $toPortal = $response->isRedirect() && is_string($location) && str_ends_with(rtrim($location, '/'), '/portal');

            if (! $toPortal) {
                $failures[] = "{$method} /{$route->uri()} → {$response->getStatusCode()} ".($location ?? '');
            }
        }
    }

    expect($checked)->toBeGreaterThan(100)
        ->and($failures)->toBe([]);
});

it('en peticiones JSON, toda ruta interna responde 403 al cliente', function () use ($uriFor, $isInternal) {
    $failures = [];

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        if (! $isInternal($route)) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            $status = $this->actingAs($this->client)->json($method, $uriFor($route))->getStatusCode();

            if ($status !== 403) {
                $failures[] = "{$method} /{$route->uri()} → {$status}";
            }
        }
    }

    expect($failures)->toBe([]);
});

it('las rutas que no son del portal ni internas son solo de cuenta, públicas o de administración cerrada', function () use ($isInternal) {
    $allowed = [
        // Cuenta propia (perfil, contraseña, 2FA, sesiones y apariencia) y autenticación.
        '#^ajustes#', '#^user/#', '#^login$#', '#^logout$#', '#^forgot-password$#', '#^reset-password#',
        '#^two-factor-challenge$#', '#^invitacion#',
        // Públicas o técnicas.
        '#^health$#', '#^up$#', '#^styleguide$#', '#^storage/#', '#^_inertia/#',
        // Logo de la empresa (D-067): público para los emails, sin sesión.
        '#^marca/logo/#',
        // Horizon: su propia puerta (solo admin fuera de local).
        '#^horizon#',
    ];
    $unexpected = [];

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        if ($isInternal($route) || in_array('portal', $middleware, true)) {
            continue;
        }

        $uri = ltrim($route->uri(), '/');
        $matches = array_filter($allowed, fn (string $pattern) => preg_match($pattern, $uri) === 1);
        if ($matches === []) {
            $unexpected[] = implode('|', $route->methods()).' /'.$uri;
        }
    }

    expect($unexpected)->toBe([]);
});

it('el cliente no entra en Horizon ni descarga ficheros sin firma', function () {
    $this->actingAs($this->client)->get('/horizon/api/stats')->assertForbidden();
    $this->actingAs($this->client)->get('/storage/attachments/1/x.pdf')->assertForbidden();
});
