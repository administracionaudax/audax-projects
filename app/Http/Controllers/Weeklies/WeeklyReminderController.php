<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Models\WeeklyCycle;
use Illuminate\Support\Facades\Gate;

/**
 * Avisos de la weekly (F-101 a F-110): reglas y plantillas, envío manual con registro y
 * recordatorio a una persona pendiente. Esqueleto del contrato 10.1 (10.5).
 */
class WeeklyReminderController extends Controller
{
    use PendingDelivery;

    public function edit(): never
    {
        Gate::authorize('manage-weeklies');

        $this->pending('10.5');
    }

    public function update(): never
    {
        Gate::authorize('manage-weeklies');

        $this->pending('10.5');
    }

    /** Envío manual a personas concretas, a todas o a las pendientes (F-109). */
    public function send(): never
    {
        Gate::authorize('manage-weeklies');

        $this->pending('10.5');
    }

    /** Recordar a una persona pendiente de la semana activa (F-037 y F-110). */
    public function remind(WeeklyCycle $cycle): never
    {
        Gate::authorize('remind', $cycle);

        $this->pending('10.5');
    }
}
