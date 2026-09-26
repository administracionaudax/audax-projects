<?php

namespace App\Domain\Admin;

use App\Domain\Time\Capacity;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Jornadas versionadas (SPEC §4.1, D-036). Reglas:
 * - las versiones no se solapan: una nueva empieza después de la última y cierra la vigente con
 *   valid_to = valid_from − 1 día,
 * - el histórico no se reescribe: solo se edita o borra la ÚLTIMA versión, y solo si aún no ha
 *   empezado (valid_from posterior a hoy en Madrid),
 * - al borrar la última, la anterior vuelve a quedar abierta (sin valid_to).
 * Todo en una transacción con las versiones del usuario bloqueadas.
 */
final class WorkScheduleVersions
{
    /**
     * Horario inicial de un usuario nuevo: el ajuste default_work_minutes desde hoy (D-036).
     */
    public function createDefault(User $user, ?CarbonImmutable $from = null): WorkSchedule
    {
        return $this->create($user, ($from ?? LocalTime::today())->toDateString(), Capacity::defaultWeek());
    }

    /**
     * @param  list<int>  $week  Minutos de lunes a domingo.
     *
     * @throws ValidationException
     */
    public function create(User $user, string $validFrom, array $week): WorkSchedule
    {
        return DB::transaction(function () use ($user, $validFrom, $week): WorkSchedule {
            $latest = $this->latest($user);
            $from = CarbonImmutable::parse($validFrom);

            if ($latest !== null) {
                if ($from->toDateString() <= $latest->valid_from->toDateString()) {
                    throw ValidationException::withMessages([
                        'valid_from' => __('admin.schedules.must_follow', ['date' => $latest->valid_from->format('d/m/Y')]),
                    ]);
                }

                $closing = $from->subDay()->toDateString();

                if ($latest->valid_to === null || $latest->valid_to->toDateString() >= $from->toDateString()) {
                    $latest->valid_to = CarbonImmutable::parse($closing);
                    $latest->save();
                }
            }

            /** @var WorkSchedule $schedule */
            $schedule = $user->workSchedules()->create([
                'valid_from' => $from->toDateString(),
                'valid_to' => null,
                ...$this->columns($week),
            ]);

            return $schedule;
        });
    }

    /**
     * @param  list<int>  $week
     *
     * @throws ValidationException
     */
    public function update(WorkSchedule $schedule, string $validFrom, array $week): WorkSchedule
    {
        return DB::transaction(function () use ($schedule, $validFrom, $week): WorkSchedule {
            $current = $this->assertEditable($schedule);
            $from = CarbonImmutable::parse($validFrom);

            $this->assertNotStarted($from, 'valid_from', 'admin.schedules.from_must_be_future');

            $previous = $this->previous($current);

            if ($previous !== null) {
                if ($from->toDateString() <= $previous->valid_from->toDateString()) {
                    throw ValidationException::withMessages([
                        'valid_from' => __('admin.schedules.must_follow', ['date' => $previous->valid_from->format('d/m/Y')]),
                    ]);
                }

                $previous->valid_to = $from->subDay();
                $previous->save();
            }

            $current->fill(['valid_from' => $from->toDateString(), ...$this->columns($week)])->save();

            return $current;
        });
    }

    /**
     * @throws ValidationException
     */
    public function delete(WorkSchedule $schedule): void
    {
        DB::transaction(function () use ($schedule): void {
            $current = $this->assertEditable($schedule);
            $previous = $this->previous($current);

            $current->delete();

            if ($previous !== null) {
                $previous->valid_to = null;
                $previous->save();
            }
        });
    }

    /**
     * ¿Se puede editar o borrar? Solo la última versión y si aún no ha empezado.
     */
    public function isEditable(WorkSchedule $schedule, ?WorkSchedule $latest): bool
    {
        return $latest !== null
            && $latest->id === $schedule->id
            && $schedule->valid_from->toDateString() > LocalTime::todayString();
    }

    /**
     * @throws ValidationException
     */
    private function assertEditable(WorkSchedule $schedule): WorkSchedule
    {
        /** @var User $user */
        $user = User::query()->findOrFail($schedule->user_id);
        $latest = $this->latest($user);

        if ($latest === null || $latest->id !== $schedule->id) {
            throw ValidationException::withMessages(['schedule' => __('admin.schedules.only_latest')]);
        }

        $this->assertNotStarted($latest->valid_from, 'schedule', 'admin.schedules.already_started');

        return $latest;
    }

    /**
     * @throws ValidationException
     */
    private function assertNotStarted(CarbonImmutable $from, string $field, string $message): void
    {
        if ($from->toDateString() <= LocalTime::todayString()) {
            throw ValidationException::withMessages([$field => __($message)]);
        }
    }

    /**
     * Última versión (la de valid_from más reciente), bloqueando todas las del usuario.
     */
    private function latest(User $user): ?WorkSchedule
    {
        $versions = WorkSchedule::query()
            ->where('user_id', $user->id)
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        return $versions->first();
    }

    private function previous(WorkSchedule $schedule): ?WorkSchedule
    {
        return WorkSchedule::query()
            ->where('user_id', $schedule->user_id)
            ->whereKeyNot($schedule->id)
            ->where('valid_from', '<', $schedule->valid_from->toDateString())
            ->orderByDesc('valid_from')
            ->first();
    }

    /**
     * @param  list<int>  $week
     * @return array<string, int>
     */
    private function columns(array $week): array
    {
        $columns = [];

        foreach (array_values(WorkSchedule::DAY_COLUMNS) as $index => $column) {
            $columns[$column] = max(0, min((int) ($week[$index] ?? 0), 24 * 60));
        }

        return $columns;
    }
}
