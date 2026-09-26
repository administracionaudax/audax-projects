<?php

namespace App\Listeners;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * Registra el intento de inicio de sesión fallido (SPEC §15). Nunca guarda la contraseña.
 */
class RecordFailedLogin
{
    public function __construct(private readonly Request $request) {}

    public function handle(Failed $event): void
    {
        $email = Str::lower(trim((string) ($event->credentials[Fortify::username()] ?? $event->credentials['email'] ?? '')));

        $user = $event->user instanceof User
            ? $event->user
            : ($email !== '' ? User::query()->where('email', $email)->first() : null);

        LoginEvent::query()->create([
            'user_id' => $user?->id,
            'email' => Str::limit($email, 255, ''),
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 1000, ''),
            'succeeded' => false,
        ]);
    }
}
