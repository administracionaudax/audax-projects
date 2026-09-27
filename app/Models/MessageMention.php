<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mención en un mensaje: a una persona (user_id) o a todos los participantes (@todos, everyone).
 *
 * @property int $id
 * @property int $message_id
 * @property int|null $user_id
 * @property bool $everyone
 * @property-read Message $message
 * @property-read User|null $user
 */
#[Fillable(['message_id', 'user_id', 'everyone'])]
class MessageMention extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['everyone' => 'boolean'];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
