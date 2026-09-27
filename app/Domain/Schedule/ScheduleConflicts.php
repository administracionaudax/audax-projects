<?php

namespace App\Domain\Schedule;

use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * Conflictos al mover una tarea con sucesoras (SPEC §6.1, D-057): una sucesora está en conflicto
 * si empieza (o, sin inicio, vence) el mismo día o antes de que acabe su predecesora. La
 * propuesta desplaza cada sucesora en conflicto lo justo para empezar el día siguiente, conserva
 * su duración y sigue en cascada. NUNCA se aplica sola: la interfaz la enseña y pide confirmación.
 */
final class ScheduleConflicts
{
    public function __construct(private readonly DependencyService $dependencies) {}

    /**
     * @return list<array{task_id: int, title: string, start_date: string|null, due_date: string|null,
     *     new_start_date: string|null, new_due_date: string|null, shift_days: int, predecessor_id: int}>
     */
    public function proposeShift(Task $task, ?CarbonImmutable $newStart, ?CarbonImmutable $newDue): array
    {
        if ($newDue === null) {
            return [];
        }

        $edges = [];
        foreach ($this->dependencies->projectDependencies($task->project_id) as [$from, $to]) {
            $edges[$from][] = $to;
        }

        if (($edges[$task->id] ?? []) === []) {
            return [];
        }

        /** @var array<int, string|null> $start */
        $start = [];
        /** @var array<int, string|null> $due */
        $due = [];
        /** @var array<int, array{title: string, start: string|null, due: string|null}> $original */
        $original = [];
        foreach (Task::query()->where('project_id', $task->project_id)->get(['id', 'title', 'start_date', 'due_date']) as $row) {
            $start[$row->id] = $row->start_date?->toDateString();
            $due[$row->id] = $row->due_date?->toDateString();
            $original[$row->id] = ['title' => $row->title, 'start' => $start[$row->id], 'due' => $due[$row->id]];
        }
        $start[$task->id] = $newStart?->toDateString();
        $due[$task->id] = $newDue->toDateString();

        $proposals = [];
        $queue = [$task->id];
        $guard = 0;

        while ($queue !== [] && $guard++ < 10000) {
            $current = array_shift($queue);
            $end = $due[$current] ?? null;
            if ($end === null) {
                continue;
            }

            foreach ($edges[$current] ?? [] as $successor) {
                if (! isset($original[$successor])) {
                    continue;
                }
                $begin = $start[$successor] ?? $due[$successor] ?? null;
                if ($begin === null || $begin > $end) {
                    continue;
                }

                $shift = (int) CarbonImmutable::parse($begin)->diffInDays(CarbonImmutable::parse($end)) + 1;
                $start[$successor] = self::addDays($start[$successor] ?? null, $shift);
                $due[$successor] = self::addDays($due[$successor] ?? null, $shift);

                $from = $original[$successor]['start'] ?? $original[$successor]['due'];
                $to = $start[$successor] ?? $due[$successor];

                $proposals[$successor] = [
                    'task_id' => $successor,
                    'title' => $original[$successor]['title'],
                    'start_date' => $original[$successor]['start'],
                    'due_date' => $original[$successor]['due'],
                    'new_start_date' => $start[$successor],
                    'new_due_date' => $due[$successor],
                    'shift_days' => $from !== null && $to !== null ? (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) : 0,
                    'predecessor_id' => $current,
                ];
                $queue[] = $successor;
            }
        }

        return array_values($proposals);
    }

    private static function addDays(?string $date, int $days): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date)->addDays($days)->toDateString();
    }
}
