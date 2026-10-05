<?php

namespace App\Enums;

/**
 * Proveedor de IA externa (D-146): Gemini para el texto y Google Cloud TTS para la locución.
 */
enum AiProvider: string
{
    case Gemini = 'gemini';
    case GoogleTts = 'google_tts';

    public function label(): string
    {
        return __("weeklies.enums.ai_provider.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
