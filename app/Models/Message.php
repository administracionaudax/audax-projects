<?php

namespace App\Models;

use App\Enums\MessageType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mensaje del chat (SPEC §12). El cuerpo es markdown ligero (se sanea al pintar); los de sistema
 * no tienen autor y llevan system_key + system_payload. Borrar es lógico («eliminado»); ocultar es
 * moderación del admin (auditado). task_id enlaza la tarea creada desde el mensaje. link_preview es la
 * previsualización del primer enlace (D-069), sin imagen remota.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int|null $user_id
 * @property MessageType $type
 * @property string|null $body
 * @property int|null $parent_id
 * @property int|null $task_id
 * @property string|null $system_key
 * @property array<string, mixed>|null $system_payload
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable|null $hidden_at
 * @property int|null $hidden_by
 * @property CarbonImmutable|null $pinned_at
 * @property int|null $pinned_by
 * @property array{url: string, title: string, description: string|null, domain: string}|null $link_preview
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Conversation $conversation
 * @property-read User|null $author
 * @property-read Message|null $parent
 * @property-read Task|null $task
 * @property-read Collection<int, MessageReaction> $reactions
 * @property-read Collection<int, MessageMention> $mentions
 * @property-read Collection<int, Attachment> $attachments
 * @property-read AudioTranscription|null $transcription
 */
#[Fillable(['conversation_id', 'user_id', 'type', 'body', 'parent_id', 'task_id', 'system_key', 'system_payload', 'edited_at', 'hidden_at', 'hidden_by', 'pinned_at', 'pinned_by'])]
class Message extends Model
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'system_payload' => 'array',
            'edited_at' => 'immutable_datetime',
            'hidden_at' => 'immutable_datetime',
            'pinned_at' => 'immutable_datetime',
            'link_preview' => 'array',
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'parent_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return HasMany<MessageReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * @return HasMany<MessageMention, $this>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(MessageMention::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * @return HasOne<AudioTranscription, $this>
     */
    public function transcription(): HasOne
    {
        return $this->hasOne(AudioTranscription::class);
    }
}
