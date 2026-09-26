<?php

namespace App\Http\Controllers\Time;

use App\Domain\Time\ApprovalService;
use App\Domain\Time\Messages;
use App\Domain\Time\TimesheetService;
use App\Domain\Time\Week;
use App\Enums\TimesheetStatus;
use App\Http\Requests\Time\WeekRequest;
use App\Http\Resources\Time\LoggableTaskResource;
use App\Http\Resources\Time\Plain;
use App\Http\Resources\Time\TimesheetPeriodResource;
use App\Http\Resources\TimeEntryResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hoja semanal /horas?semana=2026-W39[&persona=12] (SPEC §7, D-020, D-021, D-036), y enviar o
 * retirar la semana propia.
 */
class TimesheetController extends TimeController
{
    public function __construct(
        private readonly TimesheetService $sheets,
        private readonly ApprovalService $approvals,
    ) {}

    public function show(Request $request): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $week = Week::fromIso($request->string('semana')->toString()) ?? Week::current();
        $owner = $this->owner($request, $viewer);

        $period = TimesheetPeriod::query()->with('reviewer')->firstOrNew(
            ['user_id' => $owner->id, 'week_start' => $week->startString()],
            ['status' => TimesheetStatus::Open],
        );
        $period->setRelation('user', $owner);

        abort_unless($this->sheets->canView($viewer, $owner, $week, $period), 403, Messages::get('time.errors.cannot_view_hours'));

        $entries = $this->sheets->entries($viewer, $owner, $week);
        $gate = Gate::forUser($viewer);
        $isOwn = $viewer->id === $owner->id;
        // Hoja completa o solo con las entradas de los proyectos que gestiona (D-021).
        $scope = $isOwn || $gate->allows('view', $period) ? 'full' : 'managed_projects';
        $periodData = Plain::of(new TimesheetPeriodResource($period));

        if ($scope === 'managed_projects') {
            // El comentario de quien devuelve la semana habla de toda ella, también de horas de
            // otros proyectos que un gestor no ve (D-021): solo ve el estado.
            $periodData['review_comment'] = null;
        }

        return Inertia::render('time/index', [
            'week' => [
                'iso' => $week->iso(),
                'start' => $week->startString(),
                'end' => $week->endString(),
                'days' => $week->days(),
                'previous' => $week->previous()->iso(),
                'next' => $week->next()->iso(),
                'current' => Week::current()->iso(),
            ],
            'person' => Plain::of(new UserSummaryResource($owner)),
            'is_own' => $isOwn,
            // Sin contar a quien mira: si no ve a nadie más, la página no muestra el selector.
            'people' => Plain::of(UserSummaryResource::collection($this->sheets->visiblePeople($viewer))),
            'scope' => $scope,
            'period' => $periodData,
            'rows' => $this->rows($entries, $week),
            'totals' => $this->sheets->totals($entries, $week),
            'capacity' => $this->sheets->capacity($owner, $week),
            'previous_week_tasks' => Plain::of(LoggableTaskResource::collection($this->sheets->previousWeekTasks($viewer, $owner, $week))),
            'can' => [
                'edit' => $period->isEditable(),
                'submit' => $isOwn && $gate->allows('submit', $period) && $week->startString() <= LocalTime::todayString(),
                'withdraw' => $gate->allows('withdraw', $period),
                'review' => $period->status === TimesheetStatus::Submitted && $gate->allows('review', $period),
                'reopen' => $period->exists && $gate->allows('reopen', $period),
            ],
            'settings' => [
                'today' => LocalTime::todayString(),
                'allow_future' => (bool) Setting::get('allow_future_time_entries', false),
            ],
        ]);
    }

    /**
     * POST /horas/semana/enviar {week}: la envía su dueño (también sin horas). Responsables,
     * admins o sin aprobación obligatoria: queda aprobada en el acto (D-020).
     */
    public function submit(WeekRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $period = $this->approvals->submit($user, $user, $request->week());

        $this->toast(Messages::get($period->status === TimesheetStatus::Approved ? 'time.flash.week_auto_approved' : 'time.flash.week_submitted'));

        return back();
    }

    /**
     * POST /horas/semana/retirar {week}: el dueño la retira mientras nadie la ha revisado (D-034).
     */
    public function withdraw(WeekRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var TimesheetPeriod $period */
        $period = TimesheetPeriod::query()
            ->where('user_id', $user->id)
            ->where('week_start', $request->week()->startString())
            ->firstOrFail();

        $this->approvals->withdraw($user, $period);
        $this->toast(Messages::get('time.flash.week_withdrawn'));

        return back();
    }

    private function owner(Request $request, User $viewer): User
    {
        if (! $request->filled('persona') || $request->integer('persona') === $viewer->id) {
            return $viewer;
        }

        /** @var User $owner */
        $owner = User::query()->internal()->findOrFail($request->integer('persona'));

        return $owner;
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     * @return list<array{task: array<string, mixed>, cells: list<list<array<string, mixed>>>, total: int}>
     */
    private function rows(Collection $entries, Week $week): array
    {
        $rows = [];

        foreach ($this->sheets->rows($entries, $week) as $row) {
            $task = $row['task'];
            $task->setRelation('project', $row['project']);

            $rows[] = [
                'task' => Plain::of(new LoggableTaskResource($task)),
                'cells' => array_map(
                    fn (array $cell): array => array_map(fn (TimeEntry $entry): array => Plain::of(new TimeEntryResource($entry)), $cell),
                    $row['cells'],
                ),
                'total' => $row['total'],
            ];
        }

        return $rows;
    }
}
