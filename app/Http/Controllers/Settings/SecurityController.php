<?php

namespace App\Http\Controllers\Settings;

use App\Auth\SessionTerminator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $request->user()->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/security', $props);
    }

    /**
     * Cambia la contraseña y cierra las demás sesiones y el «Recordarme» de los otros dispositivos:
     * quien sospecha que le han robado el acceso cambia la contraseña para echar al intruso (SPEC §15).
     * La sesión actual sigue abierta.
     */
    public function update(PasswordUpdateRequest $request, SessionTerminator $terminator): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->update([
            'password' => $request->password,
        ]);

        $terminator->destroyOthers($request, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('app.password_updated')]);

        return back();
    }
}
