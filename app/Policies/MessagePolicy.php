<?php

namespace App\Policies;

use App\Enums\MessageType;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Mensajes (SPEC §12): cada uno edita y borra los suyos (nunca los de sistema); el admin los
 * oculta (moderación); fijar y reaccionar, quien puede escribir en la conversación.
 * Toda escritura exige poder escribir en la conversación: en el chat de un proyecto archivado
 * todo es de solo lectura, también borrar lo propio (D-071). Un mensaje ocultado por un admin
 * no lo borra su autor: el moderador conserva su contenido (D-115).
 */
class MessagePolicy
{
    public function update(User $user, Message $message): bool
    {
        return $message->user_id === $user->id
            && $message->type !== MessageType::System
            && $message->hidden_at === null
            && ! $message->trashed()
            && Gate::forUser($user)->allows('post', $message->conversation);
    }

    public function delete(User $user, Message $message): bool
    {
        return $message->user_id === $user->id
            && $message->type !== MessageType::System
            && $message->hidden_at === null
            && ! $message->trashed()
            && Gate::forUser($user)->allows('post', $message->conversation);
    }

    public function moderate(User $user, Message $message): bool
    {
        return Gate::forUser($user)->allows('moderate', $message->conversation);
    }

    public function pin(User $user, Message $message): bool
    {
        return ! $message->trashed() && Gate::forUser($user)->allows('post', $message->conversation);
    }

    public function react(User $user, Message $message): bool
    {
        return ! $message->trashed() && $message->hidden_at === null && Gate::forUser($user)->allows('post', $message->conversation);
    }
}
