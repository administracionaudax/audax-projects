<?php

namespace App\Listeners;

use App\Models\LoginEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Registra como inicio de sesión fallido un código 2FA o de recuperación incorrecto (SPEC §15):
 * es justo el intento de quien ya conoce la contraseña. Fortify no dispara Failed en este caso.
 */
class RecordFailedTwoFactorLogin
{
    public function __construct(private readonly Request $request) {}

    public function handle(TwoFactorAuthenticationFailed $event): void
    {
        $user = $event->user;

        LoginEvent::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'email' => Str::limit($user->email, 255, ''),
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 1000, ''),
            'succeeded' => false,
        ]);
    }
}
