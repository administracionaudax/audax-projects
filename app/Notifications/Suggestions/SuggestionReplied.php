<?php

namespace App\Notifications\Suggestions;

/**
 * Te han respondido (F-165): un comentario en tu sugerencia o una respuesta a tu comentario.
 */
final class SuggestionReplied extends SuggestionNotification
{
    public const string ON_POST = 'post';

    public const string ON_COMMENT = 'comment';

    public function __construct(
        int $postId,
        string $postTitle,
        string $actorName,
        public readonly string $context,
        public readonly string $excerpt,
    ) {
        parent::__construct($postId, $postTitle, $actorName);
    }

    public function kind(): string
    {
        return 'suggestions.replied';
    }

    public function title(object $notifiable): string
    {
        $key = $this->context === self::ON_COMMENT ? 'help.notifications.replied_comment' : 'help.notifications.replied_post';

        return __($key, ['actor' => $this->actorName, 'post' => $this->postTitle]);
    }

    public function body(object $notifiable): ?string
    {
        return $this->excerpt === '' ? null : $this->excerpt;
    }

    public function icon(): string
    {
        return 'message-circle';
    }
}
