<?php

namespace App\Notifications\System;

/**
 * Copias de seguridad atrasadas o fallidas, o prueba de restauración fallida (D-076,
 * app:check-storage con App\Domain\System\BackupStatus). Evento system.backup_failed, obligatorio
 * para el admin. No enlaza a ninguna página: se resuelve en el servidor (docs/DEPLOY.md).
 */
class BackupWarningNotification extends SystemWarning
{
    public function kind(): string
    {
        return 'system.backup_failed';
    }

    protected function group(): string
    {
        return 'backups';
    }

    public function url(object $notifiable): ?string
    {
        return null;
    }
}
