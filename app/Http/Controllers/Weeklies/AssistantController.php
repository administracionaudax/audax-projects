<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asistente IA (/ia, F-006, F-146 y F-147): preguntas sobre weeklies, clientes, tareas y estado de
 * proyectos con SOLO los datos que puede ver quien pregunta (D-146). Esqueleto del contrato 10.1
 * (10.6): la página existe; preguntar responde 501.
 */
class AssistantController extends Controller
{
    use PendingDelivery;

    public function index(): Response
    {
        Gate::authorize('use-weeklies');

        return Inertia::render('assistant/index');
    }

    public function ask(): never
    {
        Gate::authorize('use-weeklies');

        $this->pending('10.6');
    }
}
