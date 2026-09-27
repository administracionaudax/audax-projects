<?php

namespace App\Models;

use App\Enums\ConversationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversación del chat (SPEC §4.5 y §12): de proyecto (una por proyecto, con sus miembros),
 * directa 1:1 (una por pareja, direct_key = "idMenor:idMayor") o de grupo (con nombre).
 *
 * @property int $id
 * @property ConversationType $type
 * @property int|null $project_id
 * @property string|null $name
 * @property string|null $direct_key
 * @property int|null $created_by
 * @property CarbonImmutable|null $last_message_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project|null $project
 * @property-read Collection<int, ConversationParticipant> $participants
 * @property-read Collection<int, Message> $messages
 */
#[Fillable(['type', 'project_id', 'name', 'direct_key', 'created_by', 'last_message_at'])]
class Conversation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'last_message_at' => 'immutable_datetime',
        ];
    }

    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<ConversationParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /**
     * @return HasMany<ConversationParticipant, $this>
     */
    public function activeParticipants(): HasMany
    {
        return $this->participants()->whereNull('left_at');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function hasParticipant(User $user): bool
    {
        return $this->activeParticipants()->where('user_id', $user->id)->exists();
    }
}
