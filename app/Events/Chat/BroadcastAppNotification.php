<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Campana en tiempo real (D-037 → Fase 6): cada notificación que se guarda en la base de datos
 * sale también por el canal personal App.Models.User.{id}, con el mismo formato que
 * GET /notificaciones/recientes (NotificationResource). Sin tiempo real, la campana sigue
 * consultando cada 60 s.
 */
final class BroadcastAppNotification implements ShouldBroadcast, ShouldRescue
{
    use Dispatchable;

    public bool $afterCommit = true;

    /**
     * @param  array{id: string, data: array<string, mixed>, read_at: string|null, created_at: string|null}  $notification
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $notification,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * @return array{notification: array{id: string, data: array<string, mixed>, read_at: string|null, created_at: string|null}}
     */
    public function broadcastWith(): array
    {
        return ['notification' => $this->notification];
    }
}
