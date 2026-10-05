<?php

namespace App\Domain\Integrations\Google;

use App\Models\User;

/**
 * Auditoría de las conexiones con Google (D-142): log `integrations` en activity_log, sobre la
 * persona dueña de la cuenta. Eventos:
 * - google_connected: conecta (o reconecta) su cuenta,
 * - google_disconnected: la desconecta ella desde Ajustes (con si Google confirmó la revocación),
 * - google_auto_disconnected: se desconecta sola, con el motivo (baja, paso a colaborador o
 *   acceso retirado por Google).
 * Solo el correo de la cuenta y el motivo: nunca tokens.
 */
final class GoogleConnectionAudit
{
    public const string LOG = 'integrations';

    public static function connected(User $user, string $email): void
    {
        self::record('google_connected', $user, ['google_email' => $email]);
    }

    public static function disconnected(User $user, string $email, GoogleDisconnectReason $reason, ?bool $revoked = null): void
    {
        self::record(
            $reason === GoogleDisconnectReason::Manual ? 'google_disconnected' : 'google_auto_disconnected',
            $user,
            [
                'google_email' => $email,
                'reason' => $reason->value,
                ...($revoked === null ? [] : ['revoked' => $revoked]),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private static function record(string $event, User $user, array $properties): void
    {
        activity(self::LOG)->performedOn($user)->event($event)->withProperties($properties)->log("integrations.{$event}");
    }
}
