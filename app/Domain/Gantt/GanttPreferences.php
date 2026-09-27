<?php

namespace App\Domain\Gantt;

use Illuminate\Http\Request;

/**
 * Preferencias de vista del Gantt en la URL, en español, para poder compartirla (D-060):
 * ?escala=dia|semana|mes y ?color=estado|responsable. Los valores desconocidos se ignoran.
 */
final class GanttPreferences
{
    public const array SCALES = ['dia' => 'day', 'semana' => 'week', 'mes' => 'month'];

    public const array COLORS = ['estado' => 'status', 'responsable' => 'assignee'];

    public const string DEFAULT_SCALE = 'week';

    public const string DEFAULT_COLOR = 'status';

    /**
     * @return array{scale: string, color: string}
     */
    public static function fromRequest(Request $request): array
    {
        $scale = $request->query('escala');
        $color = $request->query('color');

        return [
            'scale' => is_string($scale) && isset(self::SCALES[$scale]) ? self::SCALES[$scale] : self::DEFAULT_SCALE,
            'color' => is_string($color) && isset(self::COLORS[$color]) ? self::COLORS[$color] : self::DEFAULT_COLOR,
        ];
    }
}
