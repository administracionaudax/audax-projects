<?php

namespace App\Listeners;

use App\Auth\SessionTerminator;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Tras restablecer la contraseña por correo se cierran todas las sesiones abiertas del usuario:
 * si alguien le había robado el acceso, deja de tenerlo (SPEC §15).
 */
class CloseSessionsOnPasswordReset
{
    public function __construct(private readonly SessionTerminator $terminator) {}

    public function handle(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->terminator->destroyAll($event->user);
        }
    }
}
