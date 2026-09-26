<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Base de las notificaciones de la app (SPEC §13). Contrato del canal `database`, que pinta la
 * campana y /notificaciones (resources/js/types/domain.ts, AppNotificationData):
 *   kind   → identificador estable ("task.assigned", "hour_bank.threshold"…)
 *   title  → texto corto en español
 *   body   → detalle opcional (texto plano)
 *   url    → a dónde lleva al pulsar (relativa y estable, p. ej. /tareas/12)
 *   icon   → nombre de icono de lucide (opcional)
 * Van por cola: el email por la cola `mail` y el resto por `default`. Las preferencias por canal
 * llegan en la Fase 7; hasta entonces, cada subclase decide sus canales en via().
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function kind(): string;

    abstract public function title(object $notifiable): string;

    public function body(object $notifiable): ?string
    {
        return null;
    }

    abstract public function url(object $notifiable): ?string;

    public function icon(): ?string
    {
        return null;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return [
            'database' => 'default',
            'mail' => 'mail',
        ];
    }

    /**
     * @return array{kind: string, title: string, body: string|null, url: string|null, icon: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind(),
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'url' => $this->url($notifiable),
            'icon' => $this->icon(),
        ];
    }
}
