<?php

namespace App\Domain\Time;

use App\Domain\HourBanks\HourBankLedger;
use App\Enums\TimeEntryStatus;
use App\Enums\TimesheetStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\TimeEntryLock;
use App\Models\TimesheetPeriod;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Bloqueo de horas al facturar (SPEC §7, D-034). Solo un admin (gate lock-time), por cliente o
 * proyecto y un rango de fechas:
 * - bloquea las entradas APROBADAS del rango (approved → locked, con locked_at y el bloqueo),
 * - las no aprobadas del rango no se tocan (la vista previa las avisa),
 * - es una actualización masiva: no cambia minutos, así que no hace falta recalcular las bolsas,
 * - las semanas cuyas entradas quedan todas bloqueadas pasan a `locked`; al desbloquear vuelven a
 *   `approved`, y cada entrada vuelve al estado de su semana (ver unlock),
 * - queda en la auditoría del bloqueo (quién, cuándo, alcance y número de entradas).
 */
final class TimeLockService
{
    public function __construct(
        private readonly HourBankLedger $ledger,
    ) {}

    /**
     * Entradas del alcance (cliente o proyecto) en el rango, en cualquier estado.
     *
     * @return Builder<TimeEntry>
     */
    public function scope(?Client $client, ?Project $project, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return TimeEntry::query()
            ->between($from->toDateString(), $to->toDateString())
            ->when($project !== null, fn (Builder $query) => $query->where('time_entries.project_id', $project?->id))
            ->when($project === null && $client !== null, fn (Builder $query) => $query->whereIn(
                'time_entries.project_id',
                Project::query()->withTrashed()->select('id')->where('client_id', $client?->id),
            ));
    }

    /**
     * Vista previa: lo que se bloquearía y lo que se queda fuera por no estar aprobado.
     *
     * @return array{lockable: array{count: int, minutes: int}, pending: array<string, array{count: int, minutes: int}>, locked: array{count: int, minutes: int}}
     */
    public function preview(?Client $client, ?Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->scope($client, $project, $from, $to)
            ->selectRaw('status, COUNT(*) as entries, COALESCE(SUM(minutes), 0) as total')
            ->groupBy('status')
            ->toBase()
            ->get();

        $byStatus = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $byStatus[(string) $row['status']] = ['count' => (int) $row['entries'], 'minutes' => (int) $row['total']];
        }

        $empty = ['count' => 0, 'minutes' => 0];
        $pending = [];
        foreach ([TimeEntryStatus::Draft, TimeEntryStatus::Submitted] as $status) {
            $pending[$status->value] = $byStatus[$status->value] ?? $empty;
        }

