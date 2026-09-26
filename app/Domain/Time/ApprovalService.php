<?php

namespace App\Domain\Time;

use App\Enums\Role;
use App\Enums\TimeEntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Notifications\Time\TimesheetApproved;
use App\Notifications\Time\TimesheetReturned;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Flujo de la semana (SPEC §7, D-020, D-034): enviar, aprobar, devolver, retirar y reabrir.
 *
 * - Cada paso va en una transacción con la semana bloqueada (lockForUpdate) y lo autoriza
 *   TimesheetPeriodPolicy.
 * - Las entradas cambian de estado una a una con Eloquent: cada cambio queda en la auditoría de
 *   TimeEntry (antes/después). Además, cada paso deja un registro resumido en la semana.
 * - Al aprobar se congelan la tarifa (RateResolver) y el coste; reabrir los borra. Las entradas
 *   bloqueadas nunca cambian.
 * - Se aprueban solas (sin revisor) las semanas de responsables y administradores, y todas si el
 *   ajuste require_timesheet_approval está desactivado.
 */
final class ApprovalService
{
    public function __construct(
        private readonly RateResolver $rates,
    ) {}

    /**
     * ¿La semana de $owner necesita que la revise otra persona?
     */
    public function requiresReview(User $owner): bool
    {
        return (bool) Setting::get('require_timesheet_approval', true)
            && ! $owner->hasAnyRole([Role::Admin->value, Role::DepartmentManager->value]);
    }

