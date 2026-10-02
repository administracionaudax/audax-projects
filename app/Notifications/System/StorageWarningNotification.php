<?php

namespace App\Notifications\System;

/**
 * El disco del servidor o los adjuntos pasan del umbral de /admin/privacidad (D-076,
 * app:check-storage). Evento system.disk_space, obligatorio para el admin.
 */
class StorageWarningNotification extends SystemWarning
{
    public const string DISK = 'disk';

    public const string ATTACHMENTS = 'attachments';

    public function kind(): string
    {
        return 'system.disk_space';
    }

    protected function group(): string
    {
        return 'storage';
    }

    public function url(object $notifiable): ?string
    {
        return '/admin/privacidad';
    }
}
