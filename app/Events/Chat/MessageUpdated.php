<?php

namespace App\Events\Chat;

use App\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Mensaje editado, borrado, ocultado o mostrado, fijado o desfijado, o con reacciones nuevas.
 */
final class MessageUpdated
{
    use Dispatchable;

    public function __construct(public readonly Message $message) {}
}