    /**
     * Envía la semana de $owner (también sin entradas). Devuelve la semana ya enviada o, si se
     * aprueba sola, aprobada.
     *
     * @throws ValidationException
     */
    public function submit(User $actor, User $owner, Week $week): TimesheetPeriod
    {
        if ($week->startString() > LocalTime::todayString()) {
            throw ValidationException::withMessages(['week' => __('time.errors.week_future')]);
        }

        return DB::transaction(function () use ($actor, $owner, $week): TimesheetPeriod {
            $period = $this->lockPeriod($owner, $week);
            Gate::forUser($actor)->authorize('submit', $period);

            $count = 0;
            foreach ($this->entries($period, [TimeEntryStatus::Draft]) as $entry) {
                $entry->status = TimeEntryStatus::Submitted;
                $entry->save();
                $count++;
            }

            $period->fill([
                'status' => TimesheetStatus::Submitted,
                'submitted_at' => now(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_comment' => null,
            ])->save();

            $this->log($period, $actor, 'submitted', ['entries' => $count]);

            if (! $this->requiresReview($owner)) {
                $approved = $this->approveEntries($period, $owner, null);
                $period->fill([
                    'status' => TimesheetStatus::Approved,
                    'reviewed_by' => null,
                    'reviewed_at' => now(),
                ])->save();

                $this->log($period, $actor, 'auto_approved', ['entries' => $approved]);
            }

            return $period;
        });
    }

    /**
     * Aprueba una semana enviada: congela tarifa y coste y avisa a su dueño.
     *
     * @throws ValidationException
     */
    public function approve(User $reviewer, TimesheetPeriod $period): TimesheetPeriod
    {
        Gate::forUser($reviewer)->authorize('review', $period);

        $period = DB::transaction(function () use ($reviewer, $period): TimesheetPeriod {
            $current = $this->relock($period);
            $this->assertSubmitted($current);

            $count = $this->approveEntries($current, $current->user, $reviewer);
            $current->fill([
                'status' => TimesheetStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_comment' => null,
            ])->save();

            $this->log($current, $reviewer, 'approved', ['entries' => $count]);

            return $current;
        });

        $period->user->notify(new TimesheetApproved($period->week_start->toDateString(), $reviewer->name));

        return $period;
    }

    /**
     * Devuelve una semana enviada con un comentario: las entradas vuelven a borrador.
     *
     * @throws ValidationException
     */
    public function sendBack(User $reviewer, TimesheetPeriod $period, string $comment): TimesheetPeriod
    {
        Gate::forUser($reviewer)->authorize('review', $period);

        $comment = trim($comment);
        if ($comment === '') {
            throw ValidationException::withMessages(['comment' => __('time.errors.return_comment_required')]);
        }

        $period = DB::transaction(function () use ($reviewer, $period, $comment): TimesheetPeriod {
            $current = $this->relock($period);
            $this->assertSubmitted($current);

            $count = $this->toDraft($current, [TimeEntryStatus::Submitted]);
            $current->fill([
                'status' => TimesheetStatus::Returned,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_comment' => $comment,
            ])->save();

            $this->log($current, $reviewer, 'returned', ['entries' => $count, 'comment' => $comment]);

            return $current;
        });

        $period->user->notify(new TimesheetReturned($period->week_start->toDateString(), $reviewer->name, $comment));

        return $period;
    }

    /**
     * El dueño retira una semana enviada que aún no se ha revisado.
     */
    public function withdraw(User $actor, TimesheetPeriod $period): TimesheetPeriod
    {
        Gate::forUser($actor)->authorize('withdraw', $period);

        return DB::transaction(function () use ($actor, $period): TimesheetPeriod {
            $current = $this->relock($period);
            Gate::forUser($actor)->authorize('withdraw', $current);

            $count = $this->toDraft($current, [TimeEntryStatus::Submitted]);
            $current->fill([
                'status' => TimesheetStatus::Open,
                'submitted_at' => null,
            ])->save();

            $this->log($current, $actor, 'withdrawn', ['entries' => $count]);

            return $current;
        });
    }

    /**
     * Reabre una semana aprobada (quien puede aprobarla o un admin) o bloqueada (solo un admin).
     * Las entradas aprobadas vuelven a borrador sin instantáneas; las bloqueadas no cambian: para
     * tocarlas hay que deshacer su bloqueo en /horas/bloqueo.
     */
    public function reopen(User $actor, TimesheetPeriod $period): TimesheetPeriod
    {
        Gate::forUser($actor)->authorize('reopen', $period);

        return DB::transaction(function () use ($actor, $period): TimesheetPeriod {
            $current = $this->relock($period);

            if (! in_array($current->status, [TimesheetStatus::Approved, TimesheetStatus::Locked], true)) {
                throw ValidationException::withMessages(['week' => __('time.errors.week_not_reopenable')]);
            }

            $previous = $current->status->value;
            $count = $this->toDraft($current, [TimeEntryStatus::Approved]);
            $current->fill([
                'status' => TimesheetStatus::Open,
                'submitted_at' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_comment' => null,
            ])->save();

            $this->log($current, $actor, 'reopened', ['entries' => $count, 'from' => $previous]);

            return $current;
        });
    }

    /**
     * Semana de $owner bloqueada para escribir (la crea si no existía: estado abierta).
     */
    private function lockPeriod(User $owner, Week $week): TimesheetPeriod
    {
        $period = TimesheetPeriod::query()->createOrFirst(
            ['user_id' => $owner->id, 'week_start' => $week->startString()],
            ['status' => TimesheetStatus::Open],
        );

        $locked = $this->relock($period);
        $locked->setRelation('user', $owner);

        return $locked;
    }

    private function relock(TimesheetPeriod $period): TimesheetPeriod
    {
        /** @var TimesheetPeriod $current */
        $current = TimesheetPeriod::query()->with('user')->whereKey($period->id)->lockForUpdate()->firstOrFail();

        return $current;
    }

    /**
     * @throws ValidationException
     */
    private function assertSubmitted(TimesheetPeriod $period): void
    {
        if ($period->status !== TimesheetStatus::Submitted) {
            throw ValidationException::withMessages(['week' => __('time.errors.week_not_submitted', [
                'week' => $period->week_start->format('d/m/Y'),
                'status' => mb_strtolower($period->status->label()),
            ])]);
        }
    }

    /**
     * Entradas de la semana en esos estados, en orden.
     *
     * @param  list<TimeEntryStatus>  $statuses
     * @return Collection<int, TimeEntry>
     */
    private function entries(TimesheetPeriod $period, array $statuses, bool $withRates = false): Collection
    {
        return TimeEntry::query()
            ->where('user_id', $period->user_id)
            ->between($period->week_start->toDateString(), $period->weekEnd()->toDateString())
            ->whereIn('status', array_map(fn (TimeEntryStatus $status): string => $status->value, $statuses))
            ->when($withRates, fn (Builder $query) => $query->with(['hourBank', 'project.client']))
            ->orderBy('date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Aprueba las entradas pendientes (borrador o enviadas) y congela tarifa y coste.
     */
    private function approveEntries(TimesheetPeriod $period, User $owner, ?User $reviewer): int
    {
        $cost = $this->rates->cost($owner);
        $count = 0;

        foreach ($this->entries($period, [TimeEntryStatus::Draft, TimeEntryStatus::Submitted], withRates: true) as $entry) {
            $entry->fill([
                'status' => TimeEntryStatus::Approved,
                'approved_by' => $reviewer?->id,
                'approved_at' => now(),
                'hourly_rate_snapshot' => $this->rates->rateFor($entry, $owner),
                'hourly_cost_snapshot' => $cost,
            ])->save();
            $count++;
        }

        return $count;
    }

    /**
     * Vuelve a borrador las entradas en esos estados y borra su aprobación e instantáneas.
     *
     * @param  list<TimeEntryStatus>  $statuses
     */
    private function toDraft(TimesheetPeriod $period, array $statuses): int
    {
        $count = 0;

        foreach ($this->entries($period, $statuses) as $entry) {
            $entry->fill([
                'status' => TimeEntryStatus::Draft,
                'approved_by' => null,
                'approved_at' => null,
                'hourly_rate_snapshot' => null,
                'hourly_cost_snapshot' => null,
            ])->save();
            $count++;
        }

        return $count;
    }

    /**
     * Registro resumido del paso en la auditoría de la semana.
     *
     * @param  array<string, mixed>  $properties
     */
    private function log(TimesheetPeriod $period, User $actor, string $event, array $properties): void
    {
        $description = __("time.activity.{$event}");

        activity('timesheet_periods')
            ->performedOn($period)
            ->causedBy($actor)
            ->event($event)
            ->withProperties(['week' => $period->week_start->toDateString(), 'user_id' => $period->user_id, ...$properties])
            ->log(is_string($description) ? $description : $event);
    }
}
