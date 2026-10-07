<?php

namespace App\Domain\People;

use App\Enums\ClockEventKind;
use App\Models\ClockEvent;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Los fichajes **efectivos** de una persona y sus jornadas (PLAN-FASE-11 §7.1; D-333): todos los
 * fichajes menos los anulados por una corrección aceptada (filas `void`), que ya incluyen los que
 * añadió la corrección. Nunca se lee otra cosa para calcular: el original no se toca y se sigue
 * viendo en el historial.
 *
 * Las jornadas se arman en orden (instante y, a igualdad, `seq`): cada entrada abre una; la pausa,
 * la vuelta y la salida se le añaden. Una jornada es del día de Madrid de su entrada.
 */
final class RegisterTimeline
{
    /**
     * Fichajes efectivos con el instante en [$from, $to].
     *
     * @return list<ClockEvent>
     */
    public function effective(int $userId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var list<ClockEvent> */
        return self::effectiveQuery($userId)
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->orderBy('occurred_at')
            ->orderBy('seq')
            ->get()
            ->all();
    }

    /**
     * Fichajes efectivos de una persona (sin las filas `void` ni los anulados).
     *
     * @return Builder<ClockEvent>
     */
    public static function effectiveQuery(int $userId): Builder
    {
        return ClockEvent::query()
            ->where('user_id', $userId)
            ->whereIn('kind', ClockEventKind::punchValues())
            ->whereNotExists(fn (QueryBuilder $voids) => $voids
                ->from('clock_events as voids')
                ->whereColumn('voids.voided_event_id', 'clock_events.id'));
    }

    /**
     * Jornadas que EMPIEZAN entre los días $fromDate y $toDate (AAAA-MM-DD, de Madrid), por día.
     * Se leen dos días antes y después para no partir una jornada que cruza la medianoche.
     *
     * @return array<string, list<Workday>>
     */
    public function workdaysByDate(int $userId, string $fromDate, string $toDate): array
    {
        $zone = LocalTime::timezone();
        $from = CarbonImmutable::parse($fromDate, $zone)->startOfDay()->subDays(2);
        $to = CarbonImmutable::parse($toDate, $zone)->endOfDay()->addDays(2);

        $byDate = [];

        foreach (self::build($this->effective($userId, $from, $to)) as $workday) {
            if ($workday->date >= $fromDate && $workday->date <= $toDate) {
                $byDate[$workday->date][] = $workday;
            }
        }

        return $byDate;
    }

    /**
     * Arma las jornadas de una lista de fichajes efectivos ordenados. Los fichajes sueltos antes de
     * la primera entrada (el final de una jornada que empezó antes del rango leído) se ignoran.
     *
     * @param  list<ClockEvent>  $events
     * @return list<Workday>
     */
    public static function build(array $events): array
    {
        $workdays = [];
        $current = null;
        $state = null;

        foreach ($events as $event) {
            if ($event->kind === ClockEventKind::ClockIn) {
                // Una entrada con la jornada anterior abierta: la anterior se queda sin salida y su
                // tramo abierto no cuenta (D-333).
                $current = new Workday(LocalTime::dateOf($event->occurred_at), $event->occurred_at);
                $current->events[] = $event;
                $current->segments[] = ['kind' => 'work', 'from' => $event->occurred_at, 'to' => null, 'mode' => $event->work_mode];
                $workdays[] = $current;
                $state = 'working';

                continue;
            }

            if ($current === null || $state === null) {
                continue;
            }

            $expected = match ($state) {
                'working' => [ClockEventKind::PauseStart, ClockEventKind::ClockOut],
                'paused' => [ClockEventKind::PauseEnd, ClockEventKind::ClockOut],
                default => [],
            };

            if (! in_array($event->kind, $expected, true)) {
                $current->irregular = true;

                continue;
            }

            $current->events[] = $event;
            $last = array_key_last($current->segments);
            $current->segments[$last]['to'] = $event->occurred_at;

            if ($event->kind === ClockEventKind::PauseStart) {
                $current->segments[] = ['kind' => 'pause', 'from' => $event->occurred_at, 'to' => null, 'mode' => null];
                $state = 'paused';
            } elseif ($event->kind === ClockEventKind::PauseEnd) {
                $current->segments[] = ['kind' => 'work', 'from' => $event->occurred_at, 'to' => null, 'mode' => $event->work_mode];
                $state = 'working';
            } else {
                // Salida: si estaba en la pausa, la pausa acaba con la salida y queda la incidencia.
                $current->pauseOpenAtClockOut = $state === 'paused';
                $state = null;
            }
        }

        return $workdays;
    }
}
