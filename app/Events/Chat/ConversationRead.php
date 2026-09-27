<?php

namespace App\Events\Chat;

use App\Models\ConversationParticipant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Alguien ha leído hasta un mensaje (contadores de no leídos y «leído por»).
 */
final class ConversationRead
{
    use Dispatchable;

    public function __construct(public readonly ConversationParticipant $participant) {}
}
