<?php

namespace App\Http\Controllers\Forecast;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

/**
 * Base de los controladores de la previsión (docs/PLAN-CARGAS.md, Nivel 2; D-280 a D-289). Sus rutas
 * van con `module:forecast` (routes/app/forecast.php); los permisos, con las gates view-forecast y
 * use-forecast y las políticas ForecastProjectPolicy, AllocationPolicy y ProjectPolicy.
 */
abstract class ForecastController extends Controller
{
    use AuthorizesRequests;

    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
