<?php

namespace App\Http\Controllers\Time;

use App\Domain\Time\ApprovalService;
use App\Domain\Time\Capacity;
use App\Domain\Time\Messages;
use App\Domain\Time\Week;
use App\Enums\TimesheetStatus;
use App\Http\Requests\Time\ApproveWeeksRequest;
use App\Http\Requests\Time\ReturnWeekRequest;
use App\Http\Resources\Time\Plain;
use App\Http\Resources\Time\TimesheetPeriodResource;
use App\Http\Resources\TimeEntryResource;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /horas/aprobaciones (gate approve-time, D-020, D-024, D-034): semanas enviadas de las personas
 * que supervisa quien revisa (un admin, todas: también las de personas sin departamento o de
 * departamentos sin responsables), con totales por día y detalle; aprobar, devolver con
 * comentario, aprobar varias y reabrir.
 */
class ApprovalController extends TimeController
{
    /**
     * Semanas pendientes que se muestran como máximo (las más antiguas primero).
     */
    public const int PENDING_LIMIT = 100;

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly Capacity $capacity,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $reviewer */
        $reviewer = $request->user();

        /** @var Collection<int, TimesheetPeriod> $pending */
        $pending = $this->scoped($reviewer)
            ->where('status', TimesheetStatus::Submitted->value)
            ->with('user.department')
            ->orderBy('week_start')
            ->orderBy('submitted_at')
            ->limit(self::PENDING_LIMIT)
            ->get();

        /** @var Collection<int, TimesheetPeriod> $history */
        $history = $this->scoped($reviewer)
            ->whereIn('status', [TimesheetStatus::Approved->value, TimesheetStatus::Returned->value, TimesheetStatus::Locked->value])
            ->whereNotNull('reviewed_at')
            ->with(['user', 'reviewer'])
            ->orderByDesc('reviewed_at')
            ->limit(20)
            ->get();

        $entries = $this->entriesFor($pending->concat($history));

