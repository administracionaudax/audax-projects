<?php

namespace App\Broadcasting;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Canal de notificaciones «avisos del navegador» (D-072). Las notificaciones lo piden en via()
 * con WebPushChannel::class y van por cola como el resto (AppNotification es ShouldQueue).
 * Sin VAPID configurado, o sin navegadores suscritos, no hace nada.
 */
final class WebPushChannel
{
    public function __construct(private readonly WebPushSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notification instanceof SendsWebPush || ! WebPushConfig::enabled()) {
            return;
        }

        $message = $notification->toWebPush($notifiable);
        if ($message === null) {
            return;
        }

        $subscriptions = $notifiable->pushSubscriptions()->orderBy('id')->get();
        if ($subscriptions->isNotEmpty()) {
            $this->sender->send($subscriptions, $message);
        }
    }
}
