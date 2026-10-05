<?php

namespace App\Domain\Auth\Google;

use App\Models\User;
use RuntimeException;

/**
 * No se deja entrar con Google (D-165). Lleva el correo de la cuenta de Google (si se llegó a
 * leer) y la persona de la app (si existe), para el registro de accesos y la auditoría.
 */
final class GoogleLoginRejected extends RuntimeException
{
    public function __construct(
        public readonly GoogleLoginRejection $reason,
        public readonly ?string $email = null,
        public readonly ?User $user = null,
    ) {
        parent::__construct("Google login: {$reason->value}");
    }

    public function userMessage(): string
    {
        return $this->reason->message($this->email ?? '');
    }
}
