<?php

namespace App\Http\Resources\Chat;

use App\Models\Conversation;
use App\Models\User;

/**
 * Props de una conversación abierta (en /chat/{id} y en la pestaña Chat del proyecto): la
 * cabecera, la primera página de mensajes (los últimos o, con ?mensaje=, los de alrededor de ese
 * mensaje) y los fijados. Quien llama ya ha comprobado ConversationPolicy::view.
 */
final class ConversationView
{
    public function __construct(private readonly MessageWindow $window) {}

    /**
     * @return array{conversation: array<string, mixed>, messages: array<string, mixed>, pinned: list<array<string, mixed>>, focus: int|null}
     */
    public function props(Conversation $conversation, User $viewer, ?int $focus = null): array
    {
        $can = ConversationAbilities::for($viewer, $conversation);

        if ($focus !== null && ! $conversation->messages()->withTrashed()->whereKey($focus)->exists()) {
            $focus = null;
        }

        [$messages, $hasOlder, $hasNewer] = $focus === null
            ? $this->window->latest($conversation)
            : $this->window->around($conversation, $focus);

        return [
            'conversation' => ConversationPresenter::detail($conversation, $viewer, $can),
            'messages' => [
                ...(new MessagePresenter($viewer, $can))->present($messages),
                'has_older' => $hasOlder,
                'has_newer' => $hasNewer,
                // Desde cuándo pedirá cambios la primera consulta periódica.
                'server_time' => now()->toIso8601ZuluString(),
            ],
            'pinned' => PinnedPresenter::list($this->window->pinned($conversation, $can->moderate)),
            'focus' => $focus,
        ];
    }
}
