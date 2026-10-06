<?php

namespace App\Http\Controllers\Forecast;

use App\Domain\Forecast\ForecastPeriod;
use App\Domain\Forecast\LoadCombiner;
use App\Models\Department;
use App\Models\ForecastProject;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La previsión del equipo (`/prevision`, docs/PLAN-CARGAS.md §5.3, D-285): capacidad frente a
 * asignado por departamento y por persona, por semanas o por meses, en tres capas (real, segura y
 * posible). Parámetros: ?desde=YYYY-MM-DD, ?meses=1..12 (3), ?por=semanas|meses, ?departamento=id.
 *
 * Y «mi carga» (`/prevision/mi-carga`, JSON, P8 b): mis asignaciones de proyectos reales y de
 * previstos, también los posibles, por semanas; para cualquier persona de la plantilla.
 */
class ForecastBoardController extends ForecastController
{
    /** GET /prevision */
    public function index(Request $request, LoadCombiner $combiner): Response
    {
        Gate::authorize('view-forecast');

        /** @var User $user */
        $user = $request->user();
        $period = $this->period($request);
        $departmentId = $request->query('departamento');
        $departmentId = is_string($departmentId) && ctype_digit($departmentId) && Department::query()->whereKey((int) $departmentId)->exists()
            ? (int) $departmentId
            : null;

        return Inertia::render('forecast/index', [
            'board' => $combiner->board($period, $departmentId === null ? [] : ['department_ids' => [$departmentId]]),
            'filters' => [
                'from' => $period->from->toDateString(),
                'months' => $this->months($request),
                'granularity' => $period->granularity,
                'department_id' => $departmentId,
            ],
            'departments' => array_values(Department::query()->orderBy('name')->get(['id', 'name', 'color'])
                ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name, 'color' => $department->color])->all()),
            'can' => ['manage' => $user->can('create', ForecastProject::class)],
        ]);
    }

    /** GET /prevision/mi-carga (JSON) */
    public function mine(Request $request, LoadCombiner $combiner): JsonResponse
    {
        Gate::authorize('use-forecast');

        /** @var User $user */
        $user = $request->user();
        $request->query->set('por', $request->query('por', 'semanas'));

        return response()->json($combiner->board($this->period($request), ['user_ids' => [$user->id]]));
    }

    private function period(Request $request): ForecastPeriod
    {
        $from = $request->query('desde');
        $date = is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $from) : null;
        $date = $date !== null && $date->toDateString() === $from ? $date : LocalTime::today();

        return ForecastPeriod::make($date, $this->months($request), $request->query('por') === 'semanas' ? ForecastPeriod::WEEK : ForecastPeriod::MONTH);
    }

    private function months(Request $request): int
    {
        $months = $request->query('meses');

        return is_string($months) && ctype_digit($months)
            ? max(ForecastPeriod::MIN_MONTHS, min(ForecastPeriod::MAX_MONTHS, (int) $months))
            : ForecastPeriod::DEFAULT_MONTHS;
    }
}
