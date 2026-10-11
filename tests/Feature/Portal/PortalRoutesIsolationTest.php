<?php

use App\Domain\Tasks\AttachmentStorage;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\TaskStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Storage;

/*
| Aislamiento de TODAS las rutas del portal (SPEC §17, aceptación de la Fase 5), como
| ClientIsolationTest con las internas: se recorren las rutas registradas con el middleware portal.
| - Con los ids de OTRO cliente (con todo abierto al portal), su usuario recibe 404 en cada ruta con
|   parámetros: nunca un 403 que revele que existe. Con los suyos, la misma ruta responde (control).
| - Un interno recibe 403 en todas.
| Una ruta nueva del portal con un parámetro que este test no conoce FALLA: hay que añadir aquí cómo
| conseguir un id de otro cliente para ese parámetro (y protegerla con PortalScope).
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    // Las facturas propias (E2, D-443): la emisión encendida, con la serie F ya empezada.
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    enableInvoicing();
    invoicingIssuer();
    $admin = userWithRole('admin');

    // Lo propio y lo de otro cliente, abierto del todo al portal: lo único que cambia es el cliente.
    $world = function (Client $client) use ($admin): array {
        $project = Project::factory()->hourBank()->create([
            'client_id' => $client->id,
            'portal_project_visible' => true,
            'portal_show_task_hours' => true,
            'portal_gantt_visible' => true,
        ]);
        $bank = HourBank::factory()->create(['project_id' => $project->id, 'total_minutes' => 600]);

        // Su factura emitida, con las facturas abiertas al portal y un adjunto visible.
        $client->billingProfile()->update(['portal_invoices' => true]);
        $document = invoicingIssue($admin, $client);
        $attachment = app(AttachmentStorage::class)->store(UploadedFile::fake()->create('contrato.pdf', 10, 'application/pdf'), $document, null, $admin);
        $attachment->forceFill(['client_visible' => true])->save();

        return ['project' => $project->id, 'bank' => $bank->id, 'document' => $document->id, 'attachment' => $attachment->id];
    };

    $own = invoicingClient();
    $this->portal = User::factory()->portalOf($own)->create();
    $this->own = $world($own);
    $this->foreign = $world(invoicingClient());

    $this->routes = array_values(array_filter(
        RouteFacade::getRoutes()->getRoutes(),
        fn (Route $route): bool => in_array('portal', $route->gatherMiddleware(), true),
    ));

    /** La URI con cada parámetro relleno con el id de $ids; null si hay un parámetro desconocido. */
    $this->uri = function (Route $route, array $ids): ?string {
        $unknown = false;
        $uri = (string) preg_replace_callback('/\{([^}?]+)\??\}/', function (array $match) use ($ids, &$unknown): string {
            if (! isset($ids[$match[1]])) {
                $unknown = true;

                return '0';
            }

            return (string) $ids[$match[1]];
        }, $route->uri());

        return $unknown ? null : '/'.ltrim($uri, '/');
    };
    $this->methods = fn (Route $route): array => array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
});

it('cada ruta del portal con ids da 404 con los de otro cliente y responde con los propios', function () {
    $failures = [];
    $checked = 0;

    foreach ($this->routes as $route) {
        if ($route->parameterNames() === []) {
            continue;
        }

        $foreignUri = ($this->uri)($route, $this->foreign);
        $ownUri = ($this->uri)($route, $this->own);
        if ($foreignUri === null || $ownUri === null) {
            $failures[] = "/{$route->uri()}: parámetro sin id de otro cliente en este test (".implode(', ', $route->parameterNames()).')';

            continue;
        }

        foreach (($this->methods)($route) as $method) {
            $checked++;
            $status = $this->actingAs($this->portal)->call($method, $foreignUri)->getStatusCode();
            if ($status !== 404) {
                $failures[] = "{$method} {$foreignUri} (otro cliente) → {$status}";
            }

            // Control: con sus propios ids la ruta sí responde (el 404 no es por otra cosa).
            if ($method === 'GET') {
                $status = $this->actingAs($this->portal)->get($ownUri)->getStatusCode();
                if ($status !== 200) {
                    $failures[] = "GET {$ownUri} (propio) → {$status}";
                }
            }
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(4)
        ->and($failures)->toBe([]);
});

it('los internos reciben 403 en todas las rutas del portal', function () {
    $failures = [];
    $internals = [];
    foreach (['admin', 'department_manager', 'employee'] as $role) {
        $internals[$role] = userWithRole($role);
    }

    foreach ($this->routes as $route) {
        $uri = ($this->uri)($route, $this->own) ?? '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/');

        foreach (($this->methods)($route) as $method) {
            foreach ($internals as $role => $internal) {
                $status = $this->actingAs($internal)->call($method, $uri)->getStatusCode();
                if ($status !== 403) {
                    $failures[] = "{$method} {$uri} ({$role}) → {$status}";
                }
            }
        }
    }

    expect(count($this->routes))->toBeGreaterThanOrEqual(5)
        ->and($failures)->toBe([]);
});
