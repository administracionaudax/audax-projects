<?php

namespace App\Enums;

/**
 * Estado de la weekly de una persona en una semana (F-042, F-066 y F-136). Lo calcula App\Domain\Weeklies\WeeklyTiming::personStatus().
 */
enum WeeklyPersonStatus: string
{
    case Upcoming = 'upcoming';
    case Pending = 'pending';
    case Overdue = 'overdue';
    case Submitted = 'submitted';
    case SubmittedLate = 'submitted_late';
    case Missed = 'missed';
    case Exempt = 'exempt';
    case NotRequired = 'not_required';

    public function label(): string
    {
        return __("weeklies.enums.person_status.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
