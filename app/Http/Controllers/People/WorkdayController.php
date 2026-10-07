<?php

namespace App\Http\Controllers\People;

use App\Domain\People\PeopleAccess;
use App\Domain\People\WorkdayPresenter;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Mi jornada» (`/personas/jornada?mes=AAAA-MM&dia=AAAA-MM-DD`) y la jornada de una persona del
 * equipo (`/personas/equipo/{persona}`), PLAN-FASE-11 §6.1 y §6.2 (D-341): el diario del mes como
 * «Mi presencia» de Woffu, con el día abierto en un panel con su historial y sus correcciones. Las
 * filas del formulario de corrección de un día llegan en JSON (`/personas/equipo/{persona}/filas`).
 */
class WorkdayController extends Controller
{
    public function mine(Request $request, WorkdayPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('people/workday', $presenter->month($user, $user, $this->month($request), $this->day($request)));
    }

    public function show(Request $request, User $person, WorkdayPresenter $presenter): Response|RedirectResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        abort_unless(PeopleAccess::seesRegisterOf($viewer, $person), 403);

        if ($viewer->id === $person->id) {
            return redirect()->route('people.workday.index', $request->query());
        }

        return Inertia::render('people/workday', $presenter->month($person, $viewer, $this->month($request), $this->day($request)));
    }

    /** GET /personas/equipo/{persona}/filas?dia=AAAA-MM-DD: los fichajes efectivos del día. */
    public function rows(Request $request, User $person, WorkdayPresenter $presenter): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        abort_unless(PeopleAccess::seesRegisterOf($viewer, $person), 403);

        $day = $this->day($request) ?? LocalTime::todayString();

        return response()->json(['date' => $day, 'rows' => $presenter->rows($person, $day)]);
    }

    /** ?mes=AAAA-MM (este mes si falta o no es válido; nunca más allá de este mes). */
    private function month(Request $request): CarbonImmutable
    {
        $value = $request->query('mes');
        $current = LocalTime::today()->startOfMonth();

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            return $current;
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $value);

        if ($month === null || $month->format('Y-m') !== $value || $month->greaterThan($current)) {
            return $current;
        }

        return $month;
    }

    /** ?dia=AAAA-MM-DD válido y no futuro, o null. */
    private function day(Request $request): ?string
    {
        $value = $request->query('dia');

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== null && $date->toDateString() === $value && $value <= LocalTime::todayString() ? $value : null;
    }
}
