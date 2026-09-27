<?php

namespace App\Broadcasting;

/**
 * Notificación que también puede salir como aviso del navegador (WebPushChannel).
 */
interface SendsWebPush
{
    public function toWebPush(object $notifiable): ?WebPushMessage;
}