        return Inertia::render('time/approvals', [
            'pending' => $pending->map(fn (TimesheetPeriod $period): array => $this->pendingWeek($period, $entries))->values()->all(),
            'history' => $history->map(fn (TimesheetPeriod $period): array => [
                'period' => Plain::of(new TimesheetPeriodResource($period)),
                'total' => (int) $this->weekEntries($entries, $period)->sum('minutes'),
                'can_reopen' => Gate::forUser($reviewer)->allows('reopen', $period),
            ])->values()->all(),
            'limit' => self::PENDING_LIMIT,
        ]);
    }

    /**
     * POST /horas/aprobaciones/{period}/aprobar
     */
    public function approve(Request $request, TimesheetPeriod $period): RedirectResponse
    {
        $this->authorize('review', $period);

        /** @var User $reviewer */
        $reviewer = $request->user();
        $period = $this->approvals->approve($reviewer, $period);

        $this->toast(Messages::get('time.flash.week_approved', ['name' => $period->user->name]));

        return back();
    }

    /**
     * POST /horas/aprobaciones/aprobar {periods: [id…]}: se comprueban todas antes de aprobar
     * ninguna; cada semana se aprueba en su propia transacción y avisa a su dueño.
     */
    public function approveMany(ApproveWeeksRequest $request): RedirectResponse
    {
        /** @var User $reviewer */
        $reviewer = $request->user();

        /** @var Collection<int, TimesheetPeriod> $periods */
        $periods = TimesheetPeriod::query()->with('user')->whereKey($request->periodIds())->orderBy('week_start')->get();

        foreach ($periods as $period) {
            $this->authorize('review', $period);

            if ($period->status !== TimesheetStatus::Submitted) {
                throw ValidationException::withMessages(['periods' => Messages::get('time.errors.week_not_submitted', [
                    'week' => $period->week_start->format('d/m/Y'),
                    'status' => mb_strtolower($period->status->label()),
                ])]);
            }
        }

        foreach ($periods as $period) {
            $this->approvals->approve($reviewer, $period);
        }

        $this->toast(Messages::choice('time.flash.weeks_approved', $periods->count()));

        return back();
    }

    /**
     * POST /horas/aprobaciones/{period}/devolver {comment}
     */
    public function sendBack(ReturnWeekRequest $request, TimesheetPeriod $period): RedirectResponse
    {
        $this->authorize('review', $period);

        /** @var User $reviewer */
        $reviewer = $request->user();
        $period = $this->approvals->sendBack($reviewer, $period, $request->string('comment')->toString());

        $this->toast(Messages::get('time.flash.week_returned', ['name' => $period->user->name]));

        return back();
    }

    /**
     * POST /horas/semanas/{period}/reabrir: aprobada (quien puede aprobarla o un admin) o
     * bloqueada (solo un admin).
     */
    public function reopen(Request $request, TimesheetPeriod $period): RedirectResponse
    {
        $this->authorize('reopen', $period);

        /** @var User $actor */
        $actor = $request->user();
        $this->approvals->reopen($actor, $period);

        $this->toast(Messages::get('time.flash.week_reopened'));

        return back();
    }

    /**
     * Semanas de las personas que $reviewer revisa (nunca las suyas: se aprueban solas).
     *
     * @return Builder<TimesheetPeriod>
     */
    private function scoped(User $reviewer): Builder
    {
        $query = TimesheetPeriod::query()->where('user_id', '!=', $reviewer->id);

        if ($reviewer->isAdmin()) {
            return $query;
        }

        return $query->whereIn('user_id', User::query()->select('id')->whereIn('department_id', $reviewer->managedDepartmentIds()));
    }

    /**
     * Entradas de todas esas semanas en una consulta (evita N+1).
     *
     * @param  \Illuminate\Support\Collection<int, TimesheetPeriod>  $periods
     * @return Collection<int, TimeEntry>
     */
    private function entriesFor(\Illuminate\Support\Collection $periods): Collection
    {
        if ($periods->isEmpty()) {
            return new Collection;
        }

        $from = $periods->min(fn (TimesheetPeriod $period): string => $period->week_start->toDateString());
        $to = $periods->max(fn (TimesheetPeriod $period): string => $period->weekEnd()->toDateString());

        return TimeEntry::query()
            ->whereIn('user_id', $periods->pluck('user_id')->unique()->values()->all())
            ->between((string) $from, (string) $to)
            ->with([
                'task:id,title,deleted_at',
                'project:id,code,name,color,deleted_at',
            ])
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     * @return Collection<int, TimeEntry>
     */
    private function weekEntries(Collection $entries, TimesheetPeriod $period): Collection
    {
        $week = Week::containing($period->week_start);

        return $entries->filter(fn (TimeEntry $entry): bool => $entry->user_id === $period->user_id && $week->contains($entry->date))->values();
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     * @return array<string, mixed>
     */
    private function pendingWeek(TimesheetPeriod $period, Collection $entries): array
    {
        $week = Week::containing($period->week_start);
        $weekEntries = $this->weekEntries($entries, $period);
        $days = array_fill_keys($week->days(), 0);

        foreach ($weekEntries as $entry) {
            $days[$entry->date->toDateString()] += $entry->minutes;
        }

        $capacity = $this->capacity->forRange($period->user, $week->start, $week->end());

        return [
            'period' => Plain::of(new TimesheetPeriodResource($period)),
            'department' => $period->user->department?->name,
            'days' => $days,
            'total' => array_sum($days),
            'capacity' => array_sum($capacity),
            'capacity_days' => $capacity,
            'billable' => (int) $weekEntries->where('is_billable', true)->sum('minutes'),
            'overage' => (int) $weekEntries->sum('overage_minutes'),
            'entries' => $weekEntries->map(fn (TimeEntry $entry): array => Plain::of(new TimeEntryResource($entry)))->all(),
        ];
    }
}
