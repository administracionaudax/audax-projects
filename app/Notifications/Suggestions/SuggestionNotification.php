<?php

namespace App\Notifications\Suggestions;

use App\Notifications\AppNotification;

/**
 * Avisos de las sugerencias del centro de ayuda (Fase 10, 10.7, D-209): llevan a la sugerencia
 * (/ayuda/sugerencias/{id}). Grupo «Sugerencias» del catálogo, para quien usa la ayuda con el
 * módulo de sugerencias encendido.
 */
abstract class SuggestionNotification extends AppNotification
{
    public function __construct(
        public readonly int $postId,
        public readonly string $postTitle,
        public readonly string $actorName,
    ) {}

    public function url(object $notifiable): ?string
    {
        return self::postUrl($this->postId);
    }

    public static function postUrl(int $postId): string
    {
        return "/ayuda/sugerencias/{$postId}";
    }
}
