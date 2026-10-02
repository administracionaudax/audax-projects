<?php

namespace App\Domain\Schedule;

use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * Conflictos al mover una tarea con sucesoras (SPEC §6.1, D-057): una sucesora está en conflicto
 * si empieza (o, sin inicio, vence) el mismo día o antes de que acabe su predecesora. La
 * propuesta desplaza cada sucesora en conflicto lo justo para empezar el día siguiente, conserva
 * su duración y sigue en cascada. NUNCA se aplica sola: la interfaz la enseña y pide confirmación.
 * Mover una tarea antes (o sin cambiar su entrega) no propone nada: las sucesoras nunca se
 * adelantan solas, y un conflicto que ya estaba no lo causa ese cambio.
 * A prueba de ciclos (D-090; D-056 no permite crearlos, pero la base podría tener alguno): la
 * tarea movida nunca se desplaza y la cascada no vuelve a una tarea de su propio camino.
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

        // Antes o con la misma entrega: nada que proponer (D-057).
        $oldDue = $task->due_date?->toDateString();
        if ($oldDue !== null && $newDue->toDateString() <= $oldDue) {
            return [];
        }

        $edges = [];
        foreach ($this->dependencies->projectDependencies($task->project_id) as [$from, $to]) {
            // La tarea movida nunca se desplaza, aunque un ciclo vuelva a ella.
            if ($to !== $task->id) {
                $edges[$from][] = $to;
            }
        }

        if (($edges[$task->id] ?? []) === []) {
            return [];
        }

        [$order, $links] = self::acyclicFrom($task->id, $edges);

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
        // Solo propaga lo que se mueve: la tarea movida y las sucesoras que se desplazan (un
        // conflicto que ya había más abajo no lo causa este cambio). En orden topológico, cada tarea
        // se revisa una vez, con sus fechas definitivas.
        $moved = [$task->id => true];

        foreach ($order as $current) {
            $end = $due[$current] ?? null;
            if (! isset($moved[$current]) || $end === null) {
                continue;
            }

            foreach ($links[$current] ?? [] as $successor) {
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
                $moved[$successor] = true;
            }
        }

        return array_values($proposals);
    }

    /**
     * Tareas que se alcanzan desde la movida, en orden topológico, y los enlaces por los que se
     * propaga el desplazamiento (búsqueda en profundidad). Sin ciclos es todo el subgrafo; con uno
     * (datos dañados), se descarta el enlace que vuelve a una tarea del camino en curso, así que
     * la cascada nunca da vueltas.
     *
     * @param  array<int, list<int>>  $edges  predecesora → sucesoras
     * @return array{0: list<int>, 1: array<int, list<int>>}
     */
    private static function acyclicFrom(int $root, array $edges): array
    {
        // 1 = en el camino en curso, 2 = terminada.
        $state = [$root => 1];
        $links = [];
        $finished = [];
        /** @var list<array{0: int, 1: int}> $stack tarea y siguiente enlace por revisar */
        $stack = [[$root, 0]];

        while ($stack !== []) {
            $top = count($stack) - 1;
            [$node, $index] = $stack[$top];
            $next = $edges[$node][$index] ?? null;

            if ($next === null) {
                array_pop($stack);
                $state[$node] = 2;
                $finished[] = $node;

                continue;
            }

            $stack[$top][1] = $index + 1;

            if (($state[$next] ?? 0) === 1) {
                continue;
            }

            $links[$node][] = $next;

            if (! isset($state[$next])) {
                $state[$next] = 1;
                $stack[] = [$next, 0];
            }
        }

        return [array_reverse($finished), $links];
    }

    private static function addDays(?string $date, int $days): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date)->addDays($days)->toDateString();
    }
}
