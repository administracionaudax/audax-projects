<?php

namespace App\Enums;

/**
 * Estado de una sugerencia (F-167), en el orden de WeeklySync: open → future → planned →
 * building_now → beta → completed. El roadmap (F-168) muestra de planned en adelante.
 */
enum SuggestionStatus: string
{
    case Open = 'open';
    case Future = 'future';
    case Planned = 'planned';
    case BuildingNow = 'building_now';
    case Beta = 'beta';
    case Completed = 'completed';

    /** ¿Tiene columna en el roadmap? (SuggestionRoadmapStatus de WeeklySync.) */
    public function isRoadmap(): bool
    {
        return ! in_array($this, [self::Open, self::Future], true);
    }

    public function label(): string
    {
        return __("weeklies.enums.suggestion_status.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
