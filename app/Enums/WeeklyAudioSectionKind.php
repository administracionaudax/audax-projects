<?php

namespace App\Enums;

/**
 * Sección de la locución del informe (F-084): entrada, un cliente o cierre.
 */
enum WeeklyAudioSectionKind: string
{
    case Intro = 'intro';
    case Client = 'client';
    case Outro = 'outro';

    public function label(): string
    {
        return __("weeklies.enums.audio_section.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
