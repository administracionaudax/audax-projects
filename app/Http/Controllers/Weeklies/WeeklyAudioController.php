<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Models\WeeklyAudioSection;
use App\Models\WeeklyCycle;
use Illuminate\Support\Facades\Gate;

/**
 * Audio del informe (F-084 a F-087): generar por secciones (Job en la cola `ai`), servir cada
 * sección con URL firmada y descargar el audio completo. Esqueleto del contrato 10.1 (10.3).
 */
class WeeklyAudioController extends Controller
{
    use PendingDelivery;

    public function store(WeeklyCycle $cycle): never
    {
        Gate::authorize('generate', $cycle);

        $this->pending('10.3');
    }

    public function show(WeeklyCycle $cycle, WeeklyAudioSection $section): never
    {
        Gate::authorize('view', $cycle);
        abort_unless($section->weekly_cycle_id === $cycle->id, 404);

        $this->pending('10.3');
    }

    public function download(WeeklyCycle $cycle): never
    {
        Gate::authorize('view', $cycle);

        $this->pending('10.3');
    }
}
