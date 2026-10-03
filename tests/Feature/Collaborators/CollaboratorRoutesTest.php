<?php

use App\Domain\Access\CollaboratorAccess;
use App\Http\Middleware\EnsureInternalUser;
use App\Http\Middleware\RestrictCollaborators;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

/*
| Colaboradores externos (D-134): las rutas internas están CERRADAS por defecto. Se recorren TODAS
| las rutas registradas, como ClientIsolationTest con los clientes: cualquier ruta nueva de
| cualquier fase queda cubierta sin tocar este test. Solo las de config/collaborators.php se abren;
| en esas, la política y las consultas limitan lo que ve (CollaboratorScopeTest).
*/

beforeEach(function () {
    $this->collaborator = User::factory()->collaborator()->create();
    $this->forbidden = __('app.collaborator_forbidden');
});

/**
 * La URI con sus parámetros rellenos con ids que no existen (el middleware corta antes de buscarlos)
 * y que cumplen las restricciones de la ruta.
 */
$uriFor = function (Route $route): string {
    $uri = (string) preg_replace_callback('/\{([^}?]+)\??\}/', function (array $match) use ($route): string {
        $pattern = $route->wheres[$match[1]] ?? null;

        return is_string($pattern) && str_contains($pattern, 'a-f') ? '00000000-0000-4000-8000-000000000000' : '999999';
    }, $route->uri());

    return '/'.ltrim($uri, '/');
};

$gathers = fn (Route $route, string $alias, string $class): bool => in_array($alias, $route->gatherMiddleware(), true)
    || in_array($class, $route->gatherMiddleware(), true);

$restricted = fn (Route $route): bool => $gathers($route, 'collaborator', RestrictCollaborators::class);

$methods = fn (Route $route): array => array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));

it('toda ruta interna pasa por el middleware de los colaboradores', function () use ($gathers, $restricted) {
    $missing = [];

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        if ($gathers($route, 'internal', EnsureInternalUser::class) && ! $restricted($route)) {
            $missing[] = implode('|', $route->methods()).' /'.$route->uri();
        }
    }

    expect($missing)->toBe([]);
});

it('en JSON, toda ruta interna fuera de la lista responde 403 al colaborador y no ejecuta nada', function () use ($uriFor, $restricted, $methods) {
    $failures = [];
    $closed = 0;
    $open = 0;

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        if (! $restricted($route)) {
            continue;
        }

        $allowed = CollaboratorAccess::allows($route);

        foreach ($methods($route) as $method) {
            $response = $this->actingAs($this->collaborator)->json($method, $uriFor($route));
            $blocked = $response->getStatusCode() === 403 && $response->json('message') === $this->forbidden;

            if ($allowed) {
                $open++;
                if ($blocked || $response->getStatusCode() >= 500) {
                    $failures[] = "permitida {$method} /{$route->uri()} → {$response->getStatusCode()}";
                }
            } else {
                $closed++;
                if (! $blocked) {
                    $failures[] = "cerrada {$method} /{$route->uri()} → {$response->getStatusCode()}";
                }
            }
        }
    }

    expect($closed)->toBeGreaterThan(100)
        ->and($open)->toBeGreaterThan(50)
        ->and($failures)->toBe([]);
});

it('sin JSON, toda ruta interna fuera de la lista responde 403 al colaborador', function () use ($uriFor, $restricted, $methods) {
    $failures = [];

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        if (! $restricted($route) || CollaboratorAccess::allows($route)) {
            continue;
        }

        foreach ($methods($route) as $method) {
            $status = $this->actingAs($this->collaborator)->call($method, $uriFor($route))->getStatusCode();

            if ($status !== 403) {
                $failures[] = "{$method} /{$route->uri()} → {$status}";
            }
        }
    }

    expect($failures)->toBe([]);
});

it('las áreas que no son suyas quedan cerradas: clientes, bolsas, informes, carga, Gantt global, ausencias, plantillas y administración', function () use ($restricted) {
    $closed = [
        'clients.index', 'clients.show', 'hour-banks.index', 'reports.index', 'reports.detail', 'workload.index',
        'gantt.index', 'absences.index', 'absences.team.index', 'templates.index', 'recurring.index', 'admin.index',
        'admin.users.index', 'admin.audit.index', 'admin.settings.edit', 'time.approvals.index', 'time.locks.index',
        'time.week.reopen', 'projects.time', 'projects.time.export', 'projects.hour-banks.index', 'projects.settings',
        'projects.create', 'projects.store', 'projects.update', 'projects.members.store', 'projects.owner.update',
        'projects.portal.update', 'clients.portal.users.store', 'chat.direct.store', 'chat.groups.store', 'chat.people',
        'chat.moderation', 'chat.messages.moderate', 'workload.tasks.update',
    ];

    foreach ($closed as $name) {
        $route = RouteFacade::getRoutes()->getByName($name);

        expect($route)->not->toBeNull("La ruta {$name} no existe")
            ->and($restricted($route))->toBeTrue("La ruta {$name} no pasa por el middleware")
            ->and(CollaboratorAccess::allows($route))->toBeFalse("La ruta {$name} está abierta al colaborador");
    }
});

it('cada patrón de la lista abre al menos una ruta registrada (sin entradas caducadas)', function () {
    $names = array_values(array_filter(array_map(fn (Route $route): ?string => $route->getName(), RouteFacade::getRoutes()->getRoutes())));
    $uris = array_map(fn (Route $route): string => ltrim($route->uri(), '/'), RouteFacade::getRoutes()->getRoutes());

    foreach (config('collaborators.routes') as $pattern) {
        expect(array_filter($names, fn (string $name): bool => Str::is($pattern, $name)))->not->toBeEmpty("El patrón {$pattern} no abre ninguna ruta");
    }

    foreach (config('collaborators.uris') as $uri) {
        expect($uris)->toContain($uri);
    }
});

it('al resto de internos el middleware no les cierra nada', function () use ($uriFor, $restricted) {
    $employee = User::factory()->employee()->create();
    $failures = [];

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        if (! $restricted($route) || CollaboratorAccess::allows($route) || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $response = $this->actingAs($employee)->getJson($uriFor($route));

        if ($response->getStatusCode() === 403 && $response->json('message') === $this->forbidden) {
            $failures[] = "/{$route->uri()}";
        }
    }

    expect($failures)->toBe([]);
});
