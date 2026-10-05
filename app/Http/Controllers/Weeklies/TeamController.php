<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Equipo (F-134 a F-145): lista con el estado del reporte y ficha de persona (racha, hábitos,
 * historial y resúmenes IA). Los resúmenes IA de una persona, solo el admin y sus responsables
 * (view-person-ai-summary, D-147). Esqueleto del contrato 10.1 (10.4).
 */
class TeamController extends Controller
{
    use PendingDelivery;

    public function index(): never
    {
        Gate::authorize('use-weeklies');

        $this->pending('10.4');
    }

    public function show(User $user): never
    {
        Gate::authorize('use-weeklies');

        $this->pending('10.4');
    }

    /** Resumen de desempeño o actividad por cliente con IA (F-144 y F-145). */
    public function aiSummary(User $user): never
    {
        Gate::authorize('view-person-ai-summary', $user);

        $this->pending('10.4');
    }
}
