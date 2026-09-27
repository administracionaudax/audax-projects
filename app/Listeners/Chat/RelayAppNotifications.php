<?php

namespace App\Listeners\Chat;

use App\Events\Chat\BroadcastAppNotification;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Campana en tiempo real: cada notificación guardada en la base de datos (de cualquier área: tareas,
 * horas, bolsas, chat…) sale también por el canal personal del usuario con el formato de
 * NotificationResource. No hace falta tocar cada notificación: basta con que use el canal database.
 */
final class RelayAppNotifications
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User || ! $event->response instanceof DatabaseNotification) {
            return;
        }

        /** @var array{id: string, data: array<string, mixed>, read_at: string|null, created_at: string|null} $payload */
        $payload = (new NotificationResource($event->response))->resolve();

        event(new BroadcastAppNotification($event->notifiable->id, $payload));
    }
}
