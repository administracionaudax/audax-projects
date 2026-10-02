<?php

namespace App\Events\Chat;

use App\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Mensaje nuevo (texto, audio, archivo o sistema). C2 lo emite a la conversación y avisa de las menciones.
 */
final class MessagePosted
{
    use Dispatchable;

    public function __construct(public readonly Message $message) {}
}
