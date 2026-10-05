<?php

namespace App\Notifications\Suggestions;

/**
 * El estado de tu sugerencia ha cambiado (F-167), con la nota oficial si la hay.
 */
final class SuggestionStatusChanged extends SuggestionNotification
{
    public function __construct(
        int $postId,
        string $postTitle,
        string $actorName,
        public readonly string $statusLabel,
        public readonly ?string $note,
    ) {
        parent::__construct($postId, $postTitle, $actorName);
    }

    public function kind(): string
    {
        return 'suggestions.status_changed';
    }

    public function title(object $notifiable): string
    {
        return __('help.notifications.status_changed', ['post' => $this->postTitle, 'status' => $this->statusLabel]);
    }

    public function body(object $notifiable): ?string
    {
        return $this->note === null || $this->note === '' ? null : $this->note;
    }

    public function icon(): string
    {
        return 'rocket';
    }
}
