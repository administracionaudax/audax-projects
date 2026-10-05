<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Comentario de una sugerencia, con respuestas anidadas (parent_id), adjuntos y reacciones (F-165 y
 * F-166). Las menciones @ avisan con el patrón de App\Domain\Tasks\TaskMentions.
 *
 * @property int $id
 * @property int $suggestion_post_id
 * @property int $author_id
 * @property int|null $parent_id
 * @property string $body
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SuggestionPost $post
 * @property-read User $author
 * @property-read SuggestionComment|null $parent
 * @property-read Collection<int, SuggestionComment> $replies
 * @property-read Collection<int, SuggestionCommentReaction> $reactions
 * @property-read Collection<int, Attachment> $attachments
 */
#[Fillable(['suggestion_post_id', 'author_id', 'parent_id', 'body', 'edited_at'])]
class SuggestionComment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SuggestionPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(SuggestionPost::class, 'suggestion_post_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<SuggestionComment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(SuggestionComment::class, 'parent_id');
    }

    /**
     * @return HasMany<SuggestionComment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(SuggestionComment::class, 'parent_id')->orderBy('created_at');
    }

    /**
     * @return HasMany<SuggestionCommentReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(SuggestionCommentReaction::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
