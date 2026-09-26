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
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $entries = $this->entriesFor($pending)
            ->groupBy(fn (TimeEntry $entry): string => $this->weekKey($entry->user_id, $entry->date));
        // Capacidad de todas las semanas pendientes con una sola consulta (en el mismo orden).
        $capacities = $this->capacity->forRanges(array_values($pending->map(fn (TimesheetPeriod $period): array => [
            'user_id' => $period->user_id,
            'from' => $period->week_start,
            'to' => $period->weekEnd(),
        ])->all()));
        $historyTotals = $this->weekTotals($history);
        $isAdmin = $reviewer->isAdmin();

        return Inertia::render('time/approvals', [
            'pending' => $pending->values()->map(fn (TimesheetPeriod $period, int $index): array => $this->pendingWeek(
                $period,
                $entries->get($this->weekKey($period->user_id, $period->week_start)) ?? new Collection,
                $capacities[$index],
            ))->all(),
            'history' => $history->map(fn (TimesheetPeriod $period): array => [
                'period' => Plain::of(new TimesheetPeriodResource($period)),
                'total' => $historyTotals[$this->weekKey($period->user_id, $period->week_start)] ?? 0,
                // Igual que TimesheetPeriodPolicy::reopen, sin una consulta por fila: scoped() ya
                // limita a las personas que supervisa (o a todas, si es admin).
                'can_reopen' => match ($period->status) {
                    TimesheetStatus::Approved => true,
                    TimesheetStatus::Locked => $isAdmin,
                    default => false,
                },
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
     * Entradas de esas semanas (y solo de esas: nada de los días entre una y otra) en una consulta.
     *
     * @param  Collection<int, TimesheetPeriod>  $periods
     * @return Collection<int, TimeEntry>
     */
    private function entriesFor(Collection $periods): Collection
    {
        if ($periods->isEmpty()) {
            return new Collection;
        }

        return TimeEntry::query()
            ->where(fn (Builder $query) => $this->inWeeks($query, $periods))
            ->with([
                'task:id,title,deleted_at',
                'project:id,code,name,color,deleted_at',
            ])
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Minutos de cada semana del histórico, sumados en la base de datos (una consulta, sin cargar
     * las entradas).
     *
     * @param  Collection<int, TimesheetPeriod>  $periods
     * @return array<string, int> weekKey → minutos
     */
    private function weekTotals(Collection $periods): array
    {
        if ($periods->isEmpty()) {
            return [];
        }

        $rows = TimeEntry::query()
            ->where(fn (Builder $query) => $this->inWeeks($query, $periods))
            ->select(['time_entries.user_id', 'time_entries.date'])
            ->selectRaw('COALESCE(SUM(time_entries.minutes), 0) as total')
            ->groupBy('time_entries.user_id', 'time_entries.date')
            ->toBase()
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $key = $this->weekKey((int) $row['user_id'], CarbonImmutable::parse((string) $row['date']));
            $totals[$key] = ($totals[$key] ?? 0) + (int) $row['total'];
        }

        return $totals;
    }

    /**
     * Filtro por pares (persona, semana): una condición por persona con los rangos de sus semanas.
     *
     * @param  Builder<TimeEntry>  $query
     * @param  Collection<int, TimesheetPeriod>  $periods
     */
    private function inWeeks(Builder $query, Collection $periods): void
    {
        foreach ($periods->groupBy('user_id') as $userId => $userPeriods) {
            $query->orWhere(function (Builder $person) use ($userId, $userPeriods): void {
                $person->where('time_entries.user_id', (int) $userId)
                    ->where(function (Builder $weeks) use ($userPeriods): void {
                        foreach ($userPeriods as $period) {
                            $weeks->orWhereBetween('time_entries.date', [$period->week_start->toDateString(), $period->weekEnd()->toDateString()]);
                        }
                    });
            });
        }
    }

    private function weekKey(int $userId, CarbonInterface $date): string
    {
        return $userId.'|'.Week::containing($date)->startString();
    }

    /**
     * @param  Collection<int, TimeEntry>  $weekEntries  Las de esa semana y persona.
     * @param  array<string, int>  $capacity
     * @return array<string, mixed>
     */
    private function pendingWeek(TimesheetPeriod $period, Collection $weekEntries, array $capacity): array
    {
        $week = Week::containing($period->week_start);
        $days = array_fill_keys($week->days(), 0);

        foreach ($weekEntries as $entry) {
            $days[$entry->date->toDateString()] += $entry->minutes;
        }

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
