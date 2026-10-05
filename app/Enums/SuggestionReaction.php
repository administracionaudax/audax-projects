<?php

namespace App\Enums;

/**
 * Reacción a un comentario de una sugerencia (F-166): una por persona y comentario.
 */
enum SuggestionReaction: string
{
    case ThumbsUp = 'thumbs_up';
    case Rocket = 'rocket';
    case Eyes = 'eyes';
    case Heart = 'heart';

    public function label(): string
    {
        return __("weeklies.enums.suggestion_reaction.{$this->value}");
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
