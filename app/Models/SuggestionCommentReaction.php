<?php

namespace App\Models;

use App\Enums\SuggestionReaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reacción de una persona a un comentario de una sugerencia (F-166): una por persona y comentario.
 *
 * @property int $id
 * @property int $suggestion_comment_id
 * @property int $user_id
 * @property SuggestionReaction $reaction
 * @property CarbonImmutable|null $created_at
 * @property-read SuggestionComment $comment
 * @property-read User $user
 */
#[Fillable(['suggestion_comment_id', 'user_id', 'reaction'])]
class SuggestionCommentReaction extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reaction' => SuggestionReaction::class,
        ];
    }

    /**
     * @return BelongsTo<SuggestionComment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(SuggestionComment::class, 'suggestion_comment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
