<?php

namespace App\Http\Controllers\Forecast;

use App\Domain\Forecast\ForecastBoardView;
use App\Domain\Forecast\ForecastPeriod;
use App\Domain\Forecast\ForecastPresenter;
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
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
    public function index(Request $request, LoadCombiner $combiner, ForecastBoardView $view, ForecastPresenter $presenter): Response|SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Quien usa la previsión pero no ve la global (un empleado, P4): el 403 dice por qué y le
        // lleva a su carga (D-306).
        if (! $user->can('view-forecast')) {
            Gate::authorize('use-forecast');

            return Inertia::render('error', ['status' => 403, 'reason' => 'forecast'])
                ->toResponse($request)
                ->setStatusCode(403);
        }
        $period = $this->period($request);
        $departmentId = $request->query('departamento');
        $departmentId = is_string($departmentId) && ctype_digit($departmentId) && Department::query()->whereKey((int) $departmentId)->exists()
            ? (int) $departmentId
            : null;

        return Inertia::render('forecast/index', [
            'board' => ForecastBoardView::redact($combiner->board($period, $departmentId === null ? [] : ['department_ids' => [$departmentId]]), $user),
            'filters' => [
                'from' => $period->from->toDateString(),
                'months' => $this->months($request),
                'granularity' => $period->granularity,
                'department_id' => $departmentId,
            ],
            'departments' => array_values(Department::query()->orderBy('name')->get(['id', 'name', 'color'])
                ->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name, 'color' => $department->color])->all()),
            'can' => ['manage' => $user->can('create', ForecastProject::class)],
            // Debajo de la matriz (D-302): los huecos sin persona y los previstos abiertos del periodo.
            'gaps' => Inertia::defer(fn (): array => $view->gaps($period, $user, $departmentId), 'lists'),
            'open_forecasts' => Inertia::defer(fn (): array => array_values(array_filter(
                $presenter->list('active'),
                fn (array $forecast): bool => ($forecast['start_date'] ?? null) === null
                    || (($forecast['start_date'] <= $period->to->toDateString()) && (($forecast['end_date'] ?? null) === null || $forecast['end_date'] >= $period->from->toDateString())),
            )), 'lists'),
        ]);
    }

    /**
     * GET /prevision/disponibilidad?desde=&hasta= (JSON): la carga de cada persona asignable en esas
     * fechas, para «Asignar a…» (D-303). Quien ve la previsión.
     */
    public function availability(Request $request, ForecastBoardView $view): JsonResponse
    {
        Gate::authorize('view-forecast');

        $from = self::date($request->query('desde')) ?? LocalTime::today();
        $to = self::date($request->query('hasta')) ?? $from->addMonthsNoOverflow(1);

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        // Como mucho un año: es para un selector, no un informe.
        $to = $to->min($from->addYear());

        return response()->json(['people' => $view->availability($from, $to)]);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== null && $date->toDateString() === $value ? $date : null;
    }

    /** GET /prevision/mi-carga (JSON) */
    public function mine(Request $request, LoadCombiner $combiner): JsonResponse
    {
        Gate::authorize('use-forecast');

        /** @var User $user */
        $user = $request->user();
        $request->query->set('por', $request->query('por', 'semanas'));

        return response()->json(ForecastBoardView::redact($combiner->board($this->period($request), ['user_ids' => [$user->id]]), $user));
    }

    private function period(Request $request): ForecastPeriod
    {
        $from = $request->query('desde');
        $date = is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $from) : null;
        $date = $date !== null && $date->toDateString() === $from ? $date : LocalTime::today();

        // Por semanas hasta 3 meses y por meses desde 6, salvo que se pida otra cosa (D-294).
        $months = $this->months($request);
        $granularity = match ($request->query('por')) {
            'semanas' => ForecastPeriod::WEEK,
            'meses' => ForecastPeriod::MONTH,
            default => $months <= 3 ? ForecastPeriod::WEEK : ForecastPeriod::MONTH,
        };

        return ForecastPeriod::make($date, $months, $granularity);
    }

    private function months(Request $request): int
    {
        $months = $request->query('meses');

        return is_string($months) && ctype_digit($months)
            ? max(ForecastPeriod::MIN_MONTHS, min(ForecastPeriod::MAX_MONTHS, (int) $months))
            : ForecastPeriod::DEFAULT_MONTHS;
    }
}