        return [
            'lockable' => $byStatus[TimeEntryStatus::Approved->value] ?? $empty,
            'pending' => $pending,
            'locked' => $byStatus[TimeEntryStatus::Locked->value] ?? $empty,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function lock(User $admin, ?Client $client, ?Project $project, CarbonImmutable $from, CarbonImmutable $to, ?string $reference): TimeEntryLock
    {
        Gate::forUser($admin)->authorize('lock-time');

        return DB::transaction(function () use ($admin, $client, $project, $from, $to, $reference): TimeEntryLock {
            $entries = $this->scope($client, $project, $from, $to)
                ->where('time_entries.status', TimeEntryStatus::Approved->value)
                ->lockForUpdate()
                ->get(['id', 'user_id', 'date', 'minutes']);

            if ($entries->isEmpty()) {
                throw ValidationException::withMessages(['date_from' => __('time.errors.lock_nothing')]);
            }

            $lock = TimeEntryLock::query()->create([
                'client_id' => $project !== null ? $project->client_id : $client?->id,
                'project_id' => $project?->id,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'locked_by' => $admin->id,
                'entries_count' => $entries->count(),
                'reference' => $reference,
            ]);

            $now = now();
            foreach ($entries->pluck('id')->chunk(500) as $chunk) {
                TimeEntry::query()->whereKey($chunk->all())->update([
                    'status' => TimeEntryStatus::Locked->value,
                    'locked_at' => $now,
                    'time_entry_lock_id' => $lock->id,
                    'updated_at' => $now,
                ]);
            }

            $this->lockWeeks($entries->map(fn (TimeEntry $entry): array => [$entry->user_id, $entry->date->toDateString()])->all());

            $this->log($lock, $admin, 'locked', [
                'entries' => $entries->count(),
                'minutes' => (int) $entries->sum('minutes'),
            ]);

            return $lock;
        });
    }

    /**
     * Deshace un bloqueo. Cada entrada vuelve al estado que corresponde a su semana, para que nunca
     * quede una entrada «aprobada» en una semana que se puede editar:
     * - semana aprobada o bloqueada → aprobada (y la semana bloqueada, a aprobada),
     * - semana enviada → enviada, y abierta, devuelta o sin fila → borrador; en los dos casos se
     *   borran la aprobación y las instantáneas de tarifa y coste (se congelan de nuevo al aprobar).
     * Luego se recalculan sus bolsas: mientras estaban bloqueadas, su exceso estaba congelado.
     *
     * @throws ValidationException
     */
    public function unlock(User $admin, TimeEntryLock $lock): int
    {
        Gate::forUser($admin)->authorize('lock-time');

        return DB::transaction(function () use ($admin, $lock): int {
            /** @var TimeEntryLock $current */
            $current = TimeEntryLock::query()->whereKey($lock->id)->lockForUpdate()->firstOrFail();

            if ($current->unlocked_at !== null) {
                throw ValidationException::withMessages(['lock' => __('time.errors.lock_already_unlocked', [
                    'date' => $current->unlocked_at->setTimezone(LocalTime::timezone())->format('d/m/Y'),
                ])]);
            }

            $entries = TimeEntry::query()
                ->where('time_entry_lock_id', $current->id)
                ->where('status', TimeEntryStatus::Locked->value)
                ->lockForUpdate()
                ->get(['id', 'user_id', 'date', 'hour_bank_id']);

            $now = now();
            $restored = $this->restore($entries, $now);

            $current->forceFill(['unlocked_at' => $now, 'unlocked_by' => $admin->id])->save();

            foreach ($entries->pluck('hour_bank_id')->filter()->unique() as $bankId) {
                $this->ledger->recalculateById((int) $bankId);
            }

            $this->log($current, $admin, 'unlocked', ['entries' => $entries->count(), ...$restored]);

            return $entries->count();
        });
    }

    /**
     * Devuelve las entradas desbloqueadas al estado de su semana (una consulta por semana).
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @return array{approved: int, submitted: int, draft: int} Entradas que quedan en cada estado.
     */
    private function restore(Collection $entries, CarbonInterface $now): array
    {
        /** @var array<int, array<string, list<int>>> $weeks user_id → week_start → ids */
        $weeks = [];
        foreach ($entries as $entry) {
            $weeks[$entry->user_id][Week::containing($entry->date)->startString()][] = $entry->id;
        }

        $restored = ['approved' => 0, 'submitted' => 0, 'draft' => 0];

        foreach ($weeks as $userId => $starts) {
            foreach ($starts as $start => $ids) {
                $period = TimesheetPeriod::query()
                    ->where('user_id', $userId)
                    ->where('week_start', $start)
                    ->lockForUpdate()
                    ->first();

                $status = match ($period?->status) {
                    TimesheetStatus::Approved, TimesheetStatus::Locked => TimeEntryStatus::Approved,
                    TimesheetStatus::Submitted => TimeEntryStatus::Submitted,
                    default => TimeEntryStatus::Draft,
                };

                if ($period !== null && $period->status === TimesheetStatus::Locked) {
                    $period->status = TimesheetStatus::Approved;
                    $period->save();
                }

                $attributes = [
                    'status' => $status->value,
                    'locked_at' => null,
                    'time_entry_lock_id' => null,
                    'updated_at' => $now,
                ];

                if ($status !== TimeEntryStatus::Approved) {
                    $attributes += [
                        'approved_by' => null,
                        'approved_at' => null,
                        'hourly_rate_snapshot' => null,
                        'hourly_cost_snapshot' => null,
                    ];
                }

                foreach (array_chunk($ids, 500) as $chunk) {
                    TimeEntry::query()->whereKey($chunk)->update($attributes);
                }

                $restored[$status->value] += count($ids);
            }
        }

        return $restored;
    }

    /**
     * Al bloquear, pasan a `locked` las semanas afectadas que ya no tienen entradas sin bloquear.
     *
     * @param  array<int, array{0: int, 1: string}>  $userDates
     */
    private function lockWeeks(array $userDates): void
    {
        /** @var array<int, array<string, true>> $weeks user_id → [week_start => true] */
        $weeks = [];
        foreach ($userDates as [$userId, $date]) {
            $weeks[$userId][Week::containing($date)->startString()] = true;
        }

        foreach ($weeks as $userId => $starts) {
            $starts = array_keys($starts);
            sort($starts);

            // Una consulta por persona: fechas con entradas aún sin bloquear en esas semanas.
            $unlockedByWeek = [];
            $dates = TimeEntry::query()
                ->where('user_id', $userId)
                ->between($starts[0], CarbonImmutable::parse($starts[count($starts) - 1])->addDays(6)->toDateString())
                ->where('status', '!=', TimeEntryStatus::Locked->value)
                ->pluck('date');

            foreach ($dates as $date) {
                $unlockedByWeek[Week::containing($date)->startString()] = true;
            }

            foreach ($starts as $start) {
                if (isset($unlockedByWeek[$start])) {
                    continue;
                }

                $period = TimesheetPeriod::query()
                    ->where('user_id', $userId)
                    ->where('week_start', $start)
                    ->lockForUpdate()
                    ->first();

                $period ??= new TimesheetPeriod(['user_id' => $userId, 'week_start' => $start]);
                $period->status = TimesheetStatus::Locked;
                $period->save();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(TimeEntryLock $lock, User $admin, string $event, array $properties): void
    {
        $description = __("time.activity.{$event}");

        activity('time_entry_locks')
            ->performedOn($lock)
            ->causedBy($admin)
            ->event($event)
            ->withProperties([
                'client_id' => $lock->client_id,
                'project_id' => $lock->project_id,
                'date_from' => $lock->date_from->toDateString(),
                'date_to' => $lock->date_to->toDateString(),
                'reference' => $lock->reference,
                ...$properties,
            ])
            ->log(is_string($description) ? $description : $event);
    }
}
