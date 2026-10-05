<?php

namespace App\Jobs;

use App\Domain\Integrations\Google\GoogleOAuth;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;

/**
 * Revoca en Google el token de refresco de una cuenta desconectada de forma automática (baja o paso
 * a colaborador, GoogleDisconnector, D-142). La fila ya se ha borrado: esto solo avisa a Google.
 *
 * - El payload va cifrado en la cola (ShouldBeEncrypted): el token nunca queda en claro en Valkey
 *   ni en failed_jobs.
 * - Un solo intento: si Google no confirma la revocación, se registra un aviso (sin el token) y no
 *   se reintenta. El token ya no está en la app, así que nadie puede usarlo desde aquí.
 * - Se encola tras el commit: si la baja se deshace, no se revoca nada.
 */
class RevokeGoogleToken implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        #[SensitiveParameter] public readonly string $token,
        public readonly int $userId,
    ) {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function handle(GoogleOAuth $oauth): void
    {
        if (! $oauth->revokeToken($this->token)) {
            Log::warning('Google token revocation failed after automatic disconnection', ['user_id' => $this->userId]);
        }
    }
}
