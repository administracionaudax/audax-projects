<?php

namespace App\Domain\Weeklies;

use App\Enums\WeeklyCycleStatus;
use Carbon\CarbonInterface;

/**
 * Racha y puntualidad de una persona (D-150, F-028, F-031 y F-099), portadas de
 * `ws:components/MemberDashboard.tsx:40-80`. Lógica pura: el llamante da las semanas.
 *
 * Racha = semanas seguidas, de la más reciente hacia atrás, enviadas a tiempo:
 * - una semana anterior al alta de la persona no cuenta (se salta),
 * - una semana exenta no la rompe ni suma,
 * - la semana activa sin enviar no la rompe mientras no pase el plazo,
 * - un envío con retraso o una semana sin enviar ya vencida la cortan.
 */
final class StreakCalculator
{
    public function __construct(private readonly WeeklyTiming $timing = new WeeklyTiming) {}

    /**
     * @param  iterable<StreakWeek>  $weeks  en cualquier orden
     */
    public function streak(iterable $weeks, CarbonInterface $now): int
    {
        $count = 0;

        foreach ($this->newestFirst($weeks) as $week) {
            if (! $week->required || $week->exempt) {
                continue;
            }

            if ($week->submittedAt !== null) {
                if (! $this->timing->isOnTime($week->submittedAt, $week->deadlineDate)) {
                    break;
                }

                $count++;

                continue;
            }

            if ($week->status === WeeklyCycleStatus::Active && ! $this->timing->isOverdue($week->deadlineDate, $now)) {
                continue;
            }

            break;
        }

        return $count;
    }

    /**
     * Estadísticas del perfil (F-028): enviadas, a tiempo y racha. Solo cuentan las semanas que le
     * tocaban y en las que no estaba exenta.
     *
     * @param  iterable<StreakWeek>  $weeks
     * @return array{submitted: int, on_time: int, streak: int}
     */
    public function summary(iterable $weeks, CarbonInterface $now): array
    {
        $weeks = $this->newestFirst($weeks);
        $submitted = 0;
        $onTime = 0;

        foreach ($weeks as $week) {
            if (! $week->required || $week->exempt || $week->submittedAt === null) {
                continue;
            }

            $submitted++;

            if ($this->timing->isOnTime($week->submittedAt, $week->deadlineDate)) {
                $onTime++;
            }
        }

        return ['submitted' => $submitted, 'on_time' => $onTime, 'streak' => $this->streak($weeks, $now)];
    }

    /**
     * @param  iterable<StreakWeek>  $weeks
     * @return list<StreakWeek>
     */
    private function newestFirst(iterable $weeks): array
    {
        $list = [];

        foreach ($weeks as $week) {
            $list[] = $week;
        }

        usort($list, fn (StreakWeek $a, StreakWeek $b): int => $b->endDate->getTimestamp() <=> $a->endDate->getTimestamp());

        return $list;
    }
}
