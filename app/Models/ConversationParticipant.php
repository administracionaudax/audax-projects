<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Participante de una conversación: último mensaje leído (contadores de no leídos y «leído por»),
 * silenciada y, si salió (baja del proyecto o del grupo), left_at: conserva el histórico.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $user_id
 * @property int|null $last_read_message_id
 * @property bool $muted
 * @property CarbonImmutable $joined_at
 * @property CarbonImmutable|null $left_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Conversation $conversation
 * @property-read User $user
 */
#[Fillable(['conversation_id', 'user_id', 'last_read_message_id', 'muted', 'joined_at', 'left_at'])]
class ConversationParticipant extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'muted' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'muted' => 'boolean',
            'joined_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
