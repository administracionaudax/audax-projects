<?php

namespace App\Domain\Auth\Google;

use App\Models\User;

/**
 * Auditoría de los accesos con Google (D-165): log `auth` en activity_log, visible en
 * /admin/auditoria (entidad «Accesos con Google»). Eventos:
 * - google_login: Google ha identificado a la persona y puede entrar (con si falta su 2FA),
 * - google_login_rejected: una cuenta de Google identificada que no puede entrar, con el motivo.
 * Los intentos sin identidad (state caducado, cancelado en Google…) no se auditan. El registro de
 * accesos (login_events, con method = google) se lleva aparte, como en el login con contraseña.
 */
final class GoogleLoginAudit
{
    public const string LOG = 'auth';

    public static function accepted(User $user, string $email, bool $twoFactorPending): void
    {
        activity(self::LOG)
            ->performedOn($user)
            ->causedBy($user)
            ->event('google_login')
            ->withProperties(['google_email' => $email, 'two_factor_pending' => $twoFactorPending])
            ->log('auth.google_login');
    }

    public static function rejected(GoogleLoginRejected $rejection): void
    {
        if ($rejection->email === null) {
            return;
        }

        $activity = activity(self::LOG)
            ->event('google_login_rejected')
            ->withProperties(['google_email' => $rejection->email, 'reason' => $rejection->reason->value]);

        if ($rejection->user !== null) {
            $activity->performedOn($rejection->user);
        }

        $activity->log('auth.google_login_rejected');
    }
}
