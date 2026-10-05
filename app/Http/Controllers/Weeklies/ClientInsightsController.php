<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Models\Client;
use Illuminate\Support\Facades\Gate;

/**
 * Ficha de cliente de la Weekly (F-129 a F-132): resumen IA del cliente y análisis IA de la actividad
 * del equipo. Las pestañas Historial y Satisfacción se sirven en la ficha /clientes/{client}.
 * Esqueleto del contrato 10.1 (10.4).
 */
class ClientInsightsController extends Controller
{
    use PendingDelivery;

    public function summary(Client $client): never
    {
        Gate::authorize('view', $client);
        Gate::authorize('use-weeklies');

        $this->pending('10.4');
    }

    public function teamActivity(Client $client): never
    {
        Gate::authorize('view', $client);
        Gate::authorize('use-weeklies');

        $this->pending('10.4');
    }
}
