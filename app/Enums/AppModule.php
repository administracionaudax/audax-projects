<?php

namespace App\Enums;

/**
 * Módulos activables (F-177, setting `modules`): con uno apagado, sus rutas dan 404 y la navegación lo oculta.
 */
enum AppModule: string
{
    case Weeklies = 'weeklies';
    case ProjectStatus = 'project_status';
    case Help = 'help';
    case Suggestions = 'suggestions';
    case Assistant = 'assistant';
    /** Plan del día (D-250): no es parte de la Weekly; activado por defecto. */
    case DayPlan = 'day_plan';

    public function label(): string
    {
        return __("weeklies.enums.module.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
