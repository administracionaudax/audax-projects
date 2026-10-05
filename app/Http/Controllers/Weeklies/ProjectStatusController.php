<?php

namespace App\Http\Controllers\Weeklies;

use App\Domain\Weeklies\ProjectStatus\ProjectStatusBoard;
use App\Http\Controllers\Controller;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Estado de proyectos» con datos reales (D-148, F-064 y F-119 a F-121): la tercera pestaña de
 * /weeklies, con presupuesto, consumido, esperado y desviación por proyecto (ProjectStatusBoard). La
 * ve la plantilla (D-021 y D-151: minutos, sin importes); un colaborador externo no entra en la Weekly.
 * Los filtros (cliente y tipo), el orden y la vista (por cliente o tabla) son de la página.
 */
class ProjectStatusController extends Controller
{
    public function __invoke(ProjectStatusBoard $board): Response
    {
        Gate::authorize('use-weeklies');

        $today = CarbonImmutable::parse(LocalTime::today()->toDateString());

        return Inertia::render('weeklies/project-status', [
            'clients' => $board->build($today),
            'reference_date' => $today->toDateString(),
        ]);
    }
}
