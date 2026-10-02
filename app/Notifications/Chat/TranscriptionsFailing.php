<?php

namespace App\Notifications\Chat;

use App\Notifications\AppNotification;

/**
 * Aviso al admin (SPEC §12): hay audios que siguen sin transcribir tras agotar los reintentos.
 * Se pueden relanzar a mano desde /admin/transcripciones.
 */
class TranscriptionsFailing extends AppNotification
{
    public function __construct(public readonly int $count) {}

    public function kind(): string
    {
        return 'system.transcriptions_failing';
    }

    public function title(object $notifiable): string
    {
        return trans_choice('chat.notifications.transcriptions_failing.title', $this->count, ['count' => $this->count]);
    }

    public function body(object $notifiable): ?string
    {
        return __('chat.notifications.transcriptions_failing.body');
    }

    public function url(object $notifiable): ?string
    {
        return '/admin/transcripciones';
    }

    public function icon(): ?string
    {
        return 'triangle-alert';
    }
}
