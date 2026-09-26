<?php

namespace App\Listeners;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Registra el inicio de sesión correcto (SPEC §15) y sincroniza la cookie "appearance" con la
 * preferencia guardada del usuario, para que el tema sea el suyo en cualquier dispositivo.
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

        LoginEvent::query()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 1000, ''),
            'succeeded' => true,
        ]);

        Cookie::queue('appearance', $user->theme_preference, self::APPEARANCE_COOKIE_MINUTES);
    }
}
