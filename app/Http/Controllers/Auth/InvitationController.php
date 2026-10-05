<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\PasswordValidationRules;
use App\Domain\Auth\Google\GoogleLogin;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aceptar una invitación de alta (SPEC §14): la persona fija su contraseña con el enlace del
 * email. Usa el broker «invitations» (config/auth.php), que caduca a los 7 días en lugar de los
 * 60 minutos del restablecimiento. El token es de un solo uso (se borra al aceptar).
 * También se puede aceptar entrando con Google (D-165, GoogleLoginController).
 */
class InvitationController extends Controller
{
    use PasswordValidationRules;

    public const string BROKER = 'invitations';

    public function show(Request $request, string $token): Response
    {
        $email = Str::lower((string) $request->query('email', ''));

        return Inertia::render('auth/accept-invitation', [
            'token' => $token,
            'email' => $email,
            'passwordRules' => PasswordRule::defaults()->toPasswordRulesString(),
            // «Entrar con Google» en lugar de fijar la contraseña (D-165), si el correo es de un
            // dominio permitido. No revela nada: el callback vuelve a comprobarlo todo.
            'googleLogin' => GoogleLogin::available() && GoogleLogin::allowsEmail($email),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => $this->passwordRules(),
        ]);

        $email = Str::lower((string) $request->string('email'));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        // Una cuenta desactivada no puede aceptar la invitación (mismo mensaje: no se revela nada).
        if ($user === null || ! $user->is_active) {
            throw ValidationException::withMessages(['email' => __('app.invitation_invalid')]);
        }

        $status = Password::broker(self::BROKER)->reset(
            [
                'email' => $user->email,
                'token' => (string) $request->string('token'),
                'password' => (string) $request->string('password'),
                'password_confirmation' => (string) $request->string('password_confirmation'),
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __('app.invitation_invalid')]);
        }

        return redirect()->route('login')->with('status', __('app.invitation_accepted'));
    }

    /**
     * URL de aceptación para un email de invitación (la usa el alta de usuarios).
     */
    public static function urlFor(User $user): string
    {
        $token = Password::broker(self::BROKER)->createToken($user);

        return route('invitation.show', ['token' => $token, 'email' => $user->email]);
    }
}
