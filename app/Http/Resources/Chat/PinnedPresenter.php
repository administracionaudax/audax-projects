<?php

namespace App\Http\Resources\Chat;

use App\Enums\MessageType;
use App\Models\Message;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Barra de mensajes fijados (contrato: resources/js/types/chat.ts, ChatPinnedMessage): extracto,
 * autor y quién lo fijó; al pulsar se va al mensaje.
 */
final class PinnedPresenter
{
    /**
     * @param  EloquentCollection<int, Message>  $messages  MessageWindow::pinned()
     * @return list<array<string, mixed>>
     */
    public static function list(EloquentCollection $messages): array
    {
        if ($messages->isEmpty()) {
            return [];
        }

        $mentions = ChatUsers::mentionable($messages);
        $ids = [];
        foreach ($messages as $message) {
            array_push($ids, $message->user_id, $message->pinned_by, ...$mentions[$message->id] ?? []);
        }
        $users = ChatUsers::load($ids);

        return array_values($messages->map(fn (Message $message): array => [
            'id' => $message->id,
            'type' => $message->type->value,
            'author' => $message->user_id === null ? null : ($users[$message->user_id] ?? null)?->name,
            'excerpt' => $message->type === MessageType::System ? '' : MessagePreview::plain($message->body, ChatUsers::only($users, $mentions[$message->id] ?? []), 100),
            'system' => $message->type === MessageType::System ? ['key' => (string) $message->system_key, 'payload' => (object) ($message->system_payload ?? [])] : null,
            'hidden' => $message->hidden_at !== null,
            'pinned_at' => $message->pinned_at?->toIso8601ZuluString(),
            'pinned_by' => $message->pinned_by === null ? null : ($users[$message->pinned_by] ?? null)?->name,
        ])->all());
    }
}
