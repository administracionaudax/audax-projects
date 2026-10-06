<?php

namespace App\Http\Controllers\DayPlan;

use App\Domain\DayPlan\DayPlanAccess;
use App\Domain\DayPlan\DayPlanReminders;
use App\Domain\DayPlan\TeamDay;
use App\Domain\DayPlan\TeamWeek;
use App\Domain\Time\Week;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Equipo hoy» (`/dia/equipo?fecha=&departamento=`) y la semana del equipo
 * (`/dia/semana?semana=&departamento=`), docs/PLAN-CARGAS.md §4.2 y §4.3 (D-251). Toda la plantilla
 * los ve (textos y checks, P1 a); las cifras de cada persona, solo ella, su responsable y los admins.
 * Sin ?departamento, el de quien mira (o todos si no tiene); `todos` = toda la plantilla.
 * «Recordar» (D-252): su responsable o un admin, a quien aún no ha escrito el plan de hoy.
 */
class TeamDayController extends DayPlanController
{
    public function team(Request $request, TeamDay $team): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return Inertia::render('day-plan/team', $team->for($viewer, $this->date($request), $this->department($request, $viewer)));
    }

    public function week(Request $request, TeamWeek $week): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $selected = Week::fromIso($request->string('semana')->toString()) ?? Week::current();

        return Inertia::render('day-plan/week', $week->for($viewer, $selected, $this->department($request, $viewer)));
    }

    /** POST /dia/equipo/{person}/recordar */
    public function remind(Request $request, User $person, DayPlanReminders $reminders): RedirectResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        abort_unless(DayPlanAccess::staff($person) && DayPlanAccess::reminds($viewer, $person), 403);

        $result = $reminders->remind($viewer, $person);
        $this->toast(__("day_plan.remind.{$result}", ['name' => $person->name]), $result === 'sent' ? 'success' : 'info');

        return back();
    }

    private function department(Request $request, User $viewer): ?int
    {
        $value = $request->query('departamento');

        if ($value === 'todos') {
            return null;
        }

        if (is_string($value) && ctype_digit($value) && Department::query()->whereKey((int) $value)->exists()) {
            return (int) $value;
        }

        return $viewer->department_id;
    }
}
