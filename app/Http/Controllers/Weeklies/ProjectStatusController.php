<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use Illuminate\Support\Facades\Gate;

/**
 * «Estado de proyectos» con datos reales (D-148, F-119 a F-121): presupuesto, consumido, esperado y
 * desviación por proyecto. La ve la plantilla (D-021: el consumo de las bolsas en %, sin importes).
 * Esqueleto del contrato 10.1 (10.4).
 */
class ProjectStatusController extends Controller
{
    use PendingDelivery;

    public function __invoke(): never
    {
        Gate::authorize('use-weeklies');

        $this->pending('10.4');
    }
}
