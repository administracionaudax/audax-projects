<?php

namespace App\Enums;

/**
 * Módulos activables (F-177, setting `modules`): con uno apagado, sus rutas dan 404 y la navegación lo oculta.
 *
 * Un módulo nuevo necesita una migración que lo añada **apagado** al ajuste ya guardado (como
 * `add_day_plan_off_to_stored_modules`): si falta en él, cuenta como activo y se abriría a toda la
 * plantilla al desplegar.
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
    /** Previsión (D-280): asignaciones y proyectos previstos; apagado por defecto. */
    case Forecast = 'forecast';
    /** «Personas» (Fase 11, D-330): registro de jornada y RR. HH.; apagado por defecto. */
    case People = 'people';

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
