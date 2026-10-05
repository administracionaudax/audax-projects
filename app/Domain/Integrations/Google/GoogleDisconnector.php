<?php

namespace App\Domain\Integrations\Google;

use App\Jobs\RevokeGoogleToken;
use App\Models\GoogleConnection;
use App\Models\User;

/**
 * Desconexión automática de la cuenta de Google (D-142) cuando una persona deja de poder usarla:
 * al darla de baja (cualquier is_active = false, User::booted) o al pasarla a colaborador externo
 * (CollaboratorOffboarding::becameCollaborator).
 *
 * Borra la fila de google_connections en el momento (dentro de la transacción de quien llama, si
 * la hay), lo deja en la auditoría y revoca el token en Google desde la cola (RevokeGoogleToken),
 * después del commit: la baja no espera a Google ni falla si Google no responde.
 */
final class GoogleDisconnector
{
    public function disconnect(User $user, GoogleDisconnectReason $reason): void
    {
        $connection = GoogleConnection::query()->where('user_id', $user->id)->first();

        if ($connection === null) {
            return;
        }

        $token = $connection->refresh_token;
        $email = $connection->google_email;

        $connection->delete();

        GoogleConnectionAudit::disconnected($user, $email, $reason);

        RevokeGoogleToken::dispatch($token, $user->id);
    }

    /**
     * Google ha retirado el acceso (invalid_grant al renovar, o rechaza el token recién renovado):
     * se borra la conexión y queda en la auditoría. No hay nada que revocar.
     */
    public static function forgetRevoked(GoogleConnection $connection): void
    {
        $connection->delete();

        $user = User::query()->find($connection->user_id);

        if ($user !== null) {
            GoogleConnectionAudit::disconnected($user, $connection->google_email, GoogleDisconnectReason::RevokedByGoogle);
        }
    }
}
