<?php

namespace App\Http\Controllers\DayPlan;

use App\Domain\DayPlan\DayPlanCalendar;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Base de los controladores del plan del día (docs/PLAN-CARGAS.md, D-250 a D-256). Sus rutas van con
 * `module:day_plan` y `can:use-day-plan` (routes/app/day-plan.php).
 */
abstract class DayPlanController extends Controller
{
    use AuthorizesRequests;

    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }

    /**
     * ?fecha=YYYY-MM-DD (hoy si falta o no es válida). Más allá del horizonte, el horizonte.
     */
    protected function date(Request $request, string $key = 'fecha'): CarbonImmutable
    {
        $value = $request->query($key);
        $today = DayPlanCalendar::today();

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return $today;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === null || $date->toDateString() !== $value) {
            return $today;
        }

        $horizon = DayPlanCalendar::horizonEnd($today);

        return $date > $horizon ? $horizon : $date;
    }
}
