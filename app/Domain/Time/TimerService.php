<?php

namespace App\Domain\Time;

use App\Models\ActiveTimer;
use App\Models\HourBank;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Support\Duration;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Temporizador (SPEC §7, D-035, D-036):
 * - uno por usuario; iniciar otro para el anterior y lo imputa,
 * - no se inicia si no podrá imputarse (reglas de la tarea, semana cerrada o bolsa `block` sin saldo),
 * - al parar se crean entradas en borrador con TimeEntryWriter: una por día local (Europe/Madrid)
 *   si cruza la medianoche, con los minutos redondeados al múltiplo más cercano del ajuste
 *   timer_rounding_minutes; los tramos que quedan en 0 se descartan,
 * - si la imputación falla (p. ej. bolsa `block`), el temporizador sigue en marcha: nada se pierde.
 *   Se puede parar indicando otra duración ($minutes) o descartarlo.
 */
final class TimerService
{
    public function __construct(
        private readonly TimeEntryWriter $writer,
        private readonly TimeEntryRules $rules,
    ) {}

    /**
     * @return list<TimeEntryResult> Entradas del temporizador anterior, si lo había.
     *
     * @throws ValidationException
     */
    public function start(User $user, Task $task, ?string $description = null): array
    {
        $task->loadMissing(['project' => fn ($query) => $query->withTrashed()]);
        $bank = $task->hour_bank_id !== null ? HourBank::query()->withTrashed()->with('department')->find($task->hour_bank_id) : null;

        $this->rules->assertCanStartTimer($user, $task, $task->project, $bank);

        return DB::transaction(function () use ($user, $task, $description): array {
            $previous = [];
            $timer = ActiveTimer::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($timer !== null) {
                if ($timer->task_id === $task->id) {
                    return [];
                }

                $previous = $this->persist($user, $timer);
                $timer->delete();
            }

            ActiveTimer::query()->create([
                'user_id' => $user->id,
                'task_id' => $task->id,
                'started_at' => now(),
                'description' => $description,
            ]);

            return $previous;
        });
    }

    /**
     * Para el temporizador e imputa. Con $minutes se imputa esa duración en una sola entrada, en el
     * día (local) en que empezó; con $task, en otra tarea (diálogo al parar, D-035).
     *
     * @return list<TimeEntryResult> Vacío si la duración redondeada es 0 (el temporizador se descarta).
     *
     * @throws ValidationException
     */
    public function stop(User $user, ?int $minutes = null, ?Task $task = null): array
    {
        return DB::transaction(function () use ($user, $minutes, $task): array {
            $timer = ActiveTimer::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($timer === null) {
                throw ValidationException::withMessages(['timer' => __('time.errors.no_timer')]);
            }

            $results = $this->persist($user, $timer, $minutes, $task);
            $timer->delete();

            return $results;
        });
    }

    public function discard(User $user): void
    {
        ActiveTimer::query()->whereKey($user->id)->delete();
    }

    /**
     * Tramos por día local entre dos instantes, con los minutos redondeados.
     *
     * @return list<array{date: string, minutes: int, started_at: CarbonImmutable, ended_at: CarbonImmutable}>
     */
    public static function split(CarbonImmutable $start, CarbonImmutable $end, int $rounding = 1): array
    {
        $zone = LocalTime::timezone();
        $cursor = $start->setTimezone($zone);
        $end = $end->setTimezone($zone);
        $pieces = [];

        while ($cursor < $end) {
            $midnight = $cursor->startOfDay()->addDay();
            $pieceEnd = $midnight < $end ? $midnight : $end;
            $seconds = $pieceEnd->getTimestamp() - $cursor->getTimestamp();
            $pieceMinutes = Duration::roundToNearest((int) round($seconds / 60), max($rounding, 1));

            if ($pieceMinutes > 0) {
                $pieces[] = [
                    'date' => $cursor->toDateString(),
                    'minutes' => min($pieceMinutes, Duration::MAX_MINUTES),
                    'started_at' => $cursor->utc(),
                    'ended_at' => $pieceEnd->utc(),
                ];
            }

            $cursor = $pieceEnd;
        }

        return $pieces;
    }

    /**
     * @return list<TimeEntryResult>
     */
    private function persist(User $user, ActiveTimer $timer, ?int $minutes = null, ?Task $task = null): array
    {
        $taskId = $task->id ?? $timer->task_id;
        $start = CarbonImmutable::instance($timer->started_at);
        $end = CarbonImmutable::now();

        $pieces = $minutes !== null
            ? [['date' => LocalTime::dateOf($start), 'minutes' => $minutes, 'started_at' => $start, 'ended_at' => $end]]
            : self::split($start, $end, (int) Setting::get('timer_rounding_minutes', 1));

        $results = [];
        foreach ($pieces as $piece) {
            $results[] = $this->writer->create($user, new TimeEntryData(
                userId: $user->id,
                taskId: $taskId,
                date: CarbonImmutable::parse($piece['date']),
                minutes: $piece['minutes'],
                description: $timer->description,
                startedAt: $piece['started_at'],
                endedAt: $piece['ended_at'],
            ));
        }

        return $results;
    }
}
