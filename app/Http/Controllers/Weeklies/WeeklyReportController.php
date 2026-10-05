<?php

namespace App\Http\Controllers\Weeklies;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Weeklies\Concerns\PendingDelivery;
use App\Models\WeeklyCycle;
use Illuminate\Support\Facades\Gate;

/**
 * Informe de la semana (F-072 a F-083): generar o regenerar (Job en la cola `ai`), editar, estado
 * para la espera y PDF. Esqueleto del contrato 10.1; lo completa 10.3.
 */
class WeeklyReportController extends Controller
{
    use PendingDelivery;

    /** Generar o regenerar el texto con IA (F-072). Encola el Job y vuelve enseguida. */
    public function store(WeeklyCycle $cycle): never
    {
        Gate::authorize('generate', $cycle);

        $this->pending('10.3');
    }

    /** Editar el informe por cliente (F-077). */
    public function update(WeeklyCycle $cycle): never
    {
        Gate::authorize('update', $cycle);

        $this->pending('10.3');
    }

    /** Estado del informe y del audio mientras se generan (report_state, audio_state). */
    public function status(WeeklyCycle $cycle): never
    {
        Gate::authorize('view', $cycle);

        $this->pending('10.3');
    }

    /** PDF e impresión con Gotenberg (F-083, D-140). */
    public function pdf(WeeklyCycle $cycle): never
    {
        Gate::authorize('view', $cycle);

        $this->pending('10.3');
    }
}
