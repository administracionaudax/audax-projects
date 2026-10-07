<?php

namespace App\Http\Controllers\People;

use App\Domain\People\PeopleAccess;
use App\Domain\People\TeamWorkday;
use App\Domain\People\WorkdayPresenter;
use App\Domain\Time\Week;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Jornada del equipo» (`/personas/equipo?semana=AAAA-Www&departamento=`) y «Pendientes»
 * (`/personas/pendientes`), PLAN-FASE-11 §6.2 (D-341): para los responsables (su departamento) y
 * RR. HH. (toda la plantilla). La bandeja junta, como el panel de Woffu (W-081), las correcciones
 * que esperan su decisión, para aceptarlas una a una o en bloque.
 */
class TeamWorkdayController extends Controller
{
    public function index(Request $request, TeamWorkday $team): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $week = Week::fromIso($request->string('semana')->toString()) ?? Week::current();

        return Inertia::render('people/team', $team->for($viewer, $week, $this->department($request, $viewer)));
    }

    public function pending(Request $request, TeamWorkday $team, WorkdayPresenter $presenter): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('people/pending', [
            'corrections' => $presenter->list($team->pendingCorrections($viewer), $viewer),
            'manages_all' => PeopleAccess::managesAll($viewer),
        ]);
    }

    private function department(Request $request, User $viewer): ?int
    {
        $value = $request->query('departamento');

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $id = (int) $value;

        return PeopleAccess::departments($viewer)->contains('id', $id) ? $id : null;
    }
}
