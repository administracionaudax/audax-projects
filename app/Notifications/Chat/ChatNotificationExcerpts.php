<?php

namespace App\Notifications\Chat;

use Illuminate\Notifications\DatabaseNotification;

/**
 * La campana no conserva el texto de un mensaje del chat ocultado por un admin o borrado por su
 * autor (D-115): se vacía el extracto (`body`) de sus avisos ya guardados. El título («Ana te ha
 * mencionado en…») y el enlace se quedan; al abrirlo, el chat enseña «Mensaje oculto» o
 * «Mensaje eliminado». Al volver a mostrar un mensaje no se restaura.
 */
final class ChatNotificationExcerpts
{
    public static function forget(int $messageId): void
    {
        DatabaseNotification::query()
            ->where('chat_message_id', $messageId)
            ->get()
            ->each(function (DatabaseNotification $notification): void {
                /** @var array<string, mixed> $data */
                $data = $notification->data;

                if (($data['body'] ?? null) !== null) {
                    $notification->forceFill(['data' => [...$data, 'body' => null]])->save();
                }
            });
    }
}
