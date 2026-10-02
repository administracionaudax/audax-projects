<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\Duration;

/**
 * Horarios: las versiones de la jornada (minutos de cada día, de lunes a domingo).
 */
final class WorkSchedulesSection extends Section
{
    public function key(): string
    {
        return 'horarios';
    }

    protected function textKey(): string
    {
        return 'schedules';
    }

    protected function columnKeys(): array
    {
        return ['valid_from', 'valid_to', ...array_values(WorkSchedule::DAY_COLUMNS), 'weekly_total'];
    }

    public function rows(User $user): iterable
    {
        $schedules = WorkSchedule::query()
            ->where('user_id', $user->id)
            ->orderBy('valid_from')
            ->orderBy('id')
            ->get();

        foreach ($schedules as $schedule) {
            $row = [
                'valid_from' => self::date($schedule->valid_from),
                'valid_to' => self::date($schedule->valid_to),
            ];
            $total = 0;

            foreach (WorkSchedule::DAY_COLUMNS as $column) {
                $minutes = (int) $schedule->getAttribute($column);
                $row[$column] = $minutes;
                $total += $minutes;
            }

            $row['weekly_total'] = Duration::format($total);

            yield $row;
        }
    }
}
