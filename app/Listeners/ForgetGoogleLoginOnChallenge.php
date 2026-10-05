<?php

namespace App\Listeners;

use App\Http\Controllers\Auth\GoogleLoginController;
use Illuminate\Http\Request;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;

/**
 * Un reto 2FA nuevo del login con contraseña olvida un acceso con Google a medias (D-165), para
 * que ese inicio de sesión no se registre como `google`. El acceso con Google dispara este evento
 * antes de marcar la sesión, así que el suyo no se pierde.
 */
class ForgetGoogleLoginOnChallenge
{
    public function __construct(private readonly Request $request) {}

    public function handle(TwoFactorAuthenticationChallenged $event): void
    {
        if ($this->request->hasSession()) {
            $this->request->session()->forget(GoogleLoginController::GOOGLE_USER);
        }
    }
}
