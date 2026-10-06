<?php

namespace App\Http\Controllers\DayPlan;

use App\Domain\DayPlan\DayPlanTargets;
use App\Domain\DayPlan\MyDay;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Mi día» (`/dia?fecha=YYYY-MM-DD`, docs/PLAN-CARGAS.md §4.3, D-250): mis líneas de ese día con sus
 * cifras, las pendientes de días anteriores (solo hoy) y, en una petición aparte al pintar, el
 * catálogo de clientes y proyectos del selector de la línea.
 */
class MyDayController extends DayPlanController
{
    public function __invoke(Request $request, MyDay $day, DayPlanTargets $targets): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('day-plan/index', [
            'day' => $day->for($user, $this->date($request)),
            'targets' => Inertia::defer(fn (): array => $targets->for($user)),
        ]);
    }
}
