<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use Illuminate\Support\Facades\Gate;

/**
 * Exenciones (F-038, F-053 y F-098): eximir a alguien, renunciar a la propia exención por ausencia
 * y quitar una exención. Esqueleto del contrato 10.1 (10.2).
 */
class WeeklyExemptionController extends Controller
{
    use PendingDelivery;

    public function store(WeeklyCycle $cycle): never
    {
        Gate::authorize('create', [WeeklyExemption::class, $cycle]);

        $this->pending('10.2');
    }

    public function waive(WeeklyCycle $cycle): never
    {
        Gate::authorize('waive', [WeeklyExemption::class, $cycle]);

        $this->pending('10.2');
    }

    public function destroy(WeeklyCycle $cycle, WeeklyExemption $exemption): never
    {
        abort_unless($exemption->weekly_cycle_id === $cycle->id, 404);
        Gate::authorize('delete', $exemption);

        $this->pending('10.2');
    }
}
