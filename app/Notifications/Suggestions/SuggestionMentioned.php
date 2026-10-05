<?php

namespace App\Notifications\Suggestions;

/**
 * Te han mencionado (F-165) en una sugerencia o en un comentario.
 */
final class SuggestionMentioned extends SuggestionNotification
{
    public const string IN_POST = 'post';

    public const string IN_COMMENT = 'comment';

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
        return 'suggestions.mentioned';
    }

    public function title(object $notifiable): string
    {
        $key = $this->context === self::IN_COMMENT ? 'help.notifications.mentioned_comment' : 'help.notifications.mentioned_post';

        return __($key, ['actor' => $this->actorName, 'post' => $this->postTitle]);
    }

    public function body(object $notifiable): ?string
    {
        return $this->excerpt === '' ? null : $this->excerpt;
    }

    public function icon(): string
    {
        return 'at-sign';
    }
}
