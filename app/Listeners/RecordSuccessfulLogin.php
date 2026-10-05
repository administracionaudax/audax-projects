<?php

namespace App\Listeners;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Registra el inicio de sesión correcto (SPEC §15) y sincroniza la cookie "appearance" con la
 * preferencia guardada del usuario, para que el tema sea el suyo en cualquier dispositivo. Al entrar
 * con el formulario, da la bienvenida con un aviso (F-012).
 *
 * Un usuario desactivado que vuelve con la cookie de «Recordarme» dispara Login antes de que
 * EnsureUserIsActive lo expulse: ese acceso se registra como fallido, nunca como correcto.
 */
class RecordSuccessfulLogin
{
    public const int APPEARANCE_COOKIE_MINUTES = 60 * 24 * 365;

    public function __construct(private readonly Request $request) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user;
        $active = $user->isActive();

        LoginEvent::query()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 1000, ''),
            'succeeded' => $active,
        ]);

        if ($active) {
            Cookie::queue(self::appearanceCookie($user->theme_preference));

            // Aviso de bienvenida al entrar con el formulario (F-012); no al volver con «Recordarme».
            if ($this->request->isMethod('post') && $this->request->hasSession()) {
                Inertia::flash('toast', ['type' => 'success', 'message' => __('weeklies.welcome', ['name' => Str::before(trim($user->name), ' ')])]);
            }
        }
    }

    /**
     * Cookie "appearance" sin HttpOnly: el JS (use-appearance.tsx) la actualiza al cambiar de tema.
     * No es un dato sensible y va sin cifrar (bootstrap/app.php).
     */
    public static function appearanceCookie(string $theme): SymfonyCookie
    {
        return cookie('appearance', $theme, self::APPEARANCE_COOKIE_MINUTES, httpOnly: false);
    }
}
