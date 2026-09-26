<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Enlace de restablecimiento de contraseña, enviado POR COLA (SPEC §13: todos los emails van por cola).
 * Además, al no enviarse durante la petición, el tiempo de respuesta de /forgot-password no delata
 * si el correo existe (revisión adversarial, SEC-04).
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        parent::__construct($token);

        $this->onQueue('mail');
    }
}
