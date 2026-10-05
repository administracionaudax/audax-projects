<?php

namespace App\Enums;

/**
 * Estado de una semana en el histórico (F-066): Finalizada, Próximamente, Con retraso, Completada o
 * Por completar. Lo calcula App\Domain\Weeklies\WeeklyTiming::cycleProgress().
 */
enum WeeklyCycleProgress: string
{
    case Finished = 'finished';
    case Upcoming = 'upcoming';
    case Overdue = 'overdue';
    case Completed = 'completed';
    case InProgress = 'in_progress';

    public function label(): string
    {
        return __("weeklies.enums.cycle_progress.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
